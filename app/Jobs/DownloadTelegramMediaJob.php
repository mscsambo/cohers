<?php

namespace App\Jobs;

use App\Models\ReportAttachment;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies Telegram media into private storage. Failure never affects the
 * report itself; the text report is always preserved (section 05).
 */
class DownloadTelegramMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    private const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(public int $attachmentId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(TelegramClient $telegram): void
    {
        $a = ReportAttachment::find($this->attachmentId);
        if (! $a || $a->download_status === 'STORED' || ! $a->telegram_file_id) {
            return;
        }
        if (! $telegram->configured()) {
            $a->update(['download_status' => 'SKIPPED']);

            return;
        }

        [$bytes] = $telegram->downloadFile($a->telegram_file_id, config('cohers.telegram.max_photo_bytes'));

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (! isset(self::ALLOWED[$mime])) {
            $a->update(['download_status' => 'REJECTED', 'mime' => $mime]);

            return;
        }

        $path = sprintf('private/reports/%d/%d_%s.%s', $a->report_id, $a->id, bin2hex(random_bytes(6)), self::ALLOWED[$mime]);
        Storage::disk('local')->put($path, $bytes);

        $a->update(['disk' => 'local', 'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'download_status' => 'STORED']);
    }

    public function failed(Throwable $e): void
    {
        ReportAttachment::whereKey($this->attachmentId)->update(['download_status' => 'FAILED']);
    }
}
