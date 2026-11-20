<?php

namespace App\Http\Controllers\District;

use App\Http\Controllers\Controller;
use App\Models\GeographicUnit;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Services\Analytics\ReportAnalyticsService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private ReportAnalyticsService $analytics) {}

    /**
     * Report filters from the query string. District, commune and category arrive as
     * public UUIDs and are resolved here to internal ids; unknown values are dropped.
     */
    public static function filters(Request $request): array
    {
        $f = array_filter($request->only(['priority', 'from', 'to']));
        foreach (['district_id' => GeographicUnit::class, 'commune_id' => GeographicUnit::class, 'category_id' => ReportCategory::class] as $key => $model) {
            if ($id = $model::idFromUuid($request->query($key))) {
                $f[$key] = $id;
            }
        }

        return $f;
    }

    public static function districtOptions(Request $request)
    {
        $user = $request->user();

        return GeographicUnit::where('type', 'district')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('id', $user->districtIds() ?: [0]))
            ->orderBy('code')->get();
    }

    public function overview(Request $request)
    {
        $user = $request->user();
        $f = self::filters($request);

        return view('district.overview', [
            'kpi' => $this->analytics->overview($user, $f),
            'byStatus' => $this->analytics->byStatus($user, $f),
            'byPriority' => $this->analytics->byPriority($user, $f),
            'byCategory' => $this->analytics->byCategory($user, $f),
            'byArea' => $this->analytics->byArea($user, $user->isAdmin() || count($user->districtIds()) > 1 ? 'district_id' : 'commune_id', $f),
            'daily' => $this->analytics->dailySeries($user, 30, $f),
            'critical' => $this->analytics->base($user, $f)->with('category', 'location')
                ->whereIn('priority', ['CRITICAL', 'HIGH'])->where('status', '!=', 'CLOSED')
                ->latest('submitted_at')->limit(8)->get(),
            'overdue' => $this->analytics->base($user, $f)->overdue()->with('activeAssignment.officer', 'location')
                ->where('status', '!=', 'CLOSED')->limit(8)->get(),
            'districts' => self::districtOptions($request),
            'categories' => ReportCategory::orderBy('sort')->get(),
            'f' => $f,
        ]);
    }

    public function workload(Request $request)
    {
        return view('district.workload', ['rows' => $this->analytics->workload($request->user())]);
    }

    public function map(Request $request)
    {
        return view('district.map', [
            'categories' => ReportCategory::orderBy('sort')->get(),
            'districts' => self::districtOptions($request),
            'f' => self::filters($request),
        ]);
    }

    public function mapData(Request $request)
    {
        return response()->json($this->analytics->mapData($request->user(), self::filters($request)));
    }

    public function analytics(Request $request)
    {
        $f = self::filters($request) + ['from' => $request->input('from', now()->subDays(90)->toDateString())];

        return view('district.analytics', [
            'a' => $this->analytics->analytics($request->user(), $f),
            'districts' => self::districtOptions($request),
            'categories' => ReportCategory::orderBy('sort')->get(),
            'f' => $f,
        ]);
    }

    public function communes(Request $request, GeographicUnit $district)
    {
        abort_unless($district->type === 'district', 404);

        return $district->children()->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_km']);
    }

    public function closures(Request $request)
    {
        $user = $request->user();
        $closable = Report::visibleTo($user)->with('category', 'location', 'verifications')
            ->whereIn('status', ['VERIFIED', 'REJECTED', 'DUPLICATE'])
            ->whereNotIn('escalation_state', ['OPEN', 'IN_REVIEW'])
            ->latest('updated_at')->paginate(20, ['*'], 'closable');

        $closed = Report::visibleTo($user)->with('category', 'location', 'closures.closer')
            ->where('status', 'CLOSED')->latest('closed_at')->paginate(15, ['*'], 'closed');

        return view('district.closures', compact('closable', 'closed'));
    }
}
