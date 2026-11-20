<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sign in') · COHERS</title>
    <script>
        // Opened from Telegram (any COHERS address): sign in with Telegram instead of a password.
        if (/tgWebApp(Data|Platform)/.test(location.hash)) location.replace(@json(route('miniapp.entry')) + location.hash);
    </script>
    @include('partials.favicon')
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Khmer:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'Noto Sans Khmer', 'sans-serif'] } } } };</script>
    <style>
        .input { width:100%; border:1px solid #cbd5e1; border-radius:.5rem; padding:.6rem .75rem; font-size:.9rem; }
        .input:focus { outline:2px solid #14b8a6; outline-offset:-1px; border-color:transparent; }
    </style>
</head>
<body class="h-full bg-slate-900 font-sans antialiased">
<div class="relative flex min-h-full items-center justify-center overflow-hidden px-4 py-12">
    <div class="pointer-events-none absolute -left-32 -top-32 h-96 w-96 rounded-full bg-teal-500/20 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-40 -right-20 h-[28rem] w-[28rem] rounded-full bg-sky-500/10 blur-3xl"></div>
    <div class="relative w-full max-w-md">
        <div class="mb-8 text-center">
            <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-teal-600 text-white shadow-lg shadow-teal-900/40">
                <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="M9 12h6M12 9v6"/></svg>
            </div>
            <h1 class="text-2xl font-bold text-white">COHERS</h1>
            <p class="mt-1 text-sm text-slate-400">Community Reporting, Verification &amp; Early Warning</p>
            <p class="text-sm text-slate-500" lang="km">ប្រព័ន្ធរាយការណ៍សហគមន៍ និងការព្រមានជាមុន</p>
        </div>
        <div class="rounded-2xl bg-white p-7 shadow-2xl">
            @yield('content')
        </div>
        <p class="mt-6 text-center text-xs text-slate-500">Dashboard access is for system administrators, district officers and VHSG / CAO focal points. Community reporters use the Telegram bot.</p>
    </div>
</div>
</body>
</html>
