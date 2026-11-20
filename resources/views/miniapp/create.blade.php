@extends('layouts.miniapp')
@section('title', __('bot.miniapp.new_report'))
@section('back', route('my.index'))
@section('tab', 'cases')
@section('content')
<h1 class="mb-4 text-xl font-bold">📝 {{ __('bot.miniapp.new_report') }}</h1>

<form id="reportForm" data-offline-ok method="POST" action="{{ route('my.store') }}" enctype="multipart/form-data" class="space-y-3">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    <input type="hidden" name="latitude" id="lat">
    <input type="hidden" name="longitude" id="lng">

    <section class="card space-y-2 p-4 shadow-sm">
        <div class="text-sm font-semibold">📍 {{ __('bot.miniapp.where') }}</div>
        @if ($last && ! empty($last['saved']))
            {{-- Village already set: no drop-downs unless they want another place for this report. --}}
            @php $otherPlace = old('geographic_unit_id') && (int) old('geographic_unit_id') !== $last['id']; @endphp
            <div id="savedPlace" class="flex items-start gap-3" @if ($otherPlace) hidden @endif>
                <p class="flex-1 text-base">🏠 {{ $last['full'] }}</p>
                <button type="button" id="changePlace" class="link shrink-0 text-sm font-medium">{{ __('bot.miniapp.change') }}</button>
            </div>
            <div id="placePicker" class="space-y-2" @unless ($otherPlace) hidden @endunless>
                @include('miniapp._place_picker', ['presetUnit' => $last['id'], 'hideUseLast' => true])
            </div>
            <script>
                document.getElementById('changePlace').addEventListener('click', () => {
                    document.getElementById('savedPlace').hidden = true;
                    document.getElementById('placePicker').hidden = false;
                });
            </script>
        @else
            @include('miniapp._place_picker')
        @endif
    </section>

    <section class="card space-y-3 p-4 shadow-sm">
        <label class="block">
            <span class="text-sm font-semibold">📝 {{ __('bot.miniapp.what') }}</span>
            <textarea name="original_text" rows="4" class="field mt-1" required minlength="3" maxlength="4000" placeholder="{{ __('bot.miniapp.what_placeholder') }}">{{ old('original_text') }}</textarea>
        </label>
        @include('miniapp._dictation')
        <label class="block">
            <span class="text-sm font-semibold">👥 {{ __('bot.miniapp.affected') }}</span> <span class="hint text-xs">({{ __('bot.miniapp.optional') }})</span>
            <input name="affected_count" maxlength="60" class="field mt-1" value="{{ old('affected_count') }}" placeholder="{{ __('bot.miniapp.affected_placeholder') }}">
        </label>
        <div>
            <span class="text-sm font-semibold">🕒 {{ __('bot.miniapp.when') }}</span>
            <div class="mt-1 grid grid-cols-2 gap-2">
                @foreach (['today', 'yesterday', 'this_week', 'older'] as $w)
                    <label class="flex cursor-pointer items-center justify-center rounded-xl border border-slate-300/60 px-2 py-2 text-center text-sm has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50 has-[:checked]:text-teal-900">
                        <input type="radio" name="observed" value="{{ $w }}" class="sr-only" @checked(old('observed', 'today') === $w)>{{ __('bot.btn.'.$w) }}
                    </label>
                @endforeach
            </div>
        </div>
    </section>

    <section class="card space-y-2 p-4 shadow-sm">
        <div class="flex items-baseline justify-between">
            <span class="text-sm font-semibold">{{ __('bot.miniapp.photos') }}</span>
            <span class="hint text-xs">{{ __('bot.miniapp.photos_hint', ['max' => $maxPhotos]) }}</span>
        </div>
        <div id="thumbs" class="grid grid-cols-3 gap-2"></div>
        <input type="file" id="photoInput" accept="image/*" multiple hidden>
        <button type="button" id="addPhotos" class="w-full rounded-xl border border-dashed border-slate-400/70 py-3 text-sm">{{ __('bot.miniapp.add_photos') }}</button>
        <button type="button" id="gps" class="w-full rounded-xl py-2 text-sm link">{{ __('bot.miniapp.gps') }}</button>
    </section>

    <p id="formError" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800" hidden></p>
    <button id="submitBtn" class="btn-main">{{ __('bot.miniapp.submit_report') }}</button>
</form>

@php
    $texts = ['choose' => __('bot.miniapp.choose'), 'whole' => __('bot.miniapp.whole_commune'), 'sending' => __('bot.miniapp.sending'),
        'submit' => __('bot.miniapp.submit_report'), 'failed' => __('bot.miniapp.send_failed'), 'gpsAdded' => __('bot.miniapp.gps_added'),
        'gpsFailed' => __('bot.miniapp.gps_failed'), 'where' => __('bot.miniapp.where')];
@endphp
<script>
    (() => {
        const t = @json($texts);
        const maxPhotos = {{ $maxPhotos }};
        const $ = id => document.getElementById(id);

        // Photos are shrunk on the phone (max 1600 px, JPEG) before upload: faster on mobile data.
        const photos = [];
        const shrink = file => new Promise(resolve => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => {
                const scale = Math.min(1, 1600 / Math.max(img.width, img.height));
                const c = document.createElement('canvas');
                c.width = Math.round(img.width * scale); c.height = Math.round(img.height * scale);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(b => resolve(b || file), 'image/jpeg', 0.8);
            };
            img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
        const renderThumbs = () => {
            $('thumbs').innerHTML = photos.map((p, i) => `
                <div class="relative"><img src="${p.url}" class="h-24 w-full rounded-lg object-cover">
                <button type="button" data-remove="${i}" class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-black/60 text-xs text-white">✕</button></div>`).join('');
            $('addPhotos').hidden = photos.length >= maxPhotos;
        };
        $('addPhotos').addEventListener('click', () => $('photoInput').click());
        $('photoInput').addEventListener('change', async e => {
            for (const f of [...e.target.files].slice(0, maxPhotos - photos.length)) {
                const blob = await shrink(f);
                photos.push({ blob, url: URL.createObjectURL(blob) });
            }
            e.target.value = '';
            renderThumbs();
        });
        $('thumbs').addEventListener('click', e => {
            const b = e.target.closest('[data-remove]');
            if (b) { photos.splice(+b.dataset.remove, 1); renderThumbs(); }
        });

        $('gps').addEventListener('click', () => {
            if (!navigator.geolocation) { $('gps').textContent = t.gpsFailed; return; }
            navigator.geolocation.getCurrentPosition(pos => {
                $('lat').value = pos.coords.latitude.toFixed(6);
                $('lng').value = pos.coords.longitude.toFixed(6);
                $('gps').textContent = t.gpsAdded;
            }, () => { $('gps').textContent = t.gpsFailed; }, { enableHighAccuracy: true, timeout: 15000 });
        });

        // One key per report, made on the phone: this page may be a saved copy used for several
        // reports offline, and a retry of the same report must reuse the same key.
        const Q = window.CohersQueue;
        const form = $('reportForm');
        if (Q) form.elements.idempotency_key.value = Q.uuid();
        const capturedAt = () => form.dataset.capturedAt || (form.dataset.capturedAt = new Date().toISOString());

        // Place name for the "waiting to send" list.
        const placeLabel = () => {
            const saved = $('savedPlace');
            if (saved && !saved.hidden) return saved.querySelector('p').textContent.replace('🏠', '').trim();
            return ['province', 'district', 'commune', 'village'].map(l => $('sel-' + l))
                .filter(s => s && s.value).map(s => s.options[s.selectedIndex].text).join(' › ');
        };

        // No internet (or it dropped while sending): keep the report on the phone and send it later.
        const saveOffline = async () => {
            if (!Q) return false;
            const fields = {};
            for (const [k, v] of new FormData(form).entries()) if (k !== '_token' && typeof v === 'string') fields[k] = v;
            fields.captured_at = capturedAt();
            try {
                await Q.save(fields, photos.map(p => p.blob), placeLabel());
                location.href = window.COHERS_OFFLINE.indexUrl + '?queued=1';
                return true;
            } catch (x) { return false; }
        };

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const err = $('formError');
            err.hidden = true;
            if (!$('unitId').value) { err.textContent = '📍 ' + t.where; err.hidden = false; $('sel-province').scrollIntoView({ behavior: 'smooth' }); return; }
            const btn = $('submitBtn');
            btn.disabled = true; btn.textContent = t.sending;
            capturedAt();
            if (navigator.onLine === false && await saveOffline()) return;
            const fd = new FormData(e.target);
            fd.set('captured_at', capturedAt());
            photos.forEach((p, i) => fd.append('photos[]', p.blob, `photo${i + 1}.jpg`));
            // A very slow connection is treated like none: the same key makes a late duplicate harmless.
            const abort = new AbortController();
            const timer = setTimeout(() => abort.abort(), 45000);
            try {
                let res;
                try {
                    res = await fetch(e.target.action, { method: 'POST', body: fd, credentials: 'same-origin', signal: abort.signal,
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json' } });
                } catch (network) {
                    if (await saveOffline()) return;
                    throw network;
                } finally { clearTimeout(timer); }
                const json = await res.json().catch(() => ({}));
                if (res.ok && json.redirect) { location.href = json.redirect; return; }
                // Signed out, an old saved page, or a server problem: keep it and send it later.
                if ([401, 419].includes(res.status) || res.status >= 500) { if (await saveOffline()) return; }
                // Validation problem: show it and keep what was entered, photos included.
                const first = json.errors ? Object.values(json.errors)[0][0] : null;
                throw new Error(first || t.failed);
            } catch (x) {
                err.textContent = x.message && !/^\d+$/.test(x.message) && x.message !== 'Failed to fetch' ? x.message : t.failed;
                err.hidden = false;
                btn.disabled = false; btn.textContent = t.submit;
            }
        });
    })();
</script>
@endsection
