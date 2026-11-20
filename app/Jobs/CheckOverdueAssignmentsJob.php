<?php

namespace App\Jobs;

use App\Enums\AssignmentStatus;
use App\Enums\EscalationTrigger;
use App\Enums\Priority;
use App\Models\ReportAssignment;
use App\Services\Escalation\EscalationService;
use App\Services\Notification\NotificationService;
use App\Services\Routing\ReportRoutingService;
use App\Services\Telegram\BotMessages;
use App\Support\BotText;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled every minute. Applies the configured next step when an
 * acknowledgement target is missed (section 09):
 *   LOW      reminder; stays in overdue queue
 *   MEDIUM   reminder, then backup officer
 *   HIGH     backup officer + district alert
 *   CRITICAL district alert, repeated each target window while unacknowledged
 * Reminders stop as soon as the assignment is acknowledged.
 */
class CheckOverdueAssignmentsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function handle(NotificationService $notify, ReportRoutingService $routing, EscalationService $escalations): void
    {
        ReportAssignment::with('report.category', 'report.location', 'officer.telegramAccount')
            ->where('assignment_status', AssignmentStatus::PENDING->value)
            ->where('ack_due_at', '<', now())
            ->orderBy('ack_due_at')
            ->limit(200)
            ->get()
            ->each(fn ($a) => $this->process($a, $notify, $routing, $escalations));
    }

    private function process(ReportAssignment $a, NotificationService $notify, ReportRoutingService $routing, EscalationService $escalations): void
    {
        $report = $a->report;
        $priority = $report->priority ?? Priority::MEDIUM;
        $window = $priority->ackMinutes();
        $sinceLast = $a->last_reminded_at ?? $a->ack_due_at;
        $windowPassed = $a->reminder_count === 0 || $sinceLast->lte(now()->subMinutes($window));

        if (! $windowPassed) {
            return;
        }

        $remind = function () use ($a, $notify) {
            $a->update(['reminder_count' => $a->reminder_count + 1, 'last_reminded_at' => now()]);
            $notify->toUser($a->officer, 'assignment.reminder',
                fn ($loc) => BotText::t('officer.reminder', $loc, ['code' => $a->report->reference_code])."\n\n".BotMessages::assignmentAlert($a, $loc)[0],
                "remind:{$a->id}:{$a->reminder_count}", $a->report,
                BotMessages::assignmentKeyboard($a, $a->officer->locale()));
        };

        $toBackup = function () use ($a, $routing, $escalations, $report) {
            $a->update(['assignment_status' => AssignmentStatus::REASSIGNED, 'completed_at' => now()]);
            $routing->route($report->fresh(), ['backup', 'primary'])
                ?? $escalations->open($report, EscalationTrigger::NO_OFFICER, 'Overdue and no backup officer available.');
        };

        $overdueMinutes = (int) $a->ack_due_at->diffInMinutes(now());
        $reason = "Acknowledgement overdue by {$overdueMinutes} min ({$priority->value} target {$window} min).";

        match ($priority) {
            Priority::LOW => $remind(),
            Priority::MEDIUM => $a->reminder_count === 0 ? $remind() : $toBackup(),
            Priority::HIGH => [$escalations->open($report, EscalationTrigger::OVERDUE, $reason), $toBackup()],
            Priority::CRITICAL => $this->repeatCritical($a, $report, $reason, $escalations, $notify, $remind),
        };
    }

    private function repeatCritical($a, $report, string $reason, EscalationService $escalations, NotificationService $notify, callable $remind): void
    {
        $remind();
        $escalation = $escalations->open($report, EscalationTrigger::OVERDUE, $reason);
        // Repeat the district alert every window until someone acknowledges.
        $notify->toDistrict($report, 'district.repeat',
            fn ($loc) => BotMessages::districtAlert($report, $loc, 'REPEAT: '.$reason),
            "esc-repeat:{$escalation->id}:{$a->reminder_count}");
    }
}
