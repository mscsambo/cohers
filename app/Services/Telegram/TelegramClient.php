<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Thin Telegram Bot API wrapper. Without a token it runs in simulated mode. */
class TelegramClient
{
    public function configured(): bool
    {
        return (bool) config('cohers.telegram.bot_token');
    }

    public function call(string $method, array $params = [], int $timeout = 15): array
    {
        if (! $this->configured()) {
            Log::debug("[telegram:simulated] {$method}", $params);

            return ['ok' => true, 'simulated' => true, 'result' => []];
        }

        /** @var Response $res */
        $res = Http::timeout($timeout)->asJson()->post($this->url($method), $params);
        $json = $res->json() ?? [];

        if (! $res->successful() || ! ($json['ok'] ?? false)) {
            throw new TelegramException(
                $json['description'] ?? ('HTTP '.$res->status()),
                (int) ($json['error_code'] ?? $res->status()),
            );
        }

        return $json;
    }

    public function sendMessage(int|string $chatId, string $text, ?array $inlineKeyboard = null, ?array $replyKeyboard = null): array
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($inlineKeyboard) {
            $params['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        } elseif ($replyKeyboard) {
            $params['reply_markup'] = ['keyboard' => $replyKeyboard, 'resize_keyboard' => true, 'is_persistent' => true];
        }

        return $this->call('sendMessage', $params);
    }

    public function editMessage(int|string $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): array
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }

        try {
            return $this->call('editMessageText', $params);
        } catch (TelegramException $e) {
            // "message is not modified" or too old to edit: fall back to a new message.
            return $this->sendMessage($chatId, $text, $inlineKeyboard);
        }
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        try {
            $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackId, 'text' => $text]));
        } catch (TelegramException) {
            // Expired callback IDs are harmless.
        }
    }

    public function getUpdates(int $offset, int $timeout = 25): array
    {
        return $this->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => ['message', 'callback_query']], $timeout + 10)['result'] ?? [];
    }

    public function setWebhook(string $url, ?string $secret): array
    {
        return $this->call('setWebhook', array_filter([
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ]));
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook');
    }

    /** Download a Telegram file and return [bytes, file_path]. */
    public function downloadFile(string $fileId, int $maxBytes): array
    {
        $info = $this->call('getFile', ['file_id' => $fileId])['result'] ?? [];
        if (($info['file_size'] ?? 0) > $maxBytes) {
            throw new RuntimeException('File exceeds maximum size.');
        }
        $path = $info['file_path'] ?? throw new RuntimeException('No file path returned.');
        $res = Http::timeout(60)->get(rtrim(config('cohers.telegram.api_base'), '/').'/file/bot'.config('cohers.telegram.bot_token').'/'.$path);
        $res->throw();
        if (strlen($res->body()) > $maxBytes) {
            throw new RuntimeException('File exceeds maximum size.');
        }

        return [$res->body(), $path];
    }

    private function url(string $method): string
    {
        return rtrim(config('cohers.telegram.api_base'), '/').'/bot'.config('cohers.telegram.bot_token').'/'.$method;
    }
}
