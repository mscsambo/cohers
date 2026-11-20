<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FocalPointAssignment;
use App\Models\GeographicUnit;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Scenarios #9, #10, #14 and a render check of every dashboard page with demo data. */
class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cohers.ai.driver' => 'keyword']);
        $this->seed([ReferenceDataSeeder::class, DemoSeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cohers.test')->first();
    }

    private function district(): User
    {
        return User::where('email', 'district.baphnum@cohers.test')->first();
    }

    public function test_every_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin());
        $report = Report::where('status', 'CLOSED')->first() ?? Report::first();
        $escalated = Report::whereHas('escalations')->first();

        foreach ([
            '/dashboard', '/reports', '/reports?status=TRIAGE_REVIEW&overdue=1', "/reports/{$report->uuid}",
            "/reports/{$report->uuid}/drawer", "/reports/{$escalated->uuid}", '/escalations', '/escalations?state=all',
            '/workload', '/map', '/map/data', '/analytics', '/closures',
            '/admin', '/admin/users', '/admin/users/create', '/admin/users/'.$this->district()->uuid, '/admin/geography',
            '/admin/geography?parent='.GeographicUnit::where('code', '1401')->value('uuid'), '/admin/routing', '/admin/categories',
            '/admin/jobs', '/admin/jobs?status=all', '/admin/audit', '/admin/settings',
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/reports/export')->assertOk();
    }

    public function test_every_district_page_renders_for_district_officer(): void
    {
        $this->actingAs($this->district());
        $report = Report::whereIn('district_id', $this->district()->districtIds())->first();
        foreach (['/reports', "/reports/{$report->uuid}", '/escalations', '/workload',
            '/map', '/map/data', '/analytics', '/closures'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();

        // The dashboard lives at /dashboard; old /district links move there, and sign-in lands there.
        $this->get('/dashboard')->assertOk();
        $this->get('/district')->assertRedirect('/dashboard');
        $this->get('/district/closures?x=1')->assertRedirect('/closures?x=1');
        $this->assertSame(route('district.overview'), \App\Http\Controllers\Auth\LoginController::home($this->district()));
    }

    /** #9 District officer sees only assigned districts. */
    public function test_district_officer_is_scoped(): void
    {
        $officer = $this->district();
        $outside = Report::whereNotIn('district_id', $officer->districtIds())->first();
        $inside = Report::whereIn('district_id', $officer->districtIds())->first();

        $this->actingAs($officer)->get("/reports/{$outside->uuid}")->assertForbidden();
        $this->actingAs($officer)->get("/reports/{$inside->uuid}")->assertOk();
        $this->assertSame(0, Report::visibleTo($officer)->whereNotIn('district_id', $officer->districtIds())->where('reporter_id', '!=', $officer->id)->count());

        $json = $this->actingAs($officer)->get('/map/data')->json();
        $this->assertNotEmpty($json);
    }

    /** Focal-point areas are assigned on the account page and become router mappings. */
    public function test_account_page_assigns_router_areas(): void
    {
        $this->actingAs($this->admin());
        $cao = User::where('officer_type', 'CAO')->first();
        $before = FocalPointAssignment::where('user_id', $cao->id)->where('is_active', true)->get();
        $this->get("/admin/users/{$cao->uuid}")->assertOk()->assertSee('Assigned location');

        $district = GeographicUnit::where('type', 'district')->first();
        [$c1, $c2] = GeographicUnit::where('parent_id', $district->id)->where('type', 'commune')->take(2)->get()->all();
        $base = ['name' => $cao->name, 'email' => 'cao.areas@test.local', 'preferred_language' => 'en', 'account_status' => 'active',
            'role' => 'focal_point', 'officer_type' => 'CAO', 'areas_present' => 1];

        // A CAO cannot take the HUMAN sector.
        $this->put("/admin/users/{$cao->uuid}", $base + ['areas' => [['unit' => $c1->id, 'level' => 'primary', 'sectors' => ['HUMAN']]]])
            ->assertSessionHasErrors('areas');

        $this->put("/admin/users/{$cao->uuid}", $base + ['areas' => [
            ['unit' => $c1->id, 'level' => 'primary', 'sectors' => ['ANIMAL', 'ENVIRONMENT']],
            ['unit' => $district->id, 'level' => 'backup', 'sectors' => ['ANIMAL']],
        ]])->assertRedirect();

        $active = FocalPointAssignment::where('user_id', $cao->id)->where('is_active', true)->get();
        $this->assertEqualsCanonicalizing(
            ["{$c1->id}:ANIMAL:primary", "{$c1->id}:ENVIRONMENT:primary", "{$district->id}:ANIMAL:backup"],
            $active->map(fn ($m) => "{$m->geographic_unit_id}:{$m->sector->value}:{$m->level}")->all()
        );
        // Earlier mappings that were dropped are deactivated, not deleted.
        foreach ($before as $old) {
            $this->assertDatabaseHas('focal_point_assignments', ['id' => $old->id]);
        }
        $this->assertTrue(AuditLog::where('action', 'routing.mapping_created')->exists());

        // Changing level replaces the mapping; removing a row deactivates it.
        $this->put("/admin/users/{$cao->uuid}", $base + ['areas' => [['unit' => $c2->id, 'level' => 'backup', 'sectors' => ['ANIMAL']]]])->assertRedirect();
        $this->assertSame(["{$c2->id}:ANIMAL:backup"], FocalPointAssignment::where('user_id', $cao->id)->where('is_active', true)->get()
            ->map(fn ($m) => "{$m->geographic_unit_id}:{$m->sector->value}:{$m->level}")->all());

        // The router page is now the coverage view and links to the officer.
        $this->get('/admin/routing')->assertOk()->assertSee('Assign an officer')->assertSee(route('admin.users.edit', $cao), false);
    }

    /** Rows written without a uuid (e.g. by a worker running older code) must not break pages. */
    public function test_rows_missing_a_uuid_heal_instead_of_breaking_pages(): void
    {
        $log = \App\Models\NotificationLog::latest('id')->first();
        \Illuminate\Support\Facades\DB::table('notification_logs')->where('id', $log->id)
            ->update(['uuid' => null, 'delivery_status' => 'FAILED', 'error' => 'test failure']);

        $this->actingAs($this->admin())->get('/admin/jobs')->assertOk()->assertSee('test failure');
        $this->assertNotNull($log->fresh()->uuid);

        \Illuminate\Support\Facades\DB::table('notification_logs')->where('id', $log->id)->update(['uuid' => null]);
        $this->artisan('cohers:first-run')->assertSuccessful();
        $this->assertNotNull($log->fresh()->uuid);
    }

    public function test_admin_cannot_close_cases(): void
    {
        $r = Report::where('status', 'VERIFIED')->whereNotIn('escalation_state', ['OPEN', 'IN_REVIEW'])->first();
        $this->actingAs($this->admin())->post("/reports/{$r->uuid}/close", ['outcome_summary' => 'Trying to close as admin'])->assertForbidden();
    }

    /** #10 Admin changes to routing and role assignments are audited. */
    public function test_admin_routing_and_role_changes_are_audited(): void
    {
        $this->actingAs($this->admin());
        $mapping = FocalPointAssignment::where('is_active', true)->first();
        $this->post("/admin/routing/mappings/{$mapping->uuid}/deactivate", ['reason' => 'Officer transferred'])->assertRedirect();
        $this->assertTrue(AuditLog::where('action', 'routing.mapping_deactivated')->where('subject_id', $mapping->id)->exists());

        $u = $this->district();
        $this->put("/admin/users/{$u->uuid}", [
            'name' => $u->name, 'email' => $u->email, 'preferred_language' => 'en', 'account_status' => 'suspended',
            'role' => 'district_officer', 'scopes' => $u->districtIds(), 'reason' => 'On leave',
        ])->assertRedirect();
        $log = AuditLog::where('action', 'user.updated')->where('subject_id', $u->id)->latest('id')->first();
        $this->assertSame('active', $log->old_values['account_status']);
        $this->assertSame('suspended', $log->new_values['account_status']);
    }

    public function test_account_keeps_exactly_one_role(): void
    {
        $this->actingAs($this->admin());
        $u = User::whereNotNull('officer_type')->first();
        $district = GeographicUnit::where('type', 'district')->value('id');
        $u->roles()->syncWithoutDetaching(\App\Models\Role::where('name', 'district_officer')->pluck('id'));
        $u->scopes()->syncWithoutDetaching([$district]);
        $this->get("/admin/users/{$u->uuid}")->assertOk()->assertSee('Saving keeps only the role selected above.');

        $base = ['name' => $u->name, 'email' => 'one.role@test.local', 'preferred_language' => 'en', 'account_status' => 'active'];
        $this->put("/admin/users/{$u->uuid}", $base + ['role' => 'focal_point', 'officer_type' => ''])->assertSessionHasErrors('officer_type');
        $this->put("/admin/users/{$u->uuid}", $base + ['role' => 'focal_point', 'officer_type' => 'CAO', 'scopes' => [$district]])->assertRedirect();

        $u->refresh();
        $this->assertSame(['focal_point'], $u->roles->pluck('name')->all());
        $this->assertSame([], $u->districtIds());

        $this->put("/admin/users/{$u->uuid}", $base + ['role' => 'reporter', 'officer_type' => 'CAO'])->assertRedirect();
        $u->refresh();
        $this->assertSame(['reporter'], $u->roles->pluck('name')->all());
        $this->assertNull($u->officer_type);

        // An admin cannot demote themselves.
        $admin = $this->admin();
        $this->put("/admin/users/{$admin->uuid}", ['name' => $admin->name, 'email' => $admin->email, 'preferred_language' => 'en', 'account_status' => 'active', 'role' => 'reporter'])
            ->assertSessionHasErrors('role');
    }

    /** #14 Exports are scoped and logged. */
    public function test_export_is_scoped_and_logged(): void
    {
        $officer = $this->district();
        $csv = $this->actingAs($officer)->get('/reports/export')->streamedContent();
        $codes = Report::whereNotIn('district_id', $officer->districtIds())->pluck('reference_code');
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $csv);
        }
        $this->assertTrue(AuditLog::where('action', 'reports.exported')->where('actor_id', $officer->id)->exists());
    }

    public function test_three_roles_can_sign_in_and_reporters_cannot(): void
    {
        \App\Models\SystemSetting::updateOrCreate(['key' => 'require_mfa'], ['value' => '0', 'type' => 'bool']);
        $cao = User::whereNotNull('officer_type')->first();
        $cao->forceFill(['email' => 'cao@test.local', 'password' => 'right-password-1'])->save();

        // Field officer: lands on My field cases, and cannot open district or admin pages.
        $this->post('/login', ['email' => 'cao@test.local', 'password' => 'right-password-1'])->assertRedirect('/field');
        $this->get('/field')->assertOk()->assertSee('My Field Cases');
        $this->get('/reports')->assertForbidden();
        $this->get('/admin')->assertForbidden();
        $this->post('/logout');

        // A wrong password still gets the generic message.
        $this->post('/login', ['email' => 'cao@test.local', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'These credentials do not match an active dashboard account.']);

        // Reporter-only account: told it has no dashboard access.
        $reporter = User::whereHas('roles', fn ($q) => $q->where('name', 'reporter'))->whereDoesntHave('roles', fn ($q) => $q->where('name', '!=', 'reporter'))->first();
        $reporter->forceFill(['email' => 'rep@test.local', 'password' => 'right-password-1'])->save();
        $this->post('/login', ['email' => 'rep@test.local', 'password' => 'right-password-1'])
            ->assertSessionHasErrors(['email' => 'This account has no dashboard access. Dashboard sign-in needs the System Administrator, District Officer or Local Focal Point (VHSG / CAO) role.']);
        $this->assertGuest();
    }

    public function test_login_and_lockout(): void
    {
        $this->post('/login', ['email' => 'admin@cohers.test', 'password' => DemoSeeder::PASSWORD])->assertRedirect('/admin');
        auth()->logout();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'district.mesang@cohers.test', 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => 'district.mesang@cohers.test', 'password' => DemoSeeder::PASSWORD])->assertSessionHasErrors('email');
        $this->assertTrue(AuditLog::where('action', 'auth.lockout')->exists());
    }
}
