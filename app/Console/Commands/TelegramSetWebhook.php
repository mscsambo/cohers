<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'cohers:telegram-webhook {url? : Public HTTPS base URL, defaults to APP_URL} {--delete}';

    protected $description = 'Register (or delete) the Telegram webhook with the configured secret';

    public function handle(TelegramClient $tg): int
    {
        if ($this->option('delete')) {
            $tg->deleteWebhook();
            $this->info('Webhook deleted.');

            return self::SUCCESS;
        }

        $secret = config('cohers.telegram.webhook_secret');
        if (! $secret) {
            $this->error('Set TELEGRAM_WEBHOOK_SECRET in .env first.');

            return self::FAILURE;
        }

        $base = rtrim($this->argument('url') ?? config('app.url'), '/');
        if (! str_starts_with($base, 'https://')) {
            $this->error('Telegram requires an HTTPS URL.');

            return self::FAILURE;
        }

        $tg->setWebhook($base.'/api/telegram/webhook', $secret);
        $this->info("Webhook set to {$base}/api/telegram/webhook");

        return self::SUCCESS;
    }
}
