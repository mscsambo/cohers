@extends('layouts.app')
@section('title', 'Officer Workload')
@section('content')
    <x-card title="Focal points in scope" subtitle="Assignment lists, acknowledgements and verification aging" :padding="false">
        <div class="overflow-x-auto">
            <table class="data w-full">
                <thead><tr>
                    <th>Officer</th><th>Type</th><th>Areas (p = primary, b = backup)</th><th>Telegram</th>
                    <th class="text-right">Pending ack</th><th class="text-right">Overdue</th><th class="text-right">Verifying</th>
                    <th class="text-right">Completed 30d</th><th class="text-right">Avg ack</th><th class="text-right">Oldest verification</th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td class="font-medium">{{ $r['officer']->name }}</td>
                        <td><x-chip :color="$r['officer']->officer_type?->value === 'VHSG' ? 'sky' : 'emerald'">{{ $r['officer']->officer_type?->value }}</x-chip></td>
                        <td class="max-w-xs text-xs text-slate-500">{{ $r['areas'] ?: '—' }}</td>
                        <td>@if ($r['linked'])<x-chip color="emerald">linked</x-chip>@else<x-chip color="rose">not linked</x-chip>@endif</td>
                        <td class="text-right tabular-nums">{{ $r['pending'] }}</td>
                        <td class="text-right tabular-nums {{ $r['overdue'] ? 'font-bold text-orange-600' : 'text-slate-400' }}">{{ $r['overdue'] }}</td>
                        <td class="text-right tabular-nums">{{ $r['verifying'] }}</td>
                        <td class="text-right tabular-nums">{{ $r['completed_30d'] }}</td>
                        <td class="text-right tabular-nums">{{ \App\Support\Fmt::minutes($r['avg_ack_minutes']) }}</td>
                        <td class="text-right tabular-nums {{ $r['aging'] ? 'font-semibold text-rose-600' : '' }}">{{ $r['oldest_verification_hours'] !== null ? $r['oldest_verification_hours'].'h' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10"><x-empty text="No focal points are mapped in your districts." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
    <p class="mt-3 text-xs text-slate-500">Verification aging is highlighted after {{ \App\Models\SystemSetting::get_value('verification_target_hours', 48) }} hours (configurable in settings). Unlinked officers cannot receive Telegram alerts.</p>
@endsection
