{{--
    Province → District → Commune → Village picker, used by "New report" and "My village".
    Writes the chosen unit id to the hidden input #unitId. Needs $geo and $last
    (null, or ['path' => ids by type, 'label' => name, 'saved' => is the saved village]).
--}}
<input type="hidden" name="geographic_unit_id" id="unitId" value="{{ old('geographic_unit_id', $presetUnit ?? '') }}">
@if ($last && empty($hideUseLast))
    <button type="button" id="useLast" class="w-full rounded-xl border border-teal-500/40 px-3 py-2 text-left text-sm link">
        ↺ {{ ! empty($last['saved']) ? __('bot.miniapp.my_village_prefill', ['place' => $last['label']]) : __('bot.miniapp.same_place', ['place' => $last['label']]) }}
    </button>
@endif
<div class="grid grid-cols-2 gap-2">
    @foreach (['province', 'district', 'commune', 'village'] as $level)
        <label class="block"><span class="hint text-xs">{{ __('bot.miniapp.'.$level) }}</span>
            <select id="sel-{{ $level }}" class="field" @if ($level !== 'province') disabled @endif></select>
        </label>
    @endforeach
</div>
<p id="villagesOffline" class="hint text-xs" hidden>{{ __('bot.miniapp.villages_offline') }}</p>
@php $pickerTexts =['choose' => __('bot.miniapp.choose'), 'whole' => __('bot.miniapp.whole_commune')]; @endphp
<script>
    (() => {
        const geo = @json($geo);
        const last = @json($last);
        const t = @json($pickerTexts);
        const villagesUrl = @json(route('my.villages', ['commune' => '__ID__']));
        const $ = id => document.getElementById(id);
        const sel = { province: $('sel-province'), district: $('sel-district'), commune: $('sel-commune'), village: $('sel-village') };
        const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const fill = (el, items, extra = '') => {
            el.innerHTML = `<option value="">${esc(t.choose)}</option>` + extra + items.map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('');
            el.disabled = !items.length && !extra;
        };
        const reset = (...levels) => levels.forEach(l => { sel[l].innerHTML = ''; sel[l].disabled = true; });
        const syncUnit = () => {
            const v = sel.village.value;
            $('unitId').value = v === 'whole' ? sel.commune.value : v;
        };

        fill(sel.province, geo.province);
        if (geo.province.length === 1) sel.province.value = geo.province[0].id;

        const onProvince = () => { reset('commune', 'village'); fill(sel.district, geo.district.filter(d => d.parent === +sel.province.value)); syncUnit(); };
        const onDistrict = () => { reset('village'); fill(sel.commune, geo.commune.filter(c => c.parent === +sel.district.value)); syncUnit(); };
        const onCommune = async (preselect) => {
            reset('village'); syncUnit();
            if (!sel.commune.value) return;
            let villages = [];
            let failed = false;
            try {
                const r = await fetch(villagesUrl.replace('__ID__', sel.commune.value), { headers: { Accept: 'application/json' } });
                villages = r.ok ? await r.json() : [];
                failed = !r.ok;
            } catch (e) { failed = true; }
            $('villagesOffline').hidden = !failed;
            fill(sel.village, villages, `<option value="whole">${esc(t.whole)}</option>`);
            if (preselect) sel.village.value = preselect;
            syncUnit();
        };
        sel.province.addEventListener('change', onProvince);
        sel.district.addEventListener('change', onDistrict);
        sel.commune.addEventListener('change', () => onCommune());
        sel.village.addEventListener('change', syncUnit);
        if (sel.province.value) onProvince();

        const applyLast = async () => {
            const p = last.path;
            sel.province.value = p.province; onProvince();
            sel.district.value = p.district; onDistrict();
            sel.commune.value = p.commune;
            await onCommune(p.village || p.zone || 'whole');
        };
        if (last && $('useLast')) $('useLast').addEventListener('click', applyLast);
        // The saved village is filled in straight away; a previous report's place only on tap.
        if (last && last.saved && (!$('unitId').value || +$('unitId').value === last.id)) applyLast();

        // While online, load the villages of the reporter's own district in the background so the
        // service worker keeps them for offline use (small requests, one after another).
        if (last && last.path.district && navigator.onLine !== false && 'serviceWorker' in navigator) {
            const communes = geo.commune.filter(c => c.parent === last.path.district).slice(0, 40);
            setTimeout(async () => {
                for (const c of communes) {
                    try { await fetch(villagesUrl.replace('__ID__', c.id), { headers: { Accept: 'application/json' } }); } catch (e) { break; }
                }
            }, 3000);
        }
    })();
</script>
