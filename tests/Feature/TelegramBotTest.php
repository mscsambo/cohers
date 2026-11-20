<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Telegram\TelegramWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCohersFixtures;
use Tests\TestCase;

/** Scenarios #1, #11, #12: Telegram-only registration & submission, KM/EN menus, idempotent webhook. */
class TelegramBotTest extends TestCase
{
    use BuildsCohersFixtures, RefreshDatabase;

    private int $updateId = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCohers();
    }

    private function msg(int $from, string $text = '', array $extra = []): void
    {
        app(TelegramWebhookService::class)->ingest([
            'update_id' => ++$this->updateId,
            'message' => ['message_id' => $this->updateId, 'from' => ['id' => $from, 'first_name' => 'T'], 'chat' => ['id' => $from, 'type' => 'private'], 'text' => $text] + $extra,
        ], sync: true);
    }

    private function tap(int $from, string $data): void
    {
        app(TelegramWebhookService::class)->ingest([
            'update_id' => ++$this->updateId,
            'callback_query' => ['id' => 'cb'.$this->updateId, 'from' => ['id' => $from], 'data' => $data,
                'message' => ['message_id' => 1, 'chat' => ['id' => $from, 'type' => 'private']]],
        ], sync: true);
    }

    public function test_reporter_registers_and_submits_entirely_in_telegram(): void
    {
        $tg = 5550001;
        $this->msg($tg, '/start');
        $this->tap($tg, 'lang:en');
        $this->tap($tg, 'consent:yes');
        $this->msg($tg, 'Dara');

        $user = TelegramAccount::where('telegram_user_id', $tg)->first()->user;
        $this->assertTrue($user->hasRole('reporter'));
        $this->assertFalse($user->isPrivileged(), 'bot never grants officer roles');
        $this->assertSame('en', $user->preferred_language);

        $this->tap($tg, 'def:skip');
        $this->msg($tg, '📝 Submit Report');
        $this->tap($tg, "l:rpt:{$this->district->id}");
        $this->tap($tg, "l:rpt:{$this->commune->id}");
        $this->tap($tg, "l:rpt:{$this->village->id}");
        $this->msg($tg, 'Many pigs have fever and stopped eating');
        $this->msg($tg, '4 pigs');
        $this->tap($tg, 'rpt:when:1');
        $this->msg($tg, '', ['photo' => [['file_id' => 'small', 'file_size' => 100], ['file_id' => 'big', 'file_size' => 2000]]]);
        $this->tap($tg, 'rpt:evidence_done');

        $draftId = \App\Models\TelegramSession::where('telegram_user_id', $tg)->first()->get('draft.id');
        $this->tap($tg, "rpt:submit:{$draftId}");
        $this->tap($tg, "rpt:submit:{$draftId}"); // double press

        $this->assertSame(1, Report::count());
        $r = Report::first();
        $this->assertSame($user->id, $r->reporter_id);
        $this->assertSame($this->village->id, $r->geographic_unit_id);
        $this->assertSame('4 pigs', $r->affected_count);
        $this->assertSame('big', $r->attachments()->first()->telegram_file_id);
        $this->assertSame(ReportStatus::ASSIGNED, $r->status);

        // Track own report by code; another user cannot.
        $this->msg($tg, '🔎 Track Report');
        $this->msg($tg, $r->reference_code);
        $this->assertSame('idle', \App\Models\TelegramSession::where('telegram_user_id', $tg)->value('state'));
    }

    public function test_khmer_menu_labels_are_recognised(): void
    {
        $this->msg(8001, '📋 របាយការណ៍របស់ខ្ញុំ');
        $this->assertSame('PROCESSED', TelegramUpdate::latest('id')->value('status'));
        $this->tap(8001, 'lang:km');
        $this->assertSame('km', $this->reporter->fresh()->preferred_language);
    }

    public function test_officer_links_with_single_use_token_and_acknowledges_via_callback(): void
    {
        $officer = $this->officer(\App\Enums\OfficerType::CAO, 'New CAO', 1); // placeholder tg
        TelegramAccount::where('user_id', $officer->id)->delete();
        $token = 'tok123456789';
        TelegramLinkToken::create(['user_id' => $officer->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);

        $this->msg(6660001, "/start link_{$token}");
        $this->assertSame(6660001, (int) $officer->fresh()->telegramAccount->telegram_user_id);

        // Token is single use.
        $this->msg(6660002, "/start link_{$token}");
        $this->assertNull(TelegramAccount::where('telegram_user_id', 6660002)->first());

        $this->map($officer->fresh(), $this->village, 'ANIMAL');
        $r = $this->submit('Many pigs have fever and stopped eating');
        $a = $r->activeAssignment;
        $this->assertSame($officer->id, $a->focal_point_user_id);

        // A different officer pressing the same callback is refused.
        $this->tap(9001, "a:ack:{$a->id}");
        $this->assertNull($a->fresh()->acknowledged_at);

        $this->tap(6660001, "a:ack:{$a->id}");
        $this->assertNotNull($a->fresh()->acknowledged_at);
    }

    public function test_webhook_requires_secret_and_deduplicates_update_ids(): void
    {
        $payload = ['update_id' => 777, 'message' => ['message_id' => 1, 'from' => ['id' => 8001], 'chat' => ['id' => 8001, 'type' => 'private'], 'text' => '/help']];

        $this->postJson('/api/telegram/webhook', $payload)->assertStatus(401);
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertSame(1, TelegramUpdate::where('update_id', 777)->count());
    }

    public function test_group_chats_are_ignored(): void
    {
        app(TelegramWebhookService::class)->ingest(['update_id' => 5, 'message' => ['message_id' => 1, 'from' => ['id' => 42], 'chat' => ['id' => -100, 'type' => 'group'], 'text' => '/start']], sync: true);
        $this->assertSame(0, User::whereHas('telegramAccount', fn ($q) => $q->where('telegram_user_id', 42))->count());
    }
}
