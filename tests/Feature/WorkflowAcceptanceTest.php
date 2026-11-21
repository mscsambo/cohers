<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\EscalationTrigger;
use App\Enums\ReportStatus;
use App\Enums\VerificationOutcome;
use App\Jobs\CheckOverdueAssignmentsJob;
use App\Models\AuditLog;
use App\Models\Escalation;
use App\Models\GeographicUnit;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\SystemSetting;
use App\Services\Escalation\EscalationService;
use App\Services\Verification\ReportVerificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsCohersFixtures;
use Tests\TestCase;

/** SRS section 14 minimum acceptance scenarios. */
class WorkflowAcceptanceTest extends TestCase
{
    use BuildsCohersFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCohers();
    }

    /** #2 Report is saved even when Gemini fails; manual review is possible. */
    public function test_report_is_preserved_when_gemini_fails(): void
    {
        config(['cohers.ai.driver' => 'gemini', 'cohers.ai.gemini_api_key' => 'test']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'down'], 503)]);

        $report = $this->submit('20 chickens died suddenly near the market');

        $this->assertSame(ReportStatus::TRIAGE_REVIEW, $report->status);
        $this->assertSame('FAILED', $report->triage_status);
        $this->assertSame('20 chickens died suddenly near the market', $report->original_text);
        $this->assertGreaterThanOrEqual(1, $report->triages()->where('outcome', 'failed')->count());
        $this->assertMatchesRegularExpression('/^COH-\d{4}-\d{6}$/', $report->reference_code);
    }

    public function test_invalid_ai_schema_goes_to_manual_review(): void
    {
        config(['cohers.ai.driver' => 'gemini', 'cohers.ai.gemini_api_key' => 'test']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"category":"MADE_UP","confidence":3}']]]]]])]);

        $report = $this->submit('Something about animals');
        $this->assertSame(ReportStatus::TRIAGE_REVIEW, $report->status);
        $this->assertSame('invalid', $report->latestTriage->outcome);
    }

    /** #3 Human-health → VHSG, animal/environment → CAO. */
    public function test_routing_selects_officer_by_sector(): void
    {
        $human = $this->submit('Five children have high fever and rash in the village');
        $animal = $this->submit('Many pigs have fever and stopped eating, three pigs sick');
        $env = $this->submit('Dead fish in the pond and a strong smell from the water');

        $this->assertSame($this->vhsg->id, $human->activeAssignment->focal_point_user_id);
        $this->assertSame($this->cao->id, $animal->activeAssignment->focal_point_user_id);
        $this->assertSame($this->cao->id, $env->activeAssignment->focal_point_user_id);
        $this->assertSame(ReportStatus::ASSIGNED, $human->status);
        $this->assertNotNull($human->activeAssignment->ack_due_at);
    }

    public function test_accepted_triage_queues_assignment_on_the_database_worker(): void
    {
        config(['queue.default' => 'database']);
        $report = $this->submit('Many pigs have fever and stopped eating');
        $this->assertSame(ReportStatus::SUBMITTED, $report->status);
        $this->artisan('queue:work', ['database', '--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertSame(ReportStatus::ASSIGNED, $report->fresh()->status);
        $this->assertSame($this->cao->id, $report->fresh()->activeAssignment->focal_point_user_id);
        $this->assertSame(1, $report->assignments()->count());
    }

    public function test_delayed_routing_only_assigns_an_eligible_case_once(): void
    {
        $report = $this->submit('Many pigs have fever and stopped eating');
        $originalCount = $report->assignments()->count();
        \App\Jobs\RouteReportJob::dispatchSync($report->id);
        $this->assertSame($originalCount, $report->assignments()->count());

        $report->assignments()->delete();
        $report->update(['status' => ReportStatus::VERIFIED]);
        \App\Jobs\RouteReportJob::dispatchSync($report->id);
        $this->assertSame(ReportStatus::VERIFIED, $report->fresh()->status);
        $this->assertFalse($report->assignments()->exists());

        $report->update(['status' => ReportStatus::TRIAGE_REVIEW, 'triage_status' => 'MANUAL']);
        \App\Jobs\RouteReportJob::dispatchSync($report->id);
        $this->assertFalse($report->assignments()->exists());
        \App\Jobs\RouteReportJob::dispatchSync($report->id, manual: true);
        $this->assertSame($this->cao->id, $report->fresh()->activeAssignment->focal_point_user_id);
    }

    /** #4 Unmatched location or inactive focal point → backup, then district queue. */
    public function test_inactive_primary_falls_back_to_backup_then_district(): void
    {
        $backup = $this->officer(\App\Enums\OfficerType::VHSG, 'Backup VHSG', 9100);
        $this->map($backup, $this->district, 'HUMAN', 'backup');
        $this->vhsg->update(['account_status' => 'suspended']);

        $r = $this->submit('Many villagers have diarrhea and vomiting, ten people');
        $this->assertSame($backup->id, $r->activeAssignment->focal_point_user_id);
        $this->assertSame('backup', $r->activeAssignment->level);

        $otherVillage = GeographicUnit::where('code', 'like', '1408%')->where('type', 'village')->first();
        $r2 = $this->submit('Villagers with cough and fever, six people', $otherVillage);
        $this->assertSame(ReportStatus::TRIAGE_REVIEW, $r2->status);
        $this->assertTrue($r2->escalations()->where('trigger', EscalationTrigger::NO_OFFICER->value)->exists());
    }

    /** #5 Officer cannot view or validate an unassigned or out-of-area case. */
    public function test_officer_cannot_act_on_unassigned_case(): void
    {
        $r = $this->submit('Many pigs have fever and stopped eating');
        $assignment = $r->activeAssignment;

        $this->expectException(AuthorizationException::class);
        app(ReportVerificationService::class)->acknowledge($assignment, $this->vhsg);
    }

    /** #6 Officer acknowledges, submits findings, confirms with audit reason; ack ≠ verified. */
    public function test_officer_acknowledge_and_verify_flow_is_audited_and_idempotent(): void
    {
        $r = $this->submit('Many pigs have fever and stopped eating');
        $a = $r->activeAssignment;
        $svc = app(ReportVerificationService::class);

        $this->assertTrue($svc->acknowledge($a, $this->cao));
        $this->assertFalse($svc->acknowledge($a->fresh(), $this->cao), 'second press must be a no-op');
        $this->assertSame(ReportStatus::ACKNOWLEDGED, $r->fresh()->status);

        $svc->startVerification($a->fresh(), $this->cao);
        $svc->submitFindings($a->fresh(), $this->cao, VerificationOutcome::VERIFIED, 'Visited farm, 3 pigs febrile, samples taken.');

        $r->refresh();
        $this->assertSame(ReportStatus::VERIFIED, $r->status);
        $this->assertSame(AssignmentStatus::COMPLETED, $a->fresh()->assignment_status);
        $this->assertTrue(AuditLog::where('action', 'report.findings_submitted')->where('subject_id', $r->id)->exists());
        $this->assertNull($r->closed_at, 'verified is not closed');
    }

    public function test_findings_require_notes(): void
    {
        $r = $this->submit('Many pigs have fever and stopped eating');
        $this->expectException(\InvalidArgumentException::class);
        app(ReportVerificationService::class)->submitFindings($r->activeAssignment, $this->cao, VerificationOutcome::REJECTED, '');
    }

    /** #7 Duplicates are linked without deleting either original. */
    public function test_duplicate_is_flagged_and_linked_without_deletion(): void
    {
        $first = $this->submit('Twenty chickens died suddenly in our village yesterday');
        $second = $this->submit('Twenty chickens died suddenly in our village yesterday evening');

        $this->assertSame($first->id, $second->possible_duplicate_of_id);

        app(ReportVerificationService::class)->submitFindings($second->activeAssignment, $this->cao,
            VerificationOutcome::DUPLICATE, 'Same flock as earlier report.', false, $first);

        $this->assertSame(2, Report::whereIn('id', [$first->id, $second->id])->count());
        $this->assertSame(ReportStatus::DUPLICATE, $second->fresh()->status);
        $this->assertSame(1, $first->incidents()->count());
        $this->assertEquals($first->incidents()->first()->id, $second->incidents()->first()->id);
    }

    /** #8 Critical alerts district immediately; overdue assignments trigger configured actions. */
    public function test_critical_report_opens_district_escalation_in_parallel_with_routing(): void
    {
        $r = $this->submit('A child died and two people are unconscious after fever, 25 people sick');

        $this->assertSame('CRITICAL', $r->priority->value);
        $this->assertNotNull($r->activeAssignment, 'still routed to focal point');
        $this->assertTrue($r->escalations()->where('trigger', 'CRITICAL')->exists());
        $this->assertTrue(NotificationLog::where('recipient_id', $this->districtOfficer->id)->where('message_type', 'district.escalation')->exists());
    }

    public function test_overdue_high_priority_goes_to_backup_and_district(): void
    {
        $backup = $this->officer(\App\Enums\OfficerType::CAO, 'Backup CAO', 9200);
        $this->map($backup, $this->district, 'ANIMAL', 'backup');

        $r = $this->submit('Many dead ducks, dozens died suddenly overnight');
        $this->assertSame('HIGH', $r->priority->value);

        $this->travel(2)->hours();
        CheckOverdueAssignmentsJob::dispatchSync();

        $r->refresh();
        $this->assertSame($backup->id, $r->activeAssignment->focal_point_user_id);
        $this->assertTrue($r->escalations()->where('trigger', 'OVERDUE')->exists());
        $this->assertSame(1, Escalation::where('report_id', $r->id)->where('trigger', 'OVERDUE')->count(), 'no duplicate escalations');
    }

    public function test_reminders_stop_after_acknowledgement(): void
    {
        SystemSetting::create(['key' => 'ack_targets.LOW', 'value' => '10', 'type' => 'int']);
        $r = $this->submit('A few mosquitoes near the school water tank');
        $r->update(['priority' => 'LOW']);
        $a = $r->activeAssignment;

        $this->travel(30)->minutes();
        CheckOverdueAssignmentsJob::dispatchSync();
        $this->assertSame(1, $a->fresh()->reminder_count);

        app(ReportVerificationService::class)->acknowledge($a->fresh(), $this->cao);
        $this->travel(60)->minutes();
        CheckOverdueAssignmentsJob::dispatchSync();
        $this->assertSame(1, $a->fresh()->reminder_count);
    }

    /** #15 Complete timeline is traceable from submission to closure; closure needs district approval. */
    public function test_full_timeline_to_closure_and_reopen(): void
    {
        $r = $this->submit('Many pigs have fever and stopped eating');
        $svc = app(ReportVerificationService::class);
        $svc->startVerification($r->activeAssignment, $this->cao);
        $svc->submitFindings($r->activeAssignment()->first() ?? $r->assignments()->first(), $this->cao, VerificationOutcome::VERIFIED, 'Confirmed on site visit.');

        $esc = app(EscalationService::class);
        $esc->close($r->fresh(), $this->districtOfficer, 'Outcome documented; vet visit completed.');
        $this->assertSame(ReportStatus::CLOSED, $r->fresh()->status);

        $esc->reopen($r->fresh(), $this->districtOfficer, 'New cases reported nearby');
        $this->assertSame(ReportStatus::VERIFIED, $r->fresh()->status);

        $trail = ReportStatusHistory::where('report_id', $r->id)->orderBy('id')->pluck('to_status')->all();
        $this->assertSame(['SUBMITTED', 'ASSIGNED', 'ACKNOWLEDGED', 'VERIFYING', 'VERIFIED', 'CLOSED', 'VERIFIED'], $trail);
    }

    public function test_cannot_close_with_open_escalation(): void
    {
        $r = $this->submit('Many pigs have fever and stopped eating');
        $svc = app(ReportVerificationService::class);
        $svc->escalate($r->activeAssignment, $this->cao, 'Spreading to neighbouring commune');
        $svc->submitFindings($r->activeAssignment()->first(), $this->cao, VerificationOutcome::VERIFIED, 'Confirmed on site visit.');

        $this->assertNotNull(app(EscalationService::class)->canClose($r->fresh()));
    }

    /** Clarification request round-trip moves NEEDS_INFO back to verification. */
    public function test_information_request_round_trip(): void
    {
        $r = $this->submit('Many pigs have fever and stopped eating');
        $svc = app(ReportVerificationService::class);
        $svc->startVerification($r->activeAssignment, $this->cao);
        $req = $svc->requestInformation($r->activeAssignment()->first(), $this->cao, 'How many households?');
        $this->assertSame(ReportStatus::NEEDS_INFO, $r->fresh()->status);

        $svc->answerInformation($req, $this->reporter, 'Three households');
        $this->assertSame(ReportStatus::VERIFYING, $r->fresh()->status);
        $this->assertSame('ANSWERED', $req->fresh()->status);
    }

    public function test_submission_is_idempotent(): void
    {
        $a = $this->submit('Many pigs have fever and stopped eating', null, 'tg:abc');
        $b = $this->submit('Many pigs have fever and stopped eating', null, 'tg:abc');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Report::count());
    }
}
