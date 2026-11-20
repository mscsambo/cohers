@extends('layouts.app')
@section('title', 'My Telegram')
@section('subtitle', 'Connect your Telegram to receive case alerts from the COHERS bot')
@section('content')
<div class="grid gap-5 xl:grid-cols-3">
    <x-card title="Connection" class="xl:col-span-2">
        @unless ($botLive)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                The bot token is not configured, so no messages can be delivered yet. An administrator can set it in Admin → Configuration.
            </div>
        @endunless

        <div id="connected" class="{{ $account ? '' : 'hidden' }}">
            <div class="flex flex-wrap items-center gap-3">
                <span class="grid h-10 w-10 place-items-center rounded-full bg-emerald-100 text-emerald-700">✓</span>
                <div class="flex-1">
                    <div class="font-semibold text-slate-900">Connected<span id="connectedName">{{ $account?->username ? ' as @'.$account->username : '' }}</span></div>
                    <div class="text-xs text-slate-500">
                        @if ($account)Linked {{ \App\Support\Fmt::dt($account->linked_at) }} · chat {{ $account->status }}@else Just linked @endif
                    </div>
                </div>
                <form method="POST" action="{{ route('account.telegram.test') }}">@csrf<button class="btn btn-ghost">Send test message</button></form>
                <form method="POST" action="{{ route('account.telegram.unlink') }}" onsubmit="return confirm('Disconnect Telegram? You will stop receiving alerts.')">@csrf
                    <button class="btn btn-ghost text-rose-600">Disconnect</button>
                </form>
            </div>
            @if ($account?->status === 'blocked')
                <p class="mt-3 rounded bg-rose-50 p-2 text-sm text-rose-700">Telegram reports that you blocked the bot. Open the bot and press Start to receive alerts again.</p>
            @endif
        </div>

        <div id="notConnected" class="{{ $account ? 'hidden' : '' }}">
            @if ($link)
                <div class="grid gap-5 md:grid-cols-[auto,1fr]">
                    <div class="flex flex-col items-center gap-2">
                        <div id="qr" class="rounded-lg border border-slate-200 bg-white p-3"></div>
                        <span class="text-xs text-slate-500">Scan with your phone camera</span>
                    </div>
                    <div class="space-y-3 text-sm">
                        <ol class="list-inside list-decimal space-y-1 text-slate-700">
                            <li>On your phone, scan the QR code — or on this computer click <b>Open in Telegram</b>.</li>
                            <li>Telegram opens <b>{{ '@'.$botUsername }}</b>. Press <b>Start</b>.</li>
                            <li>This page turns green once the bot confirms.</li>
                        </ol>
                        <a href="{{ $link }}" target="_blank" rel="noopener" class="btn btn-primary">Open in Telegram</a>
                        <div>
                            <label class="label">Or copy the link</label>
                            <input readonly value="{{ $link }}" class="input font-mono text-[11px]" onclick="this.select()">
                        </div>
                        <p class="text-xs text-slate-500">Single use · expires {{ \App\Support\Fmt::dt(\Illuminate\Support\Carbon::parse($linkExpires)) }} · <span id="waiting" class="animate-pulse text-teal-700">waiting for Telegram…</span></p>
                    </div>
                </div>
            @else
                <p class="mb-4 text-sm text-slate-600">You are not connected yet. Generate a personal activation link, then open it in the Telegram account you want alerts on.</p>
                <form method="POST" action="{{ route('account.telegram.link') }}">@csrf
                    <button class="btn btn-primary">Generate my activation link</button>
                </form>
            @endif
            <p class="mt-4 text-xs text-slate-500">If this Telegram account already sent reports as a community reporter, linking moves those reports to your staff account so you keep receiving their updates.</p>
        </div>
    </x-card>

    <x-card title="What I receive">
        <form method="POST" action="{{ route('account.telegram.preferences') }}" class="space-y-3">
            @csrf
            @foreach ($events as $key => $label)
                @php $locked = $key === 'escalation' && $user->isDistrictOfficer(); @endphp
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="events[{{ $key }}]" value="1" class="mt-1" @checked($user->wantsNotification($key)) @disabled($locked)>
                    <span><b>{{ $label }}</b>
                        @if ($locked)<br><span class="text-xs text-slate-500">Always on for district officers in your districts.</span>@endif
                    </span>
                </label>
            @endforeach
            <button class="btn btn-ghost w-full justify-center">Save preferences</button>
        </form>
        <p class="mt-4 text-xs text-slate-500">
            Coverage: {{ $user->isAdmin() ? 'all districts (administrator)' : ($user->areaLabel() ?: 'no area assigned') }}.
            @if ($user->isFocalPoint()) Cases assigned to you as {{ $user->officer_type?->value }} always arrive with action buttons. @endif
        </p>
    </x-card>
</div>
@endsection

@push('scripts')
@if ($link && ! $account)
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        new QRCode(document.getElementById('qr'), { text: @json($link), width: 168, height: 168 });
        const poll = setInterval(async () => {
            try {
                const s = await fetch(@json(route('account.telegram.status')), { headers: { Accept: 'application/json' } }).then(r => r.json());
                if (s.linked) {
                    clearInterval(poll);
                    document.getElementById('notConnected').classList.add('hidden');
                    document.getElementById('connected').classList.remove('hidden');
                    if (s.username) document.getElementById('connectedName').textContent = ' as @' + s.username;
                }
            } catch (e) {}
        }, 4000);
    </script>
@endif
@endpush
