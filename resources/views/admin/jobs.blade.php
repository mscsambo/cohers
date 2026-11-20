@extends('layouts.app')
@section('title', 'Alerts & Jobs')
@section('subtitle', 'Telegram delivery, failed jobs and scheduled checks')
@section('content')
<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-6">
    @foreach (\App\Enums\DeliveryStatus::cases() as $s)
        <x-kpi :label="$s->value" :value="$counts[$s->value] ?? 0" :tone="in_array($s->value, ['FAILED', 'UNDELIVERABLE']) ? 'rose' : 'slate'" :href="route('admin.jobs.index', ['status' => $s->value])" />
    @endforeach
    <x-kpi label="Queue depth" :value="$queueDepth" tone="sky" />
</div>

<div class="mb-4 flex flex-wrap gap-2">
    <a href="{{ route('admin.jobs.index', ['status' => 'problems']) }}" class="btn {{ $status === 'problems' ? 'btn-primary' : 'btn-ghost' }}">Problems</a>
    <a href="{{ route('admin.jobs.index', ['status' => 'all']) }}" class="btn {{ $status === 'all' ? 'btn-primary' : 'btn-ghost' }}">All notifications</a>
    <div class="flex-1"></div>
    <form method="POST" action="{{ route('admin.jobs.overdue') }}">@csrf<button class="btn btn-ghost">Run overdue check now</button></form>
</div>

<x-card title="Notification log" :padding="false">
    <div class="overflow-x-auto">
    <table class="data w-full">
        <thead><tr><th>Created</th><th>Type</th><th>Recipient</th><th>Report</th><th>Status</th><th>Attempts</th><th>Error</th><th></th></tr></thead>
        <tbody>
        @forelse ($logs as $l)
            <tr>
                <td class="whitespace-nowrap text-xs">{{ \App\Support\Fmt::dt($l->created_at) }}</td>
                <td class="font-mono text-xs">{{ $l->message_type }}</td>
                <td class="text-xs">{{ $l->recipient?->name ?? '—' }}</td>
                <td class="font-mono text-xs">{{ $l->report?->reference_code }}</td>
                <td><x-chip :color="['SENT' => 'emerald', 'SIMULATED' => 'sky', 'PENDING' => 'amber', 'FAILED' => 'rose', 'UNDELIVERABLE' => 'rose'][$l->delivery_status->value]">{{ $l->delivery_status->value }}</x-chip></td>
                <td class="text-xs tabular-nums">{{ $l->attempts }}</td>
                <td class="max-w-xs truncate text-xs text-rose-600" title="{{ $l->error }}">{{ $l->error }}</td>
                <td class="text-right">
                    @if (in_array($l->delivery_status->value, ['FAILED', 'UNDELIVERABLE']))
                        <form method="POST" action="{{ route('admin.jobs.notification.retry', $l) }}">@csrf<button class="text-xs font-medium text-teal-700 hover:underline">Retry</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8"><x-empty text="No delivery problems." /></td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="border-t border-slate-100 px-4 py-3">{{ $logs->links() }}</div>
</x-card>

<div class="mt-5 grid gap-5 xl:grid-cols-2">
    <x-card title="Failed queue jobs" :padding="false">
        <table class="data w-full">
            <thead><tr><th>Failed at</th><th>Job</th><th>Error</th><th></th></tr></thead>
            <tbody>
            @forelse ($failedJobs as $j)
                <tr>
                    <td class="whitespace-nowrap text-xs">{{ $j->failed_at }}</td>
                    <td class="text-xs">{{ class_basename(json_decode($j->payload)->displayName ?? '') }}</td>
                    <td class="max-w-xs truncate text-xs text-rose-600" title="{{ \Illuminate\Support\Str::limit($j->exception, 500) }}">{{ \Illuminate\Support\Str::before($j->exception, "\n") }}</td>
                    <td class="whitespace-nowrap text-right">
                        <form method="POST" action="{{ route('admin.jobs.failed.retry', $j->uuid) }}" class="inline">@csrf<button class="text-xs text-teal-700 hover:underline">Retry</button></form>
                        <form method="POST" action="{{ route('admin.jobs.failed.forget', $j->uuid) }}" class="inline">@csrf<button class="ml-2 text-xs text-slate-500 hover:underline">Forget</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4"><x-empty text="No failed jobs." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </x-card>
    <x-card title="Failed Telegram updates" :padding="false">
        <table class="data w-full">
            <thead><tr><th>Update ID</th><th>Received</th><th>Error</th></tr></thead>
            <tbody>
            @forelse ($failedUpdates as $u)
                <tr><td class="font-mono text-xs">{{ $u->update_id }}</td><td class="text-xs">{{ \App\Support\Fmt::dt($u->created_at) }}</td><td class="max-w-sm truncate text-xs text-rose-600">{{ $u->error }}</td></tr>
            @empty
                <tr><td colspan="3"><x-empty text="No failed updates." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </x-card>
</div>
@endsection
