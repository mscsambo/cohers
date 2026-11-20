<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Priority;
use App\Enums\RoleName;
use App\Enums\Sector;
use App\Http\Controllers\Controller;
use App\Models\FocalPointAssignment;
use App\Models\GeographicUnit;
use App\Models\ReportCategory;
use App\Models\RoutingRule;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Focal Point Router: coverage view of primary/backup mappings and the safety rules.
 * Mappings are assigned on each officer's account page (Admin\UserController).
 */
class RoutingController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $districtId = GeographicUnit::idFromUuid($request->query('district'));

        $mappings = FocalPointAssignment::with('user', 'geographicUnit.parent')
            ->when($request->sector, fn ($q, $v) => $q->where('sector', $v))
            ->when($districtId, fn ($q, $v) => $q->whereHas('geographicUnit', fn ($g) => $g->where(fn ($x) => $x
                ->where('geographic_units.id', $v)->orWhere('geographic_units.parent_id', $v)
                ->orWhereHas('parent', fn ($p) => $p->where('parent_id', $v)))))
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('geographic_unit_id')->orderBy('sector')->orderBy('level')
            ->paginate(40)->withQueryString();

        return view('admin.routing', [
            'mappings' => $mappings,
            'officers' => User::withRole(RoleName::FOCAL_POINT)->where('account_status', 'active')->orderBy('name')->get(),
            'districts' => GeographicUnit::where('type', 'district')->orderBy('code')->get(),
            'rules' => RoutingRule::with('category')->orderBy('rule_type')->get(),
            'categories' => ReportCategory::orderBy('sort')->get(),
            'sectors' => array_filter(Sector::cases(), fn ($s) => $s !== Sector::UNKNOWN),
            'priorities' => Priority::cases(),
        ]);
    }

    public function deactivateMapping(Request $request, FocalPointAssignment $mapping)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        $mapping->update(['is_active' => false, 'effective_to' => now()->toDateString()]);
        $this->audit->record('routing.mapping_deactivated', $mapping, $request->user(), $request->input('reason'));

        return back()->with('status', 'Mapping deactivated.');
    }

    public function storeRule(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'rule_type' => 'required|in:keyword_priority,category_priority',
            'pattern' => 'nullable|required_if:rule_type,keyword_priority|string|max:255',
            'category_id' => 'nullable|required_if:rule_type,category_priority|exists:report_categories,id',
            'priority' => ['required', Rule::enum(Priority::class)],
            'escalate_district' => 'nullable|boolean',
        ]);
        $data['escalate_district'] = $request->boolean('escalate_district');
        $rule = RoutingRule::create($data + ['is_active' => true]);
        $this->audit->record('routing.rule_created', $rule, $request->user(), null, null, $data);

        return back()->with('status', 'Rule added.');
    }

    public function toggleRule(Request $request, RoutingRule $rule)
    {
        $rule->update(['is_active' => ! $rule->is_active]);
        $this->audit->record('routing.rule_toggled', $rule, $request->user(), null, null, ['is_active' => $rule->is_active]);

        return back()->with('status', 'Rule updated.');
    }
}
