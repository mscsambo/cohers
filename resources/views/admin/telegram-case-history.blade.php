@extends('layouts.app')
@section('title', 'Case history')
@section('subtitle', $user->name)
@section('content')
    <a class="btn btn-ghost mb-4" href="{{ route('admin.telegram-users.index', $directory) }}">← Telegram users</a>
    <x-card :title="['all' => 'All case activity', 'submitted' => 'Submitted reports', 'active' => 'Active assignments'][$filter]" :padding="false">
        <div class="overflow-x-auto">
            <table class="data w-full">
                <thead><tr><th>When</th><th>Activity</th><th>Case</th><th>Current status</th></tr></thead>
                <tbody>
                @forelse ($history as $event)
                    <tr>
                        <td>{{ \App\Support\Fmt::dt(\Illuminate\Support\Carbon::parse($event->happened_at, 'UTC')) }}</td>
                        <td>{{ $event->action }}</td>
                        <td><a class="font-mono text-teal-700 underline" href="{{ route('district.reports.show', $event->uuid) }}">{{ $event->reference_code }}</a></td>
                        <td>{{ \App\Enums\ReportStatus::from($event->status)->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-empty text="No matching case activity for this account." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3">{{ $history->links() }}</div>
    </x-card>
@endsection
