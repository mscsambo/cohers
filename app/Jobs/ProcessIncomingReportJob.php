<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Reporting\ReportDuplicateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Post-submission pipeline entry: duplicate scoring, then AI triage. */
class ProcessIncomingReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $reportId) {}

    public function handle(ReportDuplicateService $duplicates): void
    {
        $report = Report::find($this->reportId);
        if (! $report) {
            return;
        }

        $duplicates->flag($report);
        AnalyzeReportWithGeminiJob::dispatch($report->id);
    }
}
