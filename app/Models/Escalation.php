<?php

namespace App\Models;

use App\Enums\EscalationState;
use App\Enums\EscalationTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Escalation extends Model
{
    use \App\Models\Concerns\HasPublicUuid;

    protected $fillable = [
        'report_id', 'district_id', 'trigger', 'reason', 'escalation_state', 'opened_by', 'reviewed_by',
        'decision', 'decision_notes', 'opened_at', 'reviewed_at', 'resolved_at',
    ];

    protected $casts = [
        'trigger' => EscalationTrigger::class,
        'escalation_state' => EscalationState::class,
        'opened_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class, 'district_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return $this->escalation_state !== EscalationState::RESOLVED;
    }
}
