<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\NotificationLog;
use App\Models\TelegramAccount;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Sends one queued notice; retries with backoff and records every attempt. */
class SendTelegramNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $logId) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(TelegramClient $telegram): void
    {
        $log = NotificationLog::find($this->logId);
        if (! $log || in_array($log->delivery_status, [DeliveryStatus::SENT, DeliveryStatus::SIMULATED, DeliveryStatus::UNDELIVERABLE], true)) {
            return;
        }

        $log->increment('attempts');

        try {
            $res = $telegram->sendMessage($log->chat_id, $log->payload['text'] ?? '', $log->payload['keyboard'] ?? null);
            $log->update([
                'delivery_status' => ($res['simulated'] ?? false) ? DeliveryStatus::SIMULATED : DeliveryStatus::SENT,
                'sent_at' => now(),
                'error' => null,
            ]);
        } catch (TelegramException $e) {
            if ($e->isUndeliverable()) {
                $log->update(['delivery_status' => DeliveryStatus::UNDELIVERABLE, 'error' => $e->getMessage()]);
                TelegramAccount::where('private_chat_id', $log->chat_id)->update(['status' => 'blocked']);

                return;
            }
            $log->update(['error' => $e->getMessage()]);
            throw $e;
        } catch (Throwable $e) {
            $log->update(['error' => mb_substr($e->getMessage(), 0, 1000)]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        NotificationLog::whereKey($this->logId)->update([
            'delivery_status' => DeliveryStatus::FAILED->value,
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
