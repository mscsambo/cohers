<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['report_id', 'from_status', 'to_status', 'actor_id', 'reason', 'channel', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
