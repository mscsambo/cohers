<?php

namespace App\Models;

use App\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Deterministic safety rules applied after AI triage (section 02, step 4). */
class RoutingRule extends Model
{
    use \App\Models\Concerns\HasPublicUuid;

    protected $fillable = ['name', 'rule_type', 'pattern', 'category_id', 'priority', 'escalate_district', 'is_active'];

    protected $casts = [
        'priority' => Priority::class,
        'escalate_district' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReportCategory::class);
    }
}
