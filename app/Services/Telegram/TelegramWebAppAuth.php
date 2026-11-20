<?php

namespace App\Services\Telegram;

/**
 * Verifies the signed initData Telegram hands a Mini App, so the person can be
 * signed in by their Telegram account without a password.
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
class TelegramWebAppAuth
{
    /** initData older than this is refused, so a copied link cannot be replayed later. */
    public const MAX_AGE_SECONDS = 86400;

    /** Why the last verify() refused, for the log (never contains the data itself). */
    public ?string $lastError = null;

    /** Returns the Telegram user (id, first_name, language_code…) or null when the data is not genuine. */
    public function verify(string $initData, ?string $botToken = null, ?int $now = null): ?array
    {
        $this->lastError = null;
        $token = (string) ($botToken ?? config('cohers.telegram.bot_token'));
        if ($token === '' || $initData === '') {
            $this->lastError = $token === '' ? 'no bot token configured' : 'empty initData';

            return null;
        }

        parse_str($initData, $fields);
        $hash = $fields['hash'] ?? null;
        if (! is_string($hash)) {
            $this->lastError = 'no hash';

            return null;
        }
        unset($fields['hash']);
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($fields), $fields));

        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        if (! hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
            $this->lastError = 'signature mismatch (fields: '.implode(',', array_keys($fields)).')';

            return null;
        }
        if ((int) ($fields['auth_date'] ?? 0) < ($now ?? time()) - self::MAX_AGE_SECONDS) {
            $this->lastError = 'too old (auth_date '.($fields['auth_date'] ?? '?').')';

            return null;
        }

        $user = json_decode((string) ($fields['user'] ?? ''), true);
        if (! is_array($user) || ! isset($user['id'])) {
            $this->lastError = 'no user in initData';

            return null;
        }

        return $user;
    }

    /** Builds signed initData the way Telegram does (used by tests). */
    public static function sign(array $fields, string $botToken): string
    {
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($fields), $fields));
        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);

        return http_build_query($fields + ['hash' => hash_hmac('sha256', $check, $secret)]);
    }
}
