@extends('layouts.miniapp')
@section('tab', 'cases')
@section('content')
@php
    $tone = ['received' => 'bg-slate-100 text-slate-700', 'being_reviewed' => 'bg-amber-100 text-amber-800', 'assigned' => 'bg-sky-100 text-sky-800',
        'under_verification' => 'bg-indigo-100 text-indigo-800', 'more_info_needed' => 'bg-orange-100 text-orange-800',
        'reviewed' => 'bg-teal-100 text-teal-800', 'closed' => 'bg-emerald-100 text-emerald-800'];
    $filters = ['all' => __('bot.miniapp.filter_all'), 'open' => __('bot.miniapp.filter_open'), 'waiting' => __('bot.miniapp.filter_waiting'), 'closed' => __('bot.miniapp.filter_closed')];
@endphp
<h1 class="mb-0.5 text-xl font-bold">📋 {{ __('bot.miniapp.tab_cases') }}</h1>
<p class="hint mb-4 text-sm">{{ auth()->user()->name }}</p>

<div class="mb-4 grid grid-cols-3 gap-2 text-center">
    <a href="{{ route('my.index', ['filter' => 'open']) }}" class="card p-3 shadow-sm">
        <div class="text-2xl font-bold text-sky-700">{{ $counts['open'] }}</div><div class="hint text-xs">{{ __('bot.miniapp.count_open') }}</div>
    </a>
    <a href="{{ route('my.index', ['filter' => 'waiting']) }}" class="card p-3 shadow-sm {{ $counts['waiting'] ? 'ring-2 ring-orange-300' : '' }}">
        <div class="text-2xl font-bold {{ $counts['waiting'] ? 'text-orange-600' : 'hint' }}">{{ $counts['waiting'] }}</div><div class="hint text-xs">{{ __('bot.miniapp.count_waiting') }}</div>
    </a>
    <a href="{{ route('my.index', ['filter' => 'closed']) }}" class="card p-3 shadow-sm">
        <div class="text-2xl font-bold text-emerald-700">{{ $counts['closed'] }}</div><div class="hint text-xs">{{ __('bot.miniapp.count_closed') }}</div>
    </a>
</div>

<div class="-mx-4 mb-3 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
    @foreach ($filters as $key => $label)
        <a href="{{ route('my.index', $key === 'all' ? [] : ['filter' => $key]) }}"
           class="whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm {{ $filter === $key ? 'btn-main !w-auto !py-1.5' : 'card hint shadow-sm' }}">{{ $label }}</a>
    @endforeach
</div>

{{-- Reports written without internet, still on this phone (filled in by js/offline-queue.js). --}}
<div id="queuedList" hidden>
    <div class="mb-2 text-sm font-semibold">💾 {{ __('bot.miniapp.queued_title') }}</div>
    <div id="queuedItems"></div>
</div>
<script>
    (() => {
        const Q = window.CohersQueue, t = window.COHERS_OFFLINE && window.COHERS_OFFLINE.t;
        if (!Q || !t) return;
        const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const when = iso => { try { return new Date(iso).toLocaleString(window.COHERS_OFFLINE.locale === 'km' ? 'km-KH' : 'en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); } catch (e) { return iso; } };
        Q.onChange(s => {
            // Saved reports just reached the server: reload so they appear in the list with their codes.
            if (s.sent && navigator.onLine !== false) { location.replace(window.COHERS_OFFLINE.indexUrl + '?sent=' + s.sent); return; }
            const rows = s.rows || [];
            document.getElementById('queuedList').hidden = !rows.length;
            document.getElementById('queuedItems').innerHTML = rows.map(r => `
                <div class="card mb-3 border-l-4 ${r.status === 'failed' ? 'border-rose-400' : 'border-sky-400'} p-4 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="hint text-xs">${esc(t.queued_saved.replace(':time', when(r.savedAt)))}${r.photos.length ? ' · 📷 ' + r.photos.length : ''}</span>
                        <span class="flex-1"></span>
                        <button type="button" data-delete="${esc(r.key)}" class="text-xs text-rose-700 underline">${esc(t.queued_delete)}</button>
                    </div>
                    <p class="mt-1.5 line-clamp-2 text-sm">${esc(r.fields.original_text)}</p>
                    <div class="hint mt-1.5 text-xs">📍 ${esc(r.label)}</div>
                    ${r.status === 'failed' ? `<div class="mt-2 rounded-lg bg-rose-50 px-3 py-1.5 text-xs text-rose-800">${esc(t.queued_failed)} ${esc(r.error)}</div>` : ''}
                </div>`).join('');
        });
        document.getElementById('queuedItems').addEventListener('click', e => {
            const b = e.target.closest('[data-delete]');
            if (!b) return;
            const tg = window.Telegram && window.Telegram.WebApp;
            const del = ok => { if (ok) Q.remove(b.dataset.delete); };
            // Telegram's own dialog inside the Mini App (some WebViews block window.confirm).
            if (tg && tg.initData && tg.isVersionAtLeast && tg.isVersionAtLeast('6.2')) tg.showConfirm(t.queued_delete_confirm, del);
            else del(confirm(t.queued_delete_confirm));
        });
    })();
</script>

@forelse ($reports as $r)
    @php $key = $r->status->reporterKey(); @endphp
    <a href="{{ route('my.show', $r) }}" class="card mb-3 block p-4 shadow-sm active:opacity-80">
        <div class="flex items-center gap-2">
            <span class="font-mono text-sm font-semibold">{{ $r->reference_code }}</span>
            <span class="flex-1"></span>
            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $tone[$key] ?? $tone['received'] }}">{{ __('bot.status.'.$key) }}</span>
        </div>
        <p class="mt-1.5 line-clamp-2 text-sm">{{ $r->original_text }}</p>
        <div class="hint mt-1.5 text-xs">{{ __('bot.miniapp.submitted', ['date' => \App\Support\Fmt::dt($r->submitted_at, 'd M Y')]) }} · {{ $r->location?->name(app()->getLocale()) ?: $r->location?->name_en }}</div>
        @if ($r->open_questions)
            <div class="mt-2 rounded-lg bg-orange-50 px-3 py-1.5 text-xs font-medium text-orange-800">❓ {{ __('bot.miniapp.question_waiting') }}</div>
        @endif
    </a>
@empty
    <div class="card p-6 text-center text-sm">
        <div class="mb-2 text-3xl">📭</div>
        {{ $filter === 'all' ? __('bot.miniapp.no_reports') : __('bot.miniapp.no_match') }}
    </div>
@endforelse

@if ($reports->hasPages())
    <div class="mt-2 flex justify-between text-sm">
        @if ($reports->previousPageUrl())<a href="{{ $reports->previousPageUrl() }}" class="link">‹</a>@else<span></span>@endif
        <span class="hint">{{ $reports->currentPage() }} / {{ $reports->lastPage() }}</span>
        @if ($reports->nextPageUrl())<a href="{{ $reports->nextPageUrl() }}" class="link">›</a>@else<span></span>@endif
    </div>
@endif
@endsection
