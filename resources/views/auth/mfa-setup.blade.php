@extends('layouts.guest')
@section('title', 'Set up two-factor authentication')
@section('content')
    <h2 class="text-lg font-semibold text-slate-900">Set up two-factor authentication</h2>
    <p class="mb-4 text-sm text-slate-500">Privileged accounts must use an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…).</p>
    <ol class="mb-4 list-inside list-decimal space-y-1 text-sm text-slate-600">
        <li>Scan this QR code with your authenticator app.</li>
        <li>Enter the 6-digit code it shows.</li>
    </ol>
    <div class="mb-3 flex justify-center"><div id="qr" class="rounded-lg border border-slate-200 p-3"></div></div>
    <p class="mb-4 break-all rounded bg-slate-50 p-2 text-center font-mono text-xs text-slate-600">{{ $secret }}</p>
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('mfa.enable') }}" class="space-y-3">
        @csrf
        <input name="code" inputmode="numeric" maxlength="6" required class="input text-center text-xl tracking-[0.4em]" placeholder="123456">
        <button class="w-full rounded-lg bg-teal-600 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">Enable and continue</button>
    </form>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>new QRCode(document.getElementById('qr'), { text: @json($uri), width: 180, height: 180 });</script>
@endsection
