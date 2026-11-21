@extends('layouts.app')
@section('title', $report->reference_code)
@section('subtitle', $report->location?->fullPath())
@section('content')
@php
    $u = auth()->user();
    $active = $report->assignments->first(fn ($a) => $a->isActive());
    $openEsc = $report->escalations->filter->isOpen();
@endphp
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('district.reports.index') }}" class="btn btn-ghost">← Reports</a>
    <x-chip :color="$report->status->color()" dot>{{ $report->status->label() }}</x-chip>
    @if ($report->priority)<x-chip :color="$report->priority->color()">{{ $report->priority->value }}</x-chip>@endif
    @if ($report->escalation_state->value !== 'NONE')<x-chip :color="$report->escalation_state->color()">Escalation {{ $report->escalation_state->value }}</x-chip>@endif
    <span class="text-xs text-slate-500">Acknowledged ≠ Verified ≠ Closed</span>
</div>

<div class="space-y-5">
    <x-card title="Original report" subtitle="Preserved exactly as submitted">
        <p class="whitespace-pre-line text-[15px] leading-relaxed text-slate-800">{{ $report->original_text }}</p>
        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div><dt class="label">Category</dt><dd>{{ $report->category?->name_en ?? 'Unclassified' }}<div class="text-xs text-slate-500">{{ $report->subcategory }}</div></dd></div>
            <div><dt class="label">Affected</dt><dd>{{ $report->affected_count ?? '—' }}</dd></div>
            <div><dt class="label">Observed</dt><dd>{{ $report->observed_text ?? '—' }}</dd></div>
            <div><dt class="label">Submitted</dt><dd>{{ \App\Support\Fmt::dt($report->submitted_at) }}<div class="text-xs text-slate-500">via {{ $report->channel }} · {{ strtoupper($report->language) }}</div></dd></div>
            <div class="col-span-2"><dt class="label">Location</dt><dd>{{ $report->location?->fullPath() }} <span class="text-xs text-slate-400">({{ $report->location?->code }})</span></dd></div>
            <div><dt class="label">Location pin</dt><dd>{{ $report->latitude ? 'Provided (restricted)' : 'None' }}</dd></div>
            <div><dt class="label">Reporter</dt><dd class="text-slate-500">Protected identity</dd></div>
        </dl>
        @if ($report->possibleDuplicateOf)
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                ⧉ Possible duplicate of <a class="font-mono font-semibold underline" href="{{ route('district.reports.show', $report->possibleDuplicateOf) }}">{{ $report->possibleDuplicateOf->reference_code }}</a>
                (score {{ number_format($report->duplicate_score, 2) }}). Flag only — both submissions are preserved; link them below if confirmed.
            </div>
        @endif
    </x-card>

    @if ($report->attachments->isNotEmpty())
        <x-card title="Evidence ({{ $report->attachments->count() }})" subtitle="Private storage · click a photo for full size · access is logged">
            @include('district.reports._photos', ['attachments' => $report->attachments, 'height' => 'h-40'])
        </x-card>
    @endif

    <x-card title="AI triage history" subtitle="Advisory only — never a diagnosis or verification" :padding="false">
        <div class="overflow-x-auto">
            <table class="data w-full">
                <thead><tr><th>When</th><th>Provider / model</th><th>Outcome</th><th>Category</th><th>Suggested</th><th>Confidence</th><th>Review</th></tr></thead>
                <tbody>
                @forelse ($report->triages as $t)
                    <tr>
                        <td class="text-xs">{{ \App\Support\Fmt::dt($t->created_at) }}</td>
                        <td class="text-xs">{{ $t->provider }}<div class="text-slate-400">{{ $t->model }}</div></td>
                        <td><x-chip :color="$t->outcome === 'success' ? 'emerald' : 'rose'">{{ $t->outcome }}</x-chip>@if ($t->error)<div class="mt-1 max-w-xs truncate text-[11px] text-rose-600" title="{{ $t->error }}">{{ $t->error }}</div>@endif</td>
                        <td class="text-xs">{{ $t->category_code ?? '—' }}<div class="text-slate-400">{{ $t->subcategory }}</div></td>
                        <td class="text-xs">{{ $t->suggested_priority ?? '—' }}</td>
                        <td class="text-xs tabular-nums">{{ $t->confidence !== null ? number_format($t->confidence, 2) : '—' }}</td>
                        <td class="text-xs">{{ $t->needs_manual_review ? 'Yes' : 'No' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-empty text="No triage attempts yet." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card title="Complete timeline" subtitle="Submission → closure, every decision audited">
        @include('district.reports._timeline')
    </x-card>

    <x-card title="Assignment">
        @if ($active)
            <div class="text-sm">
                <div class="font-semibold">{{ $active->officer->name }}</div>
                <div class="text-xs text-slate-500">{{ $active->officer->officer_type?->label() }} · {{ ucfirst($active->level) }} · {{ $active->assignment_status->value }}</div>
                <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div><dt class="text-slate-400">Assigned</dt><dd>{{ \App\Support\Fmt::dt($active->assigned_at) }}</dd></div>
                    <div><dt class="text-slate-400">Ack due</dt><dd class="{{ $active->isOverdue() ? 'font-semibold text-orange-600' : '' }}">{{ \App\Support\Fmt::dt($active->ack_due_at) }}</dd></div>
                    <div><dt class="text-slate-400">Acknowledged</dt><dd>{{ \App\Support\Fmt::dt($active->acknowledged_at) }}</dd></div>
                    <div><dt class="text-slate-400">Verification started</dt><dd>{{ \App\Support\Fmt::dt($active->verification_started_at) }}</dd></div>
                </dl>
            </div>
        @else
            <p class="text-sm text-slate-500">No active focal-point assignment.</p>
        @endif
        @if ($report->assignments->count() > ($active ? 1 : 0))
            <details class="mt-3 text-xs text-slate-500">
                <summary class="cursor-pointer">Previous assignments ({{ $report->assignments->count() - ($active ? 1 : 0) }})</summary>
                <ul class="mt-2 space-y-1">
                    @foreach ($report->assignments->reject(fn ($a) => $a->is($active)) as $a)
                        <li>{{ $a->officer->name }} · {{ $a->level }} · {{ $a->assignment_status->value }} · {{ \App\Support\Fmt::dt($a->assigned_at) }}</li>
                    @endforeach
                </ul>
            </details>
        @endif

        @can('reassign', $report)
            <form method="POST" action="{{ route('district.reports.reassign', $report) }}" class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                @csrf
                <label class="label">{{ $u->isDistrictOfficer() ? 'Reassign to' : 'Admin correction: reassign to' }}</label>
                @php $needType = $report->category?->sector?->officerType(); @endphp
                @if ($officers->isEmpty())
                    <p class="rounded bg-amber-50 p-2 text-xs text-amber-800">No eligible officer yet for this case.</p>
                @else
                    <select name="officer_id" class="input" required>
                        <option value="">Select focal point…</option>
                        @foreach ($officers as $o)
                            <option value="{{ $o->id }}">{{ $o->name }} — {{ $o->officer_type?->value }}</option>
                        @endforeach
                    </select>
                    <textarea name="reason" rows="2" class="input" placeholder="Reason (required, audited)" required></textarea>
                    <button class="btn btn-ghost w-full justify-center">Reassign</button>
                @endif
                <p class="text-[11px] leading-relaxed text-slate-500">
                    Shows active <b>{{ $needType ? $needType->value : 'VHSG or CAO' }}</b> focal points
                    @if ($report->category) ({{ $report->category->name_en }} case) @else (unclassified case — classify it below to narrow the list) @endif
                    who cover <b>{{ $report->district?->name_en ?? 'this district' }}</b> through a
                    @if ($u->isAdmin())<a href="{{ route('admin.routing.index', ['district' => $report->district_id]) }}" class="text-teal-700 underline">Focal Point Router</a>@else Focal Point Router @endif
                    mapping or their account's district scope.
                </p>
            </form>
        @endcan
    </x-card>

    @can('triage', $report)
        <x-card title="Manual triage" subtitle="Record a human classification; AI stays advisory">
            <form method="POST" action="{{ route('district.reports.triage', $report) }}" class="space-y-2">
                @csrf
                <div class="grid grid-cols-2 gap-2">
                    <select name="category_id" class="input" required>
                        @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($report->category_id === $c->id)>{{ $c->name_en }}</option>@endforeach
                    </select>
                    <select name="priority" class="input" required>
                        @foreach (\App\Enums\Priority::cases() as $p)<option @selected($report->priority === $p)>{{ $p->value }}</option>@endforeach
                    </select>
                </div>
                <textarea name="reason" rows="2" class="input" placeholder="Reason for classification (audited)" required></textarea>
                @unless ($active)
                    <label class="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" name="route" value="1" checked> Route to focal point after saving</label>
                @endunless
                <button class="btn btn-ghost w-full justify-center">Save classification</button>
            </form>
        </x-card>
    @endcan

    @if ($report->escalations->isNotEmpty())
        <x-card title="Escalations">
            <div class="space-y-3">
                @foreach ($report->escalations as $e)
                    <div class="rounded-lg border border-slate-200 p-3 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold">{{ $e->trigger->value }}</span>
                            <x-chip :color="$e->escalation_state->color()">{{ $e->escalation_state->value }}</x-chip>
                        </div>
                        <p class="mt-1 text-xs text-slate-600">{{ $e->reason }}</p>
                        @if ($e->decision)<p class="mt-1 text-xs text-emerald-700"><b>{{ $decisions[$e->decision] ?? $e->decision }}</b> — {{ $e->decision_notes }}</p>@endif
                        @if ($e->isOpen())
                            @can('reviewEscalation', $report)
                                <div class="mt-2 space-y-2">
                                    @if ($e->escalation_state->value === 'OPEN')
                                        <form method="POST" action="{{ route('district.escalations.review', $e) }}">@csrf<button class="btn btn-ghost w-full justify-center text-xs">Start review</button></form>
                                    @endif
                                    <form method="POST" action="{{ route('district.escalations.resolve', $e) }}" class="space-y-2">
                                        @csrf
                                        <select name="decision" class="input" required>
                                            @foreach ($decisions as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                        </select>
                                        <textarea name="notes" rows="2" class="input" placeholder="District decision notes" required></textarea>
                                        <button class="btn btn-primary w-full justify-center text-xs">Record decision</button>
                                    </form>
                                </div>
                            @else
                                <p class="mt-2 text-[11px] text-slate-400">Monitoring only — district officers resolve escalations.</p>
                            @endcan
                        @endif
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    <x-card title="Incident grouping">
        @forelse ($report->incidents as $inc)
            <div class="mb-2 text-sm"><span class="font-mono font-semibold">{{ $inc->code }}</span> — {{ $inc->title }}
                <div class="text-xs text-slate-500">Linked reports: {{ $inc->reports->pluck('reference_code')->implode(', ') }}</div>
            </div>
        @empty
            <p class="text-sm text-slate-500">Not linked to an incident.</p>
        @endforelse
        @can('linkIncident', $report)
            <form method="POST" action="{{ route('district.reports.link', $report) }}" class="mt-3 space-y-2 border-t border-slate-100 pt-3">
                @csrf
                <input name="other_code" class="input font-mono" placeholder="Other report code, e.g. COH-2026-000012" value="{{ $report->possibleDuplicateOf?->reference_code }}" required>
                <input name="reason" class="input" placeholder="Reason (optional)">
                <button class="btn btn-ghost w-full justify-center">Link under shared incident</button>
            </form>
        @endcan
    </x-card>

    @can('close', $report)
        <x-card title="{{ $report->status->value === 'CLOSED' ? 'Reopen case' : 'Approve closure' }}">
            @if ($report->status->value === 'CLOSED')
                @php $c = $report->closures->whereNull('reopened_at')->sortByDesc('closed_at')->first(); @endphp
                @if ($c)<p class="mb-3 text-xs text-slate-600">Closed {{ \App\Support\Fmt::dt($c->closed_at) }} by {{ $c->closer?->name }}: {{ $c->outcome_summary }}</p>@endif
                <form method="POST" action="{{ route('district.reports.reopen', $report) }}" class="space-y-2">
                    @csrf
                    <textarea name="reason" rows="2" class="input" placeholder="Reopen reason (audited)" required></textarea>
                    <button class="btn btn-ghost w-full justify-center">Reopen</button>
                </form>
            @elseif ($closeBlocker)
                <p class="text-sm text-slate-500">{{ $closeBlocker }}</p>
            @else
                <form method="POST" action="{{ route('district.reports.close', $report) }}" class="space-y-2">
                    @csrf
                    <label class="label">Internal outcome (required, not sent to reporter)</label>
                    <textarea name="outcome_summary" rows="3" class="input" placeholder="Documented outcome" required></textarea>
                    <textarea name="response_steps" rows="2" class="input" placeholder="Response steps taken (internal)"></textarea>
                    <label class="label">Message to reporter (optional, sent via Telegram)</label>
                    <textarea name="reporter_message" rows="2" maxlength="1000" class="input" placeholder="e.g. A vet visited and vaccinated the remaining poultry. Thank you."></textarea>
                    <p class="text-xs text-slate-500">The reporter always gets a closure notice with the result (confirmed / not confirmed / combined with an earlier report). Officer notes are never shared.</p>
                    <button class="btn btn-primary w-full justify-center">Approve closure</button>
                </form>
            @endif
        </x-card>
    @else
        <x-card title="Closure">
            <p class="text-sm text-slate-500">Only a district officer assigned to {{ $report->district?->name_en ?? 'this district' }} can approve closure or reopen this case.
                @if ($report->status->isClosable()) The field outcome is recorded and it is waiting for district approval. @endif</p>
        </x-card>
    @endcan
</div>
@endsection
