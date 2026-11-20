<?php

namespace App\Services\Analytics;

use App\Enums\AssignmentStatus;
use App\Enums\EscalationState;
use App\Enums\Priority;
use App\Enums\ReportStatus;
use App\Enums\RoleName;
use App\Models\Escalation;
use App\Models\GeographicUnit;
use App\Models\Report;
use App\Models\ReportAssignment;
use App\Models\ReportCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Privacy-scoped dashboard KPIs. Every query starts from Report::visibleTo()
 * so aggregates never include reports outside the viewer's scope.
 * Aggregation is done in PHP for portability between MySQL and SQLite.
 */
class ReportAnalyticsService
{
    public function base(User $user, array $f = []): Builder
    {
        return Report::query()->visibleTo($user)
            ->when($f['district_id'] ?? null, fn ($q, $v) => $q->where('district_id', $v))
            ->when($f['commune_id'] ?? null, fn ($q, $v) => $q->where('commune_id', $v))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($f['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('submitted_at', '>=', Carbon::parse($v, config('cohers.display_timezone'))->startOfDay()->utc()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('submitted_at', '<=', Carbon::parse($v, config('cohers.display_timezone'))->endOfDay()->utc()));
    }

    public function overview(User $user, array $f = []): array
    {
        $open = $this->base($user, $f)->where('status', '!=', ReportStatus::CLOSED->value);

        $last30 = $this->base($user, $f)->where('submitted_at', '>=', now()->subDays(30))->get(['id', 'status']);
        $decided = $last30->filter(fn ($r) => in_array($r->status, [ReportStatus::VERIFIED, ReportStatus::REJECTED, ReportStatus::DUPLICATE, ReportStatus::CLOSED], true));
        $verified = Report::whereIn('id', $decided->pluck('id'))->whereHas('verifications', fn ($v) => $v->where('outcome', 'VERIFIED'))->count();

        return [
            'total' => $this->base($user, $f)->count(),
            'open' => (clone $open)->count(),
            'new_24h' => $this->base($user, $f)->where('submitted_at', '>=', now()->subDay())->count(),
            'critical_open' => (clone $open)->where('priority', Priority::CRITICAL->value)->count(),
            'overdue' => (clone $open)->overdue()->count(),
            'triage_review' => $this->base($user, $f)->where('status', ReportStatus::TRIAGE_REVIEW->value)->count(),
            'escalations_open' => $this->base($user, $f)->whereIn('escalation_state', [EscalationState::OPEN->value, EscalationState::IN_REVIEW->value])->count(),
            'verification_rate' => $decided->count() ? round(100 * $verified / $decided->count()) : null,
            'median_ack_minutes' => $this->medianAckMinutes($user, $f),
        ];
    }

    public function countBy(User $user, string $column, array $f = []): Collection
    {
        return $this->base($user, $f)->get([$column])
            ->groupBy(fn ($r) => $r->{$column} instanceof \BackedEnum ? $r->{$column}->value : ($r->{$column} ?? 'NONE'))
            ->map->count()->sortDesc();
    }

    public function byStatus(User $user, array $f = []): array
    {
        $counts = $this->countBy($user, 'status', $f);

        return collect(ReportStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();
    }

    public function byPriority(User $user, array $f = []): array
    {
        $counts = $this->countBy($user, 'priority', $f);

        return collect(Priority::cases())->mapWithKeys(fn ($p) => [$p->value => (int) ($counts[$p->value] ?? 0)])->all()
            + ['UNSET' => (int) ($counts['NONE'] ?? 0)];
    }

    public function byCategory(User $user, array $f = []): array
    {
        $counts = $this->countBy($user, 'category_id', $f);
        $names = ReportCategory::pluck('name_en', 'id');

        return $counts->mapWithKeys(fn ($n, $id) => [$names[$id] ?? 'Unclassified' => $n])->all();
    }

    public function byArea(User $user, string $level = 'district_id', array $f = []): array
    {
        $counts = $this->countBy($user, $level, $f);
        $names = GeographicUnit::whereIn('id', $counts->keys()->filter(fn ($k) => $k !== 'NONE'))->pluck('name_en', 'id');

        return $counts->mapWithKeys(fn ($n, $id) => [$names[$id] ?? '—' => $n])->take(15)->all();
    }

    public function dailySeries(User $user, int $days = 30, array $f = []): array
    {
        $tz = config('cohers.display_timezone');
        $rows = $this->base($user, $f)->where('submitted_at', '>=', now()->subDays($days))->get(['submitted_at', 'priority']);
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[now($tz)->subDays($i)->format('Y-m-d')] = ['all' => 0, 'high' => 0];
        }
        foreach ($rows as $r) {
            $d = $r->submitted_at->copy()->setTimezone($tz)->format('Y-m-d');
            if (isset($series[$d])) {
                $series[$d]['all']++;
                if (in_array($r->priority, [Priority::HIGH, Priority::CRITICAL], true)) {
                    $series[$d]['high']++;
                }
            }
        }

        return $series;
    }

    public function medianAckMinutes(User $user, array $f = []): ?float
    {
        $ids = $this->base($user, $f)->where('submitted_at', '>=', now()->subDays(30))->pluck('id');
        $mins = ReportAssignment::whereIn('report_id', $ids)->whereNotNull('acknowledged_at')
            ->get(['assigned_at', 'acknowledged_at'])
            ->map(fn ($a) => $a->assigned_at->diffInSeconds($a->acknowledged_at) / 60)
            ->sort()->values();

        return $mins->isEmpty() ? null : round($mins->median(), 1);
    }

    /** Officer workload for officers mapped inside the viewer's districts. */
    public function workload(User $user): Collection
    {
        $districts = $user->isAdmin() ? null : $user->districtIds();

        $officers = User::withRole(RoleName::FOCAL_POINT)->with('telegramAccount', 'focalPointAssignments.geographicUnit')
            ->when($districts !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('reportAssignments.report', fn ($r) => $r->whereIn('district_id', $districts ?: [0]))
                ->orWhereHas('focalPointAssignments.geographicUnit', fn ($g) => $g->where(fn ($x) => $x
                    ->whereIn('geographic_units.id', $districts ?: [0])
                    ->orWhereIn('geographic_units.parent_id', $districts ?: [0])))))
            ->orderBy('name')->get();

        $verificationTarget = (int) \App\Models\SystemSetting::get_value('verification_target_hours', 48);

        return $officers->map(function (User $o) use ($verificationTarget) {
            $assign = ReportAssignment::with('report:id,reference_code')->where('focal_point_user_id', $o->id)->get();
            $active = $assign->filter->isActive();
            $acked = $assign->whereNotNull('acknowledged_at');
            $verifying = $assign->where('assignment_status', AssignmentStatus::VERIFYING);
            $oldest = $verifying->min('verification_started_at');

            return [
                'officer' => $o,
                'pending' => $assign->where('assignment_status', AssignmentStatus::PENDING)->count(),
                'overdue' => $assign->filter->isOverdue()->count(),
                'active' => $active->count(),
                'verifying' => $verifying->count(),
                'completed_30d' => $assign->where('assignment_status', AssignmentStatus::COMPLETED)->where('completed_at', '>=', now()->subDays(30))->count(),
                'avg_ack_minutes' => $acked->isEmpty() ? null : round($acked->avg(fn ($a) => $a->assigned_at->diffInSeconds($a->acknowledged_at) / 60), 1),
                'oldest_verification_hours' => $oldest ? round($oldest->diffInMinutes(now()) / 60, 1) : null,
                'aging' => $oldest && $oldest->diffInHours(now()) > $verificationTarget,
                'linked' => $o->telegramAccount?->status === 'active',
                'areas' => $o->focalPointAssignments->where('is_active', true)->map(fn ($m) => $m->geographicUnit?->name_en.' ('.$m->level[0].')')->unique()->implode(', '),
            ];
        });
    }

    /** Commune-level aggregation only — exact villages/pins are not exposed on the map. */
    public function mapData(User $user, array $f = []): array
    {
        $rows = $this->base($user, $f)->with('commune')->get(['id', 'commune_id', 'priority', 'status']);

        return $rows->groupBy('commune_id')->map(function ($group) {
            $c = $group->first()->commune;
            if (! $c || $c->latitude === null) {
                return null;
            }

            return [
                'name' => $c->name_en,
                'lat' => $c->latitude,
                'lng' => $c->longitude,
                'count' => $group->count(),
                'open' => $group->where('status', '!=', ReportStatus::CLOSED)->count(),
                'critical' => $group->where('priority', Priority::CRITICAL)->count(),
                'high' => $group->where('priority', Priority::HIGH)->count(),
            ];
        })->filter()->values()->all();
    }

    /** Outcomes, response times and activity by commune for the Analytics page. */
    public function analytics(User $user, array $f = []): array
    {
        $reports = $this->base($user, $f)->with('commune:id,name_en', 'category:id,name_en', 'verifications:id,report_id,outcome', 'assignments:id,report_id,assigned_at,acknowledged_at,completed_at')->get();

        $byCommune = $reports->groupBy(fn ($r) => $r->commune?->name_en ?? '—')->map(function ($g) {
            $verified = $g->filter(fn ($r) => $r->verifications->contains('outcome', \App\Enums\VerificationOutcome::VERIFIED))->count();
            $acks = $g->flatMap->assignments->whereNotNull('acknowledged_at');

            return [
                'reports' => $g->count(),
                'verified' => $verified,
                'rejected' => $g->filter(fn ($r) => $r->verifications->contains('outcome', \App\Enums\VerificationOutcome::REJECTED))->count(),
                'open' => $g->where('status', '!=', ReportStatus::CLOSED)->count(),
                'critical' => $g->where('priority', Priority::CRITICAL)->count(),
                'avg_ack' => $acks->isEmpty() ? null : round($acks->avg(fn ($a) => $a->assigned_at->diffInSeconds($a->acknowledged_at) / 60), 1),
            ];
        })->sortByDesc('reports');

        $outcomes = $reports->flatMap->verifications->groupBy(fn ($v) => $v->outcome->value)->map->count();

        $byCategoryOutcome = $reports->groupBy(fn ($r) => $r->category?->name_en ?? 'Unclassified')->map(fn ($g) => [
            'total' => $g->count(),
            'verified' => $g->filter(fn ($r) => $r->verifications->contains('outcome', \App\Enums\VerificationOutcome::VERIFIED))->count(),
        ]);

        $tz = config('cohers.display_timezone');
        $weekly = $reports->groupBy(fn ($r) => $r->submitted_at->copy()->setTimezone($tz)->startOfWeek()->format('Y-m-d'))
            ->sortKeys()->map->count();

        $ackTimes = $reports->flatMap->assignments->whereNotNull('acknowledged_at')
            ->map(fn ($a) => $a->assigned_at->diffInSeconds($a->acknowledged_at) / 60)->sort()->values();
        $verifyTimes = $reports->flatMap->assignments->whereNotNull('completed_at')->whereNotNull('acknowledged_at')
            ->map(fn ($a) => $a->acknowledged_at->diffInSeconds($a->completed_at) / 3600)->sort()->values();

        $decided = $outcomes->only(['VERIFIED', 'REJECTED', 'DUPLICATE'])->sum();

        return [
            'total' => $reports->count(),
            'verification_rate' => $decided ? round(100 * ($outcomes['VERIFIED'] ?? 0) / $decided) : null,
            'median_ack' => $ackTimes->isEmpty() ? null : round($ackTimes->median(), 1),
            'p90_ack' => $ackTimes->isEmpty() ? null : round($ackTimes[(int) floor(0.9 * ($ackTimes->count() - 1))], 1),
            'median_verify_hours' => $verifyTimes->isEmpty() ? null : round($verifyTimes->median(), 1),
            'outcomes' => $outcomes->all(),
            'by_commune' => $byCommune,
            'by_category' => $byCategoryOutcome,
            'weekly' => $weekly->all(),
        ];
    }

    public function openEscalations(User $user)
    {
        return Escalation::with('report.category', 'report.location', 'district')
            ->whereHas('report', fn ($q) => $q->visibleTo($user))
            ->where('escalation_state', '!=', EscalationState::RESOLVED->value);
    }
}
