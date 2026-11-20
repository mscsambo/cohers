<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>COHERS</title>
    @include('partials.favicon')
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Noto+Sans+Khmer:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        html { font-size: 125%; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Inter, 'Noto Sans Khmer', system-ui, sans-serif;
               background: var(--tg-theme-secondary-bg-color, #f1f5f9); color: var(--tg-theme-text-color, #1e293b); }
        .box { max-width: 22rem; padding: 2rem 1.5rem; text-align: center; }
        .logo { width: 3rem; height: 3rem; margin: 0 auto 1rem; border-radius: .75rem; background: #0f766e; display: grid; place-items: center; }
        .spin { width: 1.5rem; height: 1.5rem; margin: 1rem auto 0; border: 3px solid rgba(15,118,110,.25); border-top-color: #0f766e; border-radius: 50%; animation: s 1s linear infinite; }
        @keyframes s { to { transform: rotate(360deg); } }
        p { line-height: 1.6; }
    </style>
</head>
<body>
<div class="box">
    <div class="logo"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#fff" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="M9 12h6M12 9v6"/></svg></div>
    <p id="msg"></p>
    <div id="spin" class="spin"></div>
    <p id="code" style="font-size:.75rem;opacity:.55"></p>
</div>
<script>
    (function () {
        @php $texts = ['km' => __('bot.miniapp', [], 'km'), 'en' => __('bot.miniapp', [], 'en')]; @endphp
        var text = @json($texts);
        var tg = window.Telegram && window.Telegram.WebApp;
        var lang = tg && tg.initDataUnsafe && tg.initDataUnsafe.user && tg.initDataUnsafe.user.language_code === 'en' ? 'en' : 'km';
        var msg = document.getElementById('msg'), spin = document.getElementById('spin');
        var show = function (key, done, code) {
            msg.textContent = text[lang][key];
            spin.hidden = !!done;
            // A short code helps the administrator see why (e.g. 401 = Telegram data not accepted, 419 = cookie blocked).
            if (code) document.getElementById('code').textContent = 'code: ' + code;
        };

        show('opening');
        if (!tg || !tg.initData) { show('invalid', true, tg ? 'no-data · ' + (tg.platform || '?') : 'no-telegram'); return; }
        tg.ready();
        tg.expand();
        // Staff pages keep their Telegram Mini App behaviour after the redirect.
        try { sessionStorage.setItem('cohers.tgMini', '1'); } catch (e) {}

        fetch(@json(route('miniapp.auth')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                       'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ init_data: tg.initData }),
            credentials: 'same-origin',
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (r.ok && j.redirect) { location.replace(j.redirect); return; }
                show(j.error === 'not_registered' ? 'not_registered' : 'invalid', true, r.status + ' · ' + (tg.platform || '?'));
            });
        }).catch(function (e) {
            // No internet: open the copy of "My reports" saved on this phone (new reports are kept and sent later).
            if (navigator.serviceWorker && navigator.serviceWorker.controller) { location.replace(@json(route('my.index'))); return; }
            show('invalid', true, 'network · ' + (tg.platform || '?'));
        });
    })();
</script>
</body>
</html>
