@extends('layouts.app')
@section('title', 'Focal Point Router')
@section('subtitle', 'Who covers each location (primary / backup by sector) · deterministic safety rules')
@section('content')
<div class="space-y-5">
    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <div><label class="label">District</label>
            <select name="district" class="input">
                <option value="">All</option>
                @foreach ($districts as $d)<option value="{{ $d->uuid }}" @selected(request('district') === $d->uuid)>{{ $d->name_en }}</option>@endforeach
            </select>
        </div>
        <div><label class="label">Sector</label>
            <select name="sector" class="input">
                <option value="">All</option>
                @foreach ($sectors as $s)<option @selected(request('sector') === $s->value)>{{ $s->value }}</option>@endforeach
            </select>
        </div>
        <label class="mb-2 flex items-center gap-1.5 text-xs text-slate-600"><input type="checkbox" name="all" value="1" @checked(request('all'))> Include inactive</label>
        <button class="btn btn-ghost">Filter</button>
    </form>

    <x-card title="Coverage" subtitle="Lookup walks village → commune → district; primary first, then backup, then district review. Assign areas on each officer's account page." :padding="false">
        <div class="overflow-x-auto">
        <table class="data w-full">
            <thead><tr><th>Location</th><th>Sector</th><th>Level</th><th>Officer</th><th>Effective</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($mappings as $m)
                <tr>
                    <td><div class="font-medium">{{ $m->geographicUnit?->name_en }}</div><div class="text-xs text-slate-500">{{ $m->geographicUnit?->type }} · {{ $m->geographicUnit?->parent?->name_en }}</div></td>
                    <td><x-chip :color="['HUMAN' => 'sky', 'ANIMAL' => 'emerald', 'ENVIRONMENT' => 'teal'][$m->sector->value] ?? 'slate'">{{ $m->sector->value }}</x-chip></td>
                    <td><x-chip :color="$m->level === 'primary' ? 'indigo' : 'stone'">{{ $m->level }}</x-chip></td>
                    <td class="text-sm">@if ($m->user)<a href="{{ route('admin.users.edit', $m->user) }}" class="text-teal-700 hover:underline">{{ $m->user->name }}</a>@endif</td>
                    <td class="text-xs">{{ $m->effective_from?->format('Y-m-d') }} → {{ $m->effective_to?->format('Y-m-d') ?? 'open' }}</td>
                    <td>{!! $m->is_active ? '<span class="text-xs text-emerald-700">active</span>' : '<span class="text-xs text-slate-400">inactive</span>' !!}</td>
                    <td class="text-right">
                        @if ($m->is_active)
                            <form method="POST" action="{{ route('admin.routing.mappings.deactivate', $m) }}" onsubmit="return confirm('Deactivate this mapping? Routing will fall back to the next level.')">@csrf
                                <button class="text-xs font-medium text-rose-600 hover:underline">Deactivate</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty /></td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        <div class="border-t border-slate-100 px-4 py-3">{{ $mappings->links() }}</div>
    </x-card>

    <x-card title="Deterministic safety rules" subtitle="Applied after AI triage; the stricter of rule and AI priority wins" :padding="false">
        <table class="data w-full">
            <thead><tr><th>Rule</th><th>Match</th><th>Priority</th><th>District alert</th><th>Active</th><th></th></tr></thead>
            <tbody>
            @foreach ($rules as $r)
                <tr>
                    <td class="font-medium">{{ $r->name }}</td>
                    <td class="max-w-sm text-xs text-slate-500">{{ $r->rule_type === 'keyword_priority' ? 'Keywords: '.str_replace('|', ', ', $r->pattern) : 'Category: '.$r->category?->name_en }}</td>
                    <td><x-chip :color="$r->priority->color()">{{ $r->priority->value }}</x-chip></td>
                    <td class="text-xs">{{ $r->escalate_district ? 'Yes' : 'No' }}</td>
                    <td class="text-xs">{{ $r->is_active ? 'Yes' : 'No' }}</td>
                    <td class="text-right"><form method="POST" action="{{ route('admin.routing.rules.toggle', $r) }}">@csrf<button class="text-xs font-medium text-teal-700 hover:underline">{{ $r->is_active ? 'Disable' : 'Enable' }}</button></form></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-card>

    <x-card title="Assign an officer" subtitle="Areas are set on the officer's account page">
        <form method="GET" onsubmit="if (this.officer.value) location.href = this.officer.value; return false;" class="space-y-3">
            <div><label class="label" for="officer">Focal point</label>
                <select id="officer" name="officer" class="input" required>
                    <option value="">Select…</option>
                    @foreach ($officers as $o)<option value="{{ route('admin.users.edit', $o) }}">{{ $o->name }} — {{ $o->officer_type?->value }}</option>@endforeach
                </select>
            </div>
            <button class="btn btn-primary w-full justify-center">Open account → Assigned location</button>
        </form>
        <p class="mt-3 text-xs text-slate-500">There you pick province → district → communes, primary or backup. HUMAN → VHSG; ANIMAL and ENVIRONMENT → CAO. New focal points: <a href="{{ route('admin.users.create') }}" class="text-teal-700 hover:underline">create an account</a>.</p>
    </x-card>

    <x-card title="Add safety rule">
        <form method="POST" action="{{ route('admin.routing.rules.store') }}" class="space-y-3">
            @csrf
            <div><label class="label">Name</label><input name="name" class="input" required></div>
            <div><label class="label">Type</label>
                <select name="rule_type" class="input"><option value="keyword_priority">Keyword → priority</option><option value="category_priority">Category → priority</option></select>
            </div>
            <div><label class="label">Keywords (separate with |)</label><input name="pattern" class="input" placeholder="dead pigs|ជ្រូកងាប់"></div>
            <div><label class="label">Category</label>
                <select name="category_id" class="input"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name_en }}</option>@endforeach</select>
            </div>
            <div><label class="label">Priority</label>
                <select name="priority" class="input">@foreach ($priorities as $p)<option>{{ $p->value }}</option>@endforeach</select>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="escalate_district" value="1"> Also alert district immediately</label>
            <button class="btn btn-ghost w-full justify-center">Add rule</button>
        </form>
    </x-card>
</div>
@endsection
