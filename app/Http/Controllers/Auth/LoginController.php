<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private AuditService $audit) {}

    public function show()
    {
        if ($user = Auth::user()) {
            return redirect($this->home($user));
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->audit->record('auth.lockout', null, null, $data['email']);
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $user = User::where('email', $data['email'])->first();
        $passwordOk = $user && $user->password && Hash::check($data['password'], $user->password);

        // Correct password but no dashboard role: say why, without counting it as an attack.
        // (Only someone who already knows the password learns the account's access level.)
        if ($passwordOk && $user->isActive() && ! $user->canUseDashboard()) {
            $this->audit->record('auth.no_dashboard_role', $user, null, $data['email']);
            throw ValidationException::withMessages(['email' => 'This account has no dashboard access. Dashboard sign-in needs the System Administrator, District Officer or Local Focal Point (VHSG / CAO) role.']);
        }

        $ok = $passwordOk && $user->isActive() && $user->canUseDashboard()
            && Auth::attempt(['email' => $data['email'], 'password' => $data['password']], false);

        if (! $ok) {
            RateLimiter::hit($key, 15 * 60);
            $this->audit->record('auth.failed', $user, null, $data['email']);
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active dashboard account.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->forget('mfa_passed');
        $user->update(['last_login_at' => now()]);
        $this->audit->record('auth.login', $user, $user);

        return redirect()->intended($this->home($user));
    }

    public function logout(Request $request)
    {
        $this->audit->record('auth.logout', $request->user(), $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public static function home(User $user): string
    {
        return match (true) {
            $user->isDistrictOfficer() => route('district.overview'),
            $user->isAdmin() => route('admin.overview'),
            $user->isFocalPoint() => route('field.index'),
            default => route('my.index'),
        };
    }
}
