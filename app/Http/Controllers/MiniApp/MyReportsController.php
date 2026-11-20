<?php

namespace App\Http\Controllers\MiniApp;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\GeographicUnit;
use App\Models\Report;
use App\Models\SystemSetting;
use App\Services\Notification\NotificationService;
use App\Services\Reporting\ReportService;
use App\Support\BotText;
use App\Services\Verification\ReportVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * "My reports" for community reporters (Telegram Mini App): only their own
 * reports, in plain status words, with no triage or internal details.
 */
class MyReportsController extends Controller
{
    private function start(Request $request): void
    {
        abort_unless($request->user()->isActive(), 403);
        App::setLocale($request->user()->locale());
    }

    /** Someone else's report is "not found", never "forbidden". */
    private function own(Request $request, Report $report): void
    {
        abort_unless($report->reporter_id === $request->user()->id, 404);
    }

    public function index(Request $request)
    {
        $this->start($request);
        $mine = Report::where('reporter_id', $request->user()->id);
        $waiting = fn ($q) => $q->whereHas('informationRequests', fn ($i) => $i->where('status', 'OPEN'));
        $filter = in_array($request->query('filter'), ['open', 'waiting', 'closed'], true) ? $request->query('filter') : 'all';

        $reports = (clone $mine)
            ->when($filter === 'open', fn ($q) => $q->where('status', '!=', ReportStatus::CLOSED))
            ->when($filter === 'closed', fn ($q) => $q->where('status', ReportStatus::CLOSED))
            ->when($filter === 'waiting', $waiting)
            ->with('location')
            ->withCount(['informationRequests as open_questions' => fn ($q) => $q->where('status', 'OPEN')])
            ->latest('submitted_at')->latest('id')
            ->paginate(20)->withQueryString();

        return view('miniapp.index', [
            'reports' => $reports,
            'filter' => $filter,
            'counts' => [
                'open' => (clone $mine)->where('status', '!=', ReportStatus::CLOSED)->count(),
                'waiting' => $waiting(clone $mine)->count(),
                'closed' => (clone $mine)->where('status', ReportStatus::CLOSED)->count(),
            ],
        ]);
    }

    /** Provinces, districts and communes for the place picker (villages load per commune). */
    public static function geoFor(string $locale): array
    {
        $units = fn (string $type) => GeographicUnit::where('type', $type)->where('is_active', true)->orderBy('code')
            ->get(['id', 'parent_id', 'name_en', 'name_km'])
            ->map(fn ($u) => ['id' => $u->id, 'parent' => $u->parent_id, 'name' => $u->name($locale) ?: $u->name_en])->values();

        return ['province' => $units('province'), 'district' => $units('district'), 'commune' => $units('commune')];
    }

    /** Picker preset for one place: its province/district/commune/village ids and a label. */
    public static function pickerPreset(GeographicUnit $unit, string $locale): array
    {
        $path = [];
        $names = [];
        for ($u = $unit; $u; $u = $u->parent) {
            $path[$u->type] = $u->id;
            $names[] = $u->name($locale) ?: $u->name_en;
        }

        return ['id' => $unit->id, 'path' => $path, 'label' => $unit->name($locale) ?: $unit->name_en,
            'full' => implode(' › ', array_reverse($names))];
    }

    /** New report form: the same questions as the bot conversation, plus photos and an optional GPS pin. */
    public function create(Request $request)
    {
        $this->start($request);
        $locale = app()->getLocale();

        // Offer the saved village (shared with the bot), else the place of the last report.
        $saved = ReporterAccountController::savedVillage($request->user()->loadMissing('telegramAccount'));
        $place = $saved ?? Report::where('reporter_id', $request->user()->id)->latest('id')->first()?->location;

        return view('miniapp.create', [
            'geo' => static::geoFor($locale),
            'last' => $place ? static::pickerPreset($place, $locale) + ['saved' => (bool) $saved] : null,
            'maxPhotos' => (int) config('cohers.telegram.max_photos'),
        ]);
    }

    /** Villages of one commune, loaded when the reporter picks the commune. */
    public function villages(Request $request, GeographicUnit $commune)
    {
        abort_unless($commune->type === 'commune', 404);
        $locale = $request->user()->locale();

        return response()->json($commune->children()->where('is_active', true)->whereIn('type', ['village', 'zone'])->orderBy('code')
            ->get(['id', 'name_en', 'name_km'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name($locale) ?: $u->name_en]));
    }

    /** Opaque per-account tag the phone stores with saved reports (not the database id). */
    public static function queueOwner(\App\Models\User $user): string
    {
        return substr(hash_hmac('sha256', 'offline-queue:'.$user->id, (string) config('app.key')), 0, 24);
    }

    /** For the phone's offline queue: who is signed in, and a CSRF token that is valid now. */
    public function syncToken(Request $request)
    {
        $this->start($request);

        return response()->json(['user' => static::queueOwner($request->user()), 'token' => csrf_token()])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, ReportService $reports, NotificationService $notify)
    {
        $this->start($request);
        $max = (int) config('cohers.telegram.max_photos');
        $data = $request->validate([
            'geographic_unit_id' => 'required|integer',
            'original_text' => 'required|string|min:3|max:4000',
            'affected_count' => 'nullable|string|max:60',
            'observed' => 'nullable|in:today,yesterday,this_week,older',
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
            'idempotency_key' => 'required|uuid',
            // Set when the report was filled in without internet and sent later from the phone's queue.
            'captured_at' => 'nullable|date',
            'photos' => "array|max:{$max}",
            'photos.*' => 'file|mimetypes:image/jpeg,image/png,image/webp|max:'.intdiv((int) config('cohers.telegram.max_photo_bytes'), 1024),
        ]);
        $days = ['today' => 0, 'yesterday' => 1, 'this_week' => 7, 'older' => 30][$data['observed'] ?? ''] ?? null;
        // "Today"/"yesterday" mean the day it was written down, not the day it reached us.
        // A phone clock can be wrong, so only trust it within the last 30 days and never in the future.
        $capturedAt = now();
        if (! empty($data['captured_at'])) {
            $c = \Illuminate\Support\Carbon::parse($data['captured_at']);
            if ($c->lessThan(now()) && $c->greaterThan(now()->subDays(30))) {
                $capturedAt = $c;
            }
        }

        $report = $reports->create($request->user(), [
            'geographic_unit_id' => $data['geographic_unit_id'],
            'original_text' => $data['original_text'],
            'affected_count' => $data['affected_count'] ?? null,
            'observed_text' => isset($data['observed']) ? __('bot.btn.'.$data['observed'], [], 'en') : null,
            'observed_at' => in_array($days, [0, 1], true) ? $capturedAt->copy()->subDays($days)->toIso8601String() : null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'language' => $request->user()->locale(),
            'channel' => 'telegram',
            // A double tap or a retry after a lost connection never creates a second report.
            'idempotency_key' => 'miniapp:'.$data['idempotency_key'],
        ]);

        if ($report->wasRecentlyCreated) {
            // Like the bot: the place just used becomes the saved village.
            ReporterAccountController::botSession($request->user()->loadMissing('telegramAccount'))
                ?->put(['default_village_id' => $report->geographic_unit_id])->save();
            foreach (array_slice($request->file('photos', []), 0, $max) as $photo) {
                $reports->attachUploadedPhoto($report, $request->user(), $photo);
            }
            // Same confirmation the bot sends, so the code is also in the chat.
            $notify->toUser($request->user(), 'reporter.submitted',
                fn ($loc) => BotText::t('wizard.submitted', $loc, ['code' => $report->reference_code]), "submitted:{$report->id}", $report);
        }

        $request->session()->flash('status', __('bot.miniapp.submitted_ok', ['code' => $report->reference_code]));
        // The Mini App form posts in the background (keeps photos if something fails) and then opens this page.
        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('my.show', $report)]);
        }

        return redirect()->route('my.show', $report);
    }

    public function show(Request $request, Report $report)
    {
        $this->start($request);
        $this->own($request, $report);
        $report->load('location', 'histories', 'informationRequests', 'attachments', 'reporterUpdates', 'closures', 'activeAssignment.officer');

        $officer = $report->activeAssignment?->officer;
        $type = $officer?->officer_type ? __('bot.officer_type.'.$officer->officer_type->value) : null;
        $handledBy = $type ? ($officer && SystemSetting::get_value('reporter.share_officer_name', false)
            ? __('bot.miniapp.handled_by_name', ['name' => $officer->name, 'type' => $type])
            : __('bot.miniapp.handled_by', ['type' => $type])) : null;

        return view('miniapp.show', [
            'report' => $report,
            'events' => $this->timeline($report),
            'handledBy' => $handledBy,
            'openQuestion' => $report->informationRequests->firstWhere('status', 'OPEN'),
        ]);
    }

    public function answer(Request $request, Report $report, ReportVerificationService $verification)
    {
        $this->start($request);
        $this->own($request, $report);
        $data = $request->validate(['question' => 'required|integer', 'answer' => 'required|string|min:1|max:2000']);
        $question = $report->informationRequests()->findOrFail($data['question']);

        $sent = $verification->answerInformation($question, $request->user(), $data['answer']);

        return redirect()->route('my.show', $report)->with('status', __($sent ? 'bot.miniapp.answer_sent' : 'bot.miniapp.answer_closed'));
    }

    public function addUpdate(Request $request, Report $report, ReportService $reports)
    {
        $this->start($request);
        $this->own($request, $report);
        abort_if($report->status === ReportStatus::CLOSED, 422, __('bot.miniapp.closed_note'));
        $data = $request->validate(['body' => 'required|string|min:2|max:2000']);

        $reports->addReporterUpdate($report, $request->user(), $data['body']);

        return redirect()->route('my.show', $report)->with('status', __('bot.miniapp.update_sent'));
    }

    /** Reporter-safe progress: status words, questions and answers, their own additions, the outcome. */
    private function timeline(Report $report): array
    {
        $events = [['at' => $report->submitted_at ?? $report->created_at, 'kind' => 'status', 'title' => __('bot.status.received')]];
        $last = 'received';
        foreach ($report->histories as $h) {
            $key = ReportStatus::tryFrom($h->to_status)?->reporterKey();
            if ($key && $key !== $last) {
                $events[] = ['at' => $h->created_at, 'kind' => 'status', 'title' => __('bot.status.'.$key)];
                $last = $key;
            }
        }
        foreach ($report->informationRequests as $q) {
            $events[] = ['at' => $q->created_at, 'kind' => 'question', 'title' => __('bot.miniapp.officer_asked'), 'body' => $q->question];
            if ($q->responded_at) {
                $events[] = ['at' => $q->responded_at, 'kind' => 'answer', 'title' => __('bot.miniapp.you_answered'), 'body' => $q->response];
            }
        }
        foreach ($report->reporterUpdates as $u) {
            $events[] = ['at' => $u->created_at, 'kind' => 'update', 'title' => __('bot.miniapp.you_added'), 'body' => $u->body];
        }
        $closure = $report->status === ReportStatus::CLOSED ? $report->closures->whereNull('reopened_at')->sortByDesc('closed_at')->first() : null;
        if ($closure) {
            $outcome = match ($closure->previous_status) {
                ReportStatus::VERIFIED->value => 'verified',
                ReportStatus::DUPLICATE->value => 'duplicate',
                default => 'not_confirmed',
            };
            $events[] = ['at' => $closure->closed_at, 'kind' => 'outcome', 'title' => __('bot.miniapp.outcome'),
                'body' => ucfirst(__('bot.reporter.outcome.'.$outcome)), 'note' => $closure->reporter_message];
        }
        usort($events, fn ($a, $b) => ($a['at']?->timestamp ?? 0) <=> ($b['at']?->timestamp ?? 0));

        return $events;
    }
}
