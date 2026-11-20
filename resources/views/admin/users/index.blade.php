@extends('layouts.app')
@section('title', 'Accounts & Roles')
@section('content')
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <div class="min-w-[200px] flex-1"><label class="label">Search</label><input name="q" value="{{ request('q') }}" class="input" placeholder="Name or email"></div>
        <div><label class="label">Role</label>
            <select name="role" class="input">
                <option value="">All roles</option>
                @foreach ($roles as $r)<option value="{{ $r->name }}" @selected(request('role') === $r->name)>{{ $r->label }}</option>@endforeach
            </select>
        </div>
        <div><label class="label">Status</label>
            <select name="status" class="input">
                <option value="">Any</option>
                @foreach (['active', 'suspended', 'pending'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach
            </select>
        </div>
        <button class="btn btn-ghost">Filter</button>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">+ New account</a>
    </form>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="data w-full">
                <thead><tr><th>Name</th><th>Roles</th><th>Type</th><th>Scope</th><th>Telegram</th><th>MFA</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td><div class="font-medium">{{ $u->name }}</div><div class="text-xs text-slate-500">{{ $u->email ?? 'Telegram-only' }}</div></td>
                        <td class="space-x-1">@foreach ($u->roles as $r)<x-chip :color="['system_admin' => 'violet', 'district_officer' => 'indigo', 'focal_point' => 'teal', 'reporter' => 'slate'][$r->name] ?? 'slate'">{{ $r->label }}</x-chip>@endforeach</td>
                        <td class="text-xs">{{ $u->officer_type?->value ?? '—' }}</td>
                        <td class="max-w-[200px] truncate text-xs text-slate-500">{{ $u->scopes->pluck('name_en')->implode(', ') ?: '—' }}</td>
                        <td>@if ($u->telegramAccount)<x-chip :color="$u->telegramAccount->status === 'active' ? 'emerald' : 'rose'">{{ $u->telegramAccount->status }}</x-chip>@else<span class="text-xs text-slate-400">—</span>@endif</td>
                        <td class="text-xs">{{ $u->canUseDashboard() ? ($u->mfa_enabled ? '✔' : 'not enrolled') : '' }}</td>
                        <td><x-chip :color="$u->account_status === 'active' ? 'emerald' : 'rose'">{{ $u->account_status }}</x-chip></td>
                        <td class="text-right"><a href="{{ route('admin.users.edit', $u) }}" class="text-sm font-medium text-teal-700 hover:underline">Manage</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 px-4 py-3">{{ $users->links() }}</div>
    </x-card>
@endsection
