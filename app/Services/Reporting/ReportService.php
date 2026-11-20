<?php

namespace App\Services\Reporting;

use App\Enums\ReportStatus;
use App\Jobs\DownloadTelegramMediaJob;
use App\Jobs\ProcessIncomingReportJob;
use App\Models\GeographicUnit;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Validates and persists reports; owns the status machine and its history. */
class ReportService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Create a durable report. Idempotent on $data['idempotency_key'] so repeated
     * button presses or Telegram retries never create a second report.
     */
    public function create(User $reporter, array $data): Report
    {
        $text = trim((string) ($data['original_text'] ?? ''));
        if (mb_strlen($text) < 3 || mb_strlen($text) > 4000) {
            throw ValidationException::withMessages(['original_text' => 'Description must be 3-4000 characters.']);
        }

        $location = GeographicUnit::where('is_active', true)->find($data['geographic_unit_id'] ?? 0);
        if (! $location || ! in_array($location->type, ['village', 'zone', 'commune'], true)) {
            throw ValidationException::withMessages(['geographic_unit_id' => 'A valid active village is required.']);
        }

        if (! empty($data['idempotency_key'])
            && $existing = Report::where('idempotency_key', $data['idempotency_key'])->first()) {
            return $existing;
        }

        $photos = array_slice($data['photo_file_ids'] ?? [], 0, (int) config('cohers.telegram.max_photos'));

        try {
            $report = DB::transaction(function () use ($reporter, $data, $text, $location, $photos) {
                $report = Report::create([
                    'reference_code' => 'TMP-'.uniqid(),
                    'reporter_id' => $reporter->id,
                    'geographic_unit_id' => $location->id,
                    'commune_id' => $location->ancestorOfType('commune')?->id,
                    'district_id' => $location->ancestorOfType('district')?->id,
                    'original_text' => $text,
                    'affected_count' => $data['affected_count'] ?? null,
                    'observed_text' => $data['observed_text'] ?? null,
                    'observed_at' => $data['observed_at'] ?? null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'language' => $data['language'] ?? $reporter->locale(),
                    'channel' => $data['channel'] ?? 'telegram',
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'status' => ReportStatus::SUBMITTED,
                    'escalation_state' => \App\Enums\EscalationState::NONE,
                    'triage_status' => 'PENDING',
                    'submitted_at' => now(),
                ]);
                $report->reference_code = $this->referenceCode($report);
                $report->save();

                foreach ($photos as $fileId) {
                    $report->attachments()->create([
                        'uploaded_by' => $reporter->id,
                        'source' => 'reporter',
                        'kind' => 'photo',
                        'telegram_file_id' => $fileId,
                    ]);
                }

                $this->history($report, null, ReportStatus::SUBMITTED, $reporter, 'Report submitted', $report->channel);

                return $report;
            });
        } catch (QueryException $e) {
            // Lost a race on the idempotency key: return the winner.
            if (! empty($data['idempotency_key'])
                && $existing = Report::where('idempotency_key', $data['idempotency_key'])->first()) {
                return $existing;
            }
            throw $e;
        }

        foreach ($report->attachments as $attachment) {
            DownloadTelegramMediaJob::dispatch($attachment->id);
        }
        ProcessIncomingReportJob::dispatch($report->id);

        return $report;
    }

    /**
     * The reporter adds information to their own open report. The assigned officer is
     * told in Telegram; with no officer yet, the district officers for the area are.
     */
    public function addReporterUpdate(Report $report, User $reporter, string $body): \App\Models\ReportUpdate
    {
        $update = $report->reporterUpdates()->create(['user_id' => $reporter->id, 'body' => trim($body)]);
        $this->audit->record('report.reporter_update', $report, $reporter, null, null, ['update_id' => $update->id], 'telegram');

        $notify = app(\App\Services\Notification\NotificationService::class);
        $officer = $report->activeAssignment?->officer;
        $recipients = $officer ? collect([$officer]) : $notify->districtOfficers($report);
        foreach ($recipients as $user) {
            $url = $user->is($officer) ? route('field.cases.show', $report) : route('district.reports.show', $report);
            $notify->toUser($user, 'officer.reporter_update',
                fn ($loc) => \App\Support\BotText::t('officer.reporter_update', $loc, [
                    'code' => $report->reference_code,
                    'body' => \App\Support\Fmt::h($update->body),
                    'url' => $url,
                ]), "rep-upd:{$update->id}:{$user->id}", $report);
        }

        return $update;
    }

    /**
     * Stores a photo uploaded from the Mini App the same way Telegram photos are
     * stored (private disk, type checked from the file content). Returns null for non-images.
     */
    public function attachUploadedPhoto(Report $report, User $by, \Illuminate\Http\UploadedFile $file): ?\App\Models\ReportAttachment
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (! $ext) {
            return null;
        }

        $attachment = $report->attachments()->create(['uploaded_by' => $by->id, 'source' => 'reporter', 'kind' => 'photo', 'download_status' => 'PENDING']);
        $name = sprintf('%d_%s.%s', $attachment->id, bin2hex(random_bytes(6)), $ext);
        $path = \Illuminate\Support\Facades\Storage::disk('local')->putFileAs("private/reports/{$report->id}", $file, $name);
        $attachment->update(['disk' => 'local', 'path' => $path, 'mime' => $mime, 'size' => $file->getSize(), 'download_status' => 'STORED']);

        return $attachment;
    }

    public function referenceCode(Report $report): string
    {
        return sprintf('%s-%s-%06d', config('cohers.reference_prefix'), $report->submitted_at->format('Y'), $report->id);
    }

    public function transition(Report $report, ReportStatus $to, ?User $actor = null, ?string $reason = null, string $channel = 'system'): Report
    {
        $from = $report->status;
        if ($from === $to) {
            return $report;
        }

        $report->status = $to;
        if ($to === ReportStatus::CLOSED) {
            $report->closed_at = now();
        } elseif ($from === ReportStatus::CLOSED) {
            $report->closed_at = null;
        }
        $report->save();

        $this->history($report, $from, $to, $actor, $reason, $channel);

        return $report;
    }

    public function history(Report $report, ?ReportStatus $from, ReportStatus $to, ?User $actor, ?string $reason, string $channel = 'system'): void
    {
        ReportStatusHistory::create([
            'report_id' => $report->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'channel' => $channel,
            'created_at' => now(),
        ]);
    }

    /** Human correction of the AI/rule classification, with reason (section 06). */
    public function reclassify(Report $report, int $categoryId, string $priority, User $actor, string $reason): Report
    {
        $old = ['category_id' => $report->category_id, 'priority' => $report->priority?->value];
        $report->category_id = $categoryId;
        $report->priority = $priority;
        $report->triage_status = 'MANUAL';
        $report->save();

        $this->audit->record('report.reclassified', $report, $actor, $reason, $old,
            ['category_id' => $categoryId, 'priority' => $priority]);

        return $report;
    }
}
