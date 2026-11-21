<?php

namespace App\Models;

use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthReview extends Model
{
    protected $fillable = ['report_id', 'reviewer_id', 'reason', 'acknowledged_at', 'completed_at', 'findings'];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public static function eligibleReviewers(Report $report): Collection
    {
        $units = array_filter([$report->commune_id, $report->district_id, $report->district?->parent_id]);

        return User::withRole(RoleName::HEALTH_CENTER)->where('account_status', 'active')
            ->whereHas('scopes', fn ($q) => $q->whereIn('geographic_units.id', $units))->orderBy('name')->get();
    }
}
