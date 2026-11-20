<?php

/*
|--------------------------------------------------------------------------
| COHERS pilot configuration
|--------------------------------------------------------------------------
| Numerical targets are illustrative pilot values (SRS section 09) and must
| be approved by program authorities. Most can be overridden at runtime
| from Admin > Language & Settings (system_settings table).
*/

return [

    'reference_prefix' => env('COHERS_REFERENCE_PREFIX', 'COH'),

    'display_timezone' => env('COHERS_DISPLAY_TIMEZONE', 'Asia/Phnom_Penh'),

    'require_mfa' => (bool) env('COHERS_REQUIRE_MFA', true),

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'cohers_bot'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
        'link_token_ttl_minutes' => (int) env('TELEGRAM_LINK_TOKEN_TTL', 60),
        'max_photos' => 3,
        'max_photo_bytes' => 10 * 1024 * 1024,
    ],

    'ai' => [
        // gemini = live Gemini API; keyword = offline heuristic (demo); none = always manual triage
        'driver' => env('COHERS_AI_DRIVER', env('GEMINI_API_KEY') ? 'gemini' : 'keyword'),
        'gemini_api_key' => env('GEMINI_API_KEY'),
        'gemini_model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'gemini_endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout_seconds' => (int) env('GEMINI_TIMEOUT', 20),
        'min_confidence' => (float) env('COHERS_AI_MIN_CONFIDENCE', 0.6),
    ],

    // Acknowledgement targets in minutes, by priority (section 09).
    'ack_targets' => [
        'LOW' => 24 * 60,
        'MEDIUM' => 4 * 60,
        'HIGH' => 30,
        'CRITICAL' => 15,
    ],

    // Hours after acknowledgement before a verification is considered aging.
    'verification_target_hours' => (int) env('COHERS_VERIFICATION_TARGET_HOURS', 48),

    'duplicates' => [
        'window_days' => 3,
        'min_score' => 0.35,
    ],

    'incentives_enabled' => (bool) env('COHERS_INCENTIVES_ENABLED', false),
];
