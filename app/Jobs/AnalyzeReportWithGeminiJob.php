<?php

namespace App\Jobs;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Services\AI\ReportTriageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asynchronous advisory triage. On AI outage the report stays safely in
 * TRIAGE_REVIEW and triage is retried with backoff; a human may classify it
 * in the meantime, which stops further retries.
 */
class AnalyzeReportWithGeminiJob implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    public function __construct(public int $reportId, public int $attempt = 1) {}

    public function handle(ReportTriageService $triage): void
    {
        $report = Report::find($this->reportId);
        if (! $report
            || ! in_array($report->status, [ReportStatus::SUBMITTED, ReportStatus::TRIAGE_REVIEW], true)
            || in_array($report->triage_status, ['DONE', 'MANUAL'], true)) {
            return;
        }

        if ($triage->run($report)) {
            RouteReportJob::dispatch($report->id);

            return;
        }

        if ($report->fresh()->triage_status === 'FAILED' && $this->attempt < self::MAX_ATTEMPTS) {
            self::dispatch($report->id, $this->attempt + 1)->delay(now()->addMinutes(2 * $this->attempt));
        }
    }
}
