<?php

namespace App\Models;

use App\Enums\VerificationOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportVerification extends Model
{
    protected $fillable = ['report_id', 'assignment_id', 'verifier_id', 'outcome', 'findings', 'follow_up_needed', 'duplicate_of_report_id', 'channel', 'verified_at'];

    protected $casts = [
        'outcome' => VerificationOutcome::class,
        'follow_up_needed' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
