<?php

namespace App\Console\Commands;

use App\Models\Escalation;
use App\Models\FocalPointAssignment;
use App\Models\GeographicUnit;
use App\Models\IncidentCase;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\ReportCategory;
use App\Models\Role;
use App\Models\RoutingRule;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Command;

/**
 * Safe to run on every start: sets up whatever an empty database is missing
 * (reference data, then demo data or an administrator) and does nothing on a
 * database that is already in use. Used by the Pterodactyl start.sh so a lost
 * or replaced SQLite file never leaves the dashboard without any login.
 */
class FirstRun extends Command
{
    protected $signature = 'cohers:first-run {--demo : Load demo data when there are no users} {--admin-email= : Create this administrator when there are no users}';

    protected $description = 'Set up reference data and initial accounts if the database is empty';

    /** Models whose dashboard links use a public uuid. */
    private const UUID_MODELS = [
        User::class, Report::class, ReportAttachment::class, Escalation::class, GeographicUnit::class,
        FocalPointAssignment::class, RoutingRule::class, ReportCategory::class, NotificationLog::class, IncidentCase::class,
    ];

    public function handle(): int
    {
        $this->resetMfaFromEnv();
        $this->checkEncryptionKey();

        if (Role::count() === 0) {
            $this->info('Empty database: loading roles, geography, categories and safety rules…');
            $this->call('db:seed', ['--class' => ReferenceDataSeeder::class, '--force' => true]);
        }

        foreach (self::UUID_MODELS as $model) {
            if ($n = $model::fillMissingUuids()) {
                $this->warn("Filled {$n} missing public id(s) in ".class_basename($model).'.');
            }
        }

        if (User::count() > 0) {
            $this->line('Database already set up ('.User::count().' accounts).');

            return self::SUCCESS;
        }

        if ($this->option('demo')) {
            $this->info('Loading demo data (about a minute)…');
            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $this->warn('Demo accounts — SAVE THESE PASSWORDS (shown once):');
            $this->call('cohers:secure-demo');
        }

        $email = $this->option('admin-email') ?: ($this->option('demo') ? null : 'admin@cohers.local');
        if ($email) {
            $this->warn('Administrator — SAVE THIS PASSWORD (shown once):');
            $this->call('cohers:create-admin', ['email' => $email, '--name' => 'Administrator']);
        }

        return self::SUCCESS;
    }

    /**
     * COHERS_RESET_MFA=email[,email] in .env turns two-factor off for those accounts at
     * start-up (for a server where only file access is available). They enrol again on
     * their next sign-in when MFA is required.
     */
    private function resetMfaFromEnv(): void
    {
        $emails = array_filter(array_map('trim', explode(',', (string) env('COHERS_RESET_MFA', ''))));
        foreach ($emails as $email) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->warn("COHERS_RESET_MFA: no account with email {$email}.");

                continue;
            }
            $user->forceFill(['mfa_enabled' => false, 'mfa_secret' => null])->save();
            app(\App\Services\AuditService::class)->record('auth.mfa_reset', $user, null, 'COHERS_RESET_MFA in .env at start-up', null, null, 'system');
            $this->warn("Two-factor reset for {$email}. Remove the COHERS_RESET_MFA line from .env now.");
        }
    }

    /** Warns when the database was encrypted with a different APP_KEY than the one in .env. */
    private function checkEncryptionKey(): void
    {
        $unreadable = function (?string $value): bool {
            try {
                \Illuminate\Support\Facades\Crypt::decryptString($value);

                return false;
            } catch (\Throwable) {
                return true;
            }
        };
        try {
            $mfa = \Illuminate\Support\Facades\DB::table('users')->whereNotNull('mfa_secret')->pluck('mfa_secret')->filter($unreadable)->count();
            $settings = \App\Models\SystemSetting::where('type', 'secret')->get()
                ->filter(fn ($s) => $unreadable($s->value))->pluck('key');
        } catch (\Throwable) {
            return; // tables not there yet on a brand-new database
        }
        if ($mfa || $settings->isNotEmpty()) {
            $this->error('APP_KEY in .env is not the key this database was encrypted with.');
            $this->line("  Unreadable: {$mfa} two-factor secret(s)".($settings->isNotEmpty() ? '; saved settings: '.$settings->implode(', ') : '').'.');
            $this->line('  Fix: put back the APP_KEY line from the .env you used before, then restart.');
            $this->line('  Or:  set COHERS_RESET_MFA=your@email in .env and re-enter those settings in Admin → Configuration.');
        }
    }
}
