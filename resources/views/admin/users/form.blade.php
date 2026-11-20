@extends('layouts.app')
@section('title', $user->exists ? 'Manage account' : 'New account')
@section('content')
@php
    // One role per account; older accounts holding several show their highest one.
    $heldRoles = $user->roles?->pluck('name')->all() ?? [];
    // One choice covers role and, for focal points, the VHSG / CAO type ("focal_point:CAO").
    // A new account starts with no role so the admin picks one deliberately.
    $heldRole = collect(['system_admin', 'district_officer', 'focal_point', 'reporter'])->first(fn ($r) => in_array($r, $heldRoles, true));
    $currentRole = old('role', $user->exists ? ($heldRole ?? 'reporter') : '');
    if ($currentRole === 'focal_point') {
        $currentRole .= ':'.old('officer_type', $user->officer_type?->value);
    }
    $roleLabel = fn (string $name) => $roles->firstWhere('name', $name)?->label ?? $name;
    $roleOptions = [
        'reporter' => $roleLabel('reporter'),
        'focal_point:VHSG' => $roleLabel('focal_point').' — VHSG (human health)',
        'focal_point:CAO' => $roleLabel('focal_point').' — CAO (animal & environment)',
        'district_officer' => $roleLabel('district_officer'),
        'system_admin' => $roleLabel('system_admin'),
    ];
    $roleHelp = [
        'reporter' => 'Telegram reporting only — no dashboard sign-in.',
        'focal_point:VHSG' => 'Village Health Support Group: receives HUMAN-health cases for their communes; Telegram alerts, field verification and the “My field cases” page.',
        'focal_point:CAO' => 'Commune Agriculture Officer: receives ANIMAL and ENVIRONMENT cases for their communes; Telegram alerts, field verification and the “My field cases” page.',
        'district_officer' => 'Dashboard for the assigned area (province, districts or communes): triage, reassign, review findings and close cases.',
        'system_admin' => 'Configuration and oversight of the whole system; no verification authority.',
    ];
    $userScopes = array_map('intval', old('scopes', $user->scopes?->pluck('id')->all() ?? []));
    $userAreas = old('areas', $areas->all());
@endphp
<div class="mb-4"><a href="{{ route('admin.users.index') }}" class="btn btn-ghost">← Accounts</a></div>

<div class="space-y-5">
    <x-card title="Identity, role & assigned location">
        <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="space-y-4">
            @csrf @if ($user->exists) @method('PUT') @endif
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div><label class="label">Name</label><input name="name" value="{{ old('name', $user->name) }}" class="input" required></div>
                <div><label class="label">Email (dashboard sign-in)</label><input name="email" type="email" value="{{ old('email', $user->email) }}" class="input"></div>
                <div><label class="label">Phone (optional)</label><input name="phone" value="{{ old('phone', $user->phone) }}" class="input"></div>
                <div><label class="label">Preferred language</label>
                    <select name="preferred_language" class="input">
                        <option value="km" @selected(old('preferred_language', $user->preferred_language ?? 'km') === 'km')>Khmer</option>
                        <option value="en" @selected(old('preferred_language', $user->preferred_language) === 'en')>English</option>
                    </select>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="role">Role</label>
                    <select name="role" id="role" class="input" required>
                        @if (! isset($roleOptions[$currentRole]))<option value="" selected disabled>— Choose a role —</option>@endif
                        @foreach ($roleOptions as $value => $label)<option value="{{ $value }}" @selected($currentRole === $value)>{{ $label }}</option>@endforeach
                    </select>
                    @error('role')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @error('officer_type')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div><label class="label">Account status</label>
                    <select name="account_status" class="input">
                        @foreach (['active', 'suspended', 'pending'] as $s)<option @selected(old('account_status', $user->account_status ?? 'active') === $s)>{{ $s }}</option>@endforeach
                    </select>
                </div>
            </div>
            <p id="role-help" class="-mt-2 text-xs text-slate-500">{{ $roleHelp[$currentRole] ?? '' }}</p>
            @if (count($heldRoles) > 1)
                <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    This account currently holds several roles ({{ $roles->whereIn('name', $heldRoles)->pluck('label')->join(', ') }}). Saving keeps only the role selected above.
                </p>
            @endif

            <div id="location" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold text-slate-900">Assigned location</h3>
                    <p class="text-xs text-slate-500" data-role-only="none">Choose a role first. District Officers and Local Focal Points are given a location here.</p>
                    <p class="text-xs text-slate-500" data-role-only="reporter system_admin">Not needed for this role.</p>
                    <p class="text-xs text-slate-500" data-role-only="district_officer">Tick a whole province, whole districts, or single communes. The officer sees and manages every report inside.</p>
                    <p class="text-xs text-slate-500" data-role-only="focal_point">Pick a province and district, then tick the communes this officer covers. Cases there are routed to them.</p>
                </div>
                <div data-role-only="district_officer focal_point">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div><label class="label" for="pickProvince">Province</label><select id="pickProvince" class="input"></select></div>
                    <div><label class="label" for="pickDistrict">District</label><select id="pickDistrict" class="input"></select></div>
                </div>
                <div class="mt-3">
                    <div class="mb-1 flex items-center justify-between">
                        <span class="label mb-0" id="pickListLabel">Districts</span>
                        <button type="button" id="pickAll" class="text-xs font-medium text-teal-700 hover:underline">Select all</button>
                    </div>
                    <div id="pickList" class="grid max-h-72 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-slate-200 bg-white p-2 sm:grid-cols-2 lg:grid-cols-3"></div>
                </div>

                <div class="mt-4">
                    <div class="label">Selected <span id="selCount" class="font-normal normal-case text-slate-400"></span></div>
                    <div id="selDistricts" data-role-only="district_officer" class="flex flex-wrap gap-2"></div>
                    <div id="selAreas" data-role-only="focal_point" class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white"></div>
                    <p id="selEmpty" class="text-xs text-slate-400">Nothing selected yet.</p>
                </div>
                </div>
                <input type="hidden" name="areas_present" value="1" data-role-only="focal_point">
                @error('scopes')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                @error('areas')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                <p class="mt-2 text-xs text-slate-500" data-role-only="district_officer"><b>Province</b> = every district in it, including districts added later · <b>District</b> = the whole district · <b>Commune</b> = only reports from that commune. Alerts follow the same area.</p>
                <p class="mt-2 text-xs text-slate-500" data-role-only="focal_point">“Whole district” is usually a <b>backup</b>: it catches cases in communes with no primary officer. Every mapping is listed on the <a href="{{ route('admin.routing.index') }}" class="text-teal-700 hover:underline">coverage page</a>.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="label">{{ $user->exists ? 'Set new password' : 'Password' }} (dashboard users, min 10)</label><input name="password" type="password" class="input" autocomplete="new-password"></div>
                <div><label class="label">Reason for change (audited)</label><input name="reason" class="input" value="{{ old('reason') }}"></div>
            </div>
            <button class="btn btn-primary">{{ $user->exists ? 'Save changes' : 'Create account' }}</button>
        </form>
    </x-card>

    @if ($user->exists)
        <div class="grid gap-5 md:grid-cols-3">
            <x-card title="Telegram link">
                @if (session('link'))
                    <div class="mb-3 rounded-lg border border-teal-200 bg-teal-50 p-3 text-xs">
                        <div class="mb-1 font-semibold text-teal-800">Single-use activation link (shown once)</div>
                        <input readonly value="{{ session('link') }}" class="input font-mono text-[11px]" onclick="this.select()">
                    </div>
                @endif
                @if ($user->telegramAccount)
                    <p class="text-sm">Linked <b>{{ \App\Support\Fmt::dt($user->telegramAccount->linked_at) }}</b> · status <x-chip :color="$user->telegramAccount->status === 'active' ? 'emerald' : 'rose'">{{ $user->telegramAccount->status }}</x-chip></p>
                    <form method="POST" action="{{ route('admin.users.unlink', $user) }}" class="mt-3 space-y-2">@csrf
                        <input name="reason" class="input" placeholder="Reason (e.g. lost phone)" required>
                        <button class="btn btn-ghost w-full justify-center">Unlink Telegram</button>
                    </form>
                @else
                    <p class="text-sm text-slate-500">Not linked. Officers must link through a single-use activation link; roles can never be gained by messaging the bot.</p>
                @endif
                @if ($user->isFocalPoint() || $user->isPrivileged())
                    <form method="POST" action="{{ route('admin.users.link', $user) }}" class="mt-3">@csrf
                        <button class="btn btn-primary w-full justify-center">Generate activation link</button>
                    </form>
                @endif
            </x-card>

            @if ($user->canUseDashboard())
                <x-card title="Two-factor authentication">
                    <p class="text-sm">{{ $user->mfa_enabled ? 'Enrolled.' : 'Not enrolled — will be asked at next sign-in when MFA is required.' }}</p>
                    @if ($user->mfa_enabled)
                        <form method="POST" action="{{ route('admin.users.reset-mfa', $user) }}" class="mt-3 space-y-2">@csrf
                            <input name="reason" class="input" placeholder="Reason (identity verified how?)" required>
                            <button class="btn btn-danger w-full justify-center">Reset MFA</button>
                        </form>
                    @endif
                </x-card>
            @endif

            <x-card title="Audit trail">
                <a href="{{ route('admin.audit.index', ['subject' => 'User', 'subject_uuid' => $user->uuid]) }}" class="text-sm text-teal-700 hover:underline">View all changes to this account →</a>
            </x-card>
        </div>
    @endif
</div>
<script>
    (() => {
        const geo = @json($geo);
        const extra = @json($extraUnits);
        const state = {
            scopes: new Set(@json($userScopes)),
            areas: @json(array_values($userAreas)).map(a => ({ unit: +a.unit, level: a.level, sectors: [...(a.sectors || [])] })),
        };
        const byId = {};
        ['province', 'district', 'commune'].forEach(t => geo[t].forEach(u => byId[u.id] = { ...u, type: t }));
        extra.forEach(u => byId[u.id] = { ...u, type: 'village' });
        const $ = id => document.getElementById(id);
        const role = $('role');
        const roleName = () => role.value.split(':')[0];
        const officerType = () => role.value.split(':')[1] || '';
        const isDO = () => roleName() === 'district_officer';
        const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const ancestors = id => { const out = []; let u = byId[byId[id]?.parent]; while (u) { out.push(u.id); u = byId[u.parent]; } return out; };
        const provinceOf = id => { let u = byId[id]; while (u && u.type !== 'province') u = byId[u.parent]; return u; };
        const path = id => { const out = []; let u = byId[id]; while (u && u.type !== 'province') { out.unshift(u.name); u = byId[u.parent]; } return out.join(' › '); };
        // A VHSG covers HUMAN; a CAO covers ANIMAL and ENVIRONMENT.
        const sectorChoices = () => officerType() === 'VHSG' ? ['HUMAN'] : officerType() === 'CAO' ? ['ANIMAL', 'ENVIRONMENT'] : [];
        // District officer: a unit is covered when a selected province / district contains it.
        const coveredBy = id => ancestors(id).find(a => state.scopes.has(a));

        // Start on the province of something already assigned.
        const first = [...state.scopes][0] ?? state.areas[0]?.unit;
        $('pickProvince').innerHTML = geo.province.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
        if (first && provinceOf(first)) $('pickProvince').value = provinceOf(first).id;

        const districtsIn = pid => geo.district.filter(d => d.parent === +pid);
        function fillDistricts() {
            const list = districtsIn($('pickProvince').value);
            const keep = $('pickDistrict').value;
            $('pickDistrict').innerHTML = (isDO() ? '<option value="">All districts</option>' : '')
                + list.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
            if (keep === '' && isDO()) return;
            if (list.some(d => d.id === +keep)) { $('pickDistrict').value = keep; return; }
            if (!isDO()) {
                const mapped = state.areas.map(a => byId[a.unit])
                    .map(u => u?.type === 'district' ? u.id : u?.type === 'commune' ? u.parent : u?.type === 'village' ? byId[u.parent]?.parent : null)
                    .find(id => list.some(d => d.id === id));
                if (mapped) $('pickDistrict').value = mapped;
            }
        }

        function renderList() {
            const pid = +$('pickProvince').value, did = +$('pickDistrict').value;
            let items;
            if (isDO()) {
                if (!did) {
                    $('pickListLabel').textContent = 'Districts in ' + (byId[pid]?.name ?? '');
                    items = [{ id: pid, name: 'Whole province', strong: true }].concat(districtsIn(pid).map(d => ({ id: d.id, name: d.name, sub: d.code })));
                } else {
                    $('pickListLabel').textContent = 'Communes in ' + (byId[did]?.name ?? '');
                    items = [{ id: did, name: 'Whole district', strong: true }].concat(geo.commune.filter(c => c.parent === did).map(c => ({ id: c.id, name: c.name, sub: c.code })));
                }
                items.forEach(i => { i.cover = coveredBy(i.id); i.on = state.scopes.has(i.id) || !!i.cover; });
            } else {
                $('pickListLabel').textContent = 'Communes in ' + (byId[did]?.name ?? '');
                const has = id => state.areas.some(a => a.unit === id);
                items = did ? [{ id: did, name: 'Whole district (backup)', on: has(did), strong: true }]
                    .concat(geo.commune.filter(c => c.parent === did).map(c => ({ id: c.id, name: c.name, sub: c.code, on: has(c.id) }))) : [];
            }
            $('pickList').innerHTML = items.length ? items.map(i => `
                <label class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm ${i.cover ? 'text-slate-400' : 'cursor-pointer hover:bg-slate-50'} ${i.strong ? 'font-medium text-teal-800' : ''}">
                    <input type="checkbox" data-id="${i.id}" ${i.on ? 'checked' : ''} ${i.cover ? 'disabled' : ''}> <span class="truncate">${esc(i.name)}</span>
                    <span class="ml-auto whitespace-nowrap text-[11px] text-slate-400">${i.cover ? 'covered by ' + esc(byId[i.cover].name) : esc(i.sub ?? '')}</span>
                </label>`).join('') : '<p class="p-2 text-xs text-slate-400">No locations here yet — add them in Geography.</p>';
        }

        const scopeLabel = id => {
            const u = byId[id];
            if (!u) return '#' + id;
            if (u.type === 'province') return `<b>${esc(u.name)}</b> · whole province`;
            if (u.type === 'district') return `<b>${esc(u.name)}</b> · district <span class="text-teal-600/70">· ${esc(provinceOf(id)?.name ?? '')}</span>`;
            return `<b>${esc(u.name)}</b> · commune <span class="text-teal-600/70">· ${esc(byId[u.parent]?.name ?? '')}</span>`;
        };

        function renderSelected() {
            let n;
            if (isDO()) {
                const order = { province: 0, district: 1, commune: 2 };
                const ids = [...state.scopes].filter(id => byId[id]).sort((a, b) => order[byId[a].type] - order[byId[b].type] || byId[a].name.localeCompare(byId[b].name));
                n = ids.length;
                $('selDistricts').innerHTML = ids.map(id => `
                    <span class="inline-flex items-center gap-1 rounded-full border border-teal-200 bg-teal-50 py-1 pl-3 pr-1 text-xs text-teal-900">
                        ${scopeLabel(id)}
                        <button type="button" data-remove-scope="${id}" class="grid h-5 w-5 place-items-center rounded-full hover:bg-teal-100" aria-label="Remove">✕</button>
                        <input type="hidden" name="scopes[]" value="${id}">
                    </span>`).join('');
            } else {
                n = state.areas.length;
                const choices = sectorChoices();
                $('selAreas').innerHTML = state.areas.map((a, i) => `
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">${esc(byId[a.unit]?.type === 'district' ? 'Whole district — ' + byId[a.unit].name : byId[a.unit]?.name ?? '#' + a.unit)}</div>
                            <div class="truncate text-xs text-slate-500">${esc(path(a.unit))} · ${esc(provinceOf(a.unit)?.name ?? '')}</div>
                        </div>
                        <input type="hidden" name="areas[${i}][unit]" value="${a.unit}">
                        <select name="areas[${i}][level]" data-level="${i}" class="input w-auto py-1 text-xs">
                            <option value="primary" ${a.level === 'primary' ? 'selected' : ''}>Primary</option>
                            <option value="backup" ${a.level === 'backup' ? 'selected' : ''}>Backup</option>
                        </select>
                        <div class="flex gap-3 text-xs">${choices.map(s => `
                            <label class="flex items-center gap-1"><input type="checkbox" name="areas[${i}][sectors][]" value="${s}" data-sector="${i}" ${a.sectors.includes(s) ? 'checked' : ''}>${s.charAt(0) + s.slice(1).toLowerCase()}</label>`).join('')}
                        </div>
                        <button type="button" data-remove-area="${i}" class="rounded p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove">✕</button>
                    </div>`).join('');
            }
            $('selCount').textContent = n ? `(${n})` : '';
            $('selEmpty').hidden = n > 0;
        }

        const render = () => { renderList(); renderSelected(); };

        function toggle(id, on) {
            if (isDO()) {
                if (on) {
                    // Selecting a parent replaces anything it already contains.
                    [...state.scopes].forEach(s => { if (ancestors(s).includes(id)) state.scopes.delete(s); });
                    state.scopes.add(id);
                } else {
                    state.scopes.delete(id);
                }
            } else if (on) {
                state.areas.push({ unit: id, level: byId[id]?.type === 'district' ? 'backup' : 'primary', sectors: sectorChoices() });
            } else {
                state.areas = state.areas.filter(a => a.unit !== id);
            }
        }

        $('pickProvince').addEventListener('change', () => { $('pickDistrict').value = ''; fillDistricts(); render(); });
        $('pickDistrict').addEventListener('change', render);
        $('pickList').addEventListener('change', e => {
            const id = +e.target.dataset.id;
            if (!id) return;
            toggle(id, e.target.checked);
            render();
        });
        $('pickAll').addEventListener('click', () => {
            $('pickList').querySelectorAll('input[data-id]').forEach(cb => {
                const t = byId[cb.dataset.id]?.type;
                const whole = isDO() ? (+cb.dataset.id === +$('pickProvince').value || +cb.dataset.id === +$('pickDistrict').value) : t === 'district';
                if (!cb.checked && !cb.disabled && !whole) toggle(+cb.dataset.id, true);
            });
            render();
        });
        $('location').addEventListener('click', e => {
            const s = e.target.closest('[data-remove-scope]'), a = e.target.closest('[data-remove-area]');
            if (s) { state.scopes.delete(+s.dataset.removeScope); render(); }
            if (a) { state.areas.splice(+a.dataset.removeArea, 1); render(); }
        });
        $('location').addEventListener('change', e => {
            const lv = e.target.dataset.level, sc = e.target.dataset.sector;
            if (lv !== undefined) state.areas[+lv].level = e.target.value;
            if (sc !== undefined) {
                const a = state.areas[+sc];
                a.sectors = e.target.checked ? [...new Set([...a.sectors, e.target.value])] : a.sectors.filter(x => x !== e.target.value);
            }
        });

        const help = $('role-help');
        const texts = @json($roleHelp);
        const sync = () => {
            document.querySelectorAll('[data-role-only]').forEach(el => {
                const show = el.dataset.roleOnly.split(' ').includes(roleName() || 'none');
                el.hidden = !show;
                if (el.tagName === 'INPUT') el.disabled = !show;
            });
            help.textContent = texts[role.value] || '';
            fillDistricts();
            render();
        };
        // VHSG covers HUMAN, CAO covers ANIMAL / ENVIRONMENT: keep what still fits, else use the defaults.
        let lastType = officerType();
        role.addEventListener('change', () => {
            if (officerType() !== lastType) state.areas.forEach(a => {
                const kept = a.sectors.filter(s => sectorChoices().includes(s));
                a.sectors = kept.length ? kept : sectorChoices();
            });
            lastType = officerType();
            sync();
        });
        sync();
    })();
</script>
@endsection
