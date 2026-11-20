@extends('layouts.app')
@section('title', 'My Field Cases')
@section('subtitle', (auth()->user()->officer_type?->label() ?? 'Field officer').' · cases assigned to you')
@section('content')
<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
    <x-kpi label="Waiting for you to acknowledge" :value="$kpi['pending']" tone="sky" />
    <x-kpi label="Acknowledgement overdue" :value="$kpi['overdue']" tone="rose" />
    <x-kpi label="Verifying now" :value="$kpi['verifying']" tone="teal" />
    <x-kpi label="Finished (30 days)" :value="$kpi['done_30d']" />
</div>

<x-card title="Active cases" subtitle="Open a case to acknowledge, verify, ask the reporter, escalate or submit findings" :padding="false">
    @forelse ($active as $a)
        @php $r = $a->report; @endphp
        <a href="{{ route('field.cases.show', $r) }}" class="flex flex-wrap items-start gap-3 border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-sm font-semibold">{{ $r->reference_code }}</span>
                    @if ($r->priority)<x-chip :color="$r->priority->color()">{{ $r->priority->value }}</x-chip>@endif
                    <x-chip :color="$r->status->color()" dot>{{ $r->status->label() }}</x-chip>
                    <span class="text-xs text-slate-500">{{ $r->category?->name_en ?? 'Unclassified' }} · {{ $r->location?->fullPath() }}</span>
                </div>
                <p class="mt-1 line-clamp-1 text-sm text-slate-600">{{ $r->original_text }}</p>
            </div>
            <div class="text-right text-xs">
                @if ($a->assignment_status->value === 'PENDING')
                    <div class="{{ $a->isOverdue() ? 'font-semibold text-rose-600' : 'text-slate-600' }}">
                        {{ $a->isOverdue() ? '⏰ overdue by '.$a->ack_due_at->diffForHumans(null, true) : 'acknowledge by '.\App\Support\Fmt::dt($a->ack_due_at, 'd M H:i') }}
                    </div>
                @else
                    <div class="text-slate-500">{{ $a->assignment_status->value === 'VERIFYING' ? 'verifying since' : 'acknowledged' }} {{ \App\Support\Fmt::dt($a->verification_started_at ?? $a->acknowledged_at, 'd M H:i') }}</div>
                @endif
            </div>
        </a>
    @empty
        <x-empty text="No active cases assigned to you." />
    @endforelse
</x-card>

@if ($recent->isNotEmpty())
    <x-card title="Recently finished or reassigned" class="mt-5" :padding="false">
        @foreach ($recent as $a)
            <a href="{{ route('field.cases.show', $a->report) }}" class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-2.5 text-sm last:border-0 hover:bg-slate-50">
                <span class="font-mono font-semibold">{{ $a->report->reference_code }}</span>
                <x-chip :color="$a->report->status->color()" dot>{{ $a->report->status->label() }}</x-chip>
                <span class="text-xs text-slate-500">{{ $a->assignment_status->value === 'REASSIGNED' ? 'passed to another officer' : 'your part is done' }} · {{ \App\Support\Fmt::dt($a->updated_at, 'd M') }}</span>
            </a>
        @endforeach
    </x-card>
@endif
@endsection
