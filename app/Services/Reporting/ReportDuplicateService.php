<?php

namespace App\Services\Reporting;

use App\Models\IncidentCase;
use App\Models\Report;
use App\Models\User;
use App\Services\AuditService;

/**
 * Scores likely duplicates by area, date window, category and text similarity.
 * It only flags; linking requires an authorized human (section 06). Nothing
 * is ever deleted or auto-rejected.
 */
class ReportDuplicateService
{
    public function __construct(private AuditService $audit) {}

    public function flag(Report $report): ?Report
    {
        $days = (int) config('cohers.duplicates.window_days');
        $from = $report->submitted_at->copy()->subDays($days);

        $candidates = Report::query()
            ->where('id', '!=', $report->id)
            ->where('commune_id', $report->commune_id)
            ->whereBetween('submitted_at', [$from, $report->submitted_at])
            ->when($report->category_id, fn ($q) => $q->where(fn ($w) => $w
                ->where('category_id', $report->category_id)->orWhereNull('category_id')))
            ->latest('submitted_at')->limit(50)->get();

        $best = null;
        $bestScore = 0.0;
        foreach ($candidates as $c) {
            $score = $this->similarity($report->original_text, $c->original_text);
            if ($c->geographic_unit_id === $report->geographic_unit_id) {
                $score += 0.15; // same village
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$c, $score];
            }
        }

        if ($best && $bestScore >= (float) config('cohers.duplicates.min_score')) {
            $report->update([
                'possible_duplicate_of_id' => $best->id,
                'duplicate_score' => min(1, round($bestScore, 3)),
            ]);

            return $best;
        }

        return null;
    }

    /** Token Jaccard similarity; works for Khmer (character bigrams) and English (words). */
    public function similarity(string $a, string $b): float
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);
        if (! $ta || ! $tb) {
            return 0.0;
        }

        return count(array_intersect($ta, $tb)) / count(array_unique(array_merge($ta, $tb)));
    }

    private function tokens(string $text): array
    {
        $text = mb_strtolower($text);
        if (preg_match('/[\x{1780}-\x{17FF}]/u', $text)) {
            $chars = preg_split('//u', preg_replace('/\s+/u', '', $text), -1, PREG_SPLIT_NO_EMPTY);
            $grams = [];
            for ($i = 0; $i < count($chars) - 1; $i++) {
                $grams[] = $chars[$i].$chars[$i + 1];
            }

            return array_values(array_unique($grams));
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) > 2)));
    }

    /** Authorized linking of two reports under one incident; both originals preserved. */
    public function link(Report $report, Report $other, User $actor, ?string $reason = null, string $channel = 'web'): IncidentCase
    {
        $incident = $other->incidents()->first() ?? $report->incidents()->first();

        if (! $incident) {
            $incident = IncidentCase::create([
                'code' => 'TMP-'.uniqid(),
                'title' => ($other->category?->name_en ?? 'Incident').' — '.($other->location?->name_en ?? ''),
                'category_id' => $other->category_id ?? $report->category_id,
                'district_id' => $other->district_id,
                'created_by' => $actor->id,
            ]);
            $incident->update(['code' => sprintf('INC-%s-%05d', now()->format('Y'), $incident->id)]);
        }

        foreach ([$report, $other] as $r) {
            $incident->reports()->syncWithoutDetaching([$r->id => ['linked_by' => $actor->id, 'reason' => $reason]]);
        }

        $this->audit->record('report.linked_incident', $report, $actor, $reason, null,
            ['incident' => $incident->code, 'other_report' => $other->reference_code], $channel);

        return $incident;
    }
}
