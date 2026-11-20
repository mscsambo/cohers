<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Privileged web users must pass a TOTP challenge each session (section 04). */
class EnsureMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Your account is not active.']);
        }

        if ($user->mfa_enabled) {
            if (! $request->session()->get('mfa_passed')) {
                return redirect()->route('mfa.challenge');
            }
        } elseif (SystemSetting::get_value('require_mfa', config('cohers.require_mfa'))) {
            return redirect()->route('mfa.setup');
        }

        return $next($request);
    }
}
