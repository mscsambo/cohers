<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeographicUnit extends Model
{
    use \App\Models\Concerns\HasPublicUuid;

    protected $fillable = ['parent_id', 'type', 'code', 'name_km', 'name_en', 'latitude', 'longitude', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];

    public const TYPES = ['province', 'district', 'commune', 'village', 'zone'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** This unit followed by each ancestor, nearest first. */
    public function lineage(): array
    {
        $chain = [];
        $node = $this;
        $guard = 0;
        while ($node && $guard++ < 10) {
            $chain[] = $node;
            $node = $node->parent;
        }

        return $chain;
    }

    public function ancestorOfType(string $type): ?self
    {
        foreach ($this->lineage() as $unit) {
            if ($unit->type === $type) {
                return $unit;
            }
        }

        return null;
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'km' ? $this->name_km : $this->name_en;
    }

    public function fullPath(): string
    {
        return collect(array_reverse($this->lineage()))
            ->reject(fn ($u) => $u->type === 'province')
            ->map(fn ($u) => $u->name_en)->implode(' › ');
    }
}
