@extends('layouts.guest')
@section('title', 'Two-factor verification')
@section('content')
    <h2 class="text-lg font-semibold text-slate-900">Two-factor verification</h2>
    <p class="mb-5 text-sm text-slate-500">Enter the 6-digit code from your authenticator app.</p>
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ url('/mfa') }}" class="space-y-4">
        @csrf
        <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus
               class="input text-center text-2xl tracking-[0.5em]" placeholder="••••••">
        <button class="w-full rounded-lg bg-teal-600 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">Verify</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="mt-3 text-center">@csrf
        <button class="text-xs text-slate-500 hover:underline">Sign out</button>
    </form>
@endsection
