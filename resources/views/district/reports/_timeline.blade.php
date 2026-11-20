{{-- Unified timeline: status changes, escalations, info requests, verifications, closures --}}
@php
    $events = collect();
    foreach ($report->histories as $h) {
        $events->push(['at' => $h->created_at, 'icon' => '●', 'color' => \App\Enums\ReportStatus::tryFrom($h->to_status)?->color() ?? 'slate',
            'title' => ($h->from_status ? str_replace('_', ' ', $h->from_status).' → ' : '').str_replace('_', ' ', $h->to_status),
            'body' => $h->reason, 'who' => $h->actor_id && $h->actor_id === $report->reporter_id ? 'Reporter' : ($h->actor?->name ?? 'System'), 'channel' => $h->channel]);
    }
    foreach ($report->escalations as $e) {
        $events->push(['at' => $e->opened_at, 'icon' => '▲', 'color' => 'rose', 'title' => 'Escalation opened · '.$e->trigger->value,
            'body' => $e->reason, 'who' => $e->opener?->name ?? 'System', 'channel' => null]);
        if ($e->resolved_at) {
            $events->push(['at' => $e->resolved_at, 'icon' => '✓', 'color' => 'emerald', 'title' => 'Escalation resolved · '.(\App\Services\Escalation\EscalationService::DECISIONS[$e->decision] ?? $e->decision),
                'body' => $e->decision_notes, 'who' => $e->reviewer?->name, 'channel' => 'web']);
        }
    }
    foreach ($report->informationRequests as $q) {
        $events->push(['at' => $q->created_at, 'icon' => '?', 'color' => 'orange', 'title' => 'Clarification requested', 'body' => $q->question, 'who' => $q->requester?->name, 'channel' => 'telegram']);
        if ($q->responded_at) {
            $events->push(['at' => $q->responded_at, 'icon' => '↩', 'color' => 'sky', 'title' => 'Reporter replied', 'body' => $q->response, 'who' => 'Reporter', 'channel' => 'telegram']);
        }
    }
    foreach ($report->verifications as $v) {
        $events->push(['at' => $v->verified_at, 'icon' => '◆', 'color' => $v->outcome->value === 'VERIFIED' ? 'emerald' : ($v->outcome->value === 'ESCALATED' ? 'rose' : 'stone'),
            'title' => 'Officer outcome · '.$v->outcome->value.($v->follow_up_needed ? ' (follow-up needed)' : ''), 'body' => $v->findings, 'who' => $v->verifier?->name, 'channel' => $v->channel]);
    }
    foreach ($report->closures as $c) {
        if ($c->reopened_at) {
            $events->push(['at' => $c->reopened_at, 'icon' => '↺', 'color' => 'amber', 'title' => 'Case reopened', 'body' => $c->reopen_reason, 'who' => $c->reopener?->name, 'channel' => 'web']);
        }
    }
    foreach ($report->reporterUpdates as $u) {
        $events->push(['at' => $u->created_at, 'icon' => '+', 'color' => 'indigo', 'title' => 'Reporter added information', 'body' => $u->body, 'who' => 'Reporter', 'channel' => 'telegram mini app']);
    }
    $events = $events->sortBy(fn ($e) => $e['at']?->timestamp)->values();
@endphp
<ol class="relative space-y-4 border-l border-slate-200 pl-5">
    @foreach ($events as $e)
        <li class="relative">
            <span class="absolute -left-[27px] top-0.5 grid h-3.5 w-3.5 place-items-center rounded-full bg-white text-[9px] ring-2 ring-{{ $e['color'] }}-400 text-{{ $e['color'] }}-600">{{ $e['icon'] }}</span>
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="text-sm font-semibold text-slate-800">{{ $e['title'] }}</span>
                <span class="text-xs text-slate-400">{{ \App\Support\Fmt::dt($e['at']) }}</span>
            </div>
            @if ($e['body'])<p class="mt-0.5 whitespace-pre-line text-sm text-slate-600">{{ $e['body'] }}</p>@endif
            <p class="text-xs text-slate-400">{{ $e['who'] }}@if ($e['channel']) · {{ $e['channel'] }}@endif</p>
        </li>
    @endforeach
</ol>
