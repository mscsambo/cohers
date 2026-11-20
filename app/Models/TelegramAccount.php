<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramAccount extends Model
{
    protected $fillable = ['user_id', 'telegram_user_id', 'private_chat_id', 'username', 'first_name', 'language_code', 'linked_at', 'last_seen_at', 'status'];

    protected $casts = ['linked_at' => 'datetime', 'last_seen_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Reports submitted by the linked account (for the admin directory). */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id', 'user_id');
    }

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(ReportAssignment::class, 'focal_point_user_id', 'user_id')
            ->whereIn('assignment_status', \App\Enums\AssignmentStatus::active());
    }
}
