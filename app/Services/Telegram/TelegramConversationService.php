<?php

namespace App\Services\Telegram;

use App\Enums\AssignmentStatus;
use App\Enums\RoleName;
use App\Enums\VerificationOutcome;
use App\Models\GeographicUnit;
use App\Models\IncentiveTransaction;
use App\Models\Report;
use App\Models\ReportAssignment;
use App\Models\ReportInformationRequest;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\TelegramSession;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Reporting\ReportService;
use App\Services\Verification\ReportVerificationService;
use App\Support\BotText;
use App\Support\Fmt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Stateful bot flows for reporters (onboarding, report wizard, tracking,
 * clarification replies) and focal points (account linking, alerts,
 * acknowledgement, verification). Only private chats are served.
 */
class TelegramConversationService
{
    private const KEEP = ['locale', 'default_village_id', 'last_submitted'];

    private const PAGE = 24;

    private int $chatId;

    private array $from;

    private TelegramSession $session;

    private ?User $user = null;

    private string $locale = 'km';

    public function __construct(
        private TelegramClient $tg,
        private ReportService $reports,
        private ReportVerificationService $verify,
        private AuditService $audit,
    ) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $chat = $cb['message']['chat'] ?? null;
            if (! $chat || ($chat['type'] ?? '') !== 'private') {
                return;
            }
            $this->boot($cb['from'], (int) $chat['id']);
            $toast = $this->onCallback((string) ($cb['data'] ?? ''), (int) ($cb['message']['message_id'] ?? 0));
            $this->tg->answerCallback($cb['id'], $toast);

            return;
        }

        $msg = $update['message'] ?? null;
        if (! $msg || ($msg['chat']['type'] ?? '') !== 'private' || ! isset($msg['from'])) {
            return;
        }
        $this->boot($msg['from'], (int) $msg['chat']['id']);
        $this->onMessage($msg);
    }

    public function apologize(array $update): void
    {
        $chatId = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
        if ($chatId) {
            try {
                $this->tg->sendMessage($chatId, BotText::t('error', $this->locale));
            } catch (\Throwable) {
            }
        }
    }

    // ---------------------------------------------------------------- bootstrap

    private function boot(array $from, int $chatId): void
    {
        $this->from = $from;
        $this->chatId = $chatId;

        $this->session = TelegramSession::firstOrCreate(
            ['telegram_user_id' => $from['id']],
            ['chat_id' => $chatId, 'state' => 'idle', 'data' => []],
        );
        if ((int) $this->session->chat_id !== $chatId) {
            $this->session->update(['chat_id' => $chatId]);
        }

        // Identity is always re-read from the database for every update.
        $account = TelegramAccount::with('user.roles')->where('telegram_user_id', $from['id'])->first();
        if ($account) {
            $changes = [];
            if ((int) $account->private_chat_id !== $chatId) {
                $changes['private_chat_id'] = $chatId;
            }
            if ($account->status === 'blocked') {
                $changes['status'] = 'active'; // they wrote to us again, so the chat works
            }
            foreach (['username' => 'username', 'first_name' => 'first_name', 'language_code' => 'language_code'] as $col => $key) {
                if (($from[$key] ?? null) !== null && $account->{$col} !== $from[$key]) {
                    $changes[$col] = mb_substr((string) $from[$key], 0, 190);
                }
            }
            if (! $account->last_seen_at || $account->last_seen_at->lt(now()->subMinute())) {
                $changes['last_seen_at'] = now();
            }
            $changes && $account->update($changes);
        }
        $this->user = $account && $account->status !== 'unlinked' ? $account->user : null;
        $this->locale = $this->user?->locale() ?? $this->session->get('locale', 'km');
        app()->setLocale($this->locale);
    }

    private function t(string $key, array $replace = []): string
    {
        return BotText::t($key, $this->locale, $replace);
    }

    private function send(string $text, ?array $inline = null, ?array $reply = null): void
    {
        $this->tg->sendMessage($this->chatId, $text, $inline, $reply);
    }

    private function resetSession(array $extra = []): void
    {
        $this->session->reset(self::KEEP);
        if ($extra) {
            $this->session->put($extra)->save();
        }
    }

    // ---------------------------------------------------------------- messages

    private function onMessage(array $msg): void
    {
        $text = trim((string) ($msg['text'] ?? ''));

        if (str_starts_with($text, '/start')) {
            $payload = trim(substr($text, 6));
            if (str_starts_with($payload, 'link_')) {
                $this->linkOfficer(substr($payload, 5));

                return;
            }
            $this->user ? $this->home($this->t('welcome')) : $this->startOnboarding();

            return;
        }

        if ($text === '/cancel') {
            $this->resetSession();
            $this->send($this->t('cancelled'));
            $this->user ? $this->home() : $this->startOnboarding();

            return;
        }

        if ($text === '/help') {
            $this->showHelp();

            return;
        }

        if (! $this->user) {
            $this->session->state === 'onboard_name' && $text !== ''
                ? $this->completeRegistration($text)
                : $this->startOnboarding();

            return;
        }

        if (! $this->user->isActive()) {
            $this->send($this->t('suspended'));

            return;
        }

        if ($text !== '' && $menu = $this->matchMenu($text)) {
            $this->resetSession();
            $this->runMenu($menu);

            return;
        }

        $handled = match ($this->session->state) {
            'rpt_desc' => isset($msg['voice']) ? $this->voiceDescription($msg['voice']) : $this->wizardDescription($text),
            'rpt_affected' => $this->wizardAffected($text),
            'rpt_evidence' => $this->wizardEvidence($msg),
            'track_code' => $this->trackReport($text),
            'info_reply' => $this->reporterAnswer($text),
            'o_info' => $this->officerQuestion($text),
            'o_esc' => $this->officerEscalate($text),
            'o_fnd_text' => $this->officerFindingsText($msg),
            'o_fnd_dup' => $this->officerDuplicateCode($text),
            default => false,
        };

        if (! $handled) {
            $this->home($this->t('not_understood'));
        }
    }

    private function matchMenu(string $text): ?string
    {
        foreach (['km', 'en'] as $loc) {
            foreach (trans('bot.menu', [], $loc) as $key => $label) {
                if ($label === $text) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function runMenu(string $menu): void
    {
        match ($menu) {
            'submit' => $this->startReport(),
            'my_reports' => $this->myReports(),
            'track' => $this->askTrack(),
            'points' => $this->myPoints(),
            'language' => $this->send($this->t('choose_language'), $this->languageKeyboard()),
            'help' => $this->showHelp(),
            'assignments' => $this->officerList(AssignmentStatus::active()),
            'alerts' => $this->officerList([AssignmentStatus::PENDING->value]),
            'verifications' => $this->officerList([AssignmentStatus::ACKNOWLEDGED->value, AssignmentStatus::VERIFYING->value]),
            default => $this->home(),
        };
    }

    private function homeKeyboard(): array
    {
        $m = fn ($k) => ['text' => $this->t('menu.'.$k)];
        $rows = [];
        if ($this->user?->isFocalPoint()) {
            $rows[] = [$m('assignments'), $m('alerts')];
            $rows[] = [$m('verifications')];
        }
        $rows[] = [$m('submit')];
        $rows[] = [$m('my_reports'), $m('track')];
        $last = [$m('language'), $m('help')];
        if (SystemSetting::get_value('incentives_enabled', false)) {
            array_unshift($last, $m('points'));
        }
        $rows[] = $last;

        return $rows;
    }

    private function home(?string $prefix = null): void
    {
        $this->send(trim(($prefix ? $prefix."\n\n" : '').$this->t('home')), null, $this->homeKeyboard());
    }

    private function showHelp(): void
    {
        $text = $this->t('help')."\n\n".$this->t('emergency');
        if ($this->user?->isFocalPoint()) {
            $text = $this->t('officer_help')."\n\n".$text;
        }
        $this->send($text, null, $this->user ? $this->homeKeyboard() : null);
    }

    private function languageKeyboard(): array
    {
        return [[['text' => 'ភាសាខ្មែរ', 'callback_data' => 'lang:km'], ['text' => 'English', 'callback_data' => 'lang:en']]];
    }

    // ---------------------------------------------------------------- callbacks

    private function onCallback(string $data, int $messageId): ?string
    {
        $parts = explode(':', $data);
        $head = $parts[0] ?? '';

        if ($head === 'lang') {
            return $this->setLanguage($parts[1] ?? 'km');
        }
        if ($head === 'consent') {
            return $this->consent(($parts[1] ?? '') === 'yes');
        }

        if (! $this->user) {
            $this->startOnboarding();

            return null;
        }
        if (! $this->user->isActive()) {
            $this->send($this->t('suspended'));

            return null;
        }

        try {
            return match ($head) {
                'l' => $this->onUnitSelected($parts[1] ?? '', $parts[2] ?? 'root', $messageId),
                'lp' => $this->showUnits($parts[1] ?? '', $parts[2] ?? 'root', (int) ($parts[3] ?? 0), $messageId),
                'lz' => $this->finishLocation($parts[1] ?? '', GeographicUnit::find((int) ($parts[2] ?? 0))),
                'def' => $this->home($this->t('welcome')),
                'rpt' => $this->wizardCallback($parts, $messageId),
                'r' => $this->reporterReplyStart((int) ($parts[2] ?? 0)),
                'a' => $this->officerAction($parts[1] ?? '', (int) ($parts[2] ?? 0)),
                'f' => $this->findingsCallback($parts),
                default => $this->home($this->t('not_understood')),
            };
        } catch (AuthorizationException) {
            $this->send($this->t('unauthorized'));

            return '⛔';
        } catch (InvalidArgumentException $e) {
            $this->send('⚠️ '.Fmt::h($e->getMessage()));

            return null;
        }
    }

    // ---------------------------------------------------------------- onboarding

    private function startOnboarding(): void
    {
        $this->session->transition('onboard_lang');
        $this->send($this->t('choose_language'), $this->languageKeyboard());
    }

    private function setLanguage(string $lang): ?string
    {
        $lang = in_array($lang, ['km', 'en'], true) ? $lang : 'km';
        $this->locale = $lang;
        app()->setLocale($lang);
        $this->session->put(['locale' => $lang])->save();

        if ($this->user) {
            $this->user->update(['preferred_language' => $lang]);
            $this->home($this->t('reporter.language_set'));

            return null;
        }

        $this->session->transition('onboard_consent');
        $this->send($this->t('privacy_notice'), [[
            ['text' => $this->t('btn.agree'), 'callback_data' => 'consent:yes'],
            ['text' => $this->t('btn.decline'), 'callback_data' => 'consent:no'],
        ]]);

        return null;
    }

    private function consent(bool $yes): ?string
    {
        if ($this->user) {
            $this->home();

            return null;
        }
        if (! $yes) {
            $this->resetSession();
            $this->send($this->t('consent_declined'));

            return null;
        }
        $this->session->transition('onboard_name', ['consented_at' => now()->toIso8601String()]);
        $this->send($this->t('ask_name'));

        return null;
    }

    private function completeRegistration(string $name): void
    {
        $name = trim(strip_tags($name));
        if (mb_strlen($name) < 1 || mb_strlen($name) > 60 || str_starts_with($name, '/')) {
            $this->send($this->t('ask_name'));

            return;
        }

        // Only ever creates a reporter identity — never officer/district/admin roles.
        $this->user = DB::transaction(function () use ($name) {
            $user = User::create([
                'name' => $name,
                'preferred_language' => $this->locale,
                'account_status' => 'active',
                'consented_at' => $this->session->get('consented_at', now()),
            ]);
            $user->roles()->attach(Role::where('name', RoleName::REPORTER->value)->value('id'));
            TelegramAccount::create([
                'user_id' => $user->id,
                'telegram_user_id' => $this->from['id'],
                'private_chat_id' => $this->chatId,
                'username' => $this->from['username'] ?? null,
                'first_name' => $this->from['first_name'] ?? null,
                'language_code' => $this->from['language_code'] ?? null,
                'linked_at' => now(),
                'last_seen_at' => now(),
                'status' => 'active',
            ]);
            $this->audit->record('reporter.registered', $user, $user, null, null, ['language' => $this->locale], 'telegram');

            return $user->load('roles');
        });

        $this->session->transition('onboard_defloc');
        $this->send($this->t('ask_default_location'), [[
            ['text' => $this->t('btn.choose_location'), 'callback_data' => 'l:def:root'],
            ['text' => $this->t('btn.skip'), 'callback_data' => 'def:skip'],
        ]]);
    }

    private function linkOfficer(string $token): void
    {
        $row = TelegramLinkToken::with('user.roles')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || ! $row->isUsable() || ! $row->user->isActive()) {
            $this->send($this->t('officer.link_invalid'));

            return;
        }

        $officer = $row->user;
        $existing = TelegramAccount::with('user.roles')->where('telegram_user_id', $this->from['id'])->first();
        $mergeFrom = null;
        if ($existing && $existing->user_id !== $officer->id) {
            // A plain community-reporter identity may be folded into the staff account;
            // any other existing link (officer, district, admin) must be unlinked by an admin.
            $other = $existing->user;
            $reporterOnly = $other && $other->roles->pluck('name')->diff([RoleName::REPORTER->value])->isEmpty();
            if (! $reporterOnly) {
                $this->send($this->t('officer.link_conflict'));

                return;
            }
            $mergeFrom = $other;
        }

        DB::transaction(function () use ($row, $officer, $existing, $mergeFrom) {
            if ($mergeFrom) {
                $reportIds = Report::where('reporter_id', $mergeFrom->id)->pluck('id')->all();
                Report::whereIn('id', $reportIds)->update(['reporter_id' => $officer->id]);
                $existing->delete();
                $mergeFrom->forceFill(['account_status' => 'suspended'])->save();
                $this->audit->record('telegram.reporter_merged', $officer, $officer,
                    'Telegram previously registered as reporter; merged into staff account on link',
                    ['reporter_user_id' => $mergeFrom->id], ['report_ids' => $reportIds], 'telegram');
            }
            TelegramAccount::updateOrCreate(['user_id' => $officer->id], [
                'telegram_user_id' => $this->from['id'],
                'private_chat_id' => $this->chatId,
                'username' => $this->from['username'] ?? null,
                'first_name' => $this->from['first_name'] ?? null,
                'language_code' => $this->from['language_code'] ?? null,
                'linked_at' => now(),
                'last_seen_at' => now(),
                'status' => 'active',
            ]);
            $row->update(['used_at' => now()]);
            $this->audit->record('telegram.linked', $officer, $officer, null, null, ['telegram_user_id' => $this->from['id']], 'telegram');
        });

        $this->user = $officer->fresh(['roles', 'telegramAccount']);
        $this->locale = $this->user->locale();
        $this->resetSession();
        $this->home($this->t('officer.linked', [
            'name' => Fmt::h($officer->name),
            'type' => $officer->officer_type?->value ?? implode(', ', $officer->roles->pluck('label')->all()),
        ]));
    }

    // ---------------------------------------------------------------- location picker

    private function showUnits(string $mode, string $parent, int $page = 0, int $messageId = 0): ?string
    {
        if ($parent === 'root') {
            $units = GeographicUnit::where('type', 'district')->where('is_active', true)->orderBy('code')->get();
            $title = $this->t('wizard.select_district');
            $back = null;
        } else {
            $p = GeographicUnit::where('is_active', true)->find((int) $parent);
            if (! $p) {
                return $this->showUnits($mode, 'root', 0, $messageId);
            }
            $units = $p->children()->where('is_active', true)->orderBy('name_en')->get();
            $title = match ($p->type) {
                'district' => $this->t('wizard.select_commune'),
                default => $this->t('wizard.select_village'),
            };
            $up = $p->parent;
            $back = $up && $up->type !== 'province' ? "l:{$mode}:{$up->id}" : "l:{$mode}:root";
        }

        $slice = $units->slice($page * self::PAGE, self::PAGE);
        $rows = $slice->map(fn ($u) => ['text' => $u->name($this->locale), 'callback_data' => "l:{$mode}:{$u->id}"])
            ->chunk(2)->map(fn ($c) => $c->values()->all())->values()->all();

        $nav = [];
        if ($back) {
            $nav[] = ['text' => $this->t('btn.back'), 'callback_data' => $back];
        }
        if ($page > 0) {
            $nav[] = ['text' => '◀️', 'callback_data' => "lp:{$mode}:{$parent}:".($page - 1)];
        }
        if ($units->count() > ($page + 1) * self::PAGE) {
            $nav[] = ['text' => '▶️', 'callback_data' => "lp:{$mode}:{$parent}:".($page + 1)];
        }
        if ($parent !== 'root' && isset($p) && $p->type === 'village') {
            $nav[] = ['text' => $this->t('btn.skip'), 'callback_data' => "lz:{$mode}:{$p->id}"]; // optional zone
        }
        if ($nav) {
            $rows[] = $nav;
        }

        $messageId
            ? $this->tg->editMessage($this->chatId, $messageId, $title, $rows)
            : $this->send($title, $rows);

        return null;
    }

    private function onUnitSelected(string $mode, string $id, int $messageId): ?string
    {
        if ($id === 'root') {
            return $this->showUnits($mode, 'root', 0, $messageId);
        }
        $unit = GeographicUnit::where('is_active', true)->find((int) $id);
        if (! $unit) {
            $this->send($this->t('wizard.expired'));

            return null;
        }

        $hasChildren = $unit->children()->where('is_active', true)->exists();
        if (in_array($unit->type, ['village', 'zone'], true) && ! $hasChildren || $unit->type === 'zone') {
            return $this->finishLocation($mode, $unit);
        }

        return $this->showUnits($mode, (string) $unit->id, 0, $messageId);
    }

    private function finishLocation(string $mode, ?GeographicUnit $unit): ?string
    {
        if (! $unit || ! in_array($unit->type, ['village', 'zone'], true)) {
            $this->send($this->t('wizard.expired'));

            return null;
        }

        if ($mode === 'def') {
            $this->session->put(['default_village_id' => $unit->id]);
            $this->resetSession();
            $this->home($this->t('default_saved', ['location' => Fmt::h($unit->fullPath())]));

            return null;
        }

        if (! $this->session->get('draft.id')) {
            $this->send($this->t('wizard.expired'));

            return null;
        }
        $this->putDraft(['village_id' => $unit->id]);
        if ($this->session->get('draft.editing')) {
            return $this->showConfirm();
        }
        $this->session->transition('rpt_desc');
        $this->send($this->t('wizard.describe'));

        return null;
    }

    // ---------------------------------------------------------------- report wizard

    private function putDraft(array $values): void
    {
        $draft = array_merge($this->session->get('draft', []), $values);
        $this->session->put(['draft' => $draft])->save();
    }

    private function startReport(): void
    {
        $this->session->transition('rpt_location', ['draft' => ['id' => (string) Str::uuid(), 'photos' => []]]);

        $default = GeographicUnit::where('is_active', true)->find($this->session->get('default_village_id'));
        if ($default) {
            $this->send($this->t('wizard.location_saved_prompt'), [
                [['text' => $this->t('btn.use_saved', ['location' => $default->name($this->locale)]), 'callback_data' => 'rpt:saved']],
                [['text' => $this->t('btn.choose_other'), 'callback_data' => 'l:rpt:root']],
            ]);

            return;
        }
        $this->showUnits('rpt', 'root');
    }

    private function wizardCallback(array $parts, int $messageId): ?string
    {
        $action = $parts[1] ?? '';

        if ($action === 'submit') {
            return $this->submitDraft($parts[2] ?? '', $messageId);
        }
        if ($action === 'cancel') {
            $this->resetSession();
            $this->home($this->t('cancelled'));

            return null;
        }
        if (! $this->session->get('draft.id')) {
            $this->send($this->t('wizard.expired'));

            return null;
        }

        switch ($action) {
            case 'saved':
                return $this->finishLocation('rpt', GeographicUnit::find($this->session->get('default_village_id')));
            case 'skip_affected':
                if ($this->session->state === 'rpt_affected') {
                    $this->askWhen();
                }

                return null;
            case 'when':
                return $this->wizardWhen((int) ($parts[2] ?? 0));
            case 'evidence_done':
                return $this->showConfirm();
            case 'edit':
                $this->putDraft(['editing' => true]);
                $b = fn ($k, $f) => ['text' => $this->t('btn.'.$k), 'callback_data' => "rpt:ef:{$f}"];
                $this->send($this->t('wizard.edit_which'), [
                    [$b('edit_location', 'location'), $b('edit_description', 'description')],
                    [$b('edit_affected', 'affected'), $b('edit_when', 'when')],
                    [$b('edit_photos', 'photos')],
                ]);

                return null;
            case 'ef':
                return match ($parts[2] ?? '') {
                    'location' => $this->showUnits('rpt', 'root'),
                    'description' => tap(null, fn () => [$this->session->transition('rpt_desc'), $this->send($this->t('wizard.describe'))]),
                    'affected' => tap(null, fn () => $this->askAffected()),
                    'when' => tap(null, fn () => $this->askWhen()),
                    'photos' => tap(null, function () {
                        $this->putDraft(['photos' => [], 'lat' => null, 'lng' => null]);
                        $this->askEvidence();
                    }),
                    default => null,
                };
        }

        return null;
    }

    private function voiceDescription(array $voice): bool
    {
        $maxBytes = 6 * 1024 * 1024;
        if (! config('cohers.ai.gemini_api_key') || empty($voice['file_id'])
            || ($voice['duration'] ?? 0) > 60 || ($voice['file_size'] ?? 0) > $maxBytes) {
            $this->send($this->t('wizard.voice_retry'));

            return true;
        }
        try {
            [$bytes] = $this->tg->downloadFile($voice['file_id'], $maxBytes);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $mime = ['application/ogg' => 'audio/ogg', 'video/mp4' => 'audio/mp4', 'audio/x-m4a' => 'audio/mp4'][$mime] ?? $mime;
            if (! in_array($mime, ['audio/ogg', 'audio/mp4', 'audio/mpeg'], true)) {
                throw new \UnexpectedValueException('Unsupported voice note format.');
            }
            $text = app(\App\Services\AI\CaseTranscription::class)->transcribe($bytes, $mime, $this->locale);
        } catch (\Exception $e) {
            // Telegram download URLs may contain the bot token. Log only the exception type.
            \Illuminate\Support\Facades\Log::warning('Chat voice transcription failed', ['type' => $e::class]);
            $this->send($this->t('wizard.voice_retry'));

            return true;
        }
        if (mb_strlen($text) > 4000) {
            $this->send($this->t('wizard.voice_retry'));

            return true;
        }
        foreach (mb_str_split($text, 2000) as $part) {
            $this->send(Fmt::h($part));
        }

        return $this->wizardDescription($text);
    }

    private function wizardDescription(string $text): bool
    {
        if (mb_strlen($text) < 5) {
            $this->send($this->t('wizard.too_short'));

            return true;
        }
        $this->putDraft(['text' => mb_substr(strip_tags($text), 0, 4000)]);
        $this->session->get('draft.editing') ? $this->showConfirm() : $this->askAffected();

        return true;
    }

    private function askAffected(): void
    {
        $this->session->transition('rpt_affected');
        $this->send($this->t('wizard.affected'), [[['text' => $this->t('btn.skip'), 'callback_data' => 'rpt:skip_affected']]]);
    }

    private function wizardAffected(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        $this->putDraft(['affected' => mb_substr(strip_tags($text), 0, 60)]);
        $this->session->get('draft.editing') ? $this->showConfirm() : $this->askWhen();

        return true;
    }

    private function askWhen(): void
    {
        $this->session->transition('rpt_when');
        $b = fn ($k, $d) => ['text' => $this->t('btn.'.$k), 'callback_data' => "rpt:when:{$d}"];
        $this->send($this->t('wizard.when'), [[$b('today', 0), $b('yesterday', 1)], [$b('this_week', 7), $b('older', 30)]]);
    }

    private function wizardWhen(int $days): ?string
    {
        $key = match ($days) { 0 => 'today', 1 => 'yesterday', 7 => 'this_week', default => 'older' };
        $this->putDraft([
            'observed_key' => $key,
            'observed_text' => BotText::t('btn.'.$key, 'en'),
            'observed_at' => in_array($days, [0, 1], true) ? now()->subDays($days)->toIso8601String() : null,
        ]);

        if ($this->session->get('draft.editing')) {
            return $this->showConfirm();
        }
        $this->askEvidence();

        return null;
    }

    private function askEvidence(): void
    {
        $this->session->transition('rpt_evidence');
        $this->send($this->t('wizard.evidence', ['max' => config('cohers.telegram.max_photos')]), [[
            ['text' => $this->t('btn.done'), 'callback_data' => 'rpt:evidence_done'],
            ['text' => $this->t('btn.skip'), 'callback_data' => 'rpt:evidence_done'],
        ]]);
    }

    private function wizardEvidence(array $msg): bool
    {
        $max = (int) config('cohers.telegram.max_photos');

        if (! empty($msg['photo'])) {
            $photos = $this->session->get('draft.photos', []);
            if (count($photos) >= $max) {
                $this->send($this->t('wizard.photo_limit', ['max' => $max]));

                return true;
            }
            $best = collect($msg['photo'])
                ->filter(fn ($p) => ($p['file_size'] ?? 0) <= config('cohers.telegram.max_photo_bytes'))
                ->sortByDesc('file_size')->first();
            if ($best) {
                $photos[] = $best['file_id'];
                $this->putDraft(['photos' => $photos]);
                $this->send($this->t('wizard.photo_received', ['n' => count($photos), 'max' => $max]), [[
                    ['text' => $this->t('btn.done'), 'callback_data' => 'rpt:evidence_done'],
                ]]);
            }

            return true;
        }

        if (isset($msg['location']['latitude'], $msg['location']['longitude'])) {
            $lat = (float) $msg['location']['latitude'];
            $lng = (float) $msg['location']['longitude'];
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                $this->putDraft(['lat' => $lat, 'lng' => $lng]);
                $this->send($this->t('wizard.pin_received'), [[['text' => $this->t('btn.done'), 'callback_data' => 'rpt:evidence_done']]]);
            }

            return true;
        }

        $this->askEvidence();

        return true;
    }

    private function showConfirm(): ?string
    {
        $d = $this->session->get('draft', []);
        if (empty($d['village_id'])) {
            $this->showUnits('rpt', 'root');

            return null;
        }
        if (empty($d['text'])) {
            $this->session->transition('rpt_desc');
            $this->send($this->t('wizard.describe'));

            return null;
        }

        $this->session->transition('rpt_confirm');
        $village = GeographicUnit::find($d['village_id']);
        $none = $this->t('wizard.none');
        $summary = $this->t('wizard.summary', [
            'location' => Fmt::h($village?->fullPath()),
            'description' => Fmt::h(Str::limit($d['text'], 600)),
            'affected' => Fmt::h($d['affected'] ?? $none),
            'when' => isset($d['observed_key']) ? $this->t('btn.'.$d['observed_key']) : $none,
            'photos' => count($d['photos'] ?? []),
            'pin' => ! empty($d['lat']) ? $this->t('wizard.pin_yes') : $none,
        ]);

        $this->send($summary, [
            [['text' => $this->t('btn.submit'), 'callback_data' => 'rpt:submit:'.$d['id']]],
            [['text' => $this->t('btn.edit'), 'callback_data' => 'rpt:edit'], ['text' => $this->t('btn.cancel'), 'callback_data' => 'rpt:cancel']],
        ]);

        return null;
    }

    private function submitDraft(string $draftId, int $messageId): ?string
    {
        $submitted = $this->session->get('last_submitted', []);
        if (isset($submitted[$draftId])) {
            $this->send($this->t('wizard.already_submitted', ['code' => $submitted[$draftId]]));

            return null;
        }

        $d = $this->session->get('draft', []);
        if ($this->session->state !== 'rpt_confirm' || ($d['id'] ?? null) !== $draftId) {
            $this->send($this->t('wizard.expired'));

            return null;
        }

        try {
            $report = $this->reports->create($this->user, [
                'idempotency_key' => 'tg:'.$draftId,
                'geographic_unit_id' => $d['village_id'],
                'original_text' => $d['text'],
                'affected_count' => $d['affected'] ?? null,
                'observed_text' => $d['observed_text'] ?? null,
                'observed_at' => $d['observed_at'] ?? null,
                'latitude' => $d['lat'] ?? null,
                'longitude' => $d['lng'] ?? null,
                'photo_file_ids' => $d['photos'] ?? [],
                'language' => $this->locale,
                'channel' => 'telegram',
            ]);
        } catch (ValidationException $e) {
            $this->send('⚠️ '.Fmt::h(collect($e->errors())->flatten()->first()));

            return null;
        }

        $submitted[$draftId] = $report->reference_code;
        $this->resetSession(['last_submitted' => array_slice($submitted, -5, 5, true)]);

        if ($messageId) {
            $this->tg->editMessage($this->chatId, $messageId, '✅ '.$report->reference_code, []);
        }
        $this->send($this->t('wizard.submitted', ['code' => $report->reference_code]), null, $this->homeKeyboard());

        return '✅';
    }

    // ---------------------------------------------------------------- reporter tools

    private function myReports(): void
    {
        $reports = Report::where('reporter_id', $this->user->id)->latest('submitted_at')->limit(10)->get();
        if ($reports->isEmpty()) {
            $this->home($this->t('reporter.no_reports'));

            return;
        }
        $lines = $reports->map(fn ($r) => $this->t('reporter.report_line', [
            'code' => $r->reference_code,
            'date' => Fmt::dt($r->submitted_at, 'd/m/Y'),
            'status' => $r->reporterStatusLabel($this->locale),
        ]))->implode("\n");
        $this->send($this->t('reporter.my_reports_title')."\n\n".$lines, null, $this->homeKeyboard());
    }

    private function askTrack(): void
    {
        $this->session->transition('track_code');
        $this->send($this->t('reporter.track_prompt'));
    }

    private function trackReport(string $text): bool
    {
        $code = strtoupper(trim($text));
        // Ownership is always checked: a code alone never reveals someone else's report.
        $report = Report::with('location')->where('reference_code', $code)->where('reporter_id', $this->user->id)->first();
        $this->resetSession();

        if (! $report) {
            $this->home($this->t('reporter.track_not_found'));

            return true;
        }
        $this->send($this->t('reporter.track_result', [
            'code' => $report->reference_code,
            'status' => $report->reporterStatusLabel($this->locale),
            'location' => Fmt::h($report->location?->fullPath()),
            'date' => Fmt::dt($report->submitted_at),
        ]), null, $this->homeKeyboard());

        return true;
    }

    private function myPoints(): void
    {
        if (! SystemSetting::get_value('incentives_enabled', false)) {
            $this->home($this->t('reporter.points_disabled'));

            return;
        }
        $points = (int) IncentiveTransaction::where('user_id', $this->user->id)->sum('points');
        $this->home($this->t('reporter.points', ['points' => $points]));
    }

    private function reporterReplyStart(int $requestId): ?string
    {
        $req = ReportInformationRequest::with('report')->find($requestId);
        if (! $req || $req->report->reporter_id !== $this->user->id) {
            throw new AuthorizationException;
        }
        if ($req->status !== 'OPEN') {
            $this->send($this->t('officer.already'));

            return null;
        }
        $this->session->transition('info_reply', ['req_id' => $req->id]);
        $this->send($this->t('reporter.info_reply_prompt'));

        return null;
    }

    private function reporterAnswer(string $text): bool
    {
        if (mb_strlen($text) < 1) {
            return false;
        }
        $req = ReportInformationRequest::find($this->session->get('req_id'));
        $this->resetSession();
        if (! $req) {
            return false;
        }
        $this->verify->answerInformation($req, $this->user, mb_substr(strip_tags($text), 0, 2000));
        $this->home($this->t('reporter.info_thanks'));

        return true;
    }

    // ---------------------------------------------------------------- officer flows

    private function officerList(array $statuses): void
    {
        if (! $this->user->isFocalPoint()) {
            $this->send($this->t('unauthorized'));

            return;
        }
        $items = ReportAssignment::with('report')
            ->where('focal_point_user_id', $this->user->id)
            ->whereIn('assignment_status', $statuses)
            ->orderBy('ack_due_at')->limit(20)->get();

        if ($items->isEmpty()) {
            $this->home($this->t('officer.no_assignments'));

            return;
        }

        $rows = $items->map(fn ($a) => [[
            'text' => ($a->isOverdue() ? '⏰ ' : '').$a->report->reference_code.' · '.($a->report->priority?->value ?? '—').' · '.$a->assignment_status->value,
            'callback_data' => "a:view:{$a->id}",
        ]])->all();
        $this->send($this->t('officer.assignments_title'), $rows);
    }

    private function loadAssignment(int $id): ReportAssignment
    {
        if (! $this->user->isFocalPoint()) {
            throw new AuthorizationException;
        }
        $a = ReportAssignment::with('report.category', 'report.location')->find($id);
        if (! $a) {
            throw new AuthorizationException;
        }

        return $a;
    }

    private function officerAction(string $action, int $id): ?string
    {
        $a = $this->loadAssignment($id);
        $code = $a->report->reference_code;

        switch ($action) {
            case 'view':
                if ($a->focal_point_user_id !== $this->user->id) {
                    throw new AuthorizationException;
                }
                [$text, $kb] = BotMessages::assignmentAlert($a, $this->locale);
                $this->send($text."\n\n<b>".$a->assignment_status->value.'</b>', $a->isActive() ? $kb : null);

                return null;
            case 'ack':
                $new = $this->verify->acknowledge($a, $this->user);
                $this->send($new ? $this->t('officer.acknowledged', ['code' => $code]) : $this->t('officer.already'));

                return $new ? '✅' : null;
            case 'ver':
                $new = $this->verify->startVerification($a, $this->user);
                $this->send($new ? $this->t('officer.verification_started', ['code' => $code]) : $this->t('officer.already'),
                    $new ? [[['text' => $this->t('btn.findings'), 'callback_data' => "a:fnd:{$a->id}"]]] : null);

                return null;
            case 'info':
                $this->verify->authorize($a, $this->user);
                $this->session->transition('o_info', ['aid' => $a->id]);
                $this->send($this->t('officer.ask_question', ['code' => $code]));

                return null;
            case 'esc':
                $this->verify->authorize($a, $this->user);
                $this->session->transition('o_esc', ['aid' => $a->id]);
                $this->send($this->t('officer.ask_escalation', ['code' => $code]));

                return null;
            case 'fnd':
                $this->verify->authorize($a, $this->user);
                $this->session->transition('o_fnd_text', ['aid' => $a->id, 'fnd' => []]);
                $this->send($this->t('officer.ask_findings', ['code' => $code]));

                return null;
        }

        return null;
    }

    private function contextAssignment(): ?ReportAssignment
    {
        $id = (int) $this->session->get('aid');

        return $id ? $this->loadAssignment($id) : null;
    }

    private function officerQuestion(string $text): bool
    {
        if (! $a = $this->contextAssignment()) {
            return false;
        }
        try {
            $this->verify->requestInformation($a, $this->user, mb_substr(strip_tags($text), 0, 1000));
        } catch (AuthorizationException) {
            $this->resetSession();
            $this->send($this->t('unauthorized'));

            return true;
        }
        $this->resetSession();
        $this->home($this->t('officer.question_sent'));

        return true;
    }

    private function officerEscalate(string $text): bool
    {
        if (! $a = $this->contextAssignment()) {
            return false;
        }
        try {
            $this->verify->escalate($a, $this->user, mb_substr(strip_tags($text), 0, 2000));
        } catch (AuthorizationException) {
            $this->resetSession();
            $this->send($this->t('unauthorized'));

            return true;
        } catch (InvalidArgumentException $e) {
            $this->send('⚠️ '.Fmt::h($e->getMessage()));

            return true;
        }
        $this->resetSession();
        $this->home($this->t('officer.escalated', ['code' => $a->report->reference_code]));

        return true;
    }

    private function officerFindingsText(array $msg): bool
    {
        if (! $a = $this->contextAssignment()) {
            return false;
        }

        if (! empty($msg['photo'])) {
            $best = collect($msg['photo'])->sortByDesc('file_size')->first();
            $this->verify->attachEvidence($a, $this->user, $best['file_id']);
            $this->send($this->t('officer.evidence_saved'));
            $caption = trim((string) ($msg['caption'] ?? ''));
            if ($caption === '') {
                return true;
            }
            $msg['text'] = $caption;
        }

        $text = trim((string) ($msg['text'] ?? ''));
        if (mb_strlen($text) < 5) {
            $this->send($this->t('wizard.too_short'));

            return true;
        }

        $this->session->transition('o_fnd_outcome', ['fnd' => ['text' => mb_substr(strip_tags($text), 0, 4000)]]);
        $this->send($this->t('officer.ask_outcome'), [[
            ['text' => $this->t('btn.verified'), 'callback_data' => 'f:out:VERIFIED'],
            ['text' => $this->t('btn.rejected'), 'callback_data' => 'f:out:REJECTED'],
            ['text' => $this->t('btn.duplicate'), 'callback_data' => 'f:out:DUPLICATE'],
        ]]);

        return true;
    }

    private function findingsCallback(array $parts): ?string
    {
        $a = $this->contextAssignment();
        if (! $a || ! str_starts_with($this->session->state, 'o_fnd')) {
            $this->send($this->t('wizard.expired'));

            return null;
        }
        $this->verify->authorize($a, $this->user);
        $fnd = $this->session->get('fnd', []);

        switch ($parts[1] ?? '') {
            case 'out':
                $outcome = VerificationOutcome::tryFrom($parts[2] ?? '');
                if (! $outcome) {
                    return null;
                }
                $fnd['outcome'] = $outcome->value;
                if ($outcome === VerificationOutcome::DUPLICATE) {
                    $this->session->transition('o_fnd_dup', ['fnd' => $fnd]);
                    $suggest = $a->report->possibleDuplicateOf;
                    $this->send($this->t('officer.ask_duplicate'), $suggest ? [[[
                        'text' => $this->t('officer.use_suggested', ['code' => $suggest->reference_code]),
                        'callback_data' => "f:dup:{$suggest->id}",
                    ]]] : null);

                    return null;
                }
                $this->session->put(['fnd' => $fnd])->save();
                $this->askFollowUp();

                return null;
            case 'dup':
                $other = Report::find((int) ($parts[2] ?? 0));

                return $this->setDuplicate($a, $other, $fnd);
            case 'fu':
                $this->verify->submitFindings(
                    $a, $this->user,
                    VerificationOutcome::from($fnd['outcome'] ?? 'VERIFIED'),
                    $fnd['text'] ?? '',
                    ($parts[2] ?? '0') === '1',
                    isset($fnd['dup_id']) ? Report::find($fnd['dup_id']) : null,
                );
                $this->resetSession();
                $this->home($this->t('officer.findings_saved', ['code' => $a->report->reference_code, 'outcome' => $fnd['outcome']]));

                return '✅';
        }

        return null;
    }

    private function officerDuplicateCode(string $text): bool
    {
        if (! $a = $this->contextAssignment()) {
            return false;
        }
        $other = Report::where('reference_code', strtoupper(trim($text)))->first();
        $this->setDuplicate($a, $other, $this->session->get('fnd', []));

        return true;
    }

    private function setDuplicate(ReportAssignment $a, ?Report $other, array $fnd): ?string
    {
        if (! $other || $other->id === $a->report_id || $other->district_id !== $a->report->district_id) {
            $this->send($this->t('officer.duplicate_not_found'));

            return null;
        }
        $fnd['dup_id'] = $other->id;
        $this->session->transition('o_fnd_fu', ['fnd' => $fnd]);
        $this->askFollowUp();

        return null;
    }

    private function askFollowUp(): void
    {
        $this->session->transition('o_fnd_fu');
        $this->send($this->t('officer.ask_follow_up'), [[
            ['text' => $this->t('btn.yes'), 'callback_data' => 'f:fu:1'],
            ['text' => $this->t('btn.no'), 'callback_data' => 'f:fu:0'],
        ]]);
    }
}
