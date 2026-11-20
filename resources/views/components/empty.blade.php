@props(['text' => 'Nothing to show for the current filters.'])
<div class="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center text-sm text-slate-500">
    <svg class="h-8 w-8 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 13V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6m16 0v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4m16 0h-2.6a1 1 0 0 0-.7.3l-2.4 2.4a1 1 0 0 1-.7.3h-3.2a1 1 0 0 1-.7-.3l-2.4-2.4a1 1 0 0 0-.7-.3H4"/></svg>
    {{ $text }}
</div>
