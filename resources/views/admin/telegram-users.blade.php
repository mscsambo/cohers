@extends('layouts.app')
@section('title', 'Telegram Users')
@section('subtitle', 'Every account connected to the COHERS bot · admin only')
@section('content')
<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-6">
    <x-kpi label="Connected" :value="$stats['total']" :href="route('admin.telegram-users.index')" />
    <x-kpi label="Reporters" :value="$stats['reporters']" tone="sky" :href="route('admin.telegram-users.index', ['role' => 'reporter'])" />
    <x-kpi label="Focal points" :value="$stats['focal_points']" tone="teal" :href="route('admin.telegram-users.index', ['role' => 'focal_point'])" />
    <x-kpi label="District officers" :value="$stats['district']" :href="route('admin.telegram-users.index', ['role' => 'district_officer'])" />
    <x-kpi label="Active (7 days)" :value="$stats['active_7d']" tone="teal" :href="route('admin.telegram-users.index', ['activity' => '7d'])" />
    <x-kpi label="Blocked the bot" :value="$stats['blocked']" tone="rose" sub="alerts cannot be delivered" :href="route('admin.telegram-users.index', ['status' => 'blocked'])" />
</div>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
    <div class="min-w-[220px] flex-1"><label class="label">Search</label><input name="q" value="{{ request('q') }}" class="input" placeholder="Name, @username or Telegram ID"></div>
    <div><label class="label">Role</label>
        <select name="role" class="input">
            <option value="">All roles</option>
            @foreach ($roles as $r)<option value="{{ $r->name }}" @selected(request('role') === $r->name)>{{ $r->label }}</option>@endforeach
        </select>
    </div>
    <div><label class="label">Chat status</label>
        <select name="status" class="input">
            <option value="">Any</option>
            @foreach (['active', 'blocked'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach
        </select>
    </div>
    <div><label class="label">Activity</label>
        <select name="activity" class="input">
            <option value="">Any</option>
            <option value="7d" @selected(request('activity') === '7d')>Seen in last 7 days</option>
            <option value="inactive" @selected(request('activity') === 'inactive')>Not seen in 30 days</option>
        </select>
    </div>
    <button class="btn btn-ghost">Filter</button>
    <a href="{{ route('admin.telegram-users.export', request()->query()) }}" class="btn btn-ghost">Export CSV (logged)</a>
</form>

<x-card :padding="false">
    <div class="overflow-x-auto">
        <table class="data w-full">
            <thead><tr>
                <th>Account</th><th>Roles</th><th>Telegram</th><th>Telegram ID</th><th>Linked</th><th>Last seen</th>
                <th class="text-right">Reports</th><th class="text-right">Active cases</th><th>Chat</th><th></th>
            </tr></thead>
            <tbody>
            @forelse ($accounts as $a)
                @php $u = $a->user; @endphp
                <tr>
                    <td>
                        <div class="font-medium">{{ $u?->name ?? '—' }}</div>
                        <div class="text-xs text-slate-500">{{ $u?->officer_type?->value }} {{ $u?->account_status !== 'active' ? '· account '.$u?->account_status : '' }}</div>
                    </td>
                    <td class="space-x-1 whitespace-nowrap">
                        @foreach ($u?->roles ?? [] as $r)
                            <x-chip :color="['system_admin' => 'violet', 'district_officer' => 'indigo', 'focal_point' => 'teal', 'reporter' => 'slate'][$r->name] ?? 'slate'">{{ $r->label }}</x-chip>
                        @endforeach
                    </td>
                    <td class="text-xs">
                        @if ($a->username)<a href="https://t.me/{{ $a->username }}" target="_blank" rel="noopener" class="font-medium text-teal-700 hover:underline">{{ '@'.$a->username }}</a>@else<span class="text-slate-400">no username</span>@endif
                        <div class="text-slate-500">{{ $a->first_name }} {{ $a->language_code ? '· '.$a->language_code : '' }}</div>
                    </td>
                    <td class="font-mono text-xs">{{ $a->telegram_user_id }}</td>
                    <td class="whitespace-nowrap text-xs">{{ \App\Support\Fmt::dt($a->linked_at) }}</td>
                    <td class="whitespace-nowrap text-xs">
                        @if ($a->last_seen_at)
                            <span title="{{ \App\Support\Fmt::dt($a->last_seen_at) }}">{{ $a->last_seen_at->diffForHumans() }}</span>
                        @else<span class="text-slate-400">never</span>@endif
                    </td>
                    <td class="text-right tabular-nums">{{ $a->reports_count }}</td>
                    <td class="text-right tabular-nums">{{ $a->active_assignments_count ?: '' }}</td>
                    <td><x-chip :color="$a->status === 'active' ? 'emerald' : 'rose'">{{ $a->status }}</x-chip></td>
                    <td class="whitespace-nowrap text-right">
                        @if ($u)
                            <a href="{{ route('admin.users.edit', $u) }}" class="text-xs font-medium text-teal-700 hover:underline">Manage</a>
                            <details class="inline-block text-left">
                                <summary class="ml-2 cursor-pointer text-xs font-medium text-rose-600">Unlink</summary>
                                <form method="POST" action="{{ route('admin.users.unlink', $u) }}" class="absolute right-6 z-10 mt-1 w-64 space-y-2 rounded-lg border border-slate-200 bg-white p-3 shadow-lg">
                                    @csrf
                                    <input name="reason" class="input" placeholder="Reason (required, audited)" required>
                                    <button class="btn btn-danger w-full justify-center">Unlink Telegram</button>
                                </form>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10"><x-empty text="No Telegram accounts match these filters." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-100 px-4 py-3">{{ $accounts->links() }}</div>
</x-card>
<p class="mt-3 text-xs text-slate-500">"Blocked" means the person blocked the bot or deleted the chat, so Telegram refuses delivery; it clears automatically when they message the bot again. Unlinking removes the Telegram binding only — the account, its reports and the audit history are kept.</p>
@endsection
