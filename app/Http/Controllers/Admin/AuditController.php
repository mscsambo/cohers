<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Concerns\HasPublicUuid;
use App\Services\AuditService;
use App\Support\Fmt;
use Illuminate\Http\Request;

/** Operations & Audit: read-only action log with controlled export. */
class AuditController extends Controller
{
    public function __construct(private AuditService $audit) {}

    /** Audit subject types that have a public uuid, by their stored short name. */
    private static function uuidModel(?string $type): ?string
    {
        $class = $type ? 'App\\Models\\'.$type : null;

        return $class && class_exists($class) && in_array(HasPublicUuid::class, class_uses_recursive($class), true) ? $class : null;
    }

    private function query(Request $request)
    {
        // The subject filter takes the record's public uuid; audit rows store the internal id.
        $subjectId = null;
        if ($request->subject_uuid && ($class = self::uuidModel($request->subject))) {
            $subjectId = $class::idFromUuid($request->subject_uuid) ?? 0;
        }

        return AuditLog::with('actor')
            ->when($request->action, fn ($q, $v) => $q->where('action', 'like', "{$v}%"))
            ->when($request->actor, fn ($q, $v) => $q->whereHas('actor', fn ($a) => $a->where('name', 'like', "%{$v}%")))
            ->when($request->subject, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($subjectId !== null, fn ($q) => $q->where('subject_id', $subjectId))
            ->when($request->from, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->latest('id');
    }

    public function index(Request $request)
    {
        $logs = $this->query($request)->paginate(50)->withQueryString();

        // Public uuids for the subjects on this page, so links and labels never show internal ids.
        $uuids = [];
        foreach ($logs->groupBy('subject_type') as $type => $rows) {
            if ($class = self::uuidModel($type)) {
                $uuids[$type] = $class::whereIn('id', $rows->pluck('subject_id')->filter())->pluck('uuid', 'id')->all();
            }
        }

        return view('admin.audit', [
            'logs' => $logs,
            'subjectUuids' => $uuids,
            'actions' => AuditLog::select('action')->distinct()->orderBy('action')->pluck('action'),
            'subjects' => AuditLog::select('subject_type')->whereNotNull('subject_type')->distinct()->pluck('subject_type'),
        ]);
    }

    public function export(Request $request)
    {
        $query = $this->query($request);
        $this->audit->record('audit.exported', null, $request->user(), null, null, ['filters' => $request->query()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'time_local', 'actor', 'action', 'subject', 'subject_id', 'channel', 'reason', 'old', 'new']);
            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $l) {
                    fputcsv($out, [$l->id, Fmt::dt($l->created_at, 'Y-m-d H:i:s'), $l->actor?->name ?? 'system', $l->action,
                        $l->subject_type, $l->subject_id, $l->channel, $l->reason, json_encode($l->old_values), json_encode($l->new_values)]);
                }
            });
            fclose($out);
        }, 'cohers-audit-'.now()->format('Ymd-His').'.csv');
    }
}
