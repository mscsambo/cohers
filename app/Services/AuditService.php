<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Writes the immutable, append-only audit trail (sections 03, 13). */
class AuditService
{
    public function record(
        string $action,
        ?Model $subject = null,
        ?User $actor = null,
        ?string $reason = null,
        ?array $old = null,
        ?array $new = null,
        string $channel = 'web',
    ): AuditLog {
        return AuditLog::create([
            'actor_id' => $actor?->id ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'reason' => $reason,
            'old_values' => $old,
            'new_values' => $new,
            'channel' => $channel,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }
}
