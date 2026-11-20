<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Services\AuditService;
use App\Services\Telegram\TelegramWebAppAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Mini App entry. Telegram opens /tg; the page posts Telegram's signed
 * initData here and the person is signed in by their linked Telegram account:
 * reporters to "My reports", staff to their usual home page (MFA still applies).
 */
class MiniAppController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function entry()
    {
        return view('miniapp.entry');
    }

    public function auth(Request $request, TelegramWebAppAuth $telegram)
    {
        $data = $request->validate(['init_data' => 'required|string|max:8192']);
        $tgUser = $telegram->verify($data['init_data']);
        if (! $tgUser) {
            Log::warning('Mini App sign-in refused: '.$telegram->lastError);

            return response()->json(['error' => 'invalid'], 401);
        }

        $user = TelegramAccount::with('user.roles')
            ->where('telegram_user_id', $tgUser['id'])->where('status', 'active')
            ->first()?->user;
        if (! $user || ! $user->isActive()) {
            Log::info('Mini App sign-in: no active COHERS account for Telegram user '.$tgUser['id']);

            return response()->json(['error' => 'not_registered'], 404);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('via_telegram', true);
        $this->audit->record('auth.telegram_miniapp', $user, $user, null, null, null, 'telegram');

        return response()->json(['redirect' => LoginController::home($user)]);
    }
}
