@extends('layouts.app')
@section('title', 'Analytics')
@section('content')
    @include('partials.filters')

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
        <x-kpi label="Reports in period" :value="$a['total']" />
        <x-kpi label="Verification rate" :value="$a['verification_rate'] !== null ? $a['verification_rate'].'%' : '—'" tone="teal" sub="verified ÷ (verified+rejected+duplicate)" />
        <x-kpi label="Median ack time" :value="\App\Support\Fmt::minutes($a['median_ack'])" tone="sky" />
        <x-kpi label="90th pct ack time" :value="\App\Support\Fmt::minutes($a['p90_ack'])" tone="orange" />
        <x-kpi label="Median ack → findings" :value="$a['median_verify_hours'] !== null ? $a['median_verify_hours'].'h' : '—'" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <x-card title="Weekly reports" class="xl:col-span-2"><div class="h-60"><canvas id="weekly"></canvas></div></x-card>
        <x-card title="Officer outcomes"><div class="h-60"><canvas id="outcomes"></canvas></div></x-card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-3">
        <x-card title="Activity by commune" class="xl:col-span-2" :padding="false">
            <div class="max-h-[460px] overflow-auto">
                <table class="data w-full">
                    <thead class="sticky top-0"><tr><th>Commune</th><th class="text-right">Reports</th><th class="text-right">Open</th><th class="text-right">Critical</th><th class="text-right">Verified</th><th class="text-right">Rejected</th><th class="text-right">Avg ack</th></tr></thead>
                    <tbody>
                    @forelse ($a['by_commune'] as $name => $row)
                        <tr>
                            <td>{{ $name }}</td>
                            <td class="text-right tabular-nums font-semibold">{{ $row['reports'] }}</td>
                            <td class="text-right tabular-nums">{{ $row['open'] }}</td>
                            <td class="text-right tabular-nums {{ $row['critical'] ? 'text-rose-600 font-semibold' : 'text-slate-400' }}">{{ $row['critical'] }}</td>
                            <td class="text-right tabular-nums">{{ $row['verified'] }}</td>
                            <td class="text-right tabular-nums">{{ $row['rejected'] }}</td>
                            <td class="text-right tabular-nums">{{ \App\Support\Fmt::minutes($row['avg_ack']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
        <x-card title="Verification by category" :padding="false">
            <table class="data w-full">
                <thead><tr><th>Category</th><th class="text-right">Reports</th><th class="text-right">Verified</th><th class="text-right">Rate</th></tr></thead>
                <tbody>
                @foreach ($a['by_category'] as $name => $row)
                    <tr><td>{{ $name }}</td><td class="text-right">{{ $row['total'] }}</td><td class="text-right">{{ $row['verified'] }}</td>
                        <td class="text-right">{{ $row['total'] ? round(100 * $row['verified'] / $row['total']) : 0 }}%</td></tr>
                @endforeach
                </tbody>
            </table>
        </x-card>
    </div>
    <p class="mt-3 text-xs text-slate-500">Rates describe officer verification outcomes, not confirmed diagnoses. Default period: last 90 days.</p>
@endsection
@push('scripts')
<script>
    const C = window.COHERS_COLORS;
    const weekly = @json($a['weekly']);
    new Chart(document.getElementById('weekly'), {
        type: 'bar',
        data: { labels: Object.keys(weekly).map(d => 'wk ' + d.slice(5)), datasets: [{ label: 'Reports', data: Object.values(weekly), backgroundColor: C.teal, borderRadius: 4, maxBarThickness: 40 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
    });
    const out = @json($a['outcomes']);
    const oc = { VERIFIED: C.emerald, REJECTED: C.rose, DUPLICATE: C.stone, ESCALATED: C.orange, NEEDS_INFO: C.amber };
    new Chart(document.getElementById('outcomes'), {
        type: 'doughnut',
        data: { labels: Object.keys(out), datasets: [{ data: Object.values(out), backgroundColor: Object.keys(out).map(k => oc[k] || C.slate) }] },
        options: { maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom' } } }
    });
</script>
@endpush
