<?php

namespace App\Http\Controllers\District;

use App\Enums\EscalationState;
use App\Http\Controllers\Controller;
use App\Models\Escalation;
use App\Services\Analytics\ReportAnalyticsService;
use App\Services\Escalation\EscalationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EscalationController extends Controller
{
    public function __construct(private EscalationService $escalations, private ReportAnalyticsService $analytics) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $state = $request->input('state', 'open');

        $query = Escalation::with('report.category', 'report.location', 'report.activeAssignment.officer', 'reviewer', 'opener')
            ->whereHas('report', fn ($q) => $q->visibleTo($user))
            ->when($state === 'open', fn ($q) => $q->where('escalation_state', '!=', EscalationState::RESOLVED->value))
            ->when($state === 'resolved', fn ($q) => $q->where('escalation_state', EscalationState::RESOLVED->value))
            ->when($request->trigger, fn ($q, $v) => $q->where('trigger', $v))
            ->orderByRaw("CASE escalation_state WHEN 'OPEN' THEN 0 WHEN 'IN_REVIEW' THEN 1 ELSE 2 END")
            ->latest('opened_at');

        return view('district.escalations', [
            'escalations' => $query->paginate(25)->withQueryString(),
            'state' => $state,
            'decisions' => EscalationService::DECISIONS,
        ]);
    }

    public function review(Request $request, Escalation $escalation)
    {
        $this->authorize('reviewEscalation', $escalation->report);
        $this->escalations->startReview($escalation, $request->user());

        return back()->with('status', 'Escalation marked in review.');
    }

    public function resolve(Request $request, Escalation $escalation)
    {
        $this->authorize('reviewEscalation', $escalation->report);
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(EscalationService::DECISIONS))],
            'notes' => 'required|string|min:5|max:4000',
        ]);
        $this->escalations->resolve($escalation, $request->user(), $data['decision'], $data['notes']);

        return back()->with('status', 'District decision recorded.');
    }
}
