<?php

namespace App\Http\Controllers\District;

use App\Enums\Priority;
use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\RouteReportJob;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\ReportCategory;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Escalation\EscalationService;
use App\Services\Reporting\ReportDuplicateService;
use App\Services\Reporting\ReportService;
use App\Services\Routing\ReportRoutingService;
use App\Support\Fmt;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private ReportRoutingService $routing,
        private ReportService $reports,
        private EscalationService $escalations,
        private ReportDuplicateService $duplicates,
        private AuditService $audit,
    ) {}

    private function query(Request $request)
    {
        $f = DashboardController::filters($request);

        return Report::visibleTo($request->user())
            ->with('category', 'location', 'district', 'activeAssignment.officer')
            ->withCount('attachments')
            ->when($f['district_id'] ?? null, fn ($q, $v) => $q->where('district_id', $v))
            ->when($f['commune_id'] ?? null, fn ($q, $v) => $q->where('commune_id', $v))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($f['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->escalation, fn ($q, $v) => $q->where('escalation_state', $v))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('submitted_at', '>=', \Carbon\Carbon::parse($v, config('cohers.display_timezone'))->startOfDay()->utc()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('submitted_at', '<=', \Carbon\Carbon::parse($v, config('cohers.display_timezone'))->endOfDay()->utc()))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('reference_code', 'like', "%{$v}%")->orWhere('original_text', 'like', "%{$v}%")))
            ->latest('submitted_at');
    }

    public function index(Request $request)
    {
        return view('district.reports.index', [
            'reports' => $this->query($request)->paginate(25)->withQueryString(),
            'categories' => ReportCategory::orderBy('sort')->get(),
            'districts' => DashboardController::districtOptions($request),
            'statuses' => ReportStatus::cases(),
            'priorities' => Priority::cases(),
        ]);
    }

    private function load(Report $report): Report
    {
        return $report->load([
            'category', 'location.parent.parent', 'district', 'reporter', 'attachments',
            'latestTriage', 'triages', 'assignments.officer', 'verifications.verifier',
            'histories.actor', 'escalations.opener', 'escalations.reviewer',
            'informationRequests.requester', 'closures.closer', 'closures.reopener',
            'incidents.reports', 'possibleDuplicateOf',
        ]);
    }

    public function show(Request $request, Report $report)
    {
        $this->authorize('view', $report);
        $this->audit->record('report.viewed', $report);

        return view('district.reports.show', [
            'report' => $this->load($report),
            'officers' => $this->routing->eligibleOfficers($report),
            'categories' => ReportCategory::where('is_active', true)->orderBy('sort')->get(),
            'decisions' => EscalationService::DECISIONS,
            'closeBlocker' => $this->escalations->canClose($report),
        ]);
    }

    /** Side-drawer partial with history timeline (dashboard UI guidance). */
    public function drawer(Request $request, Report $report)
    {
        $this->authorize('view', $report);

        return view('district.reports._drawer', ['report' => $this->load($report)]);
    }

    public function reassign(Request $request, Report $report)
    {
        $this->authorize('reassign', $report);
        $data = $request->validate([
            'officer_id' => 'required|exists:users,id',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        $officer = User::findOrFail($data['officer_id']);
        abort_unless($this->routing->eligibleOfficers($report)->contains('id', $officer->id), 422, 'Officer is not eligible for this district.');

        $reason = $request->user()->isDistrictOfficer() ? $data['reason'] : 'Admin correction: '.$data['reason'];
        $this->routing->reassign($report, $officer, $request->user(), $reason);

        return back()->with('status', "Reassigned to {$officer->name}.");
    }

    /** Manual triage: set category/priority with reason, then route. */
    public function triage(Request $request, Report $report)
    {
        $this->authorize('triage', $report);
        $data = $request->validate([
            'category_id' => 'required|exists:report_categories,id',
            'priority' => ['required', Rule::enum(Priority::class)],
            'reason' => 'required|string|min:5|max:1000',
            'route' => 'nullable|boolean',
        ]);

        $this->reports->reclassify($report, (int) $data['category_id'], $data['priority'], $request->user(), $data['reason']);

        if ($data['priority'] === Priority::CRITICAL->value) {
            $this->escalations->open($report, \App\Enums\EscalationTrigger::CRITICAL, 'Manually classified CRITICAL: '.$data['reason'], $request->user(), 'web');
        }

        if ($request->boolean('route') && ! $report->activeAssignment()->exists()) {
            RouteReportJob::dispatchSync($report->id);
        }

        return back()->with('status', 'Classification updated.');
    }

    public function linkIncident(Request $request, Report $report)
    {
        $this->authorize('linkIncident', $report);
        $data = $request->validate(['other_code' => 'required|string', 'reason' => 'nullable|string|max:500']);
        $other = Report::where('reference_code', strtoupper(trim($data['other_code'])))->first();

        if (! $other || $other->id === $report->id || ! $request->user()->can('view', $other)) {
            return back()->withErrors(['other_code' => 'Report not found in your scope.']);
        }
        $incident = $this->duplicates->link($report, $other, $request->user(), $data['reason'] ?? null);

        return back()->with('status', "Linked under incident {$incident->code}. Both original reports are preserved.");
    }

    public function close(Request $request, Report $report)
    {
        $this->authorize('close', $report);
        $data = $request->validate([
            'outcome_summary' => 'required|string|min:10|max:4000',
            'response_steps' => 'nullable|string|max:4000',
            'reporter_message' => 'nullable|string|max:1000',
        ]);

        try {
            $this->escalations->close($report, $request->user(), $data['outcome_summary'], $data['response_steps'] ?? null, $data['reporter_message'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', 'Case closed.');
    }

    public function reopen(Request $request, Report $report)
    {
        $this->authorize('close', $report);
        $data = $request->validate(['reason' => 'required|string|min:5|max:2000']);

        try {
            $this->escalations->reopen($report, $request->user(), $data['reason']);
        } catch (DomainException $e) {
            return back()->withErrors(['reopen' => $e->getMessage()]);
        }

        return back()->with('status', 'Case reopened.');
    }

    public function attachment(Request $request, ReportAttachment $attachment)
    {
        $this->authorize('view', $attachment->report);
        abort_unless($attachment->path && Storage::disk($attachment->disk)->exists($attachment->path), 404);
        $this->audit->record('attachment.viewed', $attachment);

        return Storage::disk($attachment->disk)->response($attachment->path, null, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Scoped, logged CSV export with no reporter identifiers. */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($request);
        $count = (clone $query)->count();
        $this->audit->record('reports.exported', null, $request->user(), null, null,
            ['filters' => $request->query(), 'rows' => $count]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['reference_code', 'submitted_at_local', 'district', 'commune', 'village', 'category', 'subcategory',
                'priority', 'status', 'escalation_state', 'assigned_officer', 'affected', 'observed', 'description']);
            $query->with('commune')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->reference_code, Fmt::dt($r->submitted_at, 'Y-m-d H:i'), $r->district?->name_en, $r->commune?->name_en,
                        $r->location?->name_en, $r->category?->name_en, $r->subcategory, $r->priority?->value, $r->status->value,
                        $r->escalation_state->value, $r->activeAssignment?->officer?->name, $r->affected_count, $r->observed_text,
                        $r->original_text,
                    ]);
                }
            });
            fclose($out);
        }, 'cohers-reports-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
