@extends('layouts.app')
@section('title', 'AI & Categories')
@section('subtitle', 'Manage report categories and review recent triage calls')
@section('content')
<div class="space-y-5">
    <x-card title="Category taxonomy" subtitle="Controlled vocabulary the AI must choose from" :padding="false">
        <div class="overflow-x-auto">
        <table class="data w-full">
            <thead><tr><th>Code</th><th>Name EN</th><th>Name KM</th><th>Sector</th><th>Default priority</th><th>Sort</th><th>Active</th><th></th></tr></thead>
            <tbody>
            @foreach ($categories as $c)
                @php $fid = 'cat'.$c->id; @endphp
                <tr>
                    <td><input form="{{ $fid }}" name="code" value="{{ $c->code }}" class="input w-40 py-1 font-mono text-xs"></td>
                    <td><input form="{{ $fid }}" name="name_en" value="{{ $c->name_en }}" class="input py-1"></td>
                    <td><input form="{{ $fid }}" name="name_km" value="{{ $c->name_km }}" class="input py-1"></td>
                    <td><select form="{{ $fid }}" name="sector" class="input py-1">@foreach ($sectors as $s)<option @selected($c->sector === $s)>{{ $s->value }}</option>@endforeach</select></td>
                    <td><select form="{{ $fid }}" name="default_priority" class="input py-1">@foreach ($priorities as $p)<option @selected($c->default_priority === $p)>{{ $p->value }}</option>@endforeach</select></td>
                    <td><input form="{{ $fid }}" name="sort" value="{{ $c->sort }}" class="input w-14 py-1"></td>
                    <td><input form="{{ $fid }}" type="checkbox" name="is_active" value="1" @checked($c->is_active)></td>
                    <td><form id="{{ $fid }}" method="POST" action="{{ route('admin.categories.update', $c) }}">@csrf @method('PUT')<button class="text-xs font-medium text-teal-700 hover:underline">Save</button></form></td>
                </tr>
            @endforeach
            <tr class="bg-slate-50/60">
                <td><input form="newcat" name="code" placeholder="NEW_CODE" class="input w-40 py-1 font-mono text-xs"></td>
                <td><input form="newcat" name="name_en" placeholder="Name" class="input py-1"></td>
                <td><input form="newcat" name="name_km" placeholder="ឈ្មោះ" class="input py-1"></td>
                <td><select form="newcat" name="sector" class="input py-1">@foreach ($sectors as $s)<option>{{ $s->value }}</option>@endforeach</select></td>
                <td><select form="newcat" name="default_priority" class="input py-1">@foreach ($priorities as $p)<option @selected($p->value === 'MEDIUM')>{{ $p->value }}</option>@endforeach</select></td>
                <td><input form="newcat" name="sort" value="{{ $categories->count() + 1 }}" class="input w-14 py-1"></td>
                <td><input form="newcat" type="checkbox" name="is_active" value="1" checked></td>
                <td><form id="newcat" method="POST" action="{{ route('admin.categories.store') }}">@csrf<button class="text-xs font-semibold text-teal-700 hover:underline">Add</button></form></td>
            </tr>
            </tbody>
        </table>
        </div>
    </x-card>

    <x-card title="Recent triage calls" :padding="false">
        <x-slot:actions>
            @foreach ($stats as $k => $n)<x-chip :color="$k === 'success' ? 'emerald' : 'rose'">{{ $k }}: {{ $n }} (7d)</x-chip>@endforeach
        </x-slot:actions>
        <div class="max-h-96 overflow-auto">
        <table class="data w-full">
            <thead class="sticky top-0"><tr><th>When</th><th>Report</th><th>Provider / model</th><th>Outcome</th><th>Result</th><th>Latency</th></tr></thead>
            <tbody>
            @foreach ($recent as $t)
                <tr>
                    <td class="whitespace-nowrap text-xs">{{ \App\Support\Fmt::dt($t->created_at) }}</td>
                    <td class="font-mono text-xs">@if ($t->report)<a class="text-teal-700 hover:underline" href="{{ route('district.reports.show', $t->report_id) }}">{{ $t->report->reference_code }}</a>@endif</td>
                    <td class="text-xs">{{ $t->provider }}<div class="text-slate-400">{{ $t->model }}</div></td>
                    <td><x-chip :color="$t->outcome === 'success' ? 'emerald' : 'rose'">{{ $t->outcome }}</x-chip></td>
                    <td class="max-w-xs text-xs">{{ $t->outcome === 'success' ? "{$t->category_code} · {$t->suggested_priority} · ".number_format($t->confidence, 2).($t->needs_manual_review ? ' · review' : '') : \Illuminate\Support\Str::limit($t->error, 80) }}</td>
                    <td class="text-xs tabular-nums">{{ $t->latency_ms }}ms</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </x-card>
</div>
@endsection
