<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Routing\ReportRoutingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Rule-based officer matching and assignment. */
class RouteReportJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function __construct(public int $reportId, public array $levels = ['primary', 'backup']) {}

    public function uniqueId(): string
    {
        return (string) $this->reportId;
    }

    public function handle(ReportRoutingService $routing): void
    {
        $report = Report::find($this->reportId);
        if (! $report || ! $report->status->isOpen() || $report->activeAssignment()->exists()) {
            return;
        }

        $routing->route($report, $this->levels);
    }
}
