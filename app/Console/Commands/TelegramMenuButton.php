<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

/** Sets the bot's menu button (beside the message box) to open the COHERS Mini App. */
class TelegramMenuButton extends Command
{
    protected $signature = 'cohers:telegram-menu-button
        {url? : Public https address of COHERS, e.g. your cloudflared tunnel (default: APP_URL)}
        {--text=COHERS : Button label}
        {--remove : Put back Telegram\'s normal commands menu}';

    protected $description = 'Set the Telegram bot menu button that opens the COHERS Mini App';

    public function handle(TelegramClient $telegram): int
    {
        if (! $telegram->configured()) {
            $this->error('No Telegram bot token is configured (Admin → Configuration or TELEGRAM_BOT_TOKEN).');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $telegram->call('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
            $this->info('Menu button reset to the normal commands menu.');

            return self::SUCCESS;
        }

        $base = rtrim((string) ($this->argument('url') ?: config('app.url')), '/');
        if (! str_starts_with($base, 'https://')) {
            $this->error("Telegram Mini Apps need an https:// address; got \"{$base}\". Pass your tunnel address, e.g.:");
            $this->line('  php artisan cohers:telegram-menu-button https://your-tunnel.trycloudflare.com');

            return self::FAILURE;
        }

        $url = $base.'/tg';
        $telegram->call('setChatMenuButton', ['menu_button' => [
            'type' => 'web_app',
            'text' => (string) $this->option('text'),
            'web_app' => ['url' => $url],
        ]]);
        $this->info("Menu button \"{$this->option('text')}\" now opens {$url}");
        $this->line('Reporters see "My reports"; linked officers and staff are signed in to their own pages.');

        return self::SUCCESS;
    }
}
