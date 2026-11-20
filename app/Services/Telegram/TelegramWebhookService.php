<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use Illuminate\Database\QueryException;

/** Authenticates the webhook, deduplicates on update_id and queues processing. */
class TelegramWebhookService
{
    public function verifySecret(?string $header): bool
    {
        $secret = config('cohers.telegram.webhook_secret');

        return $secret && $header && hash_equals($secret, $header);
    }

    /**
     * @return TelegramUpdate|null null when this update_id was already received
     */
    public function ingest(array $update, bool $sync = false): ?TelegramUpdate
    {
        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId) && ! ctype_digit((string) $updateId)) {
            return null;
        }

        if (TelegramUpdate::where('update_id', $updateId)->exists()) {
            return null;
        }

        try {
            $row = TelegramUpdate::create(['update_id' => (int) $updateId, 'payload' => $update]);
        } catch (QueryException) {
            return null; // concurrent duplicate delivery
        }

        $sync ? ProcessTelegramUpdateJob::dispatchSync($row->id) : ProcessTelegramUpdateJob::dispatch($row->id);

        return $row;
    }
}
