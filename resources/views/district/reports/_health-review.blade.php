<x-card title="Health Center review">
    @if ($healthReview)
        <p>{{ $healthReview->reviewer->name }} · {{ $healthReview->completed_at ? 'Completed' : ($healthReview->acknowledged_at ? 'Acknowledged' : 'Awaiting acknowledgement') }}</p>
        <p class="mt-2">{{ $healthReview->reason }}</p>
        @if ($healthReview->completed_at)
            <p class="mt-2 whitespace-pre-line"><strong>Findings</strong> {{ $healthReview->findings }}</p>
        @endif
    @endif
    @can('triage', $report)
        @if (! $healthReview?->completed_at)
            @if ($healthReviewers->isEmpty())
                <p>No active Health Center focal point covers this location. An administrator can assign catchment areas under Accounts &amp; Roles.</p>
            @else
                <form method="POST" action="{{ route('district.reports.health-review', $report) }}" class="mt-2 space-y-2">
                    @csrf
                    <label class="block">Health Center focal point
                        <select class="input" name="reviewer_id" required>
                            <option value="">Choose a reviewer</option>
                            @foreach ($healthReviewers as $reviewer)
                                <option value="{{ $reviewer->id }}" @selected(old('reviewer_id', $healthReview?->reviewer_id) == $reviewer->id)>{{ $reviewer->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">Human illness or exposure requiring follow-up
                        <textarea class="input" name="reason" maxlength="500" required>{{ old('reason', $healthReview?->reason) }}</textarea>
                    </label>
                    <button class="btn btn-primary">{{ $healthReview ? 'Update referral' : 'Refer to Health Center' }}</button>
                </form>
            @endif
        @endif
    @else
        @unless ($healthReview)<p>No Health Center referral.</p>@endunless
    @endcan
</x-card>
