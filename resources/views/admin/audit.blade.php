@extends('layouts.app')
@section('title', 'Operations & Audit')
@section('subtitle', 'Append-only record of roles, decisions, reassignments, exports and closures')
@section('content')
<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
    <div><label class="label">Action</label>
        <select name="action" class="input">
            <option value="">All</option>
            @foreach ($actions as $a)<option @selected(request('action') === $a)>{{ $a }}</option>@endforeach
        </select>
    </div>
    <div><label class="label">Subject</label>
        <select name="subject" class="input">
            <option value="">All</option>
            @foreach ($subjects as $s)<option @selected(request('subject') === $s)>{{ $s }}</option>@endforeach
        </select>
    </div>
    <div><label class="label">Subject UUID</label><input name="subject_uuid" value="{{ request('subject_uuid') }}" class="input w-80 font-mono text-xs" placeholder="e.g. 0192f3a1-7c2e-7b44-…"></div>
    <div><label class="label">Actor</label><input name="actor" value="{{ request('actor') }}" class="input" placeholder="Name contains"></div>
    <div><label class="label">From</label><input type="date" name="from" value="{{ request('from') }}" class="input"></div>
    <button class="btn btn-ghost">Filter</button>
    <div class="flex-1"></div>
    <a href="{{ route('admin.audit.export', request()->query()) }}" class="btn btn-ghost">Export CSV (logged)</a>
</form>

<x-card :padding="false">
    <div class="overflow-x-auto">
    <table class="data w-full">
        <thead><tr><th>#</th><th>Time</th><th>Actor</th><th>Action</th><th>Subject</th><th>Channel</th><th>Reason</th><th>Change</th></tr></thead>
        <tbody>
        @foreach ($logs as $l)
            <tr>
                <td class="text-xs text-slate-400">{{ $l->id }}</td>
                <td class="whitespace-nowrap text-xs">{{ \App\Support\Fmt::dt($l->created_at, 'd M Y H:i:s') }}</td>
                <td class="text-xs">{{ $l->actor?->name ?? 'system' }}</td>
                <td class="font-mono text-xs">{{ $l->action }}</td>
                <td class="text-xs">
                    @php $subjectUuid = $subjectUuids[$l->subject_type][$l->subject_id] ?? null; @endphp
                    @if ($l->subject_type === 'Report' && $subjectUuid)
                        <a class="text-teal-700 hover:underline" href="{{ route('district.reports.show', $subjectUuid) }}">Report</a>
                    @elseif ($l->subject_type === 'User' && $subjectUuid)
                        <a class="text-teal-700 hover:underline" href="{{ route('admin.users.edit', $subjectUuid) }}">User</a>
                    @else
                        {{ $l->subject_type }}
                    @endif
                    @if ($subjectUuid)<div class="font-mono text-[10px] text-slate-400">{{ $subjectUuid }}</div>@endif
                </td>
                <td class="text-xs">{{ $l->channel }}</td>
                <td class="max-w-xs text-xs text-slate-600">{{ \Illuminate\Support\Str::limit($l->reason, 140) }}</td>
                <td class="max-w-xs text-[11px] text-slate-500">
                    @if ($l->old_values)<div class="truncate text-rose-600" title="{{ json_encode($l->old_values, JSON_UNESCAPED_UNICODE) }}">− {{ json_encode($l->old_values, JSON_UNESCAPED_UNICODE) }}</div>@endif
                    @if ($l->new_values)<div class="truncate text-emerald-700" title="{{ json_encode($l->new_values, JSON_UNESCAPED_UNICODE) }}">+ {{ json_encode($l->new_values, JSON_UNESCAPED_UNICODE) }}</div>@endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    <div class="border-t border-slate-100 px-4 py-3">{{ $logs->links() }}</div>
</x-card>
@endsection
