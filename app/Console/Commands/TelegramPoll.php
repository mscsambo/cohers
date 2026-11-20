<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramWebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Local-development alternative to the HTTPS webhook: long-polls getUpdates
 * and feeds each update through the same idempotent ingest path.
 */
class TelegramPoll extends Command
{
    protected $signature = 'cohers:telegram-poll {--once : Process one batch and exit} {--force : Remove an existing webhook and poll anyway}';

    protected $description = 'Long-poll Telegram for updates (development only; production uses the webhook)';

    public function handle(TelegramClient $tg, TelegramWebhookService $webhook): int
    {
        if (! $tg->configured()) {
            $this->error('No Telegram bot token yet: set TELEGRAM_BOT_TOKEN in .env or in Admin > Configuration.');

            return self::FAILURE;
        }

        // Polling and a webhook cannot coexist. Never silently take the bot away from a live server.
        $hook = $tg->call('getWebhookInfo')['result']['url'] ?? '';
        if ($hook !== '' && ! $this->option('force')) {
            $this->error("This bot is served by a webhook: {$hook}");
            $this->line('Polling here would disconnect that server. Use a separate test bot token locally,');
            $this->line('or run with --force if you really want to move the bot to this machine.');

            return self::FAILURE;
        }

        $tg->deleteWebhook();
        $this->info('Polling Telegram… (Ctrl+C to stop)');
        $offset = 0;
        // Same signal as queue:restart (sent when AI / bot settings are saved): exit so the
        // start-cohers.bat loop starts polling again with the new settings.
        $restartSignal = Cache::get('illuminate:queue:restart');

        do {
            if (Cache::get('illuminate:queue:restart') !== $restartSignal) {
                $this->info('Settings changed: restarting polling to load them.');

                return self::SUCCESS;
            }
            try {
                foreach ($tg->getUpdates($offset) as $update) {
                    $offset = max($offset, $update['update_id'] + 1);
                    $row = $webhook->ingest($update, sync: true);
                    $this->line(sprintf('[%s] update %d %s', now()->format('H:i:s'), $update['update_id'], $row ? 'processed' : 'duplicate'));
                }
            } catch (Throwable $e) {
                $this->warn('Poll error: '.$e->getMessage());
                sleep(3);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
