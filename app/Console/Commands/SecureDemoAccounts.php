<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * For a public demo server: replace the well-known demo passwords with random
 * ones and turn MFA back on (the demo seeder relaxes it for local use).
 */
class SecureDemoAccounts extends Command
{
    protected $signature = 'cohers:secure-demo';

    protected $description = 'Give *@cohers.test accounts random passwords and require MFA';

    public function handle(): int
    {
        $rows = [];
        foreach (User::where('email', 'like', '%@cohers.test')->with('roles')->get() as $u) {
            $password = Str::password(14, symbols: false);
            $u->forceFill(['password' => $password, 'mfa_enabled' => false, 'mfa_secret' => null])->save();
            $rows[] = [$u->email, $u->roles->pluck('label')->implode(', '), $password];
        }

        SystemSetting::updateOrCreate(['key' => 'require_mfa'], ['value' => '1', 'type' => 'bool', 'group' => 'security']);

        $this->table(['Email', 'Role', 'New password'], $rows);
        $this->warn('Save these now — they are not shown again. MFA enrolment is required at first sign-in.');

        return self::SUCCESS;
    }
}
