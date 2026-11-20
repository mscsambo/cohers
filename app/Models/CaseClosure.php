<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseClosure extends Model
{
    protected $fillable = ['report_id', 'closed_by', 'outcome_summary', 'response_steps', 'reporter_message', 'previous_status', 'closed_at', 'reopened_by', 'reopen_reason', 'reopened_at'];

    protected $casts = ['closed_at' => 'datetime', 'reopened_at' => 'datetime'];

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
