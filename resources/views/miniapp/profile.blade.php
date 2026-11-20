@extends('layouts.miniapp')
@section('title', __('bot.miniapp.tab_profile'))
@section('tab', 'profile')
@section('content')
<div class="mb-4 flex items-center gap-3">
    <div class="grid h-14 w-14 place-items-center rounded-full bg-teal-600 text-xl font-bold text-white">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</div>
    <div class="min-w-0">
        <h1 class="truncate text-xl font-bold">{{ $user->name }}</h1>
        @if ($user->telegramAccount?->username)<p class="hint text-sm">{{ '@'.$user->telegramAccount->username }}</p>@endif
    </div>
</div>

<div class="mb-3 grid {{ $points !== null ? 'grid-cols-3' : 'grid-cols-2' }} gap-2 text-center">
    <div class="card p-3 shadow-sm"><div class="text-xl font-bold">{{ $reportsSent }}</div><div class="hint text-xs">{{ __('bot.miniapp.reports_sent') }}</div></div>
    @if ($points !== null)
        <div class="card p-3 shadow-sm"><div class="text-xl font-bold text-amber-600">⭐ {{ $points }}</div><div class="hint text-xs">{{ __('bot.miniapp.points') }}</div></div>
    @endif
    <div class="card p-3 shadow-sm"><div class="text-sm font-bold">{{ \App\Support\Fmt::dt($user->consented_at ?? $user->created_at, 'd M Y') }}</div><div class="hint text-xs">{{ __('bot.miniapp.joined') }}</div></div>
</div>
@if ($points !== null)<p class="hint -mt-1 mb-3 text-center text-xs">{{ __('bot.miniapp.points_hint') }}</p>@endif

<form method="POST" action="{{ route('my.profile.update') }}" class="card mb-3 space-y-3 p-4 shadow-sm">
    @csrf
    <label class="block"><span class="text-sm font-semibold">{{ __('bot.miniapp.name') }}</span>
        <input name="name" value="{{ old('name', $user->name) }}" class="field mt-1" required minlength="2" maxlength="120">
    </label>
    <label class="block"><span class="text-sm font-semibold">{{ __('bot.miniapp.phone') }}</span> <span class="hint text-xs">({{ __('bot.miniapp.optional') }})</span>
        <input name="phone" type="tel" inputmode="tel" value="{{ old('phone', $user->phone) }}" class="field mt-1" maxlength="32" placeholder="012 345 678">
        <span class="hint mt-0.5 block text-xs">{{ __('bot.miniapp.phone_hint') }}</span>
    </label>
    <div><span class="text-sm font-semibold">🌐 {{ __('bot.miniapp.language') }}</span>
        <div class="mt-1 grid grid-cols-2 gap-2">
            @foreach (['km' => 'ភាសាខ្មែរ', 'en' => 'English'] as $code => $label)
                <label class="flex cursor-pointer items-center justify-center rounded-xl border border-slate-300/60 py-2 text-sm has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50 has-[:checked]:text-teal-900">
                    <input type="radio" name="preferred_language" value="{{ $code }}" class="sr-only" @checked(old('preferred_language', $user->locale()) === $code)>{{ $label }}
                </label>
            @endforeach
        </div>
    </div>
    <button class="btn-main">{{ __('bot.miniapp.save') }}</button>
</form>

<details class="card mb-3 p-4 shadow-sm">
    <summary class="cursor-pointer text-sm font-semibold">❓ {{ __('bot.miniapp.help_title') }}</summary>
    {{-- Our own wording from the language file (may contain <b>). --}}
    <div class="mt-2 text-sm leading-relaxed">{!! nl2br(strip_tags(__('bot.help'), '<b><i>')) !!}</div>
</details>

<details class="card p-4 shadow-sm">
    <summary class="cursor-pointer text-sm font-semibold">🔒 {{ __('bot.miniapp.privacy_title') }}</summary>
    <p class="mt-2 text-sm leading-relaxed">{{ __('bot.miniapp.privacy_text') }}</p>
</details>
@endsection
