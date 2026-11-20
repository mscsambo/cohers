@extends('layouts.miniapp')
@section('title', __('bot.miniapp.tab_location'))
@section('tab', 'location')
@section('content')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<h1 class="mb-4 text-xl font-bold">📍 {{ __('bot.miniapp.tab_location') }}</h1>

<section class="card mb-3 p-4 shadow-sm">
    <div class="flex items-start gap-3">
        <div class="min-w-0 flex-1">
            <div class="text-sm font-semibold">🏠 {{ __('bot.miniapp.my_village') }}</div>
            <p class="mt-0.5 text-base">{{ $villagePath ?? __('bot.miniapp.not_set') }}</p>
            <p class="hint mt-0.5 text-xs">{{ __('bot.miniapp.my_village_hint') }}</p>
        </div>
        <button type="button" id="changeVillage" class="link shrink-0 text-sm font-medium">{{ __('bot.miniapp.change') }}</button>
    </div>
    <form method="POST" action="{{ route('my.location.update') }}" id="villageForm" class="mt-3 space-y-2" @if ($village && ! $errors->any()) hidden @endif>
        @csrf
        @include('miniapp._place_picker')
        <button class="btn-main">{{ __('bot.miniapp.save') }}</button>
    </form>
</section>

<section class="card overflow-hidden shadow-sm">
    <div class="p-4 pb-2 text-sm font-semibold">🗺 {{ __('bot.miniapp.map_title') }}</div>
    @if ($pins->isEmpty())
        <p class="hint px-4 pb-4 text-sm">{{ __('bot.miniapp.map_empty') }}</p>
    @else
        <div id="map" class="relative z-0" style="height: 320px; isolation: isolate"></div>
        <p class="hint px-4 py-2 text-xs">{{ __('bot.miniapp.map_approx') }}</p>
    @endif
</section>

<script>
    document.getElementById('changeVillage').addEventListener('click', () => {
        const f = document.getElementById('villageForm');
        f.hidden = !f.hidden;
    });
    (() => {
        const pins = @json($pins);
        if (!pins.length || !window.L) return;
        const map = L.map('map', { zoomControl: true, attributionControl: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '© OpenStreetMap' }).addTo(map);
        const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const bounds = [];
        pins.forEach(p => {
            const color = p.closed ? '#10b981' : '#0f766e';
            L.circleMarker([p.lat, p.lng], { radius: 9, color: '#fff', weight: 2, fillColor: color, fillOpacity: p.gps ? 1 : 0.6 })
                .addTo(map)
                .bindPopup(`<a href="${p.url}" style="font-weight:600">${esc(p.code)}</a><br>${esc(p.status)}`);
            bounds.push([p.lat, p.lng]);
        });
        bounds.length === 1 ? map.setView(bounds[0], 13) : map.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
    })();
</script>
@endsection
