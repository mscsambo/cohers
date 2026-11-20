<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'cohers:create-admin {email} {--name=System Administrator} {--password= : Leave empty to generate one}';

    protected $description = 'Create (or reset) a system administrator dashboard account';

    public function handle(AuditService $audit): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email.');

            return self::FAILURE;
        }
        $password = $this->option('password') ?: Str::password(16, symbols: false);

        $user = User::updateOrCreate(['email' => $email], [
            'name' => $this->option('name'),
            'password' => $password,
            'account_status' => 'active',
            'preferred_language' => 'en',
        ]);
        $user->roles()->syncWithoutDetaching([Role::where('name', RoleName::SYSTEM_ADMIN->value)->value('id')]);
        $audit->record('user.admin_provisioned', $user, null, 'Created from console', null, ['email' => $email], 'console');

        $this->info('Administrator ready.');
        $this->table(['Email', 'Password'], [[$email, $password]]);
        $this->line('Two-factor sign-in will be requested at first login when MFA is required.');

        return self::SUCCESS;
    }
}
