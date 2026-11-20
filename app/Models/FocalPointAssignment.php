<?php

namespace App\Models;

use App\Enums\Sector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Primary/backup router mapping: officer x location x sector x effective date. */
class FocalPointAssignment extends Model
{
    use \App\Models\Concerns\HasPublicUuid;

    protected $fillable = ['user_id', 'geographic_unit_id', 'sector', 'level', 'effective_from', 'effective_to', 'is_active', 'created_by'];

    protected $casts = [
        'sector' => Sector::class,
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function geographicUnit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class);
    }

    public function scopeEffective(Builder $q): Builder
    {
        $today = now()->toDateString();

        return $q->where('is_active', true)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today));
    }
}
