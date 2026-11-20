<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · COHERS</title>
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Khmer:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'Noto Sans Khmer', 'system-ui', 'sans-serif'] } } } };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        [x-cloak], [hidden] { display: none !important; }
        .nav-link { display:flex; align-items:center; gap:.65rem; padding:.5rem .75rem; border-radius:.5rem; color:#cbd5e1; font-size:.875rem; }
        .nav-link:hover { background:rgba(255,255,255,.06); color:#fff; }
        .nav-link.active { background:#0f766e; color:#fff; }
        .nav-link svg { width:1.1rem; height:1.1rem; flex-shrink:0; }
        .input { width:100%; border:1px solid #cbd5e1; border-radius:.5rem; padding:.45rem .65rem; font-size:.875rem; background:#fff; }
        .input:focus { outline:2px solid #14b8a6; outline-offset:-1px; border-color:transparent; }
        .btn { display:inline-flex; align-items:center; gap:.4rem; border-radius:.5rem; padding:.45rem .85rem; font-size:.875rem; font-weight:500; }
        .btn-primary { background:#0f766e; color:#fff; } .btn-primary:hover { background:#115e59; }
        .btn-ghost { background:#fff; color:#334155; border:1px solid #cbd5e1; } .btn-ghost:hover { background:#f8fafc; }
        .btn-danger { background:#e11d48; color:#fff; } .btn-danger:hover { background:#be123c; }
        .label { display:block; font-size:.75rem; font-weight:600; color:#475569; margin-bottom:.25rem; text-transform:uppercase; letter-spacing:.03em; }
        table.data th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:600; padding:.6rem .75rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
        table.data td { padding:.6rem .75rem; border-bottom:1px solid #f1f5f9; font-size:.875rem; vertical-align:top; }
        table.data tr.clickable:hover td { background:#f0fdfa; cursor:pointer; }
        /* Phones: grid columns may shrink, wide tables scroll inside their card, no page-level sideways scroll. */
        main .grid > *, main .flex-1 { min-width:0; }
        .table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        html, body { overflow-x:hidden; }
        @media (max-width: 639px) {
            .input, select.input, textarea.input { font-size:16px; padding:.55rem .7rem; }
            .btn { padding:.55rem .85rem; }
            table.data th, table.data td { padding:.5rem .6rem; }
            .nav-link { padding:.65rem .75rem; }
            form.filters:not(.filters-open) .filter-extra { display:none; }
        }
        header.app-header { padding-top:env(safe-area-inset-top); height:calc(4rem + env(safe-area-inset-top)); }
        main { padding-bottom:calc(1rem + env(safe-area-inset-bottom)); }
    </style>
    @include('partials.telegram-mini-app')
    @stack('head')
</head>
<body class="h-full bg-slate-100 font-sans text-slate-800 antialiased">
@php
    $u = auth()->user();
    $navDistrict = [
        ['district.overview', 'Dashboard', 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z'],
        ['district.reports.index', 'Reports', 'M9 12h6m-6 4h6M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z'],
        ['district.escalations.index', 'Escalations', 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z'],
        ['district.workload', 'Officer Workload', 'M17 20h5v-2a3 3 0 0 0-5.4-1.8M17 20H7m10 0v-2c0-.7-.1-1.3-.4-1.8M7 20H2v-2a3 3 0 0 1 5.4-1.8M7 20v-2c0-.7.1-1.3.4-1.8m0 0a5 5 0 0 1 9.2 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'],
        ['district.map', 'Incident Map', 'M9 20l-5.4-2.7A1 1 0 0 1 3 16.4V4.6a1 1 0 0 1 1.4-.9L9 6m0 14 6-3m-6 3V6m6 11 4.6 2.3a1 1 0 0 0 1.4-.9V6.6a1 1 0 0 0-.6-.9L15 3m0 14V3M9 6l6-3'],
        ['district.analytics', 'Analytics', 'M7 16V10m5 6V7m5 9v-3M4 20h16'],
        ['district.closures', 'Closure', 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
    ];
    $navAdmin = [
        ['admin.overview', 'System Health', 'M22 12h-4l-3 9L9 3l-3 9H2'],
        ['admin.users.index', 'Accounts & Roles', 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z'],
        ['admin.telegram-users.index', 'Telegram Users', 'M21.5 4.5 2.8 11.7c-.9.4-.9 1.4 0 1.7l4.7 1.5 1.8 5.6c.2.7 1.1.9 1.6.4l2.6-2.4 5 3.7c.6.4 1.4.1 1.6-.6L23 5.8c.2-1-.6-1.7-1.5-1.3ZM9.4 14.6l8.3-6.9'],
        ['admin.geography.index', 'Geography', 'M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11Zm0-8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
        ['admin.routing.index', 'Focal Point Router', 'M8 7h12m0 0-4-4m4 4-4 4m0 6H4m0 0 4 4m-4-4 4-4'],
        ['admin.categories.index', 'AI & Categories', 'M9.7 17 9 20l-1 1h8l-1-1-.8-3M3 13h18M5 17h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2Z'],
        ['admin.jobs.index', 'Alerts & Jobs', 'M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9'],
        ['admin.audit.index', 'Operations & Audit', 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4'],
        ['admin.configuration.edit', 'Configuration', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7-3h3M2 12h3m7-7V2m0 20v-3m5-12 2-2M5 19l2-2m10 2-2-2M5 5l2 2'],
        ['admin.settings.edit', 'Language & Settings', 'M10.3 4.3c.4-1.8 3-1.8 3.4 0a1.7 1.7 0 0 0 2.6 1.1c1.5-.9 3.3.8 2.4 2.4a1.7 1.7 0 0 0 1 2.5c1.8.5 1.8 3 0 3.5a1.7 1.7 0 0 0-1 2.5c.9 1.6-.9 3.3-2.4 2.4a1.7 1.7 0 0 0-2.6 1.1c-.4 1.8-3 1.8-3.4 0a1.7 1.7 0 0 0-2.6-1.1c-1.5.9-3.3-.8-2.4-2.4a1.7 1.7 0 0 0-1-2.5c-1.8-.5-1.8-3 0-3.5a1.7 1.7 0 0 0 1-2.5c-.9-1.6.9-3.3 2.4-2.4a1.7 1.7 0 0 0 2.6-1.1ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'],
    ];
    $isActive = fn ($r) => request()->routeIs($r) || request()->routeIs(\Illuminate\Support\Str::beforeLast($r, '.').'.*') && str_ends_with($r, '.index');
    $scopeNames = $u->isAdmin() ? 'All districts'
        : ($u->isDistrictOfficer() ? ($u->areaLabel() ?: 'no area assigned') : 'Cases assigned to you');
@endphp

<div class="min-h-full">
    {{-- Sidebar --}}
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full transform bg-slate-900 transition lg:translate-x-0">
        <div class="flex h-16 items-center gap-3 border-b border-white/10 px-5">
            <div class="grid h-9 w-9 place-items-center rounded-lg bg-teal-600 text-white">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="M9 12h6M12 9v6"/></svg>
            </div>
            <div>
                <div class="text-sm font-bold tracking-wide text-white">COHERS</div>
                <div class="text-[11px] text-slate-400">Early Warning Dashboard</div>
            </div>
        </div>
        <nav class="h-[calc(100%-4rem)] space-y-6 overflow-y-auto px-3 py-5">
            @if ($u->isFocalPoint())
                <div>
                    <div class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Field officer · {{ $u->officer_type?->value }}</div>
                    <a href="{{ route('field.index') }}" class="nav-link {{ request()->routeIs('field.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4"/></svg>My field cases
                    </a>
                </div>
            @endif
            @if ($u->isPrivileged())
            <div>
                <div class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $u->isAdmin() && ! $u->isDistrictOfficer() ? 'District monitoring' : 'District portal' }}</div>
                <div class="space-y-0.5">
                    @foreach ($navDistrict as [$route, $label, $icon])
                        <a href="{{ route($route) }}" class="nav-link {{ $isActive($route) ? 'active' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>{{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>
            @endif
            @if ($u->isAdmin())
                <div>
                    <div class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Administration</div>
                    <div class="space-y-0.5">
                        @foreach ($navAdmin as [$route, $label, $icon])
                            <a href="{{ route($route) }}" class="nav-link {{ $isActive($route) ? 'active' : '' }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>{{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="mx-3 rounded-lg border border-amber-400/20 bg-amber-400/10 p-3 text-[11px] leading-relaxed text-amber-200">
                AI classifications are <b>advisory and unverified</b>. They never establish a diagnosis, verified event or closure.
            </div>
        </nav>
    </aside>
    <div id="backdrop" class="fixed inset-0 z-30 hidden bg-slate-900/50 lg:hidden" onclick="toggleSidebar()"></div>

    <div class="lg:pl-64">
        <header class="app-header sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-slate-200 bg-white/90 px-3 backdrop-blur sm:gap-4 sm:px-6">
            <button class="rounded-md p-2 text-slate-600 hover:bg-slate-100 lg:hidden" onclick="toggleSidebar()" aria-label="Menu">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-lg font-semibold text-slate-900">@yield('title', 'Dashboard')</h1>
                <p class="hidden truncate text-xs text-slate-500 sm:block">@yield('subtitle', 'Scope: '.$scopeNames)</p>
            </div>
            @php $tgLinked = $u->telegramAccount?->status === 'active'; @endphp
            <a href="{{ route('account.telegram') }}" class="btn btn-ghost" title="{{ $tgLinked ? 'Telegram connected' : 'Connect Telegram to receive alerts' }}">
                <span class="h-2 w-2 rounded-full {{ $tgLinked ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                <span class="hidden md:inline">My Telegram</span>
            </a>
            <div class="hidden text-right sm:block">
                <div class="text-sm font-medium text-slate-800">{{ $u->name }}</div>
                <div class="text-xs text-slate-500">{{ $u->roles->pluck('label')->implode(' · ') }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="tg-hide">@csrf
                <button class="btn btn-ghost" title="Sign out">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 16l4-4-4-4M21 12H9M13 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8"/></svg>
                    <span class="hidden sm:inline">Sign out</span>
                </button>
            </form>
        </header>

        <main class="mx-auto max-w-[1500px] p-4 sm:p-6">
            @if (! $tgLinked && ! request()->routeIs('account.telegram*'))
                <div class="tg-hide mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                    <span class="flex-1">📲 {{ $u->isPrivileged()
                        ? 'Connect your Telegram to get an alert whenever a case is assigned in your '.($u->isAdmin() ? 'districts' : 'district').'.'
                        : 'Connect your Telegram to receive new cases with action buttons and reminders.' }}</span>
                    <a href="{{ route('account.telegram') }}" class="btn btn-primary">Connect Telegram</a>
                </div>
            @endif
            @if (session('status'))
                <div class="mb-4 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>{{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
        document.getElementById('backdrop').classList.toggle('hidden');
    }
    // Wide tables scroll sideways inside their card instead of widening the page on phones.
    document.querySelectorAll('main table').forEach(t => {
        if (t.parentElement.classList.contains('table-scroll') || t.closest('.overflow-x-auto')) return;
        const wrap = document.createElement('div');
        wrap.className = 'table-scroll';
        t.parentNode.insertBefore(wrap, t);
        wrap.appendChild(t);
    });
    Chart.defaults.font.family = 'Inter, "Noto Sans Khmer", system-ui, sans-serif';
    Chart.defaults.color = '#64748b';
    Chart.defaults.plugins.legend.labels.boxWidth = 10;
    window.COHERS_COLORS = { slate:'#94a3b8', amber:'#f59e0b', sky:'#0ea5e9', indigo:'#6366f1', violet:'#8b5cf6', orange:'#f97316', emerald:'#10b981', rose:'#f43f5e', stone:'#a8a29e', zinc:'#52525b', teal:'#0d9488' };
</script>
@stack('scripts')
</body>
</html>
