<?php

namespace App\Services\AI;

/**
 * Offline stand-in for Gemini so the pilot can be demonstrated without an API
 * key. Same output schema; low confidence pushes reports to manual review.
 */
class KeywordClassifier
{
    public const MODEL = 'keyword-heuristic-v1';

    private const RULES = [
        'ANIMAL_HEALTH' => [
            'subcategory' => 'Sick or dead animals',
            'words' => ['chicken', 'poultry', 'duck', 'pig', 'cow', 'cattle', 'buffalo', 'goat', 'dog', 'bird', 'livestock', 'animal',
                'មាន់', 'ទា', 'ជ្រូក', 'គោ', 'ក្របី', 'សត្វ', 'ឆ្កែ', 'បក្សី', 'ពពែ'],
        ],
        'HUMAN_HEALTH' => [
            'subcategory' => 'Illness cluster in people',
            'words' => ['fever', 'cough', 'diarrhea', 'diarrhoea', 'vomit', 'rash', 'people', 'children', 'patients', 'villagers', 'person', 'hospital',
                'គ្រុន', 'ក្អក', 'រាគ', 'ក្អួត', 'កន្ទួល', 'អ្នកជំងឺ', 'កុមារ', 'មនុស្ស', 'មន្ទីរពេទ្យ', 'ឈឺ'],
        ],
        'ENVIRONMENT' => [
            'subcategory' => 'Environmental hazard',
            'words' => ['water', 'river', 'pond', 'fish', 'smell', 'smoke', 'flood', 'chemical', 'pesticide', 'waste', 'mosquito', 'well',
                'ទឹក', 'ទន្លេ', 'ស្រះ', 'ត្រី', 'ក្លិន', 'ផ្សែង', 'ទឹកជំនន់', 'គីមី', 'ថ្នាំសម្លាប់សត្វល្អិត', 'សំរាម', 'មូស', 'អណ្ដូង'],
        ],
    ];

    private const URGENT = ['dead', 'died', 'death', 'many', 'sudden', 'hundreds', 'dozens', 'unconscious', 'bleeding', 'spreading',
        'ងាប់', 'ស្លាប់', 'ច្រើន', 'ភ្លាមៗ', 'សន្លប់', 'ហូរឈាម', 'រាលដាល'];

    private const HUMAN_SUBJECTS = ['people', 'children', 'child', 'villagers', 'person', 'patients', 'baby', 'student',
        'មនុស្ស', 'កុមារ', 'អ្នកភូមិ', 'អ្នកជំងឺ', 'ក្មេង', 'សិស្ស', 'នាក់'];

    private function mentionsAny(string $lower, array $words): bool
    {
        foreach ($words as $w) {
            if (mb_strpos($lower, $w) !== false) {
                return true;
            }
        }

        return false;
    }

    public function classify(string $text): array
    {
        $lower = mb_strtolower($text);
        $scores = [];
        foreach (self::RULES as $code => $rule) {
            $scores[$code] = 0;
            foreach ($rule['words'] as $w) {
                if (mb_strpos($lower, $w) !== false) {
                    $scores[$code]++;
                }
            }
        }
        // Symptoms like "fever" only point to human health when people are the subject,
        // so "pigs have fever" stays an animal-health report.
        if ($scores['ANIMAL_HEALTH'] > 0 && ! $this->mentionsAny($lower, self::HUMAN_SUBJECTS)) {
            $scores['HUMAN_HEALTH'] = 0;
        }
        arsort($scores);
        $top = array_key_first($scores);
        $topScore = $scores[$top];
        $second = array_values($scores)[1] ?? 0;

        $urgent = 0;
        foreach (self::URGENT as $w) {
            if (mb_strpos($lower, $w) !== false) {
                $urgent++;
            }
        }
        preg_match_all('/\d+/', $text, $m);
        $bigCount = collect($m[0])->contains(fn ($n) => (int) $n >= 20);

        if ($topScore === 0) {
            return ['category' => 'UNKNOWN', 'subcategory' => null, 'suggested_priority' => 'LOW', 'confidence' => 0.2, 'needs_manual_review' => true];
        }

        $priority = match (true) {
            $urgent >= 2 && $bigCount => 'CRITICAL',
            $urgent >= 1 && $bigCount, $urgent >= 2 => 'HIGH',
            $urgent >= 1 || $bigCount => 'MEDIUM',
            default => 'LOW',
        };
        $confidence = min(0.95, 0.5 + 0.15 * $topScore - 0.1 * $second);

        return [
            'category' => $top,
            'subcategory' => self::RULES[$top]['subcategory'],
            'suggested_priority' => $priority,
            'confidence' => round(max(0.25, $confidence), 2),
            'needs_manual_review' => $confidence < 0.55 || $topScore === $second,
        ];
    }
}
