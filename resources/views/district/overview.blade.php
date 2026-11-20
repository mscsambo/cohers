@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
    @include('partials.filters')

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
        <x-kpi label="Open reports" :value="$kpi['open']" :sub="$kpi['total'].' total'" :href="route('district.reports.index')" />
        <x-kpi label="New (24h)" :value="$kpi['new_24h']" tone="sky" />
        <x-kpi label="Critical open" :value="$kpi['critical_open']" tone="rose" :href="route('district.reports.index', ['priority' => 'CRITICAL'])" />
        <x-kpi label="Overdue ack" :value="$kpi['overdue']" tone="orange" :href="route('district.reports.index', ['overdue' => 1])" />
        <x-kpi label="Manual triage" :value="$kpi['triage_review']" tone="amber" :href="route('district.reports.index', ['status' => 'TRIAGE_REVIEW'])" />
        <x-kpi label="Escalations" :value="$kpi['escalations_open']" tone="rose" sub="open / in review" :href="route('district.escalations.index')" />
        <x-kpi label="Verification rate" :value="$kpi['verification_rate'] !== null ? $kpi['verification_rate'].'%' : '—'" tone="teal" sub="decided, last 30 days" />
        <x-kpi label="Median ack time" :value="\App\Support\Fmt::minutes($kpi['median_ack_minutes'])" sub="last 30 days" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <x-card title="Reports per day" subtitle="Last 30 days · all vs. high/critical" class="xl:col-span-2">
            <div class="h-64"><canvas id="daily"></canvas></div>
        </x-card>
        <x-card title="By status" subtitle="Canonical report states">
            <div class="h-64"><canvas id="status"></canvas></div>
        </x-card>
        <x-card title="By category" subtitle="AI-suggested or human-confirmed">
            <div class="h-56"><canvas id="category"></canvas></div>
        </x-card>
        <x-card title="By priority">
            <div class="h-56"><canvas id="priority"></canvas></div>
        </x-card>
        <x-card title="By area" subtitle="Top areas by report count">
            <div class="h-56"><canvas id="area"></canvas></div>
        </x-card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-card title="High & critical — open" :padding="false">
            <x-slot:actions><a class="text-xs font-medium text-teal-700 hover:underline" href="{{ route('district.reports.index', ['priority' => 'CRITICAL']) }}">View all</a></x-slot:actions>
            @forelse ($critical as $r)
                <a href="{{ route('district.reports.show', $r) }}" class="flex items-start gap-3 border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50">
                    <x-chip :color="$r->priority->color()">{{ $r->priority->value }}</x-chip>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 text-sm"><span class="font-mono font-semibold">{{ $r->reference_code }}</span><span class="text-slate-400">·</span><span class="truncate text-slate-600">{{ $r->location?->fullPath() }}</span></div>
                        <div class="truncate text-sm text-slate-500">{{ $r->original_text }}</div>
                    </div>
                    <x-chip :color="$r->status->color()">{{ $r->status->label() }}</x-chip>
                </a>
            @empty
                <x-empty text="No open high or critical reports." />
            @endforelse
        </x-card>
        <x-card title="Overdue acknowledgements" :padding="false">
            <x-slot:actions><a class="text-xs font-medium text-teal-700 hover:underline" href="{{ route('district.reports.index', ['overdue' => 1]) }}">View all</a></x-slot:actions>
            @forelse ($overdue as $r)
                @php $a = $r->activeAssignment; @endphp
                <a href="{{ route('district.reports.show', $r) }}" class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm"><span class="font-mono font-semibold">{{ $r->reference_code }}</span> <span class="text-slate-500">→ {{ $a?->officer?->name ?? '—' }}</span></div>
                        <div class="text-xs text-slate-500">{{ $r->location?->fullPath() }}</div>
                    </div>
                    <div class="text-right text-xs">
                        <div class="font-semibold text-orange-600">{{ $a?->ack_due_at?->diffForHumans(null, true) }} late</div>
                        <div class="text-slate-400">reminders: {{ $a?->reminder_count }}</div>
                    </div>
                </a>
            @empty
                <x-empty text="No overdue acknowledgements. 🎉" />
            @endforelse
        </x-card>
    </div>
@endsection

@push('scripts')
<script>
    const C = window.COHERS_COLORS;
    const daily = @json($daily);
    new Chart(document.getElementById('daily'), {
        type: 'line',
        data: {
            labels: Object.keys(daily).map(d => d.slice(5)),
            datasets: [
                { label: 'All reports', data: Object.values(daily).map(v => v.all), borderColor: C.teal, backgroundColor: 'rgba(13,148,136,.12)', fill: true, tension: .3, pointRadius: 0 },
                { label: 'High / critical', data: Object.values(daily).map(v => v.high), borderColor: C.rose, backgroundColor: 'transparent', tension: .3, pointRadius: 0, borderDash: [4, 3] },
            ]
        },
        options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
    });

    const statusColors = @json(collect(\App\Enums\ReportStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->color()]));
    const byStatus = Object.fromEntries(Object.entries(@json($byStatus)).filter(([, v]) => v > 0));
    new Chart(document.getElementById('status'), {
        type: 'doughnut',
        data: { labels: Object.keys(byStatus).map(s => s.replace('_', ' ')), datasets: [{ data: Object.values(byStatus), backgroundColor: Object.keys(byStatus).map(s => C[statusColors[s]]), borderWidth: 2 }] },
        options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right' } } }
    });

    const bar = (id, obj, colors, horizontal = false) => new Chart(document.getElementById(id), {
        type: 'bar',
        data: { labels: Object.keys(obj), datasets: [{ data: Object.values(obj), backgroundColor: colors, borderRadius: 4, maxBarThickness: 36 }] },
        options: { maintainAspectRatio: false, indexAxis: horizontal ? 'y' : 'x', plugins: { legend: { display: false } }, scales: { x: { grid: { display: horizontal } , ticks: { precision: 0 } }, y: { grid: { display: !horizontal }, ticks: { precision: 0 } } } }
    });
    bar('category', @json($byCategory), [C.rose, C.orange, C.sky, C.slate]);
    bar('priority', @json($byPriority), [C.slate, C.sky, C.orange, C.rose, '#cbd5e1']);
    bar('area', @json($byArea), C.teal, true);
</script>
@endpush
