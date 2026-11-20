<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AI\GeminiTriageService;
use App\Services\AuditService;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

/**
 * Integration configuration: Gemini key/model, Telegram bot, reporter notices.
 * Values saved here override .env; secrets are encrypted with APP_KEY and
 * never shown again in full.
 */
class ConfigurationController extends Controller
{
    public const GEMINI_MODELS = [
        'gemini-2.5-flash' => 'Gemini 2.5 Flash — fast, recommended',
        'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite — lowest cost',
        'gemini-2.5-pro' => 'Gemini 2.5 Pro — highest quality, slower',
        'gemini-2.0-flash' => 'Gemini 2.0 Flash — previous generation',
    ];

    private const SECRETS = [
        'ai.gemini_api_key' => ['ai', 'GEMINI_API_KEY'],
        'telegram.bot_token' => ['telegram', 'TELEGRAM_BOT_TOKEN'],
        'telegram.webhook_secret' => ['telegram', 'TELEGRAM_WEBHOOK_SECRET'],
    ];

    public function __construct(private AuditService $audit) {}

    public function edit()
    {
        $stored = SystemSetting::all_cached();
        $secrets = [];
        foreach (self::SECRETS as $key => [, $env]) {
            $value = (string) config('cohers.'.$key);
            $secrets[$key] = [
                'set' => $value !== '',
                'masked' => $value !== '' ? str_repeat('•', 8).substr($value, -4) : null,
                'source' => array_key_exists($key, $stored) ? 'Admin configuration' : ($value !== '' ? ".env / environment ({$env})" : 'not set'),
            ];
        }

        $model = (string) config('cohers.ai.gemini_model');

        return view('admin.configuration', [
            'secrets' => $secrets,
            'driver' => SystemSetting::get_value('ai.driver', config('cohers.ai.driver')),
            'model' => $model,
            'models' => self::GEMINI_MODELS + ($model && ! isset(self::GEMINI_MODELS[$model]) ? [$model => $model.' (current)'] : []),
            'endpoint' => config('cohers.ai.gemini_endpoint'),
            'timeout' => (int) config('cohers.ai.timeout_seconds'),
            'minConfidence' => (float) SystemSetting::get_value('ai.min_confidence', config('cohers.ai.min_confidence')),
            'botUsername' => config('cohers.telegram.bot_username'),
            'linkTtl' => (int) config('cohers.telegram.link_token_ttl_minutes'),
            'maxPhotos' => (int) config('cohers.telegram.max_photos'),
            'shareOfficerName' => (bool) SystemSetting::get_value('reporter.share_officer_name', false),
            'prefix' => config('cohers.reference_prefix'),
            'webhookUrl' => rtrim(config('app.url'), '/').'/api/telegram/webhook',
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'ai_driver' => 'required|in:gemini,keyword,none',
            'gemini_api_key' => 'nullable|string|max:200',
            'gemini_model' => 'required|string|max:80|regex:/^[A-Za-z0-9._\-]+$/',
            'gemini_model_custom' => 'nullable|string|max:80|regex:/^[A-Za-z0-9._\-]+$/',
            'gemini_endpoint' => 'required|url|starts_with:https://',
            'gemini_timeout' => 'required|integer|min:3|max:120',
            'min_confidence' => 'required|numeric|between:0,1',
            'telegram_bot_token' => ['nullable', 'string', 'max:100', 'regex:/^\d+:[A-Za-z0-9_\-]{30,}$/'],
            'telegram_bot_username' => ['nullable', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9_]{5,}$/'],
            'telegram_webhook_secret' => ['nullable', 'string', 'min:16', 'max:256', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'link_ttl' => 'required|integer|min:5|max:10080',
            'max_photos' => 'required|integer|min:0|max:10',
            'reference_prefix' => 'required|string|max:6|regex:/^[A-Z]+$/',
            'share_officer_name' => 'nullable|boolean',
            'clear' => 'array',
            'reason' => 'nullable|string|max:500',
        ], [
            'telegram_bot_token.regex' => 'That does not look like a BotFather token (123456789:ABC…).',
            'telegram_webhook_secret.regex' => 'Webhook secret may only contain letters, numbers, _ and -.',
        ]);

        $changes = [];
        $set = function (string $key, $value, string $type, string $group) use (&$changes) {
            $s = SystemSetting::firstOrNew(['key' => $key]);
            if (! $s->exists || (string) $s->value !== (string) $value) {
                $changes[$key] = ['old' => $s->exists ? $s->value : config('cohers.'.$key), 'new' => (string) $value];
                $s->fill(['value' => (string) $value, 'type' => $type, 'group' => $group])->save();
            }
        };

        $set('ai.driver', $data['ai_driver'], 'string', 'ai');
        $set('ai.gemini_model', ($data['gemini_model_custom'] ?? null) ?: $data['gemini_model'], 'string', 'ai');
        $set('ai.gemini_endpoint', rtrim($data['gemini_endpoint'], '/'), 'string', 'ai');
        $set('ai.timeout_seconds', $data['gemini_timeout'], 'int', 'ai');
        $set('ai.min_confidence', $data['min_confidence'], 'float', 'ai');
        if (! empty($data['telegram_bot_username'])) {
            $set('telegram.bot_username', ltrim($data['telegram_bot_username'], '@'), 'string', 'telegram');
        }
        $set('telegram.link_token_ttl_minutes', $data['link_ttl'], 'int', 'telegram');
        $set('telegram.max_photos', $data['max_photos'], 'int', 'telegram');
        $set('reference_prefix', $data['reference_prefix'], 'string', 'general');
        $set('reporter.share_officer_name', $request->boolean('share_officer_name') ? '1' : '0', 'bool', 'reporter');

        // Secrets: blank keeps the current value; "clear" falls back to .env.
        $fields = ['ai.gemini_api_key' => 'gemini_api_key', 'telegram.bot_token' => 'telegram_bot_token', 'telegram.webhook_secret' => 'telegram_webhook_secret'];
        foreach ($fields as $key => $field) {
            [$group] = self::SECRETS[$key];
            if (in_array($key, $data['clear'] ?? [], true)) {
                SystemSetting::putSecret($key, null, $group);
                $changes[$key] = ['old' => '(secret)', 'new' => '(cleared — using .env)'];
            } elseif (! empty($data[$field])) {
                SystemSetting::putSecret($key, trim($data[$field]), $group);
                $changes[$key] = ['old' => '(secret)', 'new' => '(changed, ends '.substr(trim($data[$field]), -4).')'];
            }
        }

        if ($changes) {
            $this->audit->record('configuration.updated', null, $request->user(), $data['reason'] ?? null,
                collect($changes)->map->old->all(), collect($changes)->map->new->all());
        }

        // Long-running processes (queue worker, Telegram polling) read settings once at start.
        // Signal them to exit; start-cohers.bat runs both in a loop, so they come back with the new values.
        $reloadKeys = ['telegram.bot_token', 'ai.gemini_api_key', 'ai.driver', 'ai.gemini_model', 'ai.gemini_endpoint', 'ai.timeout_seconds', 'ai.min_confidence'];
        $restart = '';
        if (array_intersect(array_keys($changes), $reloadKeys)) {
            try {
                Artisan::call('queue:restart');
                $restart = ' The queue worker and Telegram polling reload automatically within a minute. If they were not started with start-cohers.bat, restart them yourself.';
            } catch (Throwable) {
                $restart = ' Restart the queue worker and Telegram polling (close the COHERS windows and run start-cohers.bat) to use the new AI / bot settings.';
            }
        }

        return back()->with('status', count($changes).' configuration value(s) saved.'.$restart);
    }

    public function testGemini(Request $request, GeminiTriageService $ai)
    {
        $text = $request->input('text') ?: 'មាន់ ២០ ក្បាលងាប់ភ្លាមៗក្នុងភូមិ / 20 chickens died suddenly in the village';
        $result = $ai->preview($text, 'gemini');
        $this->audit->record('configuration.gemini_tested', null, $request->user(), null, null,
            ['model' => config('cohers.ai.gemini_model'), 'ok' => $result['valid']]);

        return back()->with('gemini_test', $result);
    }

    public function geminiModels(GeminiTriageService $ai)
    {
        try {
            return response()->json(['models' => $ai->availableModels()]);
        } catch (Throwable $e) {
            return response()->json(['error' => Str::limit($e->getMessage(), 300)], 422);
        }
    }

    public function testTelegram(Request $request, TelegramClient $telegram)
    {
        if (! $telegram->configured()) {
            return back()->with('telegram_test', ['ok' => false, 'message' => 'No bot token configured — the bot is in simulated mode.']);
        }

        try {
            $me = $telegram->call('getMe')['result'] ?? [];
            $hook = $telegram->call('getWebhookInfo')['result'] ?? [];
            if (! empty($me['username']) && $me['username'] !== config('cohers.telegram.bot_username')) {
                SystemSetting::updateOrCreate(['key' => 'telegram.bot_username'], ['value' => $me['username'], 'type' => 'string', 'group' => 'telegram']);
            }
            $result = ['ok' => true, 'message' => 'Connected to @'.($me['username'] ?? '?').' ('.($me['first_name'] ?? '').')',
                'webhook' => $hook['url'] ?: 'none (polling mode)', 'pending' => $hook['pending_update_count'] ?? 0,
                'last_error' => $hook['last_error_message'] ?? null];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => Str::limit($e->getMessage(), 300)];
        }
        $this->audit->record('configuration.telegram_tested', null, $request->user(), null, null, ['ok' => $result['ok']]);

        return back()->with('telegram_test', $result);
    }

    public function webhook(Request $request, TelegramClient $telegram)
    {
        $data = $request->validate(['action' => 'required|in:set,delete', 'url' => 'nullable|url|starts_with:https://']);

        try {
            if ($data['action'] === 'delete') {
                $telegram->deleteWebhook();
                $msg = 'Webhook removed. Use php artisan cohers:telegram-poll for local polling.';
            } else {
                if (! config('cohers.telegram.webhook_secret')) {
                    return back()->withErrors(['webhook' => 'Set a webhook secret first.']);
                }
                $base = rtrim($data['url'] ?? config('app.url'), '/');
                if (! str_starts_with($base, 'https://')) {
                    return back()->withErrors(['webhook' => 'Telegram requires a public HTTPS URL (APP_URL is '.config('app.url').').']);
                }
                $telegram->setWebhook($base.'/api/telegram/webhook', config('cohers.telegram.webhook_secret'));
                $msg = "Webhook set to {$base}/api/telegram/webhook";
            }
        } catch (Throwable $e) {
            return back()->withErrors(['webhook' => Str::limit($e->getMessage(), 300)]);
        }
        $this->audit->record('configuration.webhook_'.$data['action'], null, $request->user(), null, null, ['url' => $data['url'] ?? null]);

        return back()->with('status', $msg);
    }
}
