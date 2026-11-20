<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('bot.miniapp.title')) · COHERS</title>
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Khmer:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'Noto Sans Khmer', 'system-ui', 'sans-serif'] } } } };</script>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        /* Telegram passes its theme as CSS variables; fall back to COHERS colours in a browser. */
        :root { --bg: var(--tg-theme-secondary-bg-color, #f1f5f9); --card: var(--tg-theme-bg-color, #ffffff); --text: var(--tg-theme-text-color, #1e293b);
                --hint: var(--tg-theme-hint-color, #64748b); --link: var(--tg-theme-link-color, #0f766e);
                --btn: var(--tg-theme-button-color, #0f766e); --btn-text: var(--tg-theme-button-text-color, #ffffff); }
        [hidden] { display: none !important; }
        body { background: var(--bg); color: var(--text); }
        .card { background: var(--card); border-radius: 1rem; }
        .hint { color: var(--hint); }
        .link { color: var(--link); }
        .btn-main { background: var(--btn); color: var(--btn-text); border-radius: .75rem; padding: .7rem 1rem; font-weight: 600; width: 100%; }
        .btn-second { background: transparent; color: var(--text); border: 1px solid rgba(100,116,139,.45); border-radius: .75rem; padding: .7rem 1rem; font-weight: 600; width: 100%; }
        .field { width: 100%; border: 1px solid rgba(100,116,139,.35); border-radius: .75rem; padding: .65rem .8rem; font-size: 16px; background: var(--card); color: var(--text); }
        .field:focus { outline: 2px solid var(--btn); outline-offset: -1px; }
    </style>
    {{-- Offline queue for reports written without internet; loaded first so page scripts can use it. --}}
@auth
@php
    $offlineTexts = collect(['offline_now', 'queued_count', 'saved_offline', 'queued_signin', 'sent_from_queue', 'online_only',
        'queued_title', 'queued_saved', 'queued_failed', 'queued_delete', 'queued_delete_confirm', 'villages_offline'])
        ->mapWithKeys(fn ($k) => [$k => __('bot.miniapp.'.$k)]);
@endphp
<script>
    window.COHERS_OFFLINE = {
        user: @json(\App\Http\Controllers\MiniApp\MyReportsController::queueOwner(auth()->user())),
        storeUrl: @json(route('my.store')),
        tokenUrl: @json(route('my.sync-token')),
        indexUrl: @json(route('my.index')),
        locale: @json(app()->getLocale()),
        t: @json($offlineTexts),
    };
</script>
<script src="{{ asset('js/offline-queue.js') }}?v=2"></script>
@endauth
</head>
<body class="min-h-screen font-sans antialiased">
@php
    $tab = trim($__env->yieldContent('tab'));
    // Badge on "My Cases" while an officer is waiting for this person's answer.
    $waitingAnswers = auth()->check() ? \App\Models\ReportInformationRequest::where('status', 'OPEN')
        ->whereHas('report', fn ($q) => $q->where('reporter_id', auth()->id()))->count() : 0;
    $tabs = [
        'cases' => [route('my.index'), __('bot.miniapp.tab_cases'), 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 7h6m-6 4h4'],
        'location' => [route('my.location'), __('bot.miniapp.tab_location'), 'M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11Zm0-8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
        'profile' => [route('my.profile'), __('bot.miniapp.tab_profile'), 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z'],
    ];
@endphp
@php $backUrl = trim($__env->yieldContent('back')); @endphp
@if ($backUrl)
    {{-- Inner pages: a back button that stays at the top while scrolling. --}}
    <header class="card sticky top-0 z-20 !rounded-none border-b border-slate-300/40 shadow-sm">
        <div class="mx-auto flex max-w-xl items-center gap-2 px-3 py-2">
            <a href="{{ $backUrl }}" class="btn-second flex !w-auto shrink-0 items-center gap-1.5 !px-3 !py-2 text-sm" aria-label="{{ __('bot.miniapp.back_to_cases') }}">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                {{ __('bot.miniapp.back_to_cases') }}
            </a>
            <span class="min-w-0 flex-1 truncate text-right font-mono text-sm font-semibold">@yield('title')</span>
        </div>
    </header>
@endif
<main class="mx-auto max-w-xl px-4 pt-4" style="padding-bottom: calc(7.5rem + env(safe-area-inset-bottom))">
    {{-- Offline: what is happening, and reports saved on the phone that still have to be sent. --}}
    <div id="offlineBar" class="mb-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900" hidden>📴 {{ __('bot.miniapp.offline_now') }}</div>
    <div id="queueBar" class="mb-3 flex items-center gap-3 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900" hidden>
        <span class="flex-1">⏳ <span id="queueBarText"></span></span>
        <button type="button" id="queueSendNow" class="shrink-0 font-semibold underline">{{ __('bot.miniapp.send_now') }}</button>
    </div>
    <div id="queueNote" class="mb-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" hidden></div>
    @if (session('status'))
        {{-- data-flash: hidden when this page is a saved copy shown offline, so an old message is not repeated. --}}
        <div data-flash class="mb-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-3 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif
    @yield('content')
    <p class="hint mt-6 text-center text-xs">🔒 {{ __('bot.miniapp.privacy') }}</p>
</main>

{{-- Floating "New report" button (not on the form itself) --}}
@unless (request()->routeIs('my.create'))
    <a href="{{ route('my.create') }}" class="btn-main fixed right-4 z-30 flex !w-auto items-center gap-1.5 rounded-full px-5 py-3 shadow-lg"
       style="bottom: calc(4.75rem + env(safe-area-inset-bottom))" aria-label="{{ __('bot.miniapp.new_report') }}">
        <span class="text-lg leading-none">＋</span> {{ __('bot.miniapp.new_report') }}
    </a>
@endunless

{{-- App-style menu at the bottom --}}
<nav class="card fixed inset-x-0 bottom-0 z-20 border-t border-slate-300/40 !rounded-none" style="padding-bottom: env(safe-area-inset-bottom)">
    <div class="mx-auto grid max-w-xl grid-cols-3">
        @foreach ($tabs as $key => [$url, $label, $icon])
            <a href="{{ $url }}" class="relative flex flex-col items-center gap-0.5 py-2 text-[11px] {{ $tab === $key ? 'link font-semibold' : 'hint' }}" @if ($tab === $key) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="{{ $tab === $key ? 2.2 : 1.7 }}" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                {{ $label }}
                @if ($key === 'cases' && $waitingAnswers)
                    <span class="absolute right-[calc(50%-1.4rem)] top-1 grid h-4 min-w-4 place-items-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">{{ $waitingAnswers }}</span>
                @endif
            </a>
        @endforeach
    </div>
</nav>
<script>
    (function () {
        var tg = window.Telegram && window.Telegram.WebApp;
        if (!tg || !tg.initData && !sessionStorage.getItem('cohers.tgMini')) return;
        tg.ready();
        tg.expand();
        // Inner pages use Telegram's own Back button.
        var back = @json(trim($__env->yieldContent('back')));
        if (back) {
            tg.BackButton.show();
            tg.BackButton.onClick(function () { location.href = back; });
        } else {
            tg.BackButton.hide();
        }
    })();
</script>
@auth
<script>
    (function () {
        var cfg = window.COHERS_OFFLINE, Q = window.CohersQueue, $ = function (id) { return document.getElementById(id); };
        var fmt = function (s, n) { return s.replace(':count', n); };

        // Offline banner; a saved copy of a page must not repeat an old "report received" message.
        var showOnline = function () {
            var off = navigator.onLine === false;
            $('offlineBar').hidden = !off;
            if (off) document.querySelectorAll('[data-flash]').forEach(function (el) { el.hidden = true; });
        };
        window.addEventListener('online', showOnline);
        window.addEventListener('offline', showOnline);
        showOnline();

        if (Q) {
            Q.onChange(function (s) {
                var waiting = (s.pending || 0) + (s.failed || 0);
                $('queueBar').hidden = !waiting;
                $('queueBarText').textContent = s.needsSignIn ? cfg.t.queued_signin : fmt(cfg.t.queued_count, waiting);
                $('queueSendNow').hidden = !s.pending || navigator.onLine === false;
                if (s.sent) { $('queueNote').textContent = '✅ ' + fmt(cfg.t.sent_from_queue, s.sent); $('queueNote').hidden = false; }
            });
            $('queueSendNow').addEventListener('click', function () { Q.sync(); });
        }
        if (/[?&]queued=1/.test(location.search)) { $('queueNote').textContent = '💾 ' + cfg.t.saved_offline; $('queueNote').hidden = false; }
        var sentParam = /[?&]sent=(\d+)/.exec(location.search);
        if (sentParam) { $('queueNote').textContent = '✅ ' + fmt(cfg.t.sent_from_queue, sentParam[1]); $('queueNote').hidden = false; }

        // Other forms (answers, updates, profile) need the server: say so instead of a browser error page.
        document.addEventListener('submit', function (e) {
            if (navigator.onLine === false && !e.target.hasAttribute('data-offline-ok')) {
                e.preventDefault();
                $('offlineBar').textContent = '📴 ' + cfg.t.online_only;
                $('offlineBar').hidden = false;
                $('offlineBar').scrollIntoView({ behavior: 'smooth' });
            }
        }, true);

        // Service worker: keeps these pages and the files they use on the phone for offline use.
        if (!('serviceWorker' in navigator)) return;
        try {
            // Another person signed in on this phone: forget the previous person's saved pages.
            if (localStorage.getItem('cohers.owner') && localStorage.getItem('cohers.owner') !== cfg.user && window.caches) {
                caches.keys().then(function (keys) { keys.forEach(function (k) { if (k.indexOf('cohers-pages') === 0) caches.delete(k); }); });
            }
            localStorage.setItem('cohers.owner', cfg.user);
        } catch (e) {}
        navigator.serviceWorker.register(@json(asset('sw.js')), { scope: @json(rtrim(url('/'), '/').'/') }).then(function () {
            return navigator.serviceWorker.ready;
        }).then(function (reg) {
            if (navigator.onLine === false || !reg.active) return;
            var urls = [@json(route('miniapp.entry')), cfg.indexUrl, @json(route('my.create')), location.href.split('#')[0]];
            document.querySelectorAll('script[src], link[rel=stylesheet][href], link[rel~=icon][href]').forEach(function (el) { urls.push(el.src || el.href); });
            reg.active.postMessage({ type: 'warm', urls: urls });
        }).catch(function () {});
    })();
</script>
@endauth
</body>
</html>
