@extends('layouts.app')
@section('title', 'Geography')
@section('subtitle', 'Official district / commune / village codes and hierarchy')
@section('content')
<div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
    <a href="{{ route('admin.geography.index') }}" class="font-medium text-teal-700 hover:underline">Root</a>
    @foreach ($breadcrumb as $b)
        <span class="text-slate-400">/</span><a href="{{ route('admin.geography.index', ['parent' => $b->uuid]) }}" class="font-medium text-teal-700 hover:underline">{{ $b->name_en }}</a>
    @endforeach
    <div class="flex-1"></div>
    @foreach ($counts as $type => $n)<x-chip>{{ $n }} {{ $type }}s</x-chip>@endforeach
    <form method="GET" class="flex gap-2"><input name="q" value="{{ request('q') }}" class="input w-56" placeholder="Search name or code"><button class="btn btn-ghost">Search</button></form>
</div>

@if ($import)
    @php $p = $import['preview']; $sum = fn ($k) => array_sum(array_column($p['levels'], $k)); @endphp
    <x-card title="Import preview — nothing has been saved yet" subtitle="{{ $import['name'] }} · {{ $p['total'] }} usable rows" class="mb-5 border-teal-300">
        <div class="grid gap-5 lg:grid-cols-3">
            <div class="lg:col-span-1">
                <table class="data w-full">
                    <thead><tr><th>Level</th><th class="text-right">New</th><th class="text-right">Updated</th><th class="text-right">Unchanged</th></tr></thead>
                    <tbody>
                    @foreach ($p['levels'] as $level => $c)
                        <tr>
                            <td class="capitalize">{{ $level }}</td>
                            <td class="text-right tabular-nums {{ $c['new'] ? 'font-semibold text-emerald-700' : 'text-slate-400' }}">{{ $c['new'] }}</td>
                            <td class="text-right tabular-nums {{ $c['updated'] ? 'font-semibold text-amber-700' : 'text-slate-400' }}">{{ $c['updated'] }}</td>
                            <td class="text-right tabular-nums text-slate-400">{{ $c['unchanged'] }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold"><td>Total</td><td class="text-right tabular-nums">{{ $sum('new') }}</td><td class="text-right tabular-nums">{{ $sum('updated') }}</td><td class="text-right tabular-nums">{{ $sum('unchanged') }}</td></tr>
                    </tbody>
                </table>
                @if ($p['skipped'])
                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                        <b>Rows that will be skipped</b>
                        <ul class="mt-1 list-inside list-disc">@foreach ($p['skipped'] as $reason => $n)<li>{{ $n }} × {{ $reason }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="mt-4 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.geography.import.apply') }}">@csrf
                        <input type="hidden" name="token" value="{{ $import['token'] }}">
                        <button class="btn btn-primary" @disabled($sum('new') + $sum('updated') === 0)>Apply import</button>
                    </form>
                    <form method="POST" action="{{ route('admin.geography.import.cancel') }}">@csrf<button class="btn btn-ghost">Cancel</button></form>
                </div>
                <p class="mt-2 text-xs text-slate-500">Existing locations are updated, never deleted. Empty coordinate cells keep current coordinates.</p>
            </div>
            <div class="min-w-0 lg:col-span-2">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Sample of changes{{ count($p['samples']) ? '' : ' — none, everything is already up to date' }}</div>
                @if ($p['samples'])
                    <div class="overflow-x-auto rounded-lg border border-slate-200">
                        <table class="data w-full">
                            <thead><tr><th></th><th>Code</th><th>Level</th><th>Name (EN)</th><th>Name (KM)</th><th>Changes</th></tr></thead>
                            <tbody>
                            @foreach ($p['samples'] as $s)
                                <tr>
                                    <td><x-chip :color="$s['action'] === 'new' ? 'emerald' : 'amber'">{{ $s['action'] }}</x-chip></td>
                                    <td class="font-mono text-xs">{{ $s['code'] }}</td>
                                    <td class="text-xs">{{ $s['type'] }}</td>
                                    <td class="text-xs">{{ $s['name_en'] }}@if ($s['action'] === 'updated' && in_array('name_en', $s['changes'])) <div class="text-slate-400 line-through">{{ $s['old_name_en'] }}</div>@endif</td>
                                    <td class="text-xs">{{ $s['name_km'] }}@if ($s['action'] === 'updated' && in_array('name_km', $s['changes'])) <div class="text-slate-400 line-through">{{ $s['old_name_km'] }}</div>@endif</td>
                                    <td class="text-xs text-slate-500">{{ $s['action'] === 'new' ? '—' : implode(', ', $s['changes']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </x-card>
@endif

<div class="grid gap-5 xl:grid-cols-3">
    <x-card :padding="false" class="xl:col-span-2">
        <table class="data w-full">
            <thead><tr><th>Code</th><th>Name (EN)</th><th>Name (KM)</th><th>Type</th><th>Coordinates</th><th>Active</th><th></th></tr></thead>
            <tbody>
            @forelse ($units as $u)
                @php $fid = 'geo'.$u->id; @endphp
                <tr>
                    <td class="font-mono text-xs">{{ $u->code }}</td>
                    <td><input form="{{ $fid }}" name="name_en" value="{{ $u->name_en }}" class="input py-1"></td>
                    <td><input form="{{ $fid }}" name="name_km" value="{{ $u->name_km }}" class="input py-1"></td>
                    <td class="text-xs">{{ $u->type }}</td>
                    <td class="text-xs"><div class="flex gap-1"><input form="{{ $fid }}" name="latitude" value="{{ $u->latitude }}" class="input w-24 py-1"><input form="{{ $fid }}" name="longitude" value="{{ $u->longitude }}" class="input w-24 py-1"></div></td>
                    <td><input form="{{ $fid }}" type="checkbox" name="is_active" value="1" @checked($u->is_active)></td>
                    <td class="whitespace-nowrap text-right">
                        <form id="{{ $fid }}" method="POST" action="{{ route('admin.geography.update', $u) }}" class="inline">@csrf @method('PUT')
                            <button class="text-xs font-medium text-teal-700 hover:underline">Save</button>
                        </form>
                        @if ($u->children_count)
                            · <a href="{{ route('admin.geography.index', ['parent' => $u->uuid]) }}" class="text-xs font-medium text-slate-600 hover:underline">{{ $u->children_count }} children →</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty /></td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="border-t border-slate-100 px-4 py-3">{{ $units->links() }}</div>
    </x-card>

    <div class="space-y-5">
    <x-card title="Import CSV" subtitle="Add or update many locations at once">
        <form method="POST" action="{{ route('admin.geography.import.upload') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="file" name="file" id="geo-file" accept=".csv,text/csv" required class="input">
            <button class="btn btn-primary w-full justify-center">Upload and preview</button>
        </form>
        <ul class="mt-3 list-inside list-disc space-y-1 text-xs text-slate-500">
            <li>Columns: <code class="rounded bg-slate-100 px-1">type,code,name_en,name_km,latitude,longitude</code></li>
            <li>Type can be English or NCDD Khmer (ស្រុក ក្រុង ខណ្ឌ ឃុំ សង្កាត់ ភូមិ).</li>
            <li>Parents come from the codes; include a province row for a new province.</li>
            <li>In Excel, save as <b>CSV UTF-8</b> to keep Khmer text.</li>
        </ul>
        <a href="{{ route('admin.geography.import.template') }}" class="mt-3 inline-block text-xs font-medium text-teal-700 hover:underline">Download template (.csv)</a>
    </x-card>

    <x-card title="Add unit" subtitle="{{ $parent ? 'Under '.$parent->name_en : 'Top level' }}">
        <form method="POST" action="{{ route('admin.geography.store') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="parent_id" value="{{ $parent?->id }}">
            <div><label class="label">Type</label>
                <select name="type" class="input">
                    @foreach ($types as $t)<option @selected($parent && array_search($t, $types) === array_search($parent->type, $types) + 1)>{{ $t }}</option>@endforeach
                </select>
            </div>
            <div><label class="label">Official code</label><input name="code" class="input font-mono" required value="{{ $parent?->code }}"></div>
            <div><label class="label">Name (EN)</label><input name="name_en" class="input" required></div>
            <div><label class="label">Name (KM)</label><input name="name_km" class="input" required></div>
            <div class="grid grid-cols-2 gap-2"><div><label class="label">Latitude</label><input name="latitude" class="input"></div><div><label class="label">Longitude</label><input name="longitude" class="input"></div></div>
            <button class="btn btn-primary w-full justify-center">Add</button>
        </form>
    </x-card>
    </div>
</div>
@endsection
