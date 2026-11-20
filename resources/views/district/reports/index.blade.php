@extends('layouts.app')
@section('title', 'Reports')
@section('content')
    @php $filtered = collect(request()->except(['q', 'page']))->filter()->isNotEmpty(); @endphp
    <form method="GET" id="filters" class="filters mb-4 grid grid-cols-2 gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm md:grid-cols-4 xl:grid-cols-8 {{ $filtered ? 'filters-open' : '' }}">
        <div class="col-span-2">
            <label class="label">Search</label>
            <div class="flex gap-2">
                <input name="q" value="{{ request('q') }}" placeholder="Reference code or text…" class="input">
                <button type="button" class="btn btn-ghost sm:hidden" onclick="document.getElementById('filters').classList.toggle('filters-open')" aria-label="More filters">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h18M6 12h12M10 19h4"/></svg>{{ $filtered ? '•' : '' }}
                </button>
            </div>
        </div>
        @if ($districts->count() > 1)
            <div class="filter-extra">
                <label class="label">District</label>
                <select name="district_id" class="input">
                    <option value="">All in scope</option>
                    @foreach ($districts as $d)<option value="{{ $d->uuid }}" @selected(request('district_id') === $d->uuid)>{{ $d->name_en }}</option>@endforeach
                </select>
            </div>
        @endif
        <div class="filter-extra">
            <label class="label">Status</label>
            <select name="status" class="input">
                <option value="">All</option>
                @foreach ($statuses as $s)<option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>@endforeach
            </select>
        </div>
        <div class="filter-extra">
            <label class="label">Priority</label>
            <select name="priority" class="input">
                <option value="">All</option>
                @foreach ($priorities as $p)<option @selected(request('priority') === $p->value)>{{ $p->value }}</option>@endforeach
            </select>
        </div>
        <div class="filter-extra">
            <label class="label">Category</label>
            <select name="category_id" class="input">
                <option value="">All</option>
                @foreach ($categories as $c)<option value="{{ $c->uuid }}" @selected(request('category_id') === $c->uuid)>{{ $c->name_en }}</option>@endforeach
            </select>
        </div>
        <div class="filter-extra">
            <label class="label">Escalation</label>
            <select name="escalation" class="input">
                <option value="">Any</option>
                @foreach (\App\Enums\EscalationState::cases() as $e)<option @selected(request('escalation') === $e->value)>{{ $e->value }}</option>@endforeach
            </select>
        </div>
        <div class="filter-extra flex items-end gap-2">
            <label class="mb-2 flex items-center gap-1.5 text-xs text-slate-600"><input type="checkbox" name="overdue" value="1" @checked(request('overdue'))> Overdue</label>
        </div>
        <div class="col-span-2 flex flex-wrap items-end gap-2 md:col-span-4 xl:col-span-8">
            <button class="btn btn-primary">Filter</button>
            <a href="{{ route('district.reports.index') }}" class="btn btn-ghost">Reset</a>
            <div class="flex-1"></div>
            <span class="text-xs text-slate-500">{{ $reports->total() }} reports</span>
            <a href="{{ route('district.reports.export', request()->query()) }}" class="btn btn-ghost">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v12m0 0-4-4m4 4 4-4M4 20h16"/></svg> <span class="hidden sm:inline">Export CSV</span><span class="sm:hidden">CSV</span>
            </a>
        </div>
    </form>

    <x-card :padding="false">
        {{-- Phones: one card per report --}}
        <div class="divide-y divide-slate-100 sm:hidden">
            @forelse ($reports as $r)
                <button type="button" class="block w-full px-4 py-3 text-left active:bg-teal-50" onclick="openDrawer(@js(route('district.reports.drawer', $r)))">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-sm font-semibold">{{ $r->reference_code }}</span>
                        @if ($r->attachments_count)<span class="text-xs text-sky-700">📷{{ $r->attachments_count > 1 ? $r->attachments_count : '' }}</span>@endif
                        @if ($r->possible_duplicate_of_id)<span class="text-amber-600">⧉</span>@endif
                        <span class="flex-1"></span>
                        @if ($r->priority)<x-chip :color="$r->priority->color()">{{ $r->priority->value }}</x-chip>@endif
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <x-chip :color="$r->status->color()" dot>{{ $r->status->label() }}</x-chip>
                        @if ($r->escalation_state->value !== 'NONE')<x-chip :color="$r->escalation_state->color()">{{ $r->escalation_state->value }}</x-chip>@endif
                        @if ($r->activeAssignment?->isOverdue())<span class="text-xs font-semibold text-orange-600">⏰ overdue</span>@endif
                    </div>
                    <div class="mt-1 text-xs text-slate-500">{{ $r->category?->name_en ?? 'Unclassified' }} · {{ $r->location?->name_en }}, {{ $r->district?->name_en }}</div>
                    <div class="text-xs text-slate-400">{{ \App\Support\Fmt::dt($r->submitted_at) }} · {{ $r->activeAssignment?->officer?->name ?? 'unassigned' }}</div>
                </button>
            @empty
                <x-empty />
            @endforelse
        </div>
        <div class="hidden overflow-x-auto sm:block">
            <table class="data w-full">
                <thead><tr>
                    <th>Reference</th><th>Submitted</th><th>Location</th><th>Category</th><th>Priority</th><th>Status</th><th>Escalation</th><th>Officer</th>
                </tr></thead>
                <tbody>
                @forelse ($reports as $r)
                    <tr class="clickable" onclick="openDrawer(@js(route('district.reports.drawer', $r)))">
                        <td class="font-mono text-xs font-semibold">
                            {{ $r->reference_code }}
                            @if ($r->possible_duplicate_of_id)<span title="Possible duplicate" class="ml-1 text-amber-600">⧉</span>@endif
                            @if ($r->attachments_count)<span title="{{ $r->attachments_count }} photo(s) attached" class="ml-1 whitespace-nowrap font-normal text-sky-700">📷{{ $r->attachments_count > 1 ? $r->attachments_count : '' }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap text-xs text-slate-600">{{ \App\Support\Fmt::dt($r->submitted_at) }}</td>
                        <td class="text-xs">
                            <div class="font-medium text-slate-700">{{ $r->location?->name_en }}</div>
                            <div class="text-slate-500">{{ $r->district?->name_en }}</div>
                        </td>
                        <td class="text-xs">
                            {{ $r->category?->name_en ?? 'Unclassified' }}
                            @if ($r->triage_status === 'DONE')<div class="text-[10px] uppercase tracking-wide text-amber-600">AI · unverified</div>@endif
                        </td>
                        <td>@if ($r->priority)<x-chip :color="$r->priority->color()">{{ $r->priority->value }}</x-chip>@else<span class="text-xs text-slate-400">—</span>@endif</td>
                        <td><x-chip :color="$r->status->color()" dot>{{ $r->status->label() }}</x-chip></td>
                        <td>@if ($r->escalation_state->value !== 'NONE')<x-chip :color="$r->escalation_state->color()">{{ $r->escalation_state->value }}</x-chip>@endif</td>
                        <td class="text-xs text-slate-600">
                            {{ $r->activeAssignment?->officer?->name ?? '—' }}
                            @if ($r->activeAssignment?->isOverdue())<div class="font-semibold text-orange-600">⏰ overdue</div>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-empty /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 px-4 py-3">{{ $reports->links() }}</div>
    </x-card>

    {{-- Side drawer --}}
    <div id="drawer" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/40" onclick="closeDrawer()"></div>
        <aside class="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <h3 class="text-sm font-semibold">Report details</h3>
                <button onclick="closeDrawer()" class="rounded p-1 text-slate-500 hover:bg-slate-100" aria-label="Close">✕</button>
            </div>
            <div id="drawer-body" class="flex-1 overflow-y-auto p-5 text-sm"></div>
        </aside>
    </div>
@endsection

@push('scripts')
<script>
    async function openDrawer(drawerUrl) {
        const body = document.getElementById('drawer-body');
        body.innerHTML = '<div class="animate-pulse space-y-3"><div class="h-4 w-1/3 rounded bg-slate-200"></div><div class="h-20 rounded bg-slate-100"></div><div class="h-40 rounded bg-slate-100"></div></div>';
        document.getElementById('drawer').classList.remove('hidden');
        try {
            const res = await fetch(drawerUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error(res.status);
            body.innerHTML = await res.text();
        } catch (e) {
            body.innerHTML = '<div class="rounded-lg bg-rose-50 p-4 text-rose-700">Could not load this report (' + e.message + ').</div>';
        }
    }
    function closeDrawer() { document.getElementById('drawer').classList.add('hidden'); }
    document.addEventListener('keydown', e => e.key === 'Escape' && closeDrawer());
</script>
@endpush
