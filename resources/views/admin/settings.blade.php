@extends('layouts.app')
@section('title', 'Language & Settings')
@section('subtitle', 'Khmer/English bot messages, privacy text, response targets and feature flags')
@section('content')
<form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-5">
    @csrf @method('PUT')
    <div class="grid gap-5 xl:grid-cols-3">
        <x-card title="Acknowledgement targets" subtitle="Pilot suggestions — must be approved by program authorities">
            <div class="space-y-3">
                @foreach ($targets as $p => $min)
                    <div class="flex items-center gap-3">
                        <x-chip :color="\App\Enums\Priority::from($p)->color()" class="w-20 justify-center">{{ $p }}</x-chip>
                        <input type="number" name="targets[{{ $p }}]" value="{{ $min }}" min="1" class="input w-28"> <span class="text-xs text-slate-500">minutes</span>
                    </div>
                @endforeach
                <div class="border-t border-slate-100 pt-3">
                    <label class="label">Verification aging threshold (hours)</label>
                    <input type="number" name="verification_target_hours" value="{{ $verificationHours }}" class="input w-28">
                </div>
            </div>
            <p class="mt-3 text-xs text-slate-500">LOW: reminder → overdue queue · MEDIUM: reminder → backup · HIGH: backup + district alert · CRITICAL: immediate district alert, repeated until acknowledged.</p>
        </x-card>

        <x-card title="Feature flags & security">
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="require_mfa" value="1" @checked($requireMfa) class="mt-1">
                <span><b>Require MFA</b> for district officers and administrators<br><span class="text-xs text-slate-500">Mandatory for the pilot; disable only for local demos.</span></span></label>
            <label class="mt-4 flex items-start gap-3 text-sm"><input type="checkbox" name="incentives_enabled" value="1" @checked($incentives) class="mt-1">
                <span><b>Incentive points</b> (optional)<br><span class="text-xs text-slate-500">Enable only after policy approval. One award per verified report, ledgered.</span></span></label>
            <div class="mt-5"><label class="label">Reason for change (audited)</label><input name="reason" class="input"></div>
        </x-card>

        <x-card title="Save">
            <p class="mb-3 text-sm text-slate-600">All changes are written to the audit log with old and new values.</p>
            <button class="btn btn-primary w-full justify-center">Save settings</button>
        </x-card>
    </div>

    <x-card title="Bot messages" subtitle="Leave a message unchanged to keep the default translation file text. HTML tags allowed: <b>, <i>, <code>.">
        <div class="space-y-6">
            @foreach ($messages as $key => $locs)
                <div>
                    <div class="mb-2 font-mono text-xs font-semibold text-slate-500">{{ $key }}</div>
                    <div class="grid gap-3 lg:grid-cols-2">
                        <div><label class="label">ខ្មែរ (default)</label><textarea name="messages[{{ $key }}][km]" rows="5" class="input" lang="km">{{ $locs['km'] }}</textarea></div>
                        <div><label class="label">English</label><textarea name="messages[{{ $key }}][en]" rows="5" class="input">{{ $locs['en'] }}</textarea></div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-card>
</form>
@endsection
