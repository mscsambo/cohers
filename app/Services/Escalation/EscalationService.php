<?php

namespace App\Services\Escalation;

use App\Enums\EscalationState;
use App\Enums\EscalationTrigger;
use App\Enums\ReportStatus;
use App\Enums\VerificationOutcome;
use App\Models\CaseClosure;
use App\Models\Escalation;
use App\Models\IncentiveTransaction;
use App\Models\Report;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notification\NotificationService;
use App\Services\Reporting\ReportService;
use App\Services\Telegram\BotMessages;
use DomainException;
use Illuminate\Support\Facades\DB;

/** District review, escalation lifecycle and case closure (section 08). */
class EscalationService
{
    public const DECISIONS = [
        'follow_up_directed' => 'Authorized follow-up directed',
        'reassigned' => 'Reassigned to another officer',
        'more_evidence_requested' => 'More evidence requested',
        'referred_onward' => 'Referred onward',
        'no_further_action' => 'No further action required',
    ];

    public function __construct(
        private ReportService $reports,
        private NotificationService $notify,
        private AuditService $audit,
    ) {}

    /** Opens an escalation; a second open one for the same trigger is never created. */
    public function open(Report $report, EscalationTrigger $trigger, string $reason, ?User $actor = null, string $channel = 'system'): Escalation
    {
        $existing = Escalation::where('report_id', $report->id)
            ->where('trigger', $trigger->value)
            ->where('escalation_state', '!=', EscalationState::RESOLVED->value)
            ->first();
        if ($existing) {
            return $existing;
        }

        $escalation = Escalation::create([
            'report_id' => $report->id,
            'district_id' => $report->district_id,
            'trigger' => $trigger,
            'reason' => $reason,
            'escalation_state' => EscalationState::OPEN,
            'opened_by' => $actor?->id,
            'opened_at' => now(),
        ]);

        if (in_array($report->escalation_state, [EscalationState::NONE, EscalationState::RESOLVED], true)) {
            $report->update(['escalation_state' => EscalationState::OPEN]);
        }

        $this->audit->record('escalation.opened', $report, $actor, $reason, null,
            ['trigger' => $trigger->value, 'escalation_id' => $escalation->id], $channel);

        $this->notify->toDistrict($report, 'district.escalation',
            fn ($loc) => BotMessages::districtAlert($report, $loc, "[{$trigger->value}] {$reason}"),
            "esc:{$escalation->id}");

        return $escalation;
    }

    public function startReview(Escalation $escalation, User $actor): Escalation
    {
        if ($escalation->escalation_state === EscalationState::OPEN) {
            $escalation->update([
                'escalation_state' => EscalationState::IN_REVIEW,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $escalation->report->update(['escalation_state' => EscalationState::IN_REVIEW]);
            $this->audit->record('escalation.review_started', $escalation, $actor);
        }

        return $escalation;
    }

    public function resolve(Escalation $escalation, User $actor, string $decision, string $notes): Escalation
    {
        if (! array_key_exists($decision, self::DECISIONS)) {
            throw new DomainException('Unknown decision.');
        }
        if (! $escalation->isOpen()) {
            return $escalation;
        }

        $escalation->update([
            'escalation_state' => EscalationState::RESOLVED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => $escalation->reviewed_at ?? now(),
            'resolved_at' => now(),
            'decision' => $decision,
            'decision_notes' => $notes,
        ]);

        $this->syncReportState($escalation->report);
        $this->audit->record('escalation.resolved', $escalation, $actor, $notes, null, ['decision' => $decision]);

        return $escalation;
    }

    public function syncReportState(Report $report): void
    {
        $open = $report->escalations()->where('escalation_state', '!=', EscalationState::RESOLVED->value)->get();
        $state = match (true) {
            $open->contains(fn ($e) => $e->escalation_state === EscalationState::IN_REVIEW) => EscalationState::IN_REVIEW,
            $open->isNotEmpty() => EscalationState::OPEN,
            $report->escalations()->exists() => EscalationState::RESOLVED,
            default => EscalationState::NONE,
        };
        $report->update(['escalation_state' => $state]);
    }

    public function canClose(Report $report): ?string
    {
        if (! $report->status->isClosable()) {
            return 'Only verified, rejected or duplicate reports can be closed.';
        }
        if ($report->escalations()->where('escalation_state', '!=', EscalationState::RESOLVED->value)->exists()) {
            return 'Resolve all open escalations before closing.';
        }

        return null;
    }

    /** Final closure requires a documented outcome and district approval. */
    /**
     * @param  string|null  $reporterMessage  optional reporter-facing note; internal
     *                                         outcome notes are never sent to reporters
     */
    public function close(Report $report, User $actor, string $outcomeSummary, ?string $responseSteps = null, ?string $reporterMessage = null): CaseClosure
    {
        if ($why = $this->canClose($report)) {
            throw new DomainException($why);
        }

        $outcome = $report->status;
        $closure = DB::transaction(function () use ($report, $actor, $outcomeSummary, $responseSteps, $reporterMessage) {
            $closure = CaseClosure::create([
                'report_id' => $report->id,
                'closed_by' => $actor->id,
                'outcome_summary' => $outcomeSummary,
                'response_steps' => $responseSteps,
                'reporter_message' => $reporterMessage ?: null,
                'previous_status' => $report->status->value,
                'closed_at' => now(),
            ]);
            $this->reports->transition($report, ReportStatus::CLOSED, $actor, $outcomeSummary, 'web');
            $this->audit->record('report.closed', $report, $actor, $outcomeSummary, null, ['closure_id' => $closure->id]);

            return $closure;
        });

        $this->awardPoints($report);

        // Tell the case owner it is finished, in reporter-safe wording.
        $this->notify->toUser($report->reporter, 'reporter.closed',
            fn ($loc) => BotMessages::reporterClosed($report, $outcome, $reporterMessage, $loc), "reporter-closed:{$closure->id}", $report);

        return $closure;
    }

    public function reopen(Report $report, User $actor, string $reason): Report
    {
        $closure = $report->closures()->whereNull('reopened_at')->latest('closed_at')->first();
        if ($report->status !== ReportStatus::CLOSED || ! $closure) {
            throw new DomainException('Report is not closed.');
        }

        $closure->update(['reopened_by' => $actor->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);
        $this->reports->transition($report, ReportStatus::from($closure->previous_status), $actor, 'Reopened: '.$reason, 'web');
        $this->audit->record('report.reopened', $report, $actor, $reason);

        $this->notify->toUser($report->reporter, 'reporter.reopened',
            fn ($loc) => \App\Support\BotText::t('reporter.reopened', $loc, ['code' => $report->reference_code]),
            "reporter-reopened:{$closure->id}", $report);

        return $report;
    }

    /** Optional incentive points; one award per verified report, ledgered. */
    private function awardPoints(Report $report): void
    {
        if (! SystemSetting::get_value('incentives_enabled', false)) {
            return;
        }
        $verified = $report->verifications()->where('outcome', VerificationOutcome::VERIFIED->value)->exists();
        if ($verified) {
            IncentiveTransaction::firstOrCreate(
                ['user_id' => $report->reporter_id, 'report_id' => $report->id, 'reason' => 'verified_report'],
                ['points' => 10],
            );
        }
    }
}
