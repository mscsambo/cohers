<?php

namespace Tests\Feature\Concerns;

use App\Enums\OfficerType;
use App\Enums\RoleName;
use App\Models\FocalPointAssignment;
use App\Models\GeographicUnit;
use App\Models\Report;
use App\Models\Role;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Reporting\ReportService;
use Database\Seeders\ReferenceDataSeeder;

trait BuildsCohersFixtures
{
    protected GeographicUnit $district;

    protected GeographicUnit $commune;

    protected GeographicUnit $village;

    protected User $vhsg;

    protected User $cao;

    protected User $reporter;

    protected User $districtOfficer;

    protected User $admin;

    protected function setUpCohers(): void
    {
        config(['cohers.ai.driver' => 'keyword', 'cohers.telegram.bot_token' => null]);
        $this->seed(ReferenceDataSeeder::class);

        $this->district = GeographicUnit::where('code', '1401')->first();       // Ba Phnum
        $this->commune = $this->district->children()->orderBy('code')->first();
        $this->village = $this->commune->children()->orderBy('code')->first();

        $this->vhsg = $this->officer(OfficerType::VHSG, 'VHSG One', 9001);
        $this->cao = $this->officer(OfficerType::CAO, 'CAO One', 9002);
        $this->map($this->vhsg, $this->commune, 'HUMAN');
        $this->map($this->cao, $this->commune, 'ANIMAL');
        $this->map($this->cao, $this->commune, 'ENVIRONMENT');

        $this->reporter = $this->user('Reporter', RoleName::REPORTER, 8001);
        $this->districtOfficer = $this->user('District Ba Phnum', RoleName::DISTRICT_OFFICER, 7001, 'do@test.local');
        $this->districtOfficer->scopes()->attach($this->district->id);
        $this->districtOfficer->load('scopes');
        $this->admin = $this->user('Admin', RoleName::SYSTEM_ADMIN, null, 'admin@test.local');
    }

    protected function user(string $name, RoleName $role, ?int $tg = null, ?string $email = null): User
    {
        $u = User::create(['name' => $name, 'email' => $email, 'password' => $email ? 'secret-password-1' : null, 'account_status' => 'active', 'preferred_language' => 'en']);
        $u->roles()->attach(Role::where('name', $role->value)->value('id'));
        if ($tg) {
            TelegramAccount::create(['user_id' => $u->id, 'telegram_user_id' => $tg, 'private_chat_id' => $tg, 'linked_at' => now()]);
        }

        return $u->fresh(['roles', 'scopes', 'telegramAccount']);
    }

    protected function officer(OfficerType $type, string $name, int $tg): User
    {
        $u = $this->user($name, RoleName::FOCAL_POINT, $tg);
        $u->update(['officer_type' => $type]);

        return $u->fresh(['roles', 'telegramAccount']);
    }

    protected function map(User $u, GeographicUnit $unit, string $sector, string $level = 'primary'): FocalPointAssignment
    {
        return FocalPointAssignment::create([
            'user_id' => $u->id, 'geographic_unit_id' => $unit->id, 'sector' => $sector, 'level' => $level,
            'effective_from' => now()->subDay()->toDateString(), 'is_active' => true,
        ]);
    }

    protected function submit(string $text, ?GeographicUnit $village = null, ?string $key = null): Report
    {
        return app(ReportService::class)->create($this->reporter, [
            'geographic_unit_id' => ($village ?? $this->village)->id,
            'original_text' => $text,
            'idempotency_key' => $key,
        ])->fresh();
    }
}
