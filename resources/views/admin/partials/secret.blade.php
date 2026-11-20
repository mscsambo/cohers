{{-- Secret input: never echoes the stored value. Expects $f = [key, name, label, placeholder, set, masked, source] --}}
<div>
    <div class="flex items-center justify-between">
        <label class="label">{{ $f['label'] }}</label>
        <span class="text-[11px] {{ $f['set'] ? 'text-emerald-700' : 'text-rose-600' }}">{{ $f['set'] ? $f['masked'].' · '.$f['source'] : 'not set' }}</span>
    </div>
    <input type="password" name="{{ $f['name'] }}" class="input font-mono" autocomplete="new-password" placeholder="{{ $f['set'] ? 'Leave empty to keep current value' : $f['placeholder'] }}">
    @if ($f['source'] === 'Admin configuration')
        <label class="mt-1 flex items-center gap-1.5 text-xs text-slate-500"><input type="checkbox" name="clear[]" value="{{ $f['key'] }}"> Remove saved value (fall back to .env)</label>
    @endif
</div>
