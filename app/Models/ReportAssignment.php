<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAssignment extends Model
{
    protected $fillable = [
        'report_id', 'focal_point_user_id', 'assigned_by', 'level', 'assignment_status', 'assigned_at', 'ack_due_at',
        'acknowledged_at', 'verification_started_at', 'completed_at', 'reminder_count', 'last_reminded_at',
    ];

    protected $casts = [
        'assignment_status' => AssignmentStatus::class,
        'assigned_at' => 'datetime',
        'ack_due_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'verification_started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'focal_point_user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->assignment_status->value, AssignmentStatus::active(), true);
    }

    public function isOverdue(): bool
    {
        return $this->assignment_status === AssignmentStatus::PENDING && $this->ack_due_at?->isPast();
    }
}
