<?php

namespace App\Http\Controllers;

use App\Models\HealthReview;
use App\Models\Report;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HealthReviewController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $area = $user->areaScope();
        $reviews = HealthReview::with('report.commune')->where('reviewer_id', $user->id)
            ->whereHas('report', fn ($q) => $q->where(fn ($location) => $location
                ->whereIn('district_id', $area['districts'])->orWhereIn('commune_id', $area['communes'])))
            ->orderByRaw('case when completed_at is null then 0 else 1 end')->latest('id')->paginate(20);

        return view('health.index', compact('reviews'));
    }

    public function refer(Request $request, Report $report)
    {
        $this->authorize('triage', $report);
        $data = $request->validate(['reviewer_id' => 'required|integer', 'reason' => 'required|string|max:500']);
        DB::transaction(function () use ($report, $data, $request) {
            $report = Report::lockForUpdate()->findOrFail($report->id);
            $this->authorize('triage', $report);
            if (! HealthReview::eligibleReviewers($report)->contains('id', $data['reviewer_id'])) {
                throw ValidationException::withMessages(['reviewer_id' => 'Choose an active reviewer covering this location.']);
            }
            $review = HealthReview::where('report_id', $report->id)->lockForUpdate()->firstOrNew();
            if ($review->completed_at) {
                throw ValidationException::withMessages(['reviewer_id' => 'This review is complete.']);
            }
            if ($review->reviewer_id != $data['reviewer_id'] || $review->reason !== $data['reason']) {
                $review->acknowledged_at = null;
            }
            $review->fill($data + ['report_id' => $report->id])->save();
            $this->audit->record('health_review.referred', $report, $request->user(), null, null, ['reviewer_id' => $review->reviewer_id]);
        });

        return back()->with('status', 'Health Center referral saved.');
    }

    public function acknowledge(Request $request, Report $report)
    {
        return $this->recordResponse($request, $report, false);
    }

    public function complete(Request $request, Report $report)
    {
        return $this->recordResponse($request, $report, true);
    }

    private function recordResponse(Request $request, Report $report, bool $complete)
    {
        $user = $request->user();
        abort_unless($user->coversArea($report->district_id, $report->commune_id), 403);
        DB::transaction(function () use ($user, $request, $report, $complete) {
            $review = HealthReview::where('report_id', $report->id)->where('reviewer_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($complete) {
                abort_unless($review->acknowledged_at, 409, 'Acknowledge the referral first.');
                if ($review->completed_at) {
                    return;
                }
                $data = $request->validate(['findings' => 'required|string|max:2000']);
                $review->update($data + ['completed_at' => now()]);
                $this->audit->record('health_review.completed', $report, $user);
            } elseif (! $review->acknowledged_at) {
                $review->update(['acknowledged_at' => now()]);
                $this->audit->record('health_review.acknowledged', $report, $user);
            }
        });

        return redirect()->route('health.index')->with('status', $complete ? 'Review completed.' : 'Referral acknowledged.');
    }
}
