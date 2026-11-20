@extends('layouts.guest')
@section('content')
    <h2 class="text-lg font-semibold text-slate-900">Sign in to the dashboard</h2>
    <p class="mb-5 text-sm text-slate-500">Use your COHERS staff account.</p>
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ url('/login') }}" class="space-y-4">
        @csrf
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700" for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input">
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700" for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
        </div>
        <button class="w-full rounded-lg bg-teal-600 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">Sign in</button>
    </form>
    <p class="mt-4 text-xs text-slate-500">After 5 failed attempts the account is locked for 15 minutes. All sign-ins are audited.</p>
@endsection
