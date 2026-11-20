<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Advisory AI classification record. Never a diagnosis (section 02 safety rule). */
class AiTriage extends Model
{
    protected $fillable = [
        'report_id', 'provider', 'model', 'input_text', 'raw_response', 'category_code', 'subcategory',
        'suggested_priority', 'confidence', 'needs_manual_review', 'outcome', 'error', 'latency_ms',
    ];

    protected $casts = ['needs_manual_review' => 'boolean', 'confidence' => 'float'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
