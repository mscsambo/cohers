<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\EscalationState;
use App\Enums\Priority;
use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Report extends Model
{
    use \App\Models\Concerns\HasPublicUuid;

    protected $fillable = [
        'reference_code', 'reporter_id', 'geographic_unit_id', 'commune_id', 'district_id', 'category_id',
        'subcategory', 'priority', 'status', 'escalation_state', 'triage_status', 'original_text',
        'affected_count', 'observed_text', 'observed_at', 'latitude', 'longitude', 'language', 'channel',
        'possible_duplicate_of_id', 'duplicate_score', 'idempotency_key', 'submitted_at', 'closed_at',
    ];

    protected $casts = [
        'status' => ReportStatus::class,
        'priority' => Priority::class,
        'escalation_state' => EscalationState::class,
        'observed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'closed_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'duplicate_score' => 'float',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'geographic_unit_id');
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'commune_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'district_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReportCategory::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReportAttachment::class);
    }

    public function triages(): HasMany
    {
        return $this->hasMany(AiTriage::class);
    }

    public function latestTriage(): HasOne
    {
        return $this->hasOne(AiTriage::class)->latestOfMany();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ReportAssignment::class);
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(ReportAssignment::class)
            ->whereIn('assignment_status', AssignmentStatus::active())
            ->latestOfMany();
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ReportVerification::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ReportStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class);
    }

    public function informationRequests(): HasMany
    {
        return $this->hasMany(ReportInformationRequest::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(CaseClosure::class);
    }

    /** Information the reporter added after submitting (Mini App). */
    public function reporterUpdates(): HasMany
    {
        return $this->hasMany(ReportUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(IncidentCase::class, 'report_incident_links')->withPivot('linked_by', 'reason')->withTimestamps();
    }

    public function possibleDuplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'possible_duplicate_of_id');
    }

    /**
     * Deny-by-default visibility scope (section 03). Every dashboard and bot
     * query that lists reports must go through this.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $q;
        }

        return $q->where(function (Builder $w) use ($user) {
            $w->where('reporter_id', $user->id);

            if ($user->isDistrictOfficer()) {
                $area = $user->areaScope();
                if ($area['districts']) {
                    $w->orWhereIn('district_id', $area['districts']);
                }
                if ($area['communes']) {
                    $w->orWhereIn('commune_id', $area['communes']);
                }
            }

            if ($user->isFocalPoint()) {
                $w->orWhereHas('assignments', fn ($a) => $a->where('focal_point_user_id', $user->id));
            }
        });
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->whereHas('assignments', fn ($a) => $a
            ->where('assignment_status', AssignmentStatus::PENDING->value)
            ->where('ack_due_at', '<', now()));
    }

    public function reporterStatusLabel(string $locale): string
    {
        return __('bot.status.'.$this->status->reporterKey(), [], $locale);
    }
}
