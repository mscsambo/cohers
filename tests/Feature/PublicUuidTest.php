<?php

namespace Tests\Feature;

use App\Models\GeographicUnit;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsCohersFixtures;
use Tests\TestCase;

/** Links expose RFC 9562 UUIDv7 public ids, never sequential record numbers. */
class PublicUuidTest extends TestCase
{
    use BuildsCohersFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCohers();
    }

    public function test_new_records_get_a_uuid_v7(): void
    {
        $report = $this->submit('Many pigs have fever and stopped eating');

        foreach ([$report, $this->admin, $this->district, $report->activeAssignment->officer] as $model) {
            $this->assertTrue(Str::isUuid($model->uuid), class_basename($model).' has a uuid');
            $this->assertSame('7', $model->uuid[14], class_basename($model).' uuid is version 7');
        }
    }

    public function test_pages_open_by_uuid_and_not_by_numeric_id(): void
    {
        $report = $this->submit('Many pigs have fever and stopped eating');

        $this->actingAs($this->districtOfficer)->get("/reports/{$report->uuid}")->assertOk();
        $this->actingAs($this->districtOfficer)->get("/reports/{$report->id}")->assertNotFound();
        $this->actingAs($this->admin)->get("/admin/users/{$this->vhsg->uuid}")->assertOk();
        $this->actingAs($this->admin)->get("/admin/users/{$this->vhsg->id}")->assertNotFound();

        // Links rendered on pages use the uuid.
        $this->actingAs($this->admin)->get('/admin/categories')->assertOk()
            ->assertSee('href="'.route('district.reports.show', $report).'"', false);
        // (the drawer link is written as JSON, so its slashes appear escaped as \/)
        $this->actingAs($this->districtOfficer)->get('/reports')
            ->assertSee($report->uuid.'\/drawer', false)
            ->assertDontSee("reports\\/{$report->id}\\/drawer", false)
            ->assertDontSee("reports/{$report->id}/drawer", false);
    }

    public function test_filters_take_uuids(): void
    {
        $report = $this->submit('Many pigs have fever and stopped eating');
        $other = GeographicUnit::where('code', '1402')->first();

        $this->actingAs($this->admin)->get('/reports?district_id='.$this->district->uuid)->assertOk()->assertSee($report->reference_code);
        $this->actingAs($this->admin)->get('/reports?district_id='.$other->uuid)->assertOk()->assertDontSee($report->reference_code);
        $this->actingAs($this->admin)->get('/admin/geography?parent='.$this->district->uuid)->assertOk()->assertSee($this->commune->name_en);
        $this->actingAs($this->admin)->get('/admin/audit?subject=Report&subject_uuid='.$report->uuid)->assertOk();
    }

    public function test_telegram_case_links_use_the_uuid(): void
    {
        $report = $this->submit('A child died and two people are unconscious after fever, 25 people sick');
        $alert = \App\Models\NotificationLog::where('message_type', 'district.escalation')->first();

        $this->assertStringContainsString('/reports/'.$report->uuid, $alert->payload['text']);
        $this->assertTrue(Report::whereNull('uuid')->doesntExist());
    }
}
