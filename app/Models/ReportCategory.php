<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\Sector;
use Illuminate\Database\Eloquent\Model;

class ReportCategory extends Model
{
    use \App\Models\Concerns\HasPublicUuid;

    protected $fillable = ['code', 'name_en', 'name_km', 'sector', 'default_priority', 'description', 'is_active', 'sort'];

    protected $casts = [
        'is_active' => 'boolean',
        'sector' => Sector::class,
        'default_priority' => Priority::class,
    ];

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'km' ? $this->name_km : $this->name_en;
    }
}
