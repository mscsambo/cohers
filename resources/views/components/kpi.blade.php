@props(['label', 'value', 'sub' => null, 'tone' => 'slate', 'href' => null])
@php
    $tones = [
        'slate' => 'text-slate-900', 'rose' => 'text-rose-600', 'amber' => 'text-amber-600',
        'teal' => 'text-teal-700', 'sky' => 'text-sky-700', 'orange' => 'text-orange-600',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif class="block rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition {{ $href ? 'hover:border-teal-300 hover:shadow' : '' }}">
    <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</div>
    <div class="mt-1.5 text-3xl font-bold tabular-nums {{ $tones[$tone] ?? $tones['slate'] }}">{{ $value }}</div>
    @if ($sub)<div class="mt-1 text-xs text-slate-500">{{ $sub }}</div>@endif
</{{ $tag }}>
