<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramConversationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Normalizes and handles one stored Telegram update (reporter or officer). */
class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $updateRowId) {}

    public function handle(TelegramConversationService $conversation): void
    {
        $row = TelegramUpdate::find($this->updateRowId);
        if (! $row || $row->status === 'PROCESSED') {
            return;
        }

        try {
            $conversation->handle($row->payload);
            $row->update(['status' => 'PROCESSED', 'processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            Log::error('Telegram update failed', ['update_id' => $row->update_id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $row->update(['status' => 'FAILED', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            $conversation->apologize($row->payload);
        }
    }
}
