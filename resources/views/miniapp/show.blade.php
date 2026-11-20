@extends('layouts.miniapp')
@section('title', $report->reference_code)
@section('back', route('my.index'))
@section('tab', 'cases')
@section('content')
@php
    $key = $report->status->reporterKey();
    $tone = ['received' => 'bg-slate-100 text-slate-700', 'being_reviewed' => 'bg-amber-100 text-amber-800', 'assigned' => 'bg-sky-100 text-sky-800',
        'under_verification' => 'bg-indigo-100 text-indigo-800', 'more_info_needed' => 'bg-orange-100 text-orange-800',
        'reviewed' => 'bg-teal-100 text-teal-800', 'closed' => 'bg-emerald-100 text-emerald-800'];
    $dot = ['status' => 'bg-teal-500', 'question' => 'bg-orange-400', 'answer' => 'bg-sky-500', 'update' => 'bg-indigo-400', 'outcome' => 'bg-emerald-500'];
    $closed = $report->status === \App\Enums\ReportStatus::CLOSED;
@endphp


<div class="card mb-3 p-4 shadow-sm">
    <div class="flex items-center gap-2">
        <span class="font-mono text-base font-bold">{{ $report->reference_code }}</span>
        <span class="flex-1"></span>
        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $tone[$key] ?? $tone['received'] }}">{{ __('bot.status.'.$key) }}</span>
    </div>
    <div class="hint mt-1 text-xs">{{ __('bot.miniapp.submitted', ['date' => \App\Support\Fmt::dt($report->submitted_at)]) }}</div>
    @if ($handledBy)<div class="mt-2 text-sm">👤 {{ $handledBy }}</div>@endif
</div>

@if ($openQuestion)
    <div class="card mb-3 border-2 border-orange-300 p-4 shadow-sm">
        <div class="text-sm font-semibold text-orange-700">❓ {{ __('bot.miniapp.officer_asked') }}</div>
        <p class="mt-1 whitespace-pre-line text-sm">{{ $openQuestion->question }}</p>
        <form method="POST" action="{{ route('my.answer', $report) }}" class="mt-3 space-y-2">
            @csrf
            <input type="hidden" name="question" value="{{ $openQuestion->id }}">
            <textarea name="answer" rows="3" class="field" placeholder="{{ __('bot.miniapp.answer_placeholder') }}" required>{{ old('answer') }}</textarea>
            <button class="btn-main">{{ __('bot.miniapp.send_answer') }}</button>
        </form>
    </div>
@endif

<div class="card mb-3 p-4 shadow-sm">
    <div class="hint mb-1 text-xs font-semibold uppercase tracking-wide">{{ __('bot.miniapp.your_report') }}</div>
    <p class="whitespace-pre-line text-[15px] leading-relaxed">{{ $report->original_text }}</p>
    <div class="hint mt-3 text-xs font-semibold uppercase tracking-wide">{{ __('bot.miniapp.location') }}</div>
    <p class="text-sm">{{ $report->location?->fullPath() }}</p>
    @if ($report->attachments->isNotEmpty())
        <div class="hint mt-3 text-xs font-semibold uppercase tracking-wide">{{ __('bot.miniapp.photos') }}</div>
        <div class="mt-1 grid grid-cols-3 gap-2">
            @foreach ($report->attachments->filter(fn ($a) => $a->path) as $a)
                <a href="{{ route('my.photo', $a) }}" target="_blank"><img src="{{ route('my.photo', $a) }}" alt="" class="h-24 w-full rounded-lg object-cover" loading="lazy"></a>
            @endforeach
        </div>
    @endif
</div>

<div class="card mb-3 p-4 shadow-sm">
    <div class="hint mb-3 text-xs font-semibold uppercase tracking-wide">{{ __('bot.miniapp.progress') }}</div>
    <ol class="space-y-3">
        @foreach ($events as $e)
            <li class="flex gap-3">
                <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $dot[$e['kind']] ?? 'bg-slate-400' }}"></span>
                <div class="min-w-0">
                    <div class="text-sm font-semibold">{{ $e['title'] }}</div>
                    @if (! empty($e['body']))<p class="whitespace-pre-line text-sm">{{ $e['body'] }}</p>@endif
                    @if (! empty($e['note']))<p class="mt-1 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900"><b>{{ __('bot.miniapp.message_from_district') }}:</b> {{ $e['note'] }}</p>@endif
                    <div class="hint text-xs">{{ \App\Support\Fmt::dt($e['at']) }}</div>
                </div>
            </li>
        @endforeach
    </ol>
</div>

@if ($closed)
    <div class="card p-4 text-sm shadow-sm">✅ {{ __('bot.miniapp.closed_note') }}
        <a href="{{ route('my.create') }}" class="btn-main mt-3 block text-center">📝 {{ __('bot.miniapp.new_report') }}</a>
    </div>
@else
    @php $infoOpen = old('body') !== null || $errors->has('body'); @endphp
    <div class="card p-4 shadow-sm">
        {{-- Closed until tapped; Cancel closes it again and clears what was typed. --}}
        <button type="button" id="addInfoOpen" class="flex w-full items-center gap-2 text-left text-sm font-semibold link" @if ($infoOpen) hidden @endif>
            <span class="text-lg leading-none">＋</span> {{ __('bot.miniapp.add_info') }}
        </button>
        <form method="POST" action="{{ route('my.update', $report) }}" id="addInfoForm" class="space-y-2" @unless ($infoOpen) hidden @endunless>
            @csrf
            <div class="text-sm font-semibold">➕ {{ __('bot.miniapp.add_info') }}</div>
            <p class="hint text-xs">{{ __('bot.miniapp.add_info_hint') }}</p>
            <textarea name="body" id="addInfoBody" rows="3" class="field" placeholder="{{ __('bot.miniapp.add_info_placeholder') }}" required minlength="2" maxlength="2000">{{ old('body') }}</textarea>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" id="addInfoCancel" class="btn-second">{{ __('bot.miniapp.cancel') }}</button>
                <button class="btn-main">{{ __('bot.miniapp.save') }}</button>
            </div>
        </form>
    </div>
    <script>
        (() => {
            const open = document.getElementById('addInfoOpen'), form = document.getElementById('addInfoForm'), body = document.getElementById('addInfoBody');
            open.addEventListener('click', () => { open.hidden = true; form.hidden = false; body.focus(); });
            document.getElementById('addInfoCancel').addEventListener('click', () => { body.value = ''; form.hidden = true; open.hidden = false; });
        })();
    </script>
@endif
@endsection
