@props(['color' => 'slate', 'dot' => false])
@php
    $map = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200', 'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200', 'indigo' => 'bg-indigo-50 text-indigo-800 ring-indigo-200',
        'violet' => 'bg-violet-50 text-violet-800 ring-violet-200', 'orange' => 'bg-orange-50 text-orange-800 ring-orange-200',
        'emerald' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'rose' => 'bg-rose-50 text-rose-800 ring-rose-200',
        'stone' => 'bg-stone-100 text-stone-700 ring-stone-200', 'zinc' => 'bg-zinc-100 text-zinc-700 ring-zinc-300',
        'teal' => 'bg-teal-50 text-teal-800 ring-teal-200',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset '.($map[$color] ?? $map['slate'])]) }}>
    @if ($dot)<span class="h-1.5 w-1.5 rounded-full bg-current opacity-70"></span>@endif{{ $slot }}
</span>
