<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\HealthReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCohersFixtures;
use Tests\TestCase;

class HealthReviewTest extends TestCase
{
    use BuildsCohersFixtures, RefreshDatabase;

    public function test_catchment_referral_preserves_the_primary_case_workflow(): void
    {
        $this->setUpCohers();
        $hc = $this->user('HC reviewer', RoleName::HEALTH_CENTER, null, 'hc@test.local');
        $other = $this->user('Second reviewer', RoleName::HEALTH_CENTER);
        $report = $this->submit('Three chickens died suddenly');
        $status = $report->status;
        $assignments = $report->assignments()->pluck('id')->all();
        $referral = ['reviewer_id' => $hc->id, 'reason' => 'Follow up with exposed household members.'];
        $url = route('district.reports.health-review', $report);
        $this->actingAs($this->districtOfficer)->post($url, $referral)->assertSessionHasErrors('reviewer_id');
        $hc->scopes()->attach($this->commune);
        $other->scopes()->attach($this->commune);
        $this->post($url, $referral)->assertSessionHasNoErrors();
        $this->post($url, $referral)->assertSessionHasNoErrors();
        $ack = route('health.acknowledge', $report);
        $done = route('health.complete', $report);
        $this->actingAs($other->fresh())->get(route('health.index'))->assertDontSee($report->reference_code);
        $this->post($ack)->assertNotFound();
        $this->actingAs($hc->fresh())->get('/')->assertRedirect(route('health.index'));
        $this->get(route('health.index'))->assertOk()->assertSee($referral['reason'])->assertDontSee($report->original_text);
        $this->get(route('district.reports.show', $report))->assertForbidden();
        $this->post($done, ['findings' => 'Follow-up recorded.'])->assertStatus(409);
        $this->post($ack)->assertRedirect(route('health.index'));
        $this->post($done, ['findings' => 'Follow-up recorded.'])->assertRedirect(route('health.index'));
        $this->assertSame(1, HealthReview::count());
        $this->actingAs($this->districtOfficer)->get(route('district.reports.show', $report))->assertOk()->assertSee('Follow-up recorded.');
        $this->assertSame($status, $report->fresh()->status);
        $this->assertSame($assignments, $report->assignments()->pluck('id')->all());
        $hc->scopes()->detach();
        $this->actingAs($hc->fresh())->get(route('health.index'))->assertDontSee($report->reference_code);
        $this->post($ack)->assertForbidden();
    }
}
