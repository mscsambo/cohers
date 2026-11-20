<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

/**
 * Permission matrix from SRS section 03. Deny by default; every check
 * includes role, geographic scope and case relationship.
 */
class ReportPolicy
{
    private function inDistrictScope(User $user, Report $report): bool
    {
        return $user->isActive() && $user->isDistrictOfficer() && $user->coversArea($report->district_id, $report->commune_id);
    }

    public function view(User $user, Report $report): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->isAdmin()
            || $report->reporter_id === $user->id
            || $this->inDistrictScope($user, $report)
            || ($user->isFocalPoint() && $report->assignments()->where('focal_point_user_id', $user->id)->exists());
    }

    /** District: in scope. Admin: correction only (reason is mandatory in the controller). */
    public function reassign(User $user, Report $report): bool
    {
        return $report->status->isOpen() && ($this->inDistrictScope($user, $report) || ($user->isActive() && $user->isAdmin()));
    }

    public function triage(User $user, Report $report): bool
    {
        return $this->reassign($user, $report);
    }

    /** Review / resolve escalation: district only; admin monitors. */
    public function reviewEscalation(User $user, Report $report): bool
    {
        return $this->inDistrictScope($user, $report);
    }

    /** Approve closure / reopen: district only. */
    public function close(User $user, Report $report): bool
    {
        return $this->inDistrictScope($user, $report);
    }

    public function linkIncident(User $user, Report $report): bool
    {
        return $this->inDistrictScope($user, $report);
    }
}
