<?php

namespace App\Jobs;

use App\Enums\EscalationTrigger;
use App\Models\Report;
use App\Services\Escalation\EscalationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Opens and notifies a district escalation; EscalationService prevents duplicates. */
class EscalateReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reportId, public string $trigger, public string $reason) {}

    public function handle(EscalationService $escalations): void
    {
        if ($report = Report::find($this->reportId)) {
            $escalations->open($report, EscalationTrigger::from($this->trigger), $this->reason);
        }
    }
}
