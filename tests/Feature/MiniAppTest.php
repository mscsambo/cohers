<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Models\NotificationLog;
use App\Models\ReportUpdate;
use App\Services\Telegram\TelegramWebAppAuth;
use App\Services\Verification\ReportVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCohersFixtures;
use Tests\TestCase;

/** Telegram Mini App: password-less sign-in and the reporter's "My reports". */
class MiniAppTest extends TestCase
{
    use BuildsCohersFixtures, RefreshDatabase;

    private const TOKEN = '123456:TEST-BOT-TOKEN';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCohers();
    }

    private function initData(int $telegramId, ?int $authDate = null, string $token = self::TOKEN): string
    {
        return TelegramWebAppAuth::sign([
            'auth_date' => (string) ($authDate ?? time()),
            'query_id' => 'AAH-test',
            'user' => json_encode(['id' => $telegramId, 'first_name' => 'Test', 'language_code' => 'km']),
        ], $token);
    }

    /** Signs in through /tg/auth exactly as the Mini App page does. */
    private function openMiniApp(int $telegramId)
    {
        config(['cohers.telegram.bot_token' => self::TOKEN]);
        $res = $this->postJson('/tg/auth', ['init_data' => $this->initData($telegramId)]);
        config(['cohers.telegram.bot_token' => null]);   // keep Telegram simulated for the rest of the test

        return $res;
    }

    public function test_signature_is_checked(): void
    {
        $auth = new TelegramWebAppAuth;
        $this->assertSame(8001, $auth->verify($this->initData(8001), self::TOKEN)['id']);

        $this->assertNull($auth->verify($this->initData(8001, token: 'other:token'), self::TOKEN), 'signed with another bot');
        $this->assertNull($auth->verify(str_replace('8001', '9002', $this->initData(8001)), self::TOKEN), 'user id changed');
        $this->assertNull($auth->verify($this->initData(8001, time() - 2 * 86400), self::TOKEN), 'too old');
        $this->assertNull($auth->verify('user=%7B%22id%22%3A8001%7D', self::TOKEN), 'no hash');
    }

    public function test_reporter_signs_in_with_telegram_and_sees_only_own_reports(): void
    {
        $mine = $this->submit('Many pigs have fever and stopped eating');
        $other = $this->user('Someone Else', \App\Enums\RoleName::REPORTER, 8002);
        $theirs = app(\App\Services\Reporting\ReportService::class)->create($other, ['geographic_unit_id' => $this->village->id, 'original_text' => 'Dead fish in the pond today']);

        $this->get('/tg')->assertOk()->assertSee('tg\/auth', false);
        $this->openMiniApp(8001)->assertOk()->assertJson(['redirect' => route('my.index')]);
        $this->assertAuthenticatedAs($this->reporter);

        $this->get('/my')->assertOk()->assertSee($mine->reference_code)->assertDontSee($theirs->reference_code);
        $this->get("/my/{$mine->uuid}")->assertOk()->assertSee(__('bot.status.assigned', [], 'en'))
            ->assertSee(__('bot.miniapp.back_to_cases', [], 'en'))->assertSee('href="'.route('my.index').'"', false);
        $this->get("/my/{$theirs->uuid}")->assertNotFound();

        // A reporter never reaches the dashboard.
        $this->get('/reports')->assertForbidden();
        $this->get('/admin')->assertForbidden();
        $this->get('/field')->assertForbidden();
    }

    public function test_unknown_or_fake_telegram_users_are_refused(): void
    {
        $this->openMiniApp(5555)->assertNotFound()->assertJson(['error' => 'not_registered']);
        $this->assertGuest();

        config(['cohers.telegram.bot_token' => self::TOKEN]);
        $this->postJson('/tg/auth', ['init_data' => $this->initData(8001, token: 'forged:token')])->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_linked_officer_lands_on_field_cases(): void
    {
        $this->openMiniApp(9002)->assertOk()->assertJson(['redirect' => route('field.index')]);
        $this->assertAuthenticatedAs($this->cao);
    }

    public function test_reporter_answers_the_officer_and_adds_information(): void
    {
        $report = $this->submit('Many pigs have fever and stopped eating');
        $assignment = $report->fresh()->activeAssignment;
        $verify = app(ReportVerificationService::class);
        $verify->acknowledge($assignment, $this->cao);
        $question = $verify->requestInformation($assignment->fresh(), $this->cao, 'How many pigs are sick?');

        $this->openMiniApp(8001);
        $page = $this->get("/my/{$report->uuid}")->assertOk()->assertSee('How many pigs are sick?')->assertSee('id="addInfoCancel"', false);
        $this->assertMatchesRegularExpression('/id="addInfoForm"[^>]*\shidden/', $page->getContent(), 'add-information box starts closed');

        $this->post("/my/{$report->uuid}/answer", ['question' => $question->id, 'answer' => 'About twelve'])->assertRedirect("/my/{$report->uuid}");
        $this->assertSame('ANSWERED', $question->fresh()->status);
        $this->assertSame(ReportStatus::ACKNOWLEDGED, $report->fresh()->status);
        $this->assertTrue(NotificationLog::where('recipient_id', $this->cao->id)->where('message_type', 'officer.info_answer')->exists());

        $this->post("/my/{$report->uuid}/update", ['body' => 'Now three more pigs are sick'])->assertRedirect("/my/{$report->uuid}");
        $this->assertSame(1, ReportUpdate::where('report_id', $report->id)->count());
        $this->assertTrue(NotificationLog::where('recipient_id', $this->cao->id)->where('message_type', 'officer.reporter_update')->exists());

        // The officer sees it on the case page; the reporter sees it in their progress.
        $this->get("/my/{$report->uuid}")->assertSee('Now three more pigs are sick');
        $this->actingAs($this->districtOfficer)->get("/reports/{$report->uuid}")->assertSee('Reporter added information');
    }

    public function test_reporter_sends_a_new_report_with_photos(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->openMiniApp(8001);

        $this->get('/my/new')->assertOk()->assertSee($this->district->name_en);
        $villages = $this->getJson("/my/geo/{$this->commune->id}/villages")->assertOk()->json();
        $this->assertContains($this->village->id, array_column($villages, 'id'));

        $key = (string) \Illuminate\Support\Str::uuid();
        $form = [
            'geographic_unit_id' => $this->village->id,
            'original_text' => 'Many pigs have fever and stopped eating',
            'affected_count' => '6 pigs',
            'observed' => 'yesterday',
            'latitude' => '11.234567', 'longitude' => '105.123456',
            'idempotency_key' => $key,
            'photos' => [\Illuminate\Http\UploadedFile::fake()->image('a.jpg', 800, 600), \Illuminate\Http\UploadedFile::fake()->image('b.png', 400, 300)],
        ];
        $res = $this->post('/my', $form);

        $report = \App\Models\Report::where('reporter_id', $this->reporter->id)->latest('id')->first();
        $res->assertRedirect("/my/{$report->uuid}");
        $this->assertSame('6 pigs', $report->affected_count);
        $this->assertSame('Yesterday', $report->observed_text);
        $this->assertEqualsWithDelta(11.234567, (float) $report->latitude, 0.00001);
        $this->assertSame(2, $report->attachments()->where('download_status', 'STORED')->count());
        foreach ($report->attachments as $a) {
            \Illuminate\Support\Facades\Storage::disk('local')->assertExists($a->path);
        }
        // Routed like a bot report, and the reporter gets the usual confirmation with the code.
        $this->assertSame($this->cao->id, $report->fresh()->activeAssignment?->focal_point_user_id);
        $this->assertTrue(NotificationLog::where('recipient_id', $this->reporter->id)->where('message_type', 'reporter.submitted')->exists());
        $this->get("/my/{$report->uuid}")->assertOk()->assertSee(route('my.photo', $report->attachments->first()), false);

        // The Mini App posts in the background and gets the page to open.
        $this->postJson('/my', array_merge($form, ['photos' => []]))->assertOk()->assertJson(['redirect' => route('my.show', $report)]);
        $this->postJson('/my', ['original_text' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['geographic_unit_id', 'idempotency_key']);

        // Sending the same form twice (double tap, retry) does not create a second report.
        $this->post('/my', array_merge($form, ['photos' => []]))->assertRedirect("/my/{$report->uuid}");
        $this->assertSame(1, \App\Models\Report::where('idempotency_key', 'miniapp:'.$key)->count());
        $this->assertSame(2, $report->attachments()->count());
    }

    public function test_bottom_menu_tabs_counts_and_filters(): void
    {
        $open = $this->submit('Many pigs have fever and stopped eating');
        $closed = $this->submit('Twenty chickens died suddenly in our village yesterday');
        $closed->forceFill(['status' => ReportStatus::CLOSED])->save();
        $assignment = $open->fresh()->activeAssignment;
        app(ReportVerificationService::class)->acknowledge($assignment, $this->cao);
        app(ReportVerificationService::class)->requestInformation($assignment->fresh(), $this->cao, 'How many pigs?');

        $this->openMiniApp(8001);
        $page = $this->get('/my')->assertOk()
            ->assertSee(route('my.location'), false)->assertSee(route('my.profile'), false)->assertSee(route('my.create'), false);
        $this->assertSame(['open' => 1, 'waiting' => 1, 'closed' => 1], $page->viewData('counts'));

        $this->get('/my?filter=closed')->assertSee($closed->reference_code)->assertDontSee($open->reference_code);
        $this->get('/my?filter=waiting')->assertSee($open->reference_code)->assertDontSee($closed->reference_code);
        $this->get('/my?filter=open')->assertSee($open->reference_code)->assertDontSee($closed->reference_code);
    }

    public function test_location_tab_saves_the_village_shared_with_the_bot(): void
    {
        $other = $this->user('Someone Else', \App\Enums\RoleName::REPORTER, 8002);
        app(\App\Services\Reporting\ReportService::class)->create($other, ['geographic_unit_id' => $this->village->id, 'original_text' => 'Dead fish in the pond today', 'latitude' => 11.5, 'longitude' => 104.9]);
        $mine = $this->submit('Many pigs have fever and stopped eating');
        $mine->forceFill(['latitude' => 11.6, 'longitude' => 105.1])->save();

        $this->openMiniApp(8001);
        $pins = $this->get('/my/location')->assertOk()->viewData('pins');
        $this->assertSame([$mine->reference_code], $pins->pluck('code')->all(), 'only my own reports on the map');

        $this->post('/my/location', ['geographic_unit_id' => $this->village->id])->assertRedirect('/my/location');
        $session = \App\Models\TelegramSession::where('telegram_user_id', 8001)->first();
        $this->assertSame($this->village->id, $session->get('default_village_id'));

        // The new report form now starts from that village, without the drop-downs.
        $form = $this->get('/my/new');
        $this->assertTrue($form->viewData('last')['saved']);
        $form->assertSee('🏠 '.$form->viewData('last')['full'], false)
            ->assertSee('name="geographic_unit_id" id="unitId" value="'.$this->village->id.'"', false);
        $this->assertMatchesRegularExpression('/id="placePicker"[^>]*\shidden/', $form->getContent(), 'drop-downs hidden when the village is saved');
        $this->post('/my/location', ['geographic_unit_id' => $this->district->id])->assertNotFound();   // a district is not a village
    }

    public function test_profile_tab_updates_name_phone_and_bot_language(): void
    {
        $this->openMiniApp(8001);
        $this->get('/my/profile')->assertOk()->assertSee($this->reporter->name);

        $this->post('/my/profile', ['name' => 'Sok Dara', 'phone' => '012 345 678', 'preferred_language' => 'km'])->assertRedirect('/my/profile');
        $user = $this->reporter->fresh();
        $this->assertSame(['Sok Dara', '012 345 678', 'km'], [$user->name, $user->phone, $user->preferred_language]);
        $this->assertSame('km', \App\Models\TelegramSession::where('telegram_user_id', 8001)->first()->get('locale'));
        $page = $this->get('/my/profile')->assertSee(__('bot.miniapp.tab_profile', [], 'km'), false);   // shown in Khmer now
        $this->assertNull($page->viewData('points'), 'points are hidden while the programme is off');

        $this->post('/my/profile', ['name' => 'X', 'phone' => '<script>', 'preferred_language' => 'fr'])->assertSessionHasErrors(['name', 'phone', 'preferred_language']);
    }

    public function test_new_report_rejects_non_images(): void
    {
        $this->openMiniApp(8001);
        $this->post('/my', [
            'geographic_unit_id' => $this->village->id, 'original_text' => 'Dead fish in the pond',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'photos' => [\Illuminate\Http\UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')],
        ])->assertSessionHasErrors('photos.0');
        $this->assertSame(0, \App\Models\Report::where('reporter_id', $this->reporter->id)->count());
    }

    public function test_closed_reports_cannot_get_updates(): void
    {
        $report = $this->submit('Many pigs have fever and stopped eating');
        $report->forceFill(['status' => ReportStatus::CLOSED])->save();

        $this->openMiniApp(8001);
        $this->get("/my/{$report->uuid}")->assertOk()->assertSee(__('bot.miniapp.closed_note', [], 'en'));
        $this->post("/my/{$report->uuid}/update", ['body' => 'Again'])->assertStatus(422);
    }

    public function test_reports_saved_offline_are_sent_later_without_duplicates(): void
    {
        // Signed out: the phone is told to sign in again, it does not get a token.
        $this->getJson('/my/sync-token')->assertUnauthorized();

        $this->openMiniApp(8001);
        $token = $this->getJson('/my/sync-token')->assertOk()->assertHeader('Cache-Control')->json();
        $this->assertNotEmpty($token['token']);
        $this->assertNotEquals((string) $this->reporter->id, $token['user']);   // opaque tag, not the database id
        $this->get('/my')->assertOk()->assertSee($token['user'], false)->assertSee('offline-queue.js', false);
        $this->get('/my/new')->assertOk()->assertSee('data-offline-ok', false);

        // Written two days ago as "yesterday", sent now: observed three days ago, not yesterday.
        $key = (string) \Illuminate\Support\Str::uuid();
        $form = [
            'geographic_unit_id' => $this->village->id, 'original_text' => 'Chickens dying near the school',
            'observed' => 'yesterday', 'idempotency_key' => $key, 'captured_at' => now()->subDays(2)->toIso8601String(),
        ];
        $this->postJson('/my', $form)->assertOk()->assertJsonStructure(['redirect']);
        $report = \App\Models\Report::where('idempotency_key', 'miniapp:'.$key)->firstOrFail();
        $this->assertTrue($report->observed_at->isSameDay(now()->subDays(3)));

        // The queue retries after a dropped connection: still one report.
        $this->postJson('/my', $form)->assertOk();
        $this->assertSame(1, \App\Models\Report::where('idempotency_key', 'miniapp:'.$key)->count());

        // A wrong phone clock (future, or months ago) falls back to the time it arrived.
        foreach ([now()->addDays(5), now()->subMonths(3)] as $clock) {
            $k = (string) \Illuminate\Support\Str::uuid();
            $this->postJson('/my', array_merge($form, ['idempotency_key' => $k, 'captured_at' => $clock->toIso8601String()]))->assertOk();
            $this->assertTrue(\App\Models\Report::where('idempotency_key', 'miniapp:'.$k)->first()->observed_at->isSameDay(now()->subDay()));
        }
    }

    public function test_service_worker_and_queue_script_are_served(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('js/offline-queue.js'));
        $this->assertStringContainsString("'my/sync-token'", file_get_contents(public_path('sw.js')));
    }
}
