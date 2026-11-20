<?php

namespace App\Http\Controllers\Field;

use App\Enums\AssignmentStatus;
use App\Enums\VerificationOutcome;
use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportAssignment;
use App\Services\Verification\ReportVerificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * "My field cases" for VHSG / CAO focal points: the web twin of the Telegram
 * buttons. Every action goes through ReportVerificationService, which re-checks
 * that this officer holds the active assignment.
 */
class FieldCaseController extends Controller
{
    public function __construct(private ReportVerificationService $verify) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $mine = ReportAssignment::with('report.category', 'report.location')
            ->where('focal_point_user_id', $user->id);

        $active = (clone $mine)->whereIn('assignment_status', AssignmentStatus::active())
            ->orderByRaw("CASE assignment_status WHEN 'PENDING' THEN 0 WHEN 'ACKNOWLEDGED' THEN 1 ELSE 2 END")
            ->orderBy('ack_due_at')->get();
        $recent = (clone $mine)->whereNotIn('assignment_status', AssignmentStatus::active())
            ->where('updated_at', '>=', now()->subDays(30))->latest('updated_at')->limit(20)->get();

        return view('field.index', [
            'active' => $active,
            'recent' => $recent,
            'kpi' => [
                'pending' => $active->where('assignment_status', AssignmentStatus::PENDING)->count(),
                'overdue' => $active->filter->isOverdue()->count(),
                'verifying' => $active->where('assignment_status', AssignmentStatus::VERIFYING)->count(),
                'done_30d' => $recent->where('assignment_status', AssignmentStatus::COMPLETED)->count(),
            ],
        ]);
    }

    public function show(Request $request, Report $report)
    {
        $this->authorize('view', $report);

        return view('field.show', [
            'report' => $report->load([
                'category', 'location.parent.parent', 'attachments', 'latestTriage', 'histories.actor',
                'escalations.opener', 'escalations.reviewer', 'informationRequests.requester',
                'verifications.verifier', 'closures.closer', 'closures.reopener', 'possibleDuplicateOf',
            ]),
            'assignment' => $this->myActiveAssignment($request, $report),
        ]);
    }

    public function acknowledge(Request $request, Report $report)
    {
        return $this->act($request, $report, fn ($a, $u) => $this->verify->acknowledge($a, $u, 'web')
            ? 'Acknowledged. Remember: acknowledged is not verified.' : 'Already acknowledged.');
    }

    public function startVerification(Request $request, Report $report)
    {
        return $this->act($request, $report, fn ($a, $u) => $this->verify->startVerification($a, $u, 'web')
            ? 'Field verification started.' : 'Verification was already started.');
    }

    public function requestInfo(Request $request, Report $report)
    {
        $data = $request->validate(['question' => 'required|string|min:3|max:1000']);

        return $this->act($request, $report, function ($a, $u) use ($data) {
            $this->verify->requestInformation($a, $u, $data['question'], 'web');

            return 'Question sent to the reporter in Telegram. You will see the answer here and in Telegram.';
        });
    }

    public function escalate(Request $request, Report $report)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:2000']);

        return $this->act($request, $report, function ($a, $u) use ($data) {
            $this->verify->escalate($a, $u, $data['reason'], 'web');

            return 'Escalated to the district officer.';
        });
    }

    public function findings(Request $request, Report $report)
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in([VerificationOutcome::VERIFIED->value, VerificationOutcome::REJECTED->value, VerificationOutcome::DUPLICATE->value])],
            'findings' => 'required|string|min:5|max:4000',
            'follow_up' => 'nullable|boolean',
            'duplicate_code' => 'nullable|required_if:outcome,DUPLICATE|string|max:30',
        ]);

        $duplicateOf = null;
        if ($data['outcome'] === VerificationOutcome::DUPLICATE->value) {
            $duplicateOf = Report::where('reference_code', strtoupper(trim($data['duplicate_code'])))
                ->where('district_id', $report->district_id)->where('id', '!=', $report->id)->first();
            if (! $duplicateOf) {
                return back()->withInput()->withErrors(['duplicate_code' => 'No other report with that code in the same district.']);
            }
        }

        return $this->act($request, $report, function ($a, $u) use ($request, $data, $duplicateOf) {
            $this->verify->submitFindings($a, $u, VerificationOutcome::from($data['outcome']), $data['findings'],
                $request->boolean('follow_up'), $duplicateOf, 'web');

            return 'Findings recorded. The district officer has been told the case is ready for review.';
        });
    }

    private function myActiveAssignment(Request $request, Report $report): ?ReportAssignment
    {
        return $report->assignments()
            ->where('focal_point_user_id', $request->user()->id)
            ->whereIn('assignment_status', AssignmentStatus::active())
            ->latest('id')->first();
    }

    /** Runs one officer action with the same authorization as the Telegram buttons. */
    private function act(Request $request, Report $report, callable $action)
    {
        $assignment = $this->myActiveAssignment($request, $report);
        abort_unless($assignment, 403, 'This case is not actively assigned to you.');

        try {
            $message = $action($assignment, $request->user());
        } catch (AuthorizationException) {
            abort(403, 'You are not authorized to act on this case.');
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()->route('field.cases.show', $report)->with('status', $message);
    }
}
