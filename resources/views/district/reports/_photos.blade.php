{{-- Evidence thumbnails. Expects $attachments, optional $height. Click opens the full image in a new tab. --}}
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
    @foreach ($attachments as $a)
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
            @if ($a->path)
                <a href="{{ route('district.attachments.show', $a) }}" target="_blank" title="Open full size">
                    <img src="{{ route('district.attachments.show', $a) }}" class="{{ $height ?? 'h-32' }} w-full object-cover transition hover:opacity-90" alt="Evidence photo">
                </a>
            @else
                <div class="grid {{ $height ?? 'h-32' }} place-items-center px-2 text-center text-[11px] text-slate-500">
                    {{ match ($a->download_status) {
                        'PENDING' => 'Downloading… (queue worker must be running)',
                        'SKIPPED' => 'Not downloaded — bot token was not set',
                        'REJECTED' => 'Rejected — not a JPG/PNG/WebP image',
                        'FAILED' => 'Download failed — see Alerts & Jobs',
                        default => $a->download_status,
                    } }}
                </div>
            @endif
            <div class="px-2 py-1 text-[11px] text-slate-500">{{ $a->source === 'officer' ? 'Officer evidence' : 'Reporter photo' }} · {{ \App\Support\Fmt::dt($a->created_at, 'd M H:i') }}</div>
        </div>
    @endforeach
</div>
