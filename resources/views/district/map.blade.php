@extends('layouts.app')
@section('title', 'Incident Map')
@push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
@endpush
@section('content')
    @include('partials.filters')
    <div class="space-y-5">
        <x-card :padding="false">
            <div id="map" class="h-[560px] rounded-xl"></div>
        </x-card>
        <div class="grid gap-5 md:grid-cols-2">
            <x-card title="Top communes" :padding="false"><div id="top" class="divide-y divide-slate-100 text-sm"></div></x-card>
            <x-card title="How to read this map">
                <ul class="space-y-2 text-sm text-slate-600">
                    <li>Bubbles are aggregated at <b>commune</b> level to protect sensitive locations; individual villages and GPS pins are never shown.</li>
                    <li>Size = number of reports. Colour = most severe priority present.</li>
                </ul>
                <div class="mt-3 space-y-1 text-xs">
                    <div class="flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-rose-500"></span> Has critical</div>
                    <div class="flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-orange-500"></span> Has high</div>
                    <div class="flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-teal-600"></span> Medium / low only</div>
                </div>
            </x-card>
        </div>

    </div>
@endsection
@push('scripts')
<script>
    const map = L.map('map').setView([11.45, 105.4], 9);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 13, attribution: '&copy; OpenStreetMap' }).addTo(map);
    fetch(@json(route('district.map.data', request()->query())), { headers: { Accept: 'application/json' } })
        .then(r => r.json())
        .then(rows => {
            const bounds = [];
            rows.forEach(c => {
                const color = c.critical ? '#f43f5e' : (c.high ? '#f97316' : '#0d9488');
                L.circleMarker([c.lat, c.lng], { radius: 6 + Math.sqrt(c.count) * 4, color, fillColor: color, fillOpacity: .45, weight: 1.5 })
                    .bindPopup(`<b>${c.name}</b><br>${c.count} reports · ${c.open} open<br>${c.critical} critical · ${c.high} high`)
                    .addTo(map);
                bounds.push([c.lat, c.lng]);
            });
            if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 11 });
            document.getElementById('top').innerHTML = rows.sort((a, b) => b.count - a.count).slice(0, 10)
                .map(c => `<div class="flex justify-between px-4 py-2"><span>${c.name}</span><span class="font-semibold tabular-nums">${c.count}</span></div>`).join('')
                || '<div class="p-4 text-slate-500">No data.</div>';
        })
        .catch(() => document.getElementById('top').innerHTML = '<div class="p-4 text-rose-600">Map data failed to load.</div>');
</script>
@endpush
