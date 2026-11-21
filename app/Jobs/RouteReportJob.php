<?php

namespace App\Jobs;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Services\Routing\ReportRoutingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Rule-based officer matching and assignment. */
class RouteReportJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public bool $manual = false;

    public function __construct(public int $reportId, public array $levels = ['primary', 'backup'], bool $manual = false)
    {
        $this->manual = $manual;
    }

    public function uniqueId(): string
    {
        return (string) $this->reportId;
    }

    public function handle(ReportRoutingService $routing): void
    {
        DB::transaction(function () use ($routing) {
            $report = Report::lockForUpdate()->find($this->reportId);
            if (! $report || ! in_array($report->status, [ReportStatus::SUBMITTED, ReportStatus::TRIAGE_REVIEW], true)
                || $report->triage_status !== ($this->manual ? 'MANUAL' : 'DONE')
                || ! $report->category_id || $report->activeAssignment()->exists()) {
                return;
            }

            $routing->route($report, $this->levels);
        }, 3);
    }
}
