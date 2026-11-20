<?php

namespace App\Services\AI;

use App\Enums\EscalationTrigger;
use App\Enums\Priority;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\SystemSetting;
use App\Services\Escalation\EscalationService;
use App\Services\Reporting\ReportService;

/**
 * Combines advisory AI output with deterministic rules (section 02 step 4).
 * Uncertain or failed classifications go to manual review; CRITICAL opens a
 * district escalation in parallel with focal-point routing.
 *
 * @return bool true when the report is ready for automatic routing
 */
class ReportTriageService
{
    public function __construct(
        private GeminiTriageService $ai,
        private TriageRuleEngine $rules,
        private ReportService $reports,
        private EscalationService $escalations,
    ) {}

    public function run(Report $report): bool
    {
        $triage = $this->ai->classify($report);
        $minConfidence = (float) SystemSetting::get_value('ai.min_confidence', config('cohers.ai.min_confidence'));

        $category = $triage->outcome === 'success' && $triage->category_code !== 'UNKNOWN'
            ? ReportCategory::where('code', $triage->category_code)->where('is_active', true)->first()
            : null;

        $rule = $this->rules->evaluate($report, $category?->id);
        $aiPriority = $triage->outcome === 'success' ? Priority::tryFrom((string) $triage->suggested_priority) : null;
        // Final priority: the stricter of rule and AI suggestion, else the category default.
        $priority = Priority::max($rule['priority'], $aiPriority) ?? $category?->default_priority;

        $report->fill([
            'category_id' => $category?->id ?? $report->category_id,
            'subcategory' => $triage->subcategory ?? $report->subcategory,
            'priority' => $priority ?? $report->priority,
            'triage_status' => $triage->outcome === 'success' ? 'DONE' : 'FAILED',
        ])->save();

        $uncertain = $triage->outcome !== 'success'
            || ! $category
            || $triage->needs_manual_review
            || ($triage->confidence ?? 0) < $minConfidence;

        if ($priority === Priority::CRITICAL || $rule['escalate']) {
            $why = $rule['matched'] ? 'Rule: '.implode(', ', $rule['matched']) : 'CRITICAL priority';
            $this->escalations->open($report, EscalationTrigger::CRITICAL, $why.'. AI output is advisory and unverified.');
        }

        if ($uncertain) {
            $reason = match (true) {
                $triage->outcome === 'failed' => 'AI triage unavailable: pending manual triage ('.$triage->error.')',
                $triage->outcome === 'invalid' => 'AI response failed validation: manual triage',
                default => 'Low-confidence or ambiguous classification: manual triage',
            };
            $this->reports->transition($report, ReportStatus::TRIAGE_REVIEW, null, $reason);

            if ($triage->outcome !== 'success' && $rule['priority'] && $rule['priority']->rank() >= Priority::HIGH->rank()) {
                $this->escalations->open($report, EscalationTrigger::TRIAGE, 'Urgent rule-flagged report awaiting manual triage.');
            }

            return false;
        }

        return true;
    }
}
