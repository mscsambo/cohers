<?php

namespace App\Services\AI;

use App\Enums\Priority;
use App\Models\Report;
use App\Models\RoutingRule;

/**
 * Deterministic, admin-maintained safety rules. Applied on top of (or in
 * place of) AI output so priority never depends on the model alone.
 */
class TriageRuleEngine
{
    /** @return array{priority: ?Priority, escalate: bool, matched: string[]} */
    public function evaluate(Report $report, ?int $categoryId): array
    {
        $priority = null;
        $escalate = false;
        $matched = [];
        $text = mb_strtolower($report->original_text.' '.$report->affected_count);

        foreach (RoutingRule::where('is_active', true)->get() as $rule) {
            $hit = match ($rule->rule_type) {
                'keyword_priority' => $rule->pattern && $this->matchesAny($text, $rule->pattern),
                'category_priority' => $categoryId && $rule->category_id === $categoryId,
                default => false,
            };
            if ($hit) {
                $priority = Priority::max($priority, $rule->priority);
                $escalate = $escalate || $rule->escalate_district;
                $matched[] = $rule->name;
            }
        }

        return compact('priority', 'escalate', 'matched');
    }

    /** Pattern is a "|" separated keyword list. */
    private function matchesAny(string $text, string $pattern): bool
    {
        foreach (explode('|', mb_strtolower($pattern)) as $word) {
            $word = trim($word);
            if ($word !== '' && mb_strpos($text, $word) !== false) {
                return true;
            }
        }

        return false;
    }
}
