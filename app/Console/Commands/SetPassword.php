<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\RateLimiter;

/** Set a dashboard password for an existing account; the password is typed hidden. */
class SetPassword extends Command
{
    protected $signature = 'cohers:set-password {email}';

    protected $description = 'Set a new dashboard password for an account (typed hidden) and clear its sign-in lockout';

    public function handle(AuditService $audit): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();
        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password (min 10 characters, not shown)');
        if (mb_strlen($password) < 10) {
            $this->error('Too short — use at least 10 characters.');

            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Type it again')) {
            $this->error('The two entries do not match. Nothing changed.');

            return self::FAILURE;
        }

        $user->forceFill(['password' => $password])->save();
        foreach (['127.0.0.1', '::1'] as $ip) {
            RateLimiter::clear(strtolower($user->email).'|'.$ip);
        }
        $audit->record('user.password_set', $user, null, 'Set from console', null, null, 'console');

        $this->info("Password updated for {$user->email}. Sign-in lockout cleared for this computer.");

        return self::SUCCESS;
    }
}
