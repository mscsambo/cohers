<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Security\TotpService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class MfaController extends Controller
{
    public const KEY_CHANGED = 'Your two-factor code cannot be checked because the server\'s encryption key (APP_KEY in .env) has changed since you set it up. '
        .'Put back the .env from before the update, or reset two-factor for this account (COHERS_RESET_MFA in .env — see DEPLOY-PTERODACTYL.md).';

    public function __construct(private TotpService $totp, private AuditService $audit) {}

    public function challenge(Request $request)
    {
        if (! $request->user()->mfa_enabled) {
            return redirect()->route('mfa.setup');
        }

        return view('auth.mfa-challenge');
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $user = $request->user();
        $key = 'mfa|'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Wait '.RateLimiter::availableIn($key).'s.']);
        }
        try {
            $secret = $user->mfa_secret;
        } catch (DecryptException) {
            // The secret was saved under another APP_KEY (e.g. a new .env next to a restored database).
            Log::error("MFA secret for user {$user->id} cannot be decrypted: APP_KEY differs from the one used when MFA was set up.");
            throw ValidationException::withMessages(['code' => self::KEY_CHANGED]);
        }
        if (! $user->mfa_enabled || ! $this->totp->verify($secret, $request->code)) {
            RateLimiter::hit($key, 600);
            $this->audit->record('auth.mfa_failed', $user, $user);
            throw ValidationException::withMessages(['code' => 'Invalid code.']);
        }

        RateLimiter::clear($key);
        $request->session()->put('mfa_passed', true);
        $this->audit->record('auth.mfa_passed', $user, $user);

        return redirect()->intended(LoginController::home($user));
    }

    public function setup(Request $request)
    {
        $user = $request->user();
        if ($user->mfa_enabled) {
            return redirect()->route('mfa.challenge');
        }

        $secret = $request->session()->get('mfa_pending_secret') ?? $this->totp->generateSecret();
        $request->session()->put('mfa_pending_secret', $secret);

        return view('auth.mfa-setup', [
            'secret' => $secret,
            'uri' => $this->totp->uri($secret, $user->email),
        ]);
    }

    public function enable(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $user = $request->user();
        $secret = $request->session()->get('mfa_pending_secret');

        if (! $secret || ! $this->totp->verify($secret, $request->code)) {
            throw ValidationException::withMessages(['code' => 'Code did not match. Check your device time and try again.']);
        }

        $user->forceFill(['mfa_secret' => $secret, 'mfa_enabled' => true])->save();
        $request->session()->forget('mfa_pending_secret');
        $request->session()->put('mfa_passed', true);
        $this->audit->record('auth.mfa_enabled', $user, $user);

        return redirect(LoginController::home($user))->with('status', 'Two-factor authentication enabled.');
    }
}
