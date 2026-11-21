@extends('layouts.app')
@section('title', 'Health Center reviews')
@section('content')
<div class="space-y-4">
    @forelse ($reviews as $review)
        <x-card :title="$review->report->reference_code">
            <p class="text-sm text-slate-500">{{ $review->report->commune?->name_en }} · {{ $review->completed_at ? 'Completed' : ($review->acknowledged_at ? 'Acknowledged' : 'Awaiting acknowledgement') }}</p>
            <p class="my-3">{{ $review->reason }}</p>
            @if ($review->completed_at)
                <p class="whitespace-pre-line"><strong>Findings</strong> {{ $review->findings }}</p>
            @elseif ($review->acknowledged_at)
                <form method="POST" action="{{ route('health.complete', $review->report) }}" class="space-y-2">
                    @csrf
                    <label class="block">Human-health findings
                        <textarea name="findings" class="input" rows="3" maxlength="2000" required></textarea>
                    </label>
                    <button class="btn btn-primary">Complete review</button>
                </form>
            @else
                <form method="POST" action="{{ route('health.acknowledge', $review->report) }}">
                    @csrf
                    <button class="btn btn-primary">Acknowledge referral</button>
                </form>
            @endif
        </x-card>
    @empty
        <x-card title="My referrals"><p>No reviews assigned in your catchment.</p></x-card>
    @endforelse
    {{ $reviews->links() }}
</div>
@endsection
