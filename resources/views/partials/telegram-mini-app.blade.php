{{--
    Telegram Mini App support. Telegram opens the page with #tgWebAppData… in the URL;
    we remember that for the tab so later pages keep the Mini App behaviour.
    Outside Telegram nothing is loaded and nothing changes.
--}}
<style>
    html.tg-mini .tg-hide { display:none !important; }
    html.tg-mini body { background:var(--tg-theme-secondary-bg-color, #f1f5f9); }
    html.tg-mini header.app-header { padding-top:0; height:3.5rem; }
</style>
<script>
    (function () {
        var inTelegram = /tgWebApp(Data|Platform)/.test(location.hash);
        try {
            if (inTelegram) sessionStorage.setItem('cohers.tgMini', '1');
            inTelegram = inTelegram || sessionStorage.getItem('cohers.tgMini') === '1';
        } catch (e) {}
        if (!inTelegram) return;
        document.documentElement.classList.add('tg-mini');
        var s = document.createElement('script');
        s.src = 'https://telegram.org/js/telegram-web-app.js';
        s.onload = function () {
            var tg = window.Telegram && window.Telegram.WebApp;
            if (!tg) return;
            tg.ready();
            tg.expand();
            try { tg.setHeaderColor('#ffffff'); tg.setBackgroundColor('#f1f5f9'); } catch (e) {}
            // Telegram's own Back button replaces the browser's on inner pages.
            var home = ['/field', '/dashboard', '/admin'].indexOf(location.pathname) !== -1;
            if (history.length > 1 && !home) {
                tg.BackButton.show();
                tg.BackButton.onClick(function () { history.back(); });
            } else {
                tg.BackButton.hide();
            }
        };
        document.head.appendChild(s);
    })();
</script>
