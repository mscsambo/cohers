<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Priority;
use App\Enums\Sector;
use App\Http\Controllers\Controller;
use App\Models\AiTriage;
use App\Models\ReportCategory;
use App\Models\SystemSetting;
use App\Services\AI\GeminiTriageService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AI & Categories: taxonomy, Gemini integration settings and safe fallbacks. */
class CategoryController extends Controller
{
    public function __construct(private AuditService $audit, private GeminiTriageService $ai) {}

    public function index()
    {
        $recent = AiTriage::with('report:id,reference_code')->latest('id')->limit(25)->get();

        return view('admin.categories', [
            'categories' => ReportCategory::orderBy('sort')->get(),
            'sectors' => Sector::cases(),
            'priorities' => Priority::cases(),
            'recent' => $recent,
            'stats' => AiTriage::where('created_at', '>=', now()->subDays(7))->get()->groupBy('outcome')->map->count(),
            'driver' => $this->ai->driver(),
            'minConfidence' => SystemSetting::get_value('ai.min_confidence', config('cohers.ai.min_confidence')),
            'hasKey' => (bool) config('cohers.ai.gemini_api_key'),
            'model' => config('cohers.ai.gemini_model'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $cat = ReportCategory::create($data);
        $this->audit->record('category.created', $cat, $request->user(), null, null, $data);

        return back()->with('status', 'Category added.');
    }

    public function update(Request $request, ReportCategory $category)
    {
        $data = $this->validated($request, $category);
        $old = $category->only(array_keys($data));
        $category->update($data);
        $this->audit->record('category.updated', $category, $request->user(), null, $old, $data);

        return back()->with('status', 'Category saved.');
    }

    private function validated(Request $request, ?ReportCategory $cat = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z_]+$/', Rule::unique('report_categories', 'code')->ignore($cat?->id)],
            'name_en' => 'required|string|max:120',
            'name_km' => 'required|string|max:120',
            'sector' => ['required', Rule::enum(Sector::class)],
            'default_priority' => ['required', Rule::enum(Priority::class)],
            'sort' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['sort'] = (int) ($data['sort'] ?? 0);

        return $data;
    }

    public function test(Request $request)
    {
        $data = $request->validate(['text' => 'required|string|min:3|max:2000']);

        return back()->withInput()->with('preview', $this->ai->preview($data['text']));
    }
}
