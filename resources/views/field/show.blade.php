@extends('layouts.app')
@section('title', $report->reference_code)
@section('subtitle', $report->location?->fullPath())
@section('content')
@php $openQuestion = $report->informationRequests->firstWhere('status', 'OPEN'); @endphp
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('field.index') }}" class="btn btn-ghost">← My field cases</a>
    <x-chip :color="$report->status->color()" dot>{{ $report->status->label() }}</x-chip>
    @if ($report->priority)<x-chip :color="$report->priority->color()">{{ $report->priority->value }}</x-chip>@endif
    <span class="text-xs text-slate-500">Acknowledged ≠ Verified ≠ Closed</span>
</div>

@if ($errors->has('action'))
    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first('action') }}</div>
@endif

<div class="grid gap-5 xl:grid-cols-3">
    <div class="space-y-5 xl:col-span-2">
        <x-card title="Report" subtitle="As submitted by the community reporter">
            <p class="whitespace-pre-line text-[15px] leading-relaxed text-slate-800">{{ $report->original_text }}</p>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                <div><dt class="label">Category</dt><dd>{{ $report->category?->name_en ?? 'Unclassified' }}<div class="text-xs text-amber-700">AI suggestion · unverified</div></dd></div>
                <div><dt class="label">Affected</dt><dd>{{ $report->affected_count ?? '—' }}</dd></div>
                <div><dt class="label">Observed</dt><dd>{{ $report->observed_text ?? '—' }}</dd></div>
                <div><dt class="label">Submitted</dt><dd>{{ \App\Support\Fmt::dt($report->submitted_at) }}</dd></div>
                <div class="col-span-2"><dt class="label">Location</dt><dd>{{ $report->location?->fullPath() }}</dd></div>
                <div class="col-span-2"><dt class="label">Possible duplicate of</dt><dd class="font-mono">{{ $report->possibleDuplicateOf?->reference_code ?? '—' }}</dd></div>
            </dl>
        </x-card>

        @if ($report->attachments->isNotEmpty())
            <x-card title="Photos ({{ $report->attachments->count() }})">
                @include('district.reports._photos', ['attachments' => $report->attachments, 'height' => 'h-40'])
            </x-card>
        @endif

        <x-card title="Timeline">
            @include('district.reports._timeline')
        </x-card>
    </div>

    <div class="space-y-5">
        @if (! $assignment)
            <x-card title="Your part">
                <p class="text-sm text-slate-600">This case is no longer actively assigned to you, so there is nothing to do here. It stays visible for reference.</p>
            </x-card>
        @else
            <x-card title="Your assignment" subtitle="{{ ucfirst($assignment->level) }} · {{ $assignment->assignment_status->value }}">
                <dl class="mb-4 grid grid-cols-2 gap-2 text-xs">
                    <div><dt class="text-slate-400">Assigned</dt><dd>{{ \App\Support\Fmt::dt($assignment->assigned_at) }}</dd></div>
                    <div><dt class="text-slate-400">Acknowledge by</dt><dd class="{{ $assignment->isOverdue() ? 'font-semibold text-rose-600' : '' }}">{{ \App\Support\Fmt::dt($assignment->ack_due_at) }}</dd></div>
                </dl>
                <div class="flex flex-wrap gap-2">
                    @if (! $assignment->acknowledged_at)
                        <form method="POST" action="{{ route('field.cases.acknowledge', $report) }}">@csrf<button class="btn btn-primary">✅ Acknowledge</button></form>
                    @endif
                    @if (! $assignment->verification_started_at)
                        <form method="POST" action="{{ route('field.cases.verify', $report) }}">@csrf<button class="btn {{ $assignment->acknowledged_at ? 'btn-primary' : 'btn-ghost' }}">🔬 Start verification</button></form>
                    @endif
                </div>
            </x-card>

            <x-card title="Submit findings" subtitle="Your field outcome; the district officer then reviews and closes">
                <form method="POST" action="{{ route('field.cases.findings', $report) }}" class="space-y-2">
                    @csrf
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['VERIFIED' => '✔️ Verified', 'REJECTED' => '✖️ Rejected', 'DUPLICATE' => '🔁 Duplicate'] as $value => $label)
                            <label class="flex cursor-pointer items-center justify-center gap-1 rounded-lg border border-slate-200 p-2 text-xs has-[:checked]:border-teal-500 has-[:checked]:bg-teal-50">
                                <input type="radio" name="outcome" value="{{ $value }}" @checked(old('outcome') === $value) required class="sr-only">{{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <textarea name="findings" rows="4" class="input" placeholder="What you found on site (required)" required>{{ old('findings') }}</textarea>
                    <input name="duplicate_code" value="{{ old('duplicate_code', $report->possibleDuplicateOf?->reference_code) }}" class="input font-mono text-xs" placeholder="For Duplicate: original report code, e.g. COH-2026-000012">
                    @error('duplicate_code')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="follow_up" value="1" @checked(old('follow_up'))> Further response or district follow-up needed</label>
                    <button class="btn btn-primary w-full justify-center">Submit findings</button>
                </form>
            </x-card>

            <x-card title="Ask the reporter" subtitle="Sent to them in Telegram with a Reply button">
                @if ($openQuestion)
                    <p class="mb-2 rounded bg-orange-50 p-2 text-xs text-orange-800">Waiting for an answer to: <i>{{ $openQuestion->question }}</i></p>
                @endif
                <form method="POST" action="{{ route('field.cases.info', $report) }}" class="space-y-2">
                    @csrf
                    <textarea name="question" rows="2" class="input" placeholder="e.g. How many households are affected?" required></textarea>
                    <button class="btn btn-ghost w-full justify-center">Send question</button>
                </form>
            </x-card>

            <x-card title="Escalate to district">
                <form method="POST" action="{{ route('field.cases.escalate', $report) }}" class="space-y-2">
                    @csrf
                    <textarea name="reason" rows="2" class="input" placeholder="Why the district must step in (required)" required></textarea>
                    <button class="btn btn-ghost w-full justify-center text-rose-700">⚠️ Escalate</button>
                </form>
            </x-card>
        @endif
    </div>
</div>
@endsection
