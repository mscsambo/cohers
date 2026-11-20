<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\SystemSetting;
use App\Services\Routing\ReportRoutingService;
use App\Services\Verification\ReportVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsCohersFixtures;
use Tests\TestCase;

/** Reporter assignment alerts, Telegram users directory, configuration page. */
class AdminFeaturesTest extends TestCase
{
    use BuildsCohersFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCohers();
    }

    public function test_reporter_is_alerted_on_assignment_reassignment_and_acknowledgement(): void
    {
        $r = $this->submit('Many pigs have fever and stopped eating');
        $first = $r->activeAssignment;

        $alert = NotificationLog::where('recipient_id', $this->reporter->id)->where('message_type', 'reporter.assigned')->first();
        $this->assertNotNull($alert, 'case owner alerted on assignment');
        $this->assertStringContainsString($r->reference_code, $alert->payload['text']);
        $this->assertStringContainsString('Commune Agriculture Officer', $alert->payload['text']);
        $this->assertStringNotContainsString($this->cao->name, $alert->payload['text'], 'officer name hidden by default');

        // Reassignment to another CAO alerts again, as a reassignment.
        $other = $this->officer(\App\Enums\OfficerType::CAO, 'Second CAO', 9300);
        $this->map($other, $this->commune, 'ANIMAL');
        app(ReportRoutingService::class)->reassign($r->fresh(), $other, $this->districtOfficer, 'Workload balancing');
        $alerts = NotificationLog::where('recipient_id', $this->reporter->id)->where('message_type', 'reporter.assigned')->get();
        $this->assertCount(2, $alerts);
        $this->assertStringContainsString('another officer', $alerts->last()->payload['text']);

        app(ReportVerificationService::class)->acknowledge($r->fresh()->activeAssignment, $other);
        $this->assertTrue(NotificationLog::where('recipient_id', $this->reporter->id)->where('message_type', 'reporter.acknowledged')->exists());
        $this->assertNotEquals($first->id, $r->fresh()->activeAssignment->id);
    }

    public function test_reassign_list_only_offers_officers_matching_the_case_sector(): void
    {
        $animal = $this->submit('Many pigs have fever and stopped eating');
        $human = $this->submit('Five children have high fever and rash in the village');
        $routing = app(ReportRoutingService::class);

        $this->assertSame([$this->cao->id], $routing->eligibleOfficers($animal)->pluck('id')->all());
        $this->assertSame([$this->vhsg->id], $routing->eligibleOfficers($human)->pluck('id')->all());
        $this->assertNotContains($this->districtOfficer->id, $routing->eligibleOfficers($animal)->pluck('id'));
    }

    public function test_district_officers_and_admins_get_assignment_alerts_per_preferences(): void
    {
        $this->admin->telegramAccount()->create(['telegram_user_id' => 7999, 'private_chat_id' => 7999, 'linked_at' => now()]);
        $unlinkedAdmin = $this->user('Admin Two', \App\Enums\RoleName::SYSTEM_ADMIN, null, 'admin2@test.local');

        $r = $this->submit('Many pigs have fever and stopped eating');
        $staff = NotificationLog::where('message_type', 'staff.assignment')->get();

        $this->assertEqualsCanonicalizing([$this->districtOfficer->id, $this->admin->id], $staff->pluck('recipient_id')->all());
        $this->assertStringContainsString('CAO One', $staff->first()->payload['text']);
        $this->assertStringContainsString('automatic routing', $staff->first()->payload['text']);
        $this->assertFalse(NotificationLog::where('recipient_id', $unlinkedAdmin->id)->exists(), 'unlinked users create no noise');

        // Opt-out is respected; the reassigning user is not notified about their own action.
        $this->admin->forceFill(['notification_prefs' => ['assignment' => false]])->save();
        $other = $this->officer(\App\Enums\OfficerType::CAO, 'Second CAO', 9300);
        $this->map($other, $this->commune, 'ANIMAL');
        app(ReportRoutingService::class)->reassign($r->fresh(), $other, $this->districtOfficer, 'Balance');
        $this->assertSame(2, NotificationLog::where('message_type', 'staff.assignment')->count(), 'no new staff alerts: admin opted out, actor excluded');
    }

    public function test_staff_can_self_link_telegram_from_dashboard_and_reporter_history_is_merged(): void
    {
        $this->actingAs($this->districtOfficer)->get('/account/telegram')->assertOk()->assertSee('Generate my activation link');
        $this->actingAs($this->districtOfficer)->post('/account/telegram/link')->assertSessionHas('link');
        $link = session('link');
        $token = \Illuminate\Support\Str::after($link, 'start=link_');

        // The officer's phone is already a reporter (8001) with one report.
        $report = $this->submit('Many pigs have fever and stopped eating');
        app(\App\Services\Telegram\TelegramWebhookService::class)->ingest(['update_id' => 42, 'message' => [
            'message_id' => 1, 'from' => ['id' => 8001, 'username' => 'do_phone'], 'chat' => ['id' => 8001, 'type' => 'private'], 'text' => "/start link_{$token}"]], sync: true);

        $this->assertSame(8001, (int) $this->districtOfficer->fresh()->telegramAccount->telegram_user_id);
        $this->assertSame($this->districtOfficer->id, $report->fresh()->reporter_id, 'reports follow the Telegram identity');
        $this->assertSame('suspended', $this->reporter->fresh()->account_status);
        $this->actingAs($this->districtOfficer)->getJson('/account/telegram/status')->assertJson(['linked' => true, 'username' => 'do_phone']);

        $this->actingAs($this->districtOfficer)->post('/account/telegram/preferences', ['events' => ['assignment' => '0']]);
        $prefs = $this->districtOfficer->fresh()->notification_prefs;
        $this->assertFalse($prefs['assignment']);
        $this->assertTrue($prefs['escalation'], 'district officers cannot mute escalations');
    }

    public function test_closure_notifies_reporter_with_outcome_and_note_and_district_hears_about_findings(): void
    {
        $r = $this->submit('Many pigs have fever and stopped eating');
        app(ReportVerificationService::class)->submitFindings($r->activeAssignment, $this->cao,
            \App\Enums\VerificationOutcome::VERIFIED, 'Vet confirmed fever in 3 pigs, samples taken.');

        $staff = NotificationLog::where('message_type', 'staff.findings')->first();
        $this->assertSame($this->districtOfficer->id, $staff->recipient_id);
        $this->assertStringContainsString('approve closure', $staff->payload['text']);

        // Only the scoped district officer may close.
        $this->actingAs($this->admin)->post("/reports/{$r->uuid}/close", ['outcome_summary' => 'Admin attempt to close'])->assertForbidden();
        $this->actingAs($this->districtOfficer)->post("/reports/{$r->uuid}/close", [
            'outcome_summary' => 'Internal: samples sent to NAHPRI, farm quarantined.',
            'reporter_message' => 'A vet treated the sick pigs. Thank you for reporting.',
        ])->assertRedirect();

        $closed = NotificationLog::where('recipient_id', $this->reporter->id)->where('message_type', 'reporter.closed')->first();
        $this->assertStringContainsString('confirmed by the local officer', $closed->payload['text']);
        $this->assertStringContainsString('A vet treated the sick pigs', $closed->payload['text']);
        $this->assertStringNotContainsString('NAHPRI', $closed->payload['text'], 'internal outcome never sent to reporter');

        app(\App\Services\Escalation\EscalationService::class)->reopen($r->fresh(), $this->districtOfficer, 'New cases nearby');
        $this->assertTrue(NotificationLog::where('recipient_id', $this->reporter->id)->where('message_type', 'reporter.reopened')->exists());
    }

    public function test_focal_point_with_district_scope_is_offered_for_reassignment(): void
    {
        // A CAO who is also a district officer for this district, with no router mapping.
        $dual = $this->officer(\App\Enums\OfficerType::CAO, 'Dual CAO + District', 9400);
        $dual->roles()->attach(\App\Models\Role::where('name', 'district_officer')->value('id'));
        $dual->scopes()->attach($this->district->id);

        // A CAO scoped to a different district is not offered.
        $elsewhere = $this->officer(\App\Enums\OfficerType::CAO, 'CAO Elsewhere', 9401);
        $elsewhere->scopes()->attach(\App\Models\GeographicUnit::where('code', '1413')->value('id'));

        $animal = $this->submit('Many pigs have fever and stopped eating');
        $human = $this->submit('Five children have high fever and rash in the village');
        $routing = app(ReportRoutingService::class);

        $ids = $routing->eligibleOfficers($animal)->pluck('id');
        $this->assertContains($dual->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
        $this->assertNotContains($dual->id, $routing->eligibleOfficers($human)->pluck('id'), 'CAO never offered for a human-health case');

        $this->actingAs($this->districtOfficer)->get("/reports/{$animal->uuid}")
            ->assertOk()->assertSee('Dual CAO + District')->assertSee('Focal Point Router');
    }

    public function test_officer_name_can_be_shared_when_enabled(): void
    {
        SystemSetting::create(['key' => 'reporter.share_officer_name', 'value' => '1', 'type' => 'bool']);
        $this->submit('Five children have high fever and rash in the village');
        $text = NotificationLog::where('recipient_id', $this->reporter->id)->where('message_type', 'reporter.assigned')->value('payload')['text'];
        $this->assertStringContainsString($this->vhsg->name, $text);
    }

    public function test_telegram_users_page_is_admin_only(): void
    {
        $this->actingAs($this->admin)->get('/admin/telegram-users')->assertOk()->assertSee('VHSG One')->assertSee('9001');
        $this->actingAs($this->admin)->get('/admin/telegram-users?role=focal_point&q=CAO')->assertOk()->assertSee('CAO One')->assertDontSee('VHSG One');
        $this->actingAs($this->admin)->get('/admin/telegram-users/export')->assertOk();
        $this->actingAs($this->districtOfficer)->get('/admin/telegram-users')->assertForbidden();
    }

    public function test_configuration_saves_encrypted_key_and_model_and_applies_them(): void
    {
        $this->actingAs($this->admin)->get('/admin/configuration')->assertOk();

        $this->actingAs($this->admin)->put('/admin/configuration', [
            'ai_driver' => 'gemini',
            'gemini_api_key' => 'AIzaTEST-KEY-1234',
            'gemini_model' => 'gemini-2.5-pro',
            'gemini_endpoint' => 'https://generativelanguage.googleapis.com/v1beta',
            'gemini_timeout' => 15,
            'min_confidence' => 0.7,
            'telegram_bot_username' => '@my_cohers_bot',
            'link_ttl' => 30,
            'max_photos' => 3,
            'reference_prefix' => 'COH',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $stored = SystemSetting::where('key', 'ai.gemini_api_key')->first();
        $this->assertSame('secret', $stored->type);
        $this->assertStringNotContainsString('AIzaTEST', $stored->value, 'key is encrypted at rest');

        $audit = AuditLog::where('action', 'configuration.updated')->latest('id')->first();
        $this->assertStringNotContainsString('AIzaTEST-KEY', json_encode($audit->new_values), 'audit never stores the secret');

        SystemSetting::applyToConfig();
        $this->assertSame('AIzaTEST-KEY-1234', config('cohers.ai.gemini_api_key'));
        $this->assertSame('gemini-2.5-pro', config('cohers.ai.gemini_model'));
        $this->assertSame('my_cohers_bot', config('cohers.telegram.bot_username'));

        // The saved key and model are what the triage service actually calls.
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"category":"ANIMAL_HEALTH","suggested_priority":"HIGH","confidence":0.9,"needs_manual_review":false}']]]]]])]);
        $this->actingAs($this->admin)->post('/admin/configuration/test-gemini')->assertSessionHas('gemini_test', fn ($r) => $r['valid'] === true);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'models/gemini-2.5-pro:generateContent') && $req->header('x-goog-api-key')[0] === 'AIzaTEST-KEY-1234');

        // Blank secret keeps the value; "clear" removes it.
        $base = ['ai_driver' => 'gemini', 'gemini_model' => 'gemini-2.5-pro', 'gemini_endpoint' => 'https://generativelanguage.googleapis.com/v1beta',
            'gemini_timeout' => 15, 'min_confidence' => 0.7, 'link_ttl' => 30, 'max_photos' => 3, 'reference_prefix' => 'COH'];
        $this->actingAs($this->admin)->put('/admin/configuration', $base + ['gemini_api_key' => '']);
        $this->assertTrue(SystemSetting::where('key', 'ai.gemini_api_key')->exists());
        $this->actingAs($this->admin)->put('/admin/configuration', $base + ['clear' => ['ai.gemini_api_key']]);
        $this->assertFalse(SystemSetting::where('key', 'ai.gemini_api_key')->exists());
    }

    public function test_configuration_rejects_malformed_bot_token(): void
    {
        $this->actingAs($this->admin)->put('/admin/configuration', [
            'ai_driver' => 'keyword', 'gemini_model' => 'gemini-2.5-flash', 'gemini_endpoint' => 'https://x.test', 'gemini_timeout' => 10,
            'min_confidence' => 0.6, 'link_ttl' => 60, 'max_photos' => 3, 'reference_prefix' => 'COH', 'telegram_bot_token' => 'not-a-token',
        ])->assertSessionHasErrors('telegram_bot_token');
        $this->actingAs($this->districtOfficer)->get('/admin/configuration')->assertForbidden();
    }
}
