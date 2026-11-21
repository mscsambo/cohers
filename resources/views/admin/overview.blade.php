@extends('layouts.app')
@section('title', 'System Health')
@section('subtitle', 'Global operations, integrations and recent privileged activity')
@section('content')
@php $h = $health; @endphp
<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
    <x-kpi label="Reports total" :value="$reports['total']" :sub="$reports['today'].' in last 24h'" :href="route('district.reports.index')" />
    <x-kpi label="Manual triage queue" :value="$reports['triage_review']" tone="amber" :href="route('district.reports.index', ['status' => 'TRIAGE_REVIEW'])" />
    <x-kpi label="Open escalations" :value="$reports['open_escalations']" tone="rose" :href="route('district.escalations.index')" />
    <x-kpi label="Officers not linked" :value="$h['unlinked_officers']" tone="orange" sub="cannot receive alerts" :href="route('admin.users.index', ['role' => 'focal_point', 'unlinked' => 1])" />
</div>

<div class="grid gap-5 xl:grid-cols-3">
    <x-card title="Integrations & queues">
        <dl class="divide-y divide-slate-100 text-sm">
            @foreach ([
                'Telegram mode' => [$h['telegram_mode'], str_starts_with($h['telegram_mode'], 'live') ? 'emerald' : 'amber'],
                'AI triage driver' => [$h['ai_driver'], $h['ai_driver'] === 'none' ? 'rose' : 'emerald'],
                'Queue jobs pending' => [$h['queue_pending'], $h['queue_pending'] > 50 ? 'amber' : 'emerald'],
                'Failed jobs' => [$h['failed_jobs'], $h['failed_jobs'] ? 'rose' : 'emerald'],
                'Failed Telegram updates' => [$h['updates_failed'], $h['updates_failed'] ? 'rose' : 'emerald'],
                'Last Telegram update' => [$h['last_update'] ? \App\Support\Fmt::dt($h['last_update']) : 'never', 'slate'],
            ] as $label => [$value, $color])
                <div class="flex items-center justify-between py-2"><dt class="text-slate-600">{{ $label }}</dt><dd><x-chip :color="$color">{{ $value }}</x-chip></dd></div>
            @endforeach
        </dl>
        <p class="mt-3 text-xs text-slate-500">Run <code class="rounded bg-slate-100 px-1">php artisan queue:work</code> and <code class="rounded bg-slate-100 px-1">php artisan schedule:work</code> continuously in production.</p>
    </x-card>

    <x-card title="Delivery & triage (24h)">
        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-lg bg-emerald-50 p-3"><div class="text-xs text-emerald-700">Notifications sent</div><div class="text-2xl font-bold text-emerald-700">{{ $h['notif_sent_24h'] }}</div></div>
            <div class="rounded-lg bg-rose-50 p-3"><div class="text-xs text-rose-700">Failed / undeliverable</div><div class="text-2xl font-bold text-rose-700">{{ $h['notif_failed_24h'] }}</div></div>
            <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-600">AI triage calls</div><div class="text-2xl font-bold">{{ $h['triage_total_24h'] }}</div></div>
            <div class="rounded-lg bg-amber-50 p-3"><div class="text-xs text-amber-700">AI failures / invalid</div><div class="text-2xl font-bold text-amber-700">{{ $h['triage_failed_24h'] }}</div></div>
        </div>
        <a href="{{ route('admin.jobs.index') }}" class="mt-3 inline-block text-xs font-medium text-teal-700 hover:underline">Open Alerts & Jobs →</a>
    </x-card>

    <x-card title="Accounts by role">
        <div class="space-y-2">
            @foreach (\App\Enums\RoleName::cases() as $r)
                <a href="{{ route('admin.users.index', ['role' => $r->value]) }}" class="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
                    <span>{{ $r->label() }}</span><span class="font-semibold tabular-nums">{{ $roles[$r->value] ?? 0 }}</span>
                </a>
            @endforeach
        </div>
    </x-card>
</div>

<x-card title="Recent audit events" class="mt-5" :padding="false">
    <x-slot:actions><a href="{{ route('admin.audit.index') }}" class="text-xs font-medium text-teal-700 hover:underline">Full audit log</a></x-slot:actions>
    <table class="data w-full">
        <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Subject</th><th>Channel</th><th>Reason</th></tr></thead>
        <tbody>
        @foreach ($recentAudit as $l)
            <tr>
                <td class="whitespace-nowrap text-xs">{{ \App\Support\Fmt::dt($l->created_at) }}</td>
                <td class="text-xs">{{ $l->actor?->name ?? 'system' }}</td>
                <td class="font-mono text-xs">{{ $l->action }}</td>
                <td class="text-xs">{{ $l->subject_type }} #{{ $l->subject_id }}</td>
                <td class="text-xs">{{ $l->channel }}</td>
                <td class="max-w-md truncate text-xs text-slate-500">{{ $l->reason }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-card>
@endsection
