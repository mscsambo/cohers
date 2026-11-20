<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeographicUnit;
use App\Services\AuditService;
use App\Support\GazetteerImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Manage district/commune/village codes and hierarchy. */
class GeographyController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request)
    {
        $parent = $request->parent ? GeographicUnit::where('uuid', $request->parent)->firstOrFail() : null;

        $units = GeographicUnit::withCount('children')
            ->when($parent, fn ($q) => $q->where('parent_id', $parent->id), fn ($q) => $q->whereIn('type', ['province']))
            ->when($request->q, fn ($q, $v) => GeographicUnit::withCount('children')->where(fn ($w) => $w
                ->where('name_en', 'like', "%{$v}%")->orWhere('name_km', 'like', "%{$v}%")->orWhere('code', 'like', "{$v}%")))
            ->orderBy('code')->paginate(50)->withQueryString();

        return view('admin.geography', [
            'units' => $units,
            'parent' => $parent,
            'breadcrumb' => $parent ? array_reverse($parent->lineage()) : [],
            'types' => GeographicUnit::TYPES,
            'counts' => GeographicUnit::selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type'),
            'import' => $this->pendingImport($request),
        ]);
    }

    // ---- CSV import: upload → preview (nothing saved) → apply or cancel ----------

    private const IMPORT_DIR = 'private/geo-imports';

    private function importPath(string $token): string
    {
        return Storage::disk('local')->path(self::IMPORT_DIR.'/'.$token.'.csv');
    }

    /** The upload waiting for confirmation in this admin's session, with its preview. */
    private function pendingImport(Request $request): ?array
    {
        $pending = $request->session()->get('geo_import');
        if (! $pending || ! is_file($this->importPath($pending['token']))) {
            return null;
        }

        return $pending;
    }

    public function importUpload(Request $request, GazetteerImporter $importer)
    {
        $request->validate([
            'file' => 'required|file|max:5120|mimes:csv,txt',
        ], ['file.mimes' => 'Upload a .csv file (save the spreadsheet as "CSV UTF-8").']);

        $this->discardPending($request);
        $token = Str::random(32);
        $request->file('file')->storeAs(self::IMPORT_DIR, $token.'.csv', 'local');

        try {
            $preview = $importer->preview($this->importPath($token));
        } catch (\Throwable $e) {
            @unlink($this->importPath($token));

            return back()->withErrors(['file' => 'Could not read this file: '.$e->getMessage()]);
        }

        if ($preview['total'] === 0) {
            @unlink($this->importPath($token));

            return back()->withErrors(['file' => 'No usable rows found. Check the header is: type,code,name_en,name_km,latitude,longitude']);
        }

        $request->session()->put('geo_import', [
            'token' => $token,
            'name' => $request->file('file')->getClientOriginalName(),
            'preview' => $preview,
        ]);

        return redirect()->route('admin.geography.index');
    }

    public function importApply(Request $request, GazetteerImporter $importer)
    {
        $pending = $this->pendingImport($request);
        abort_unless($pending && hash_equals($pending['token'], (string) $request->input('token')), 410, 'This import preview has expired. Upload the file again.');

        $count = DB::transaction(fn () => $importer->import($this->importPath($pending['token'])));
        $this->audit->record('geography.imported', null, $request->user(), null, null, [
            'file' => $pending['name'],
            'rows_applied' => $count,
            'levels' => $pending['preview']['levels'],
            'skipped' => $importer->skipped,
        ]);
        $this->discardPending($request);

        $skipped = array_sum($importer->skipped);

        return redirect()->route('admin.geography.index')->with('status',
            "Imported {$count} locations from {$pending['name']}.".($skipped ? " {$skipped} row(s) skipped." : ''));
    }

    public function importCancel(Request $request)
    {
        $this->discardPending($request);

        return redirect()->route('admin.geography.index')->with('status', 'Import cancelled. Nothing was changed.');
    }

    private function discardPending(Request $request): void
    {
        if ($pending = $request->session()->pull('geo_import')) {
            @unlink($this->importPath($pending['token']));
        }
    }

    /** A filled-in example of the template, as a UTF-8 CSV that Excel opens with Khmer intact. */
    public function importTemplate()
    {
        $rows = [
            ['type', 'code', 'name_en', 'name_km', 'latitude', 'longitude'],
            ['province', '14', 'Prey Veng', 'ខេត្តព្រៃវែង', '11.482299', '105.322167'],
            ['district', '1401', 'Ba Phnum', 'បាភ្នំ', '11.251485', '105.397535'],
            ['commune', '140101', 'Boeng Preah', 'បឹងព្រះ', '11.324502', '105.440888'],
            ['village', '14010101', 'Angkrong', 'អង្រ្កង', '', ''],
            ['ភូមិ', '14010102', "Ruessei K'aek", 'ឫស្សីក្អែក', '', ''],
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, 'cohers-geography-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'parent_id' => 'nullable|exists:geographic_units,id',
            'type' => ['required', Rule::in(GeographicUnit::TYPES)],
            'code' => 'required|string|max:20|unique:geographic_units,code',
            'name_en' => 'required|string|max:120',
            'name_km' => 'required|string|max:120',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);
        $unit = GeographicUnit::create($data + ['is_active' => true]);
        $this->audit->record('geography.created', $unit, $request->user(), null, null, $data);

        return back()->with('status', "Added {$unit->type} {$unit->name_en}.");
    }

    public function update(Request $request, GeographicUnit $unit)
    {
        $data = $request->validate([
            'name_en' => 'required|string|max:120',
            'name_km' => 'required|string|max:120',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $old = $unit->only(array_keys($data));
        $unit->update($data);
        $this->audit->record('geography.updated', $unit, $request->user(), null, $old, $data);

        return back()->with('status', 'Saved.');
    }
}
