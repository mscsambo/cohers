@php $t = $report->latestTriage; @endphp
<div class="space-y-5">
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-base font-bold">{{ $report->reference_code }}</span>
            <x-chip :color="$report->status->color()" dot>{{ $report->status->label() }}</x-chip>
            @if ($report->priority)<x-chip :color="$report->priority->color()">{{ $report->priority->value }}</x-chip>@endif
            @if ($report->escalation_state->value !== 'NONE')<x-chip :color="$report->escalation_state->color()">Escalation {{ $report->escalation_state->value }}</x-chip>@endif
        </div>
        <p class="mt-1 text-xs text-slate-500">{{ $report->location?->fullPath() }} · submitted {{ \App\Support\Fmt::dt($report->submitted_at) }}</p>
    </div>

    <div class="rounded-lg bg-slate-50 p-3">
        <p class="whitespace-pre-line text-slate-800">{{ $report->original_text }}</p>
        <dl class="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-500">
            <div><dt class="font-semibold">Affected</dt><dd>{{ $report->affected_count ?? '—' }}</dd></div>
            <div><dt class="font-semibold">Observed</dt><dd>{{ $report->observed_text ?? '—' }}</dd></div>
        </dl>
    </div>

    @if ($report->attachments->isNotEmpty())
        <div>
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Photos ({{ $report->attachments->count() }})</h4>
            @include('district.reports._photos', ['attachments' => $report->attachments, 'height' => 'h-28'])
        </div>
    @endif

    @if ($t)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
            <div class="mb-1 font-semibold uppercase tracking-wide">AI assessment · advisory, unverified</div>
            @if ($t->outcome === 'success')
                {{ $t->category_code }} · {{ $t->subcategory }} · suggested {{ $t->suggested_priority }} · confidence {{ number_format($t->confidence, 2) }}
                @if ($t->needs_manual_review) · <b>manual review flagged</b>@endif
            @else
                Triage {{ $t->outcome }} — {{ \Illuminate\Support\Str::limit($t->error, 120) }}
            @endif
            <div class="mt-1 text-amber-700">{{ $t->provider }} / {{ $t->model }}</div>
        </div>
    @endif

    <div>
        <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">History</h4>
        @include('district.reports._timeline')
    </div>

    <a href="{{ route('district.reports.show', $report) }}" class="btn btn-primary w-full justify-center">Open full case &amp; actions</a>
</div>
