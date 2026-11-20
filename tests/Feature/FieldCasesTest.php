<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Models\NotificationLog;
use App\Models\ReportVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCohersFixtures;
use Tests\TestCase;

/** VHSG / CAO officers working their cases in the web dashboard. */
class FieldCasesTest extends TestCase
{
    use BuildsCohersFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCohers();
    }

    public function test_officer_sees_only_own_cases(): void
    {
        $animal = $this->submit('Many pigs have fever and stopped eating');      // → CAO One
        $human = $this->submit('Five children have high fever and rash in the village'); // → VHSG One

        $this->actingAs($this->cao)->get('/field')->assertOk()->assertSee($animal->reference_code)->assertDontSee($human->reference_code);
        $this->actingAs($this->cao)->get("/field/cases/{$animal->uuid}")->assertOk()->assertSee('Submit findings');
        $this->actingAs($this->cao)->get("/field/cases/{$human->uuid}")->assertForbidden();
        $this->actingAs($this->cao)->post("/field/cases/{$human->uuid}/acknowledge")->assertForbidden();
    }

    public function test_full_web_flow_matches_telegram_actions(): void
    {
        $r = $this->submit('Many pigs have fever and stopped eating');
        $base = "/field/cases/{$r->uuid}";

        $this->actingAs($this->cao)->post("{$base}/acknowledge")->assertRedirect($base);
        $this->assertSame(ReportStatus::ACKNOWLEDGED, $r->fresh()->status);
        $this->assertTrue(NotificationLog::where('recipient_id', $this->reporter->id)->where('message_type', 'reporter.acknowledged')->exists());

        $this->actingAs($this->cao)->post("{$base}/verify")->assertRedirect($base);
        $this->assertSame(ReportStatus::VERIFYING, $r->fresh()->status);

        $this->actingAs($this->cao)->post("{$base}/request-info", ['question' => 'How many households?'])->assertRedirect($base);
        $this->assertSame(ReportStatus::NEEDS_INFO, $r->fresh()->status);
        $this->assertTrue(NotificationLog::where('recipient_id', $this->reporter->id)->where('message_type', 'reporter.info_request')->exists());

        // Findings need notes.
        $this->actingAs($this->cao)->post("{$base}/findings", ['outcome' => 'VERIFIED', 'findings' => ''])->assertSessionHasErrors('findings');

        $this->actingAs($this->cao)->post("{$base}/findings", ['outcome' => 'VERIFIED', 'findings' => 'Visited the farm; 3 pigs febrile.'])->assertRedirect($base);
        $this->assertSame(ReportStatus::VERIFIED, $r->fresh()->status);
        $this->assertSame('web', ReportVerification::where('report_id', $r->id)->value('channel'));
        $this->assertTrue(NotificationLog::where('recipient_id', $this->districtOfficer->id)->where('message_type', 'staff.findings')->exists());

        // Assignment finished: the page stays readable but offers no actions.
        $this->actingAs($this->cao)->get($base)->assertOk()->assertSee('no longer actively assigned');
        $this->actingAs($this->cao)->post("{$base}/escalate", ['reason' => 'Too late now'])->assertForbidden();
    }

    public function test_duplicate_needs_an_existing_code_in_the_same_district(): void
    {
        $first = $this->submit('Twenty chickens died suddenly in our village yesterday');
        $second = $this->submit('Our pigs stopped eating and have a fever since Monday');

        $this->actingAs($this->cao)->post("/field/cases/{$second->uuid}/findings", ['outcome' => 'DUPLICATE', 'findings' => 'Same event as before.', 'duplicate_code' => 'COH-2026-999999'])
            ->assertSessionHasErrors('duplicate_code');
        $this->actingAs($this->cao)->post("/field/cases/{$second->uuid}/findings", ['outcome' => 'DUPLICATE', 'findings' => 'Same event as before.', 'duplicate_code' => $first->reference_code])
            ->assertRedirect();
        $this->assertSame(ReportStatus::DUPLICATE, $second->fresh()->status);
    }
}
