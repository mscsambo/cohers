<?php

namespace App\Services\Telegram;

use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Str;

/** Issues short-lived, single-use Telegram activation links (section 04). */
class TelegramLinkService
{
    public function __construct(private AuditService $audit) {}

    /** @return array{link: string, expires_at: \Illuminate\Support\Carbon} */
    public function issue(User $user, User $by): array
    {
        $token = Str::random(32);
        $ttl = (int) config('cohers.telegram.link_token_ttl_minutes');

        // Any earlier unused link stops working.
        TelegramLinkToken::where('user_id', $user->id)->whereNull('used_at')->update(['expires_at' => now()]);
        $row = TelegramLinkToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($ttl),
            'created_by' => $by->id,
        ]);

        $this->audit->record('telegram.link_token_issued', $user, $by, $by->is($user) ? 'Self-service from dashboard' : null);

        return [
            'link' => 'https://t.me/'.ltrim((string) config('cohers.telegram.bot_username'), '@').'?start=link_'.$token,
            'expires_at' => $row->expires_at,
        ];
    }
}
