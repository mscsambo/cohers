<?php

namespace App\Services\Routing;

use App\Enums\AssignmentStatus;
use App\Enums\EscalationTrigger;
use App\Enums\ReportStatus;
use App\Enums\RoleName;
use App\Enums\Sector;
use App\Models\FocalPointAssignment;
use App\Models\Report;
use App\Models\ReportAssignment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Escalation\EscalationService;
use App\Services\Notification\NotificationService;
use App\Services\Reporting\ReportService;
use App\Services\Telegram\BotMessages;
use Illuminate\Support\Facades\DB;

/**
 * Finds the active primary, then backup, focal point for a report's
 * location and sector; falls back to the district review queue (section 06).
 */
class ReportRoutingService
{
    public function __construct(
        private ReportService $reports,
        private NotificationService $notify,
        private EscalationService $escalations,
        private AuditService $audit,
    ) {}

    /** Route automatically. Returns the new assignment, or null if routed to district review. */
    public function route(Report $report, array $levels = ['primary', 'backup']): ?ReportAssignment
    {
        $report->loadMissing('category', 'location.parent.parent.parent');
        $sector = $report->category?->sector ?? Sector::UNKNOWN;

        if ($sector === Sector::UNKNOWN) {
            $this->reports->transition($report, ReportStatus::TRIAGE_REVIEW, null, 'Unknown category: manual triage review');

            return null;
        }

        $exclude = $report->assignments()->pluck('focal_point_user_id')->all();

        foreach ($levels as $level) {
            if ($officer = $this->findOfficer($report, $sector, $level, $exclude)) {
                return $this->assign($report, $officer, $level);
            }
        }

        $this->reports->transition($report, ReportStatus::TRIAGE_REVIEW, null, 'No eligible focal point available');
        $this->escalations->open($report, EscalationTrigger::NO_OFFICER,
            'No active '.($sector->officerType()?->value ?? 'officer').' assigned to this location (primary or backup).');

        return null;
    }

    /** Walks village → commune → district for an effective mapping at the given level. */
    public function findOfficer(Report $report, Sector $sector, string $level, array $excludeUserIds = []): ?User
    {
        $officerType = $sector->officerType();

        foreach ($report->location->lineage() as $unit) {
            $mapping = FocalPointAssignment::effective()
                ->where('geographic_unit_id', $unit->id)
                ->where('sector', $sector->value)
                ->where('level', $level)
                ->whereNotIn('user_id', $excludeUserIds)
                ->whereHas('user', fn ($u) => $u
                    ->where('account_status', 'active')
                    ->where('officer_type', $officerType?->value)
                    ->whereHas('roles', fn ($r) => $r->where('name', RoleName::FOCAL_POINT->value)))
                ->orderBy('effective_from', 'desc')
                ->first();

            if ($mapping) {
                return $mapping->user;
            }
        }

        return null;
    }

    public function assign(Report $report, User $officer, string $level, ?User $by = null, ?string $reason = null): ReportAssignment
    {
        $assignment = DB::transaction(function () use ($report, $officer, $level, $by, $reason) {
            // Close any still-active assignment so only one officer holds the case.
            $report->assignments()->whereIn('assignment_status', AssignmentStatus::active())
                ->update(['assignment_status' => AssignmentStatus::REASSIGNED->value, 'completed_at' => now()]);

            $minutes = $report->priority?->ackMinutes() ?? (int) config('cohers.ack_targets.MEDIUM');

            $assignment = ReportAssignment::create([
                'report_id' => $report->id,
                'focal_point_user_id' => $officer->id,
                'assigned_by' => $by?->id,
                'level' => $by ? 'manual' : $level,
                'assignment_status' => AssignmentStatus::PENDING,
                'assigned_at' => now(),
                'ack_due_at' => now()->addMinutes($minutes),
            ]);

            $this->reports->transition($report, ReportStatus::ASSIGNED, $by,
                $reason ?? "Assigned to {$officer->name} ({$level})", $by ? 'web' : 'system');

            return $assignment;
        });

        $this->notify->toUser($officer, 'assignment.new',
            fn ($loc) => BotMessages::assignmentAlert($assignment, $loc)[0],
            "assign:{$assignment->id}", $report,
            BotMessages::assignmentKeyboard($assignment, $officer->locale()));

        // Alert the case owner on every (re)assignment, not just the first.
        $this->notify->toUser($report->reporter, 'reporter.assigned',
            fn ($loc) => BotMessages::reporterAssigned($assignment, $loc),
            "reporter-assigned:{$assignment->id}", $report);

        // Dashboard users following this district (and opted-in admins) see every assignment.
        // The assigned officer already has the actionable alert; the actor knows what they did.
        $this->notify->toStaff($report, 'assignment', 'staff.assignment',
            fn ($loc) => BotMessages::staffAssignment($assignment, $loc, $by),
            "staff-assign:{$assignment->id}", array_filter([$officer->id, $by?->id]));

        return $assignment;
    }

    /** District/admin manual reassignment (audited). */
    public function reassign(Report $report, User $officer, User $actor, string $reason): ReportAssignment
    {
        $old = $report->activeAssignment?->focal_point_user_id;
        $assignment = $this->assign($report, $officer, 'manual', $actor, 'Reassigned: '.$reason);

        $this->audit->record('report.reassigned', $report, $actor, $reason,
            ['focal_point_user_id' => $old], ['focal_point_user_id' => $officer->id]);

        return $assignment;
    }

    /**
     * Officers a district user may pick when reassigning this report: active
     * focal points whose type matches the case sector (VHSG for human, CAO for
     * animal/environment) and who cover the report's district, either through
     * a Focal Point Router mapping (district, commune or village) or through
     * the district scope on their account. A mismatched officer would be
     * refused by ReportVerificationService::authorize().
     * Automatic routing still uses router mappings only.
     */
    public function eligibleOfficers(Report $report)
    {
        $districtId = $report->district_id;
        $officerType = $report->category?->sector?->officerType();

        return User::withRole(RoleName::FOCAL_POINT)
            ->where('account_status', 'active')
            ->whereNotNull('officer_type')
            ->when($officerType, fn ($q) => $q->where('officer_type', $officerType->value))
            ->where(fn ($w) => $w
                ->whereHas('focalPointAssignments', fn ($q) => $q->effective()->whereHas('geographicUnit',
                    fn ($g) => $g->where(fn ($x) => $x
                        ->where('geographic_units.id', $districtId)
                        ->orWhere('geographic_units.parent_id', $districtId)
                        ->orWhereHas('parent', fn ($p) => $p->where('parent_id', $districtId)))))
                ->orWhereHas('scopes', fn ($s) => $s->where('geographic_units.id', $districtId)))
            ->orderBy('name')->get();
    }
}
