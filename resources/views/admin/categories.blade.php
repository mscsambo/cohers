@extends('layouts.app')
@section('title', 'AI & Categories')
@section('subtitle', 'Category taxonomy, Gemini integration, manual-review defaults and safe fallbacks')
@section('content')
<div class="grid gap-5 xl:grid-cols-3">
    <div class="space-y-5 xl:col-span-2">
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

    <div class="space-y-5">
        <x-card title="AI integration">
            <dl class="mb-4 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Gemini API key</dt><dd>{!! $hasKey ? '<span class="text-emerald-700">configured</span>' : '<span class="text-rose-600">not set</span>' !!}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Model</dt><dd class="font-mono text-xs">{{ $model }}</dd></div>
            </dl>
            <dl class="mb-4 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Driver</dt><dd class="font-mono text-xs">{{ $driver }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Min. confidence</dt><dd class="font-mono text-xs">{{ $minConfidence }}</dd></div>
            </dl>
            <a href="{{ route('admin.configuration.edit') }}" class="btn btn-primary w-full justify-center">Change key, model &amp; driver →</a>
            <ul class="mt-4 list-inside list-disc space-y-1 text-xs text-slate-500">
                <li>Only minimized description text is sent (phone numbers, handles and emails are masked).</li>
                <li>Responses are schema-validated; invalid or failed calls keep the report and send it to manual triage, then retry.</li>
                <li>Confidence is not the probability that an event is real.</li>
            </ul>
        </x-card>

        <x-card title="Test the classifier">
            <form method="POST" action="{{ route('admin.ai.test') }}" class="space-y-2">
                @csrf
                <textarea name="text" rows="4" class="input" placeholder="e.g. មាន់ ២០ ក្បាលងាប់ភ្លាមៗ" required>{{ old('text') }}</textarea>
                <button class="btn btn-ghost w-full justify-center">Run (nothing is stored)</button>
            </form>
            @if ($p = session('preview'))
                <pre class="mt-3 max-h-64 overflow-auto rounded-lg bg-slate-900 p-3 text-[11px] text-emerald-300">{{ json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        </x-card>
    </div>
</div>
@endsection
