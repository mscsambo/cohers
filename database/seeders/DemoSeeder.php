<?php

namespace Database\Seeders;

use App\Enums\EscalationState;
use App\Enums\OfficerType;
use App\Enums\Priority;
use App\Enums\ReportStatus;
use App\Enums\RoleName;
use App\Enums\VerificationOutcome;
use App\Models\FocalPointAssignment;
use App\Models\GeographicUnit;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Escalation\EscalationService;
use App\Services\Reporting\ReportService;
use App\Services\Routing\ReportRoutingService;
use App\Services\Verification\ReportVerificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pilot demo data. Reports are pushed through the real services with a
 * travelling clock so histories, assignments, escalations and audit rows
 * look like six weeks of operation.
 *
 * Dashboard test accounts (local only):
 *   admin@cohers.test              System Administrator
 *   district.baphnum@cohers.test   District Officer — Ba Phnum
 *   district.mesang@cohers.test    District Officer — Me Sang + Prey Veaeng
 * Password for all three: Cohers@2026!
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Cohers@2026!';

    private const STAFFED = ['1401', '1405', '1410'];

    private const NAMES = ['Sok Dara', 'Chan Sophea', 'Keo Vanna', 'Lim Sreymom', 'Heng Visal', 'Mao Sokunthea', 'Pich Rithy', 'Ouk Chenda',
        'Seng Bunthoeun', 'Nhem Sreyneang', 'Ros Chantha', 'Yim Sokha', 'Chea Pisey', 'Long Rotha', 'Touch Malis', 'Sam Bopha',
        'Kim Sovann', 'Phan Sreyleak', 'Meas Sambath', 'Prak Kunthea', 'Chhun Vibol', 'Hor Navy', 'Tep Socheat', 'Vong Sreypov',
        'Nuon Rattana', 'Eng Leakhena', 'Suon Vuthy', 'Kong Channary', 'Ly Seiha', 'Ung Sopheap', 'Hun Kosal', 'Sar Thida',
        'Neang Pheara', 'Bun Ratana', 'Khim Daravy', 'Svay Monyrath', 'Oum Sreypich', 'Nget Panha', 'Ry Kimsan', 'Chum Vichea'];

    private const TEXTS = [
        'ANIMAL_HEALTH' => [
            ['en', '{n} chickens died suddenly in the past two days, others are weak and not eating.'],
            ['en', 'Several pigs have fever and stopped eating, about {n} pigs sick in three households.'],
            ['en', 'Many dead ducks found near the pond, around {n}, died suddenly overnight.'],
            ['km', 'មាន់ {n} ក្បាលងាប់ភ្លាមៗក្នុងរយៈពេលពីរថ្ងៃចុងក្រោយ សត្វផ្សេងទៀតខ្សោយ។'],
            ['km', 'ជ្រូក {n} ក្បាលឈឺ ក្ដៅខ្លួន និងមិនស៊ីចំណី។'],
            ['km', 'គោ {n} ក្បាលមានដំបៅមាត់ និងជើង ហើយដើរខ្ញែក។'],
        ],
        'HUMAN_HEALTH' => [
            ['en', '{n} children in the village have high fever and rash since last week.'],
            ['en', 'Many villagers have diarrhea and vomiting after a ceremony, about {n} people sick.'],
            ['en', '{n} people with cough and fever; one person died yesterday at home.'],
            ['km', 'កុមារ {n} នាក់មានគ្រុនក្ដៅខ្លាំង និងកន្ទួលលើស្បែក។'],
            ['km', 'អ្នកភូមិ {n} នាក់រាគ និងក្អួត បន្ទាប់ពីពិធីបុណ្យ។'],
        ],
        'ENVIRONMENT' => [
            ['en', 'Many dead fish in the river and a strong chemical smell near the bridge.'],
            ['en', 'Pesticide containers dumped next to the well used for drinking water.'],
            ['en', 'Stagnant water and lots of mosquitoes near the school after the flood.'],
            ['km', 'ត្រីងាប់ច្រើននៅក្នុងទន្លេ និងមានក្លិនគីមីខ្លាំង។'],
            ['km', 'ទឹកដក់ និងមូសច្រើននៅជិតសាលារៀន បន្ទាប់ពីទឹកជំនន់។'],
        ],
        'UNKNOWN' => [
            ['en', 'Something strange is happening near the market, please check.'],
            ['km', 'មានរឿងចម្លែកកើតឡើងនៅជិតផ្សារ សូមជួយពិនិត្យ។'],
        ],
    ];

    public function run(): void
    {
        mt_srand(2026);
        // Simulated Telegram + offline triage: demo history must not call real APIs or message fake chat IDs.
        config(['queue.default' => 'sync', 'cache.default' => 'array', 'cohers.ai.driver' => 'keyword', 'cohers.telegram.bot_token' => null]);
        Log::setDefaultDriver('null'); // keep simulated Telegram sends out of laravel.log
        SystemSetting::updateOrCreate(['key' => 'require_mfa'], ['value' => '0', 'type' => 'bool', 'group' => 'security', 'label' => 'Demo: MFA optional']);

        $start = now()->copy();
        Carbon::setTestNow($start->copy()->subDays(50));

        [$admin, $districtOfficers] = $this->staff();
        $this->focalPoints($admin);
        $reporters = $this->reporters();

        $villages = GeographicUnit::where('type', 'village')->with('parent.parent')->get()
            ->groupBy(fn ($v) => $v->parent?->parent?->code);
        $categories = ReportCategory::pluck('id', 'code');

        for ($i = 0; $i < 150; $i++) {
            $ageMinutes = (int) (pow(mt_rand(0, 1000) / 1000, 1.3) * 45 * 1440); // skew toward recent
            $at = $start->copy()->subMinutes($ageMinutes);
            Carbon::setTestNow($at);

            $districtCode = mt_rand(1, 100) <= 85 ? self::STAFFED[array_rand(self::STAFFED)] : ['1402', '1408'][mt_rand(0, 1)];
            $village = $villages[$districtCode]->random();
            $catCode = $this->weighted(['ANIMAL_HEALTH' => 42, 'HUMAN_HEALTH' => 30, 'ENVIRONMENT' => 20, 'UNKNOWN' => 8]);
            [$lang, $tpl] = self::TEXTS[$catCode][array_rand(self::TEXTS[$catCode])];
            $n = mt_rand(1, 100) <= 25 ? mt_rand(12, 80) : mt_rand(2, 9);
            $reporter = $reporters->random();

            DB::transaction(function () use ($reporter, $village, $tpl, $n, $at, $lang, $i, $start, $districtOfficers, $categories) {
            $report = app(ReportService::class)->create($reporter, [
                'geographic_unit_id' => $village->id,
                'original_text' => str_replace('{n}', (string) $n, $tpl),
                'affected_count' => (string) $n,
                'observed_text' => ['Today', 'Yesterday', '2–7 days ago'][mt_rand(0, 2)],
                'observed_at' => $at->copy()->subHours(mt_rand(2, 30)),
                'language' => $lang,
                'channel' => 'telegram',
                'idempotency_key' => 'demo:'.$i,
            ]);

            $this->lifecycle($report->fresh(), $at, $start, $districtOfficers, $categories);
            });
        }

        Carbon::setTestNow();
        $this->command?->info('Demo data seeded. Dashboard password for *@cohers.test accounts: see DemoSeeder::PASSWORD');
    }

    private function staff(): array
    {
        $role = fn (RoleName $r) => Role::where('name', $r->value)->value('id');

        $admin = User::updateOrCreate(['email' => 'admin@cohers.test'], [
            'name' => 'System Administrator', 'password' => self::PASSWORD, 'preferred_language' => 'en', 'account_status' => 'active',
        ]);
        $admin->roles()->syncWithoutDetaching([$role(RoleName::SYSTEM_ADMIN)]);

        $defs = [
            'district.baphnum@cohers.test' => ['Chhay Sopheak (Ba Phnum OD)', ['1401']],
            'district.mesang@cohers.test' => ['Nov Sreyroth (Me Sang / Prey Veng)', ['1405', '1410']],
        ];
        $officers = [];
        foreach ($defs as $email => [$name, $codes]) {
            $u = User::updateOrCreate(['email' => $email], [
                'name' => $name, 'password' => self::PASSWORD, 'preferred_language' => 'en', 'account_status' => 'active',
            ]);
            $u->roles()->syncWithoutDetaching([$role(RoleName::DISTRICT_OFFICER)]);
            $ids = GeographicUnit::whereIn('code', $codes)->pluck('id');
            $u->scopes()->sync($ids->mapWithKeys(fn ($id) => [$id => ['granted_by' => $admin->id]]));
            // No placeholder Telegram: dashboard users connect their own via "My Telegram".
            foreach ($ids as $id) {
                $officers[$id] = $u->fresh(['roles', 'scopes']);
            }
        }

        return [$admin, $officers];
    }

    private function focalPoints(User $admin): void
    {
        $role = Role::where('name', RoleName::FOCAL_POINT->value)->value('id');
        $names = self::NAMES;
        $k = 0;
        $make = function (OfficerType $type, string $area, bool $linked) use (&$k, $names, $role) {
            $name = $names[$k % count($names)];
            $u = User::create([
                'name' => $name.' ('.$type->value.')',
                'phone' => '0'.mt_rand(10, 99).' '.mt_rand(100, 999).' '.mt_rand(100, 999),
                'preferred_language' => 'km',
                'account_status' => 'active',
                'officer_type' => $type,
            ]);
            $u->roles()->attach($role);
            if ($linked) {
                $this->telegram($u, 9000000000 + $k);
            }
            $k++;

            return $u;
        };

        foreach (self::STAFFED as $code) {
            $district = GeographicUnit::where('code', $code)->first();
            foreach ($district->children()->orderBy('code')->limit(4)->get() as $commune) {
                $vhsg = $make(OfficerType::VHSG, $commune->name_en, mt_rand(1, 100) <= 85);
                $cao = $make(OfficerType::CAO, $commune->name_en, mt_rand(1, 100) <= 85);
                $this->map($vhsg, $commune, 'HUMAN', 'primary', $admin);
                $this->map($cao, $commune, 'ANIMAL', 'primary', $admin);
                $this->map($cao, $commune, 'ENVIRONMENT', 'primary', $admin);
            }
            $bv = $make(OfficerType::VHSG, $district->name_en, true);
            $bc = $make(OfficerType::CAO, $district->name_en, true);
            $this->map($bv, $district, 'HUMAN', 'backup', $admin);
            $this->map($bc, $district, 'ANIMAL', 'backup', $admin);
            $this->map($bc, $district, 'ENVIRONMENT', 'backup', $admin);
        }
    }

    private function map(User $u, GeographicUnit $unit, string $sector, string $level, User $admin): void
    {
        FocalPointAssignment::create([
            'user_id' => $u->id, 'geographic_unit_id' => $unit->id, 'sector' => $sector, 'level' => $level,
            'effective_from' => now()->subDays(10)->toDateString(), 'is_active' => true, 'created_by' => $admin->id,
        ]);
    }

    private function reporters()
    {
        $role = Role::where('name', RoleName::REPORTER->value)->value('id');

        return collect(range(1, 30))->map(function ($i) use ($role) {
            $u = User::create([
                'name' => self::NAMES[($i * 7) % count(self::NAMES)],
                'preferred_language' => $i % 4 === 0 ? 'en' : 'km',
                'account_status' => 'active',
                'consented_at' => now(),
            ]);
            $u->roles()->attach($role);
            $this->telegram($u, 8000000000 + $i);

            return $u->load('roles', 'telegramAccount');
        });
    }

    private function telegram(User $u, int $tgId): void
    {
        TelegramAccount::updateOrCreate(['user_id' => $u->id], [
            'telegram_user_id' => $tgId, 'private_chat_id' => $tgId, 'linked_at' => now(), 'status' => 'active',
        ]);
    }

    private function weighted(array $weights): string
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $k => $w) {
            if (($r -= $w) <= 0) {
                return $k;
            }
        }

        return array_key_first($weights);
    }

    private function at(Carbon $base, int $minutes, Carbon $limit): ?Carbon
    {
        $t = $base->copy()->addMinutes($minutes);
        if ($t->gte($limit)) {
            return null;
        }
        Carbon::setTestNow($t);

        return $t;
    }

    /** Simulates officer and district actions after submission, never past "now". */
    private function lifecycle(Report $report, Carbon $at, Carbon $limit, array $districtOfficers, $categories): void
    {
        $verify = app(ReportVerificationService::class);
        $esc = app(EscalationService::class);
        $routing = app(ReportRoutingService::class);
        $reports = app(ReportService::class);
        $district = $districtOfficers[$report->district_id] ?? null;
        $t = $at->copy();

        // Manual triage of uncertain reports by the district officer.
        if ($report->status === ReportStatus::TRIAGE_REVIEW && $district && mt_rand(1, 100) <= 75) {
            if (! $t = $this->at($t, mt_rand(60, 600), $limit)) {
                return;
            }
            $categoryId = $report->category_id ?? $categories[['ANIMAL_HEALTH', 'HUMAN_HEALTH', 'ENVIRONMENT'][mt_rand(0, 2)]];
            $reports->reclassify($report, $categoryId, $report->priority?->value ?? 'MEDIUM', $district, 'Reviewed description and confirmed classification');
            $routing->route($report->fresh());
            $report = $report->fresh();
        }

        $assignment = $report->activeAssignment;
        if (! $assignment) {
            $this->resolveEscalations($report, $t, $limit, $district, $esc);

            return;
        }
        $officer = $assignment->officer()->with('roles')->first();
        $prio = $report->priority ?? Priority::MEDIUM;
        $ackDelay = (int) max(2, $prio->ackMinutes() * (mt_rand(10, 140) / 100));

        if (mt_rand(1, 100) > 92 || ! $t = $this->at($t, $ackDelay, $limit)) {
            return; // left unacknowledged → overdue queue
        }
        $verify->acknowledge($assignment, $officer);

        if (mt_rand(1, 100) > 88 || ! $t = $this->at($t, mt_rand(30, 720), $limit)) {
            return;
        }
        $verify->startVerification($assignment->fresh(), $officer);

        if (mt_rand(1, 100) <= 12 && $t = $this->at($t, mt_rand(20, 240), $limit)) {
            $req = $verify->requestInformation($assignment->fresh(), $officer, 'How many households are affected, and are the animals vaccinated?');
            if (! $t = $this->at($t, mt_rand(30, 600), $limit)) {
                return;
            }
            $verify->answerInformation($req, $report->reporter, 'Three households. I do not think they were vaccinated.');
        }

        if (mt_rand(1, 100) <= 6 && $t) {
            $verify->escalate($assignment->fresh(), $officer, 'Cases appear in neighbouring villages too; needs district response.');
        }

        if (! $t || mt_rand(1, 100) > 80 || ! $t = $this->at($t, mt_rand(180, 2880), $limit)) {
            $this->resolveEscalations($report->fresh(), $t ?? $at, $limit, $district, $esc);

            return;
        }

        $dup = $report->possibleDuplicateOf;
        $roll = mt_rand(1, 100);
        $outcome = match (true) {
            $dup && $roll <= 50 => VerificationOutcome::DUPLICATE,
            $roll <= 68 => VerificationOutcome::VERIFIED,
            default => VerificationOutcome::REJECTED,
        };
        $findings = match ($outcome) {
            VerificationOutcome::VERIFIED => 'Visited the site and confirmed the reported event with household members. Samples/photos recorded.',
            VerificationOutcome::REJECTED => 'Visited the site; event could not be substantiated. Animals/people seen were healthy.',
            VerificationOutcome::DUPLICATE => 'Same event as an earlier report from this village.',
        };
        $verify->submitFindings($assignment->fresh(), $officer, $outcome, $findings, mt_rand(1, 100) <= 12,
            $outcome === VerificationOutcome::DUPLICATE ? $dup : null);

        $report = $report->fresh();
        $this->resolveEscalations($report, $t, $limit, $district, $esc);

        if ($district && mt_rand(1, 100) <= 65 && $t = $this->at($t, mt_rand(600, 4320), $limit)) {
            $report = $report->fresh();
            if (! $esc->canClose($report)) {
                $esc->close($report, $district, 'Outcome documented by field officer; no further response required.', 'Community informed; routine surveillance continues.');
            }
        }
    }

    private function resolveEscalations(Report $report, ?Carbon $t, Carbon $limit, ?User $district, EscalationService $esc): void
    {
        if (! $district || ! $t) {
            return;
        }
        foreach ($report->escalations()->where('escalation_state', '!=', EscalationState::RESOLVED->value)->get() as $e) {
            if (mt_rand(1, 100) > 70 || ! $t = $this->at($t, mt_rand(30, 900), $limit)) {
                return;
            }
            $esc->startReview($e, $district);
            if (mt_rand(1, 100) <= 80 && $t = $this->at($t, mt_rand(30, 900), $limit)) {
                $esc->resolve($e->fresh(), $district, ['follow_up_directed', 'referred_onward', 'no_further_action'][mt_rand(0, 2)],
                    'Reviewed timeline and officer notes; decision recorded.');
            }
        }
    }
}
