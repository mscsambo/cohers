@extends('layouts.app')
@section('title', 'Escalations')
@section('content')
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['open' => 'Open & in review', 'resolved' => 'Resolved', 'all' => 'All'] as $k => $label)
            <a href="{{ route('district.escalations.index', ['state' => $k] + request()->except('state', 'page')) }}"
               class="btn {{ $state === $k ? 'btn-primary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
        <div class="flex-1"></div>
        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="state" value="{{ $state }}">
            <select name="trigger" class="input w-44" onchange="this.form.submit()">
                <option value="">All triggers</option>
                @foreach (\App\Enums\EscalationTrigger::cases() as $t)<option @selected(request('trigger') === $t->value)>{{ $t->value }}</option>@endforeach
            </select>
        </form>
    </div>

    <div class="space-y-3">
        @forelse ($escalations as $e)
            @php $r = $e->report; @endphp
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-chip :color="$e->escalation_state->color()">{{ $e->escalation_state->value }}</x-chip>
                            <x-chip color="zinc">{{ $e->trigger->value }}</x-chip>
                            <a href="{{ route('district.reports.show', $r) }}" class="font-mono text-sm font-semibold text-teal-700 hover:underline">{{ $r->reference_code }}</a>
                            @if ($r->priority)<x-chip :color="$r->priority->color()">{{ $r->priority->value }}</x-chip>@endif
                            <x-chip :color="$r->status->color()" dot>{{ $r->status->label() }}</x-chip>
                        </div>
                        <p class="mt-2 text-sm text-slate-700">{{ $e->reason }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $r->category?->name_en ?? 'Unclassified' }} · {{ $r->location?->fullPath() }} · opened {{ \App\Support\Fmt::dt($e->opened_at) }} ({{ $e->opened_at->diffForHumans() }}) by {{ $e->opener?->name ?? 'system' }}</p>
                        <p class="mt-1 line-clamp-2 text-xs italic text-slate-500">“{{ $r->original_text }}”</p>
                        @if ($e->decision)
                            <p class="mt-2 rounded bg-emerald-50 px-2 py-1 text-xs text-emerald-800"><b>{{ $decisions[$e->decision] ?? $e->decision }}</b> by {{ $e->reviewer?->name }} — {{ $e->decision_notes }}</p>
                        @endif
                    </div>
                    @if ($e->isOpen())
                        <div class="w-full space-y-2 sm:w-80">
                            @can('reviewEscalation', $r)
                                @if ($e->escalation_state->value === 'OPEN')
                                    <form method="POST" action="{{ route('district.escalations.review', $e) }}">@csrf<button class="btn btn-ghost w-full justify-center">Start review</button></form>
                                @endif
                                <form method="POST" action="{{ route('district.escalations.resolve', $e) }}" class="space-y-2">
                                    @csrf
                                    <select name="decision" class="input">@foreach ($decisions as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
                                    <input name="notes" class="input" placeholder="Decision notes (required)" required>
                                    <button class="btn btn-primary w-full justify-center">Record decision</button>
                                </form>
                                <a href="{{ route('district.reports.show', $r) }}" class="block text-center text-xs text-teal-700 hover:underline">Reassign / request evidence on the case page →</a>
                            @else
                                <p class="rounded bg-slate-50 p-2 text-xs text-slate-500">Monitoring view. Resolution is performed by the assigned district officer.</p>
                            @endcan
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <x-card><x-empty text="No escalations in this view." /></x-card>
        @endforelse
    </div>
    <div class="mt-4">{{ $escalations->links() }}</div>
@endsection
