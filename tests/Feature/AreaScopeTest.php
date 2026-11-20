<?php

namespace Tests\Feature;

use App\Models\GeographicUnit;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** District-officer access by province, district or commune. */
class AreaScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cohers.ai.driver' => 'keyword']);
        $this->seed([ReferenceDataSeeder::class, DemoSeeder::class]);
    }

    private function officer(array $unitIds): User
    {
        $u = User::create(['name' => 'Area Officer', 'email' => 'area.'.uniqid().'@test.local', 'preferred_language' => 'en', 'account_status' => 'active']);
        $u->roles()->attach(Role::where('name', 'district_officer')->value('id'));
        $u->scopes()->attach($unitIds);

        return $u->fresh(['roles', 'scopes']);
    }

    public function test_province_scope_covers_every_district_in_it(): void
    {
        $report = Report::whereNotNull('district_id')->first();
        $province = GeographicUnit::find($report->district_id)->parent;
        $districts = GeographicUnit::where('parent_id', $province->id)->pluck('id')->all();
        $officer = $this->officer([$province->id]);

        $this->assertEqualsCanonicalizing($districts, $officer->districtIds());
        $this->assertSame(Report::whereIn('district_id', $districts)->count(), Report::visibleTo($officer)->count());
        $this->actingAs($officer)->get("/reports/{$report->uuid}")->assertOk();
        $this->assertTrue(app(NotificationService::class)->districtOfficers($report)->contains('id', $officer->id));
    }

    public function test_commune_scope_sees_only_that_commune(): void
    {
        $inside = Report::whereNotNull('commune_id')->first();
        $outside = Report::where('district_id', $inside->district_id)->where('commune_id', '!=', $inside->commune_id)->first();
        $this->assertNotNull($outside, 'demo data needs two communes with reports in one district');
        $officer = $this->officer([$inside->commune_id]);

        $this->assertSame([$inside->district_id], $officer->districtIds());
        $visible = Report::visibleTo($officer)->pluck('commune_id')->unique()->values()->all();
        $this->assertSame([$inside->commune_id], $visible);

        $this->actingAs($officer)->get("/reports/{$inside->uuid}")->assertOk();
        $this->actingAs($officer)->get("/reports/{$outside->uuid}")->assertForbidden();
        $this->actingAs($officer)->get('/reports')->assertOk()->assertSee($inside->reference_code)->assertDontSee($outside->reference_code);

        $notify = app(NotificationService::class);
        $this->assertTrue($notify->districtOfficers($inside)->contains('id', $officer->id));
        $this->assertFalse($notify->districtOfficers($outside)->contains('id', $officer->id));
    }

    public function test_new_accounts_get_their_location_when_created(): void
    {
        $this->actingAs(User::where('email', 'admin@cohers.test')->first());
        $this->get('/admin/users/create')->assertOk()->assertSee('Choose a role')->assertSee('Assigned location');

        $commune = GeographicUnit::where('type', 'commune')->first();
        $this->post('/admin/users', ['name' => 'New DO', 'email' => 'new.do@test.local', 'preferred_language' => 'en', 'account_status' => 'active',
            'role' => 'district_officer', 'scopes' => [$commune->id], 'password' => 'TestPass-12345'])->assertRedirect();
        $this->assertSame([$commune->id], User::where('email', 'new.do@test.local')->first()->scopes->pluck('id')->all());

        $this->post('/admin/users', ['name' => 'New CAO', 'preferred_language' => 'km', 'account_status' => 'active',
            'role' => 'focal_point', 'officer_type' => 'CAO', 'areas_present' => 1,
            'areas' => [['unit' => $commune->id, 'level' => 'primary', 'sectors' => ['ANIMAL']]]])->assertRedirect();
        $cao = User::where('name', 'New CAO')->first();
        $this->assertSame(1, $cao->focalPointAssignments()->where('is_active', true)->where('geographic_unit_id', $commune->id)->count());

        // The form sends role and type as one choice.
        $this->post('/admin/users', ['name' => 'New VHSG', 'preferred_language' => 'km', 'account_status' => 'active',
            'role' => 'focal_point:VHSG', 'areas_present' => 1,
            'areas' => [['unit' => $commune->id, 'level' => 'primary', 'sectors' => ['HUMAN']]]])->assertRedirect();
        $vhsg = User::where('name', 'New VHSG')->first();
        $this->assertTrue($vhsg->isFocalPoint());
        $this->assertSame('VHSG', $vhsg->officer_type->value);
        $this->get("/admin/users/{$vhsg->uuid}")->assertOk()->assertSee('value="focal_point:VHSG" selected', false);
    }

    public function test_account_form_saves_province_district_and_commune_scopes(): void
    {
        $admin = User::where('email', 'admin@cohers.test')->first();
        $province = GeographicUnit::where('type', 'province')->first();
        $district = GeographicUnit::where('parent_id', $province->id)->first();
        $commune = GeographicUnit::where('parent_id', $district->id)->first();
        $otherDistrict = GeographicUnit::where('parent_id', $province->id)->where('id', '!=', $district->id)->first();
        $otherCommune = GeographicUnit::where('parent_id', $otherDistrict->id)->first();
        $u = $this->officer([$district->id]);

        $base = ['name' => $u->name, 'email' => $u->email, 'preferred_language' => 'en', 'account_status' => 'active', 'role' => 'district_officer'];

        // A commune inside a selected district adds nothing; a commune elsewhere is kept.
        $this->actingAs($admin)->put("/admin/users/{$u->uuid}", $base + ['scopes' => [$district->id, $commune->id, $otherCommune->id]])->assertRedirect();
        $this->assertEqualsCanonicalizing([$district->id, $otherCommune->id], $u->fresh()->scopes->pluck('id')->all());

        // The province covers everything in it, so only the province is kept.
        $this->put("/admin/users/{$u->uuid}", $base + ['scopes' => [$province->id, $district->id, $commune->id, $otherCommune->id]])->assertRedirect();
        $this->assertSame([$province->id], $u->fresh()->scopes->pluck('id')->all());

        // A village is not a valid area.
        $village = GeographicUnit::where('type', 'village')->first();
        $this->put("/admin/users/{$u->uuid}", $base + ['scopes' => [$village->id]])->assertSessionHasErrors('scopes');

        $this->get("/admin/users/{$u->uuid}")->assertOk()->assertSee('Whole province', false);
    }
}
