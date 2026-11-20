@extends('layouts.app')
@section('title', 'Closure')
@section('content')
    <div class="grid gap-5 xl:grid-cols-2">
        <x-card title="Ready for closure review" subtitle="Officer outcome recorded and no open escalation" :padding="false">
            @forelse ($closable as $r)
                @php $v = $r->verifications->sortByDesc('verified_at')->first(); @endphp
                <a href="{{ route('district.reports.show', $r) }}" class="block border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-sm font-semibold">{{ $r->reference_code }}</span>
                        <x-chip :color="$r->status->color()" dot>{{ $r->status->label() }}</x-chip>
                        <span class="text-xs text-slate-500">{{ $r->category?->name_en }} · {{ $r->location?->fullPath() }}</span>
                    </div>
                    @if ($v)<p class="mt-1 line-clamp-2 text-xs text-slate-600">{{ $v->findings }}</p>@endif
                </a>
            @empty
                <x-empty text="Nothing awaiting closure." />
            @endforelse
            <div class="px-4 py-3">{{ $closable->links() }}</div>
        </x-card>
        <x-card title="Recently closed" subtitle="Reopen from the case page with an audit reason" :padding="false">
            @forelse ($closed as $r)
                @php $c = $r->closures->sortByDesc('closed_at')->first(); @endphp
                <a href="{{ route('district.reports.show', $r) }}" class="block border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-sm font-semibold">{{ $r->reference_code }}</span>
                        <span class="text-xs text-slate-500">closed {{ \App\Support\Fmt::dt($r->closed_at) }} by {{ $c?->closer?->name }}</span>
                    </div>
                    <p class="mt-1 line-clamp-1 text-xs text-slate-600">{{ $c?->outcome_summary }}</p>
                </a>
            @empty
                <x-empty text="No closed cases yet." />
            @endforelse
            <div class="px-4 py-3">{{ $closed->links() }}</div>
        </x-card>
    </div>
@endsection
