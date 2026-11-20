<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use \App\Models\Concerns\HasPublicUuid;

    protected $fillable = ['report_id', 'recipient_id', 'chat_id', 'channel', 'message_type', 'idempotency_key', 'payload', 'attempts', 'delivery_status', 'error', 'sent_at'];

    protected $casts = [
        'payload' => 'array',
        'delivery_status' => DeliveryStatus::class,
        'sent_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
