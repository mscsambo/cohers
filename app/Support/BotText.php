<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Localized bot copy. Admin overrides in system_settings (key "msg.{locale}.{key}")
 * take precedence over lang/{km,en}/bot.php.
 */
class BotText
{
    /** Keys that administrators may override from Language & Settings. */
    public const EDITABLE = ['welcome', 'privacy_notice', 'help', 'emergency', 'officer_help'];

    public static function t(string $key, string $locale = 'km', array $replace = []): string
    {
        $override = SystemSetting::get_value("msg.{$locale}.{$key}");
        if (is_string($override) && $override !== '') {
            foreach ($replace as $k => $v) {
                $override = str_replace(':'.$k, (string) $v, $override);
            }

            return $override;
        }

        return __('bot.'.$key, $replace, $locale);
    }
}
