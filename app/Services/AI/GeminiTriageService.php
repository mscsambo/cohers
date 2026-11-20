<?php

namespace App\Services\AI;

use App\Models\AiTriage;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends minimized report text to Gemini, validates the structured response
 * and stores it as an advisory record. Drivers: gemini | keyword | none.
 */
class GeminiTriageService
{
    public function __construct(private KeywordClassifier $keyword) {}

    public function driver(): string
    {
        return SystemSetting::get_value('ai.driver', config('cohers.ai.driver'));
    }

    /** Admin "test classifier": runs the current driver on sample text without storing anything. */
    public function preview(string $text, ?string $driver = null): array
    {
        $allowed = ReportCategory::where('is_active', true)->pluck('code')->push('UNKNOWN')->unique()->values()->all();
        $started = microtime(true);
        try {
            [$raw, $model] = match ($driver ?? $this->driver()) {
                'gemini' => $this->callGemini($text, $allowed),
                'keyword' => [json_encode($this->keyword->classify($text)), KeywordClassifier::MODEL],
                default => throw new \RuntimeException('AI triage disabled.'),
            };
            $decoded = json_decode($this->stripFences($raw), true);
            $result = is_array($decoded) ? TriageResult::fromArray($decoded, $allowed) : null;

            return ['model' => $model, 'raw' => $raw, 'valid' => (bool) $result, 'result' => $result?->toArray(), 'latency_ms' => $this->ms($started)];
        } catch (Throwable $e) {
            return ['model' => null, 'raw' => null, 'valid' => false, 'error' => mb_substr($e->getMessage(), 0, 500), 'latency_ms' => $this->ms($started)];
        }
    }

    /** Models available to the configured key that support generateContent. */
    public function availableModels(): array
    {
        $key = config('cohers.ai.gemini_api_key');
        if (! $key) {
            throw new \RuntimeException('Save a Gemini API key first.');
        }
        $res = Http::timeout(15)->withHeaders(['x-goog-api-key' => $key])
            ->get(config('cohers.ai.gemini_endpoint').'/models', ['pageSize' => 200]);
        $res->throw();

        return collect($res->json('models', []))
            ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
            ->map(fn ($m) => ['id' => str_replace('models/', '', $m['name']), 'label' => $m['displayName'] ?? $m['name']])
            ->sortBy('id')->values()->all();
    }

    /** Returns the stored triage row; outcome tells whether it is usable. */
    public function classify(Report $report): AiTriage
    {
        $input = $this->minimize($report);
        $driver = $this->driver();
        $started = microtime(true);
        $allowed = ReportCategory::where('is_active', true)->pluck('code')->push('UNKNOWN')->unique()->values()->all();

        $row = [
            'report_id' => $report->id,
            'provider' => $driver,
            'input_text' => $input,
        ];

        try {
            [$raw, $model] = match ($driver) {
                'gemini' => $this->callGemini($input, $allowed),
                'keyword' => [json_encode($this->keyword->classify($input)), KeywordClassifier::MODEL],
                default => throw new \RuntimeException('AI triage disabled; manual review required.'),
            };

            $row['model'] = $model;
            $row['raw_response'] = $raw;
            $decoded = json_decode($this->stripFences($raw), true);
            $result = is_array($decoded) ? TriageResult::fromArray($decoded, $allowed) : null;

            if (! $result) {
                return AiTriage::create($row + [
                    'outcome' => 'invalid',
                    'error' => 'Response failed schema validation.',
                    'latency_ms' => $this->ms($started),
                ]);
            }

            return AiTriage::create($row + [
                'category_code' => $result->category,
                'subcategory' => $result->subcategory,
                'suggested_priority' => $result->suggestedPriority->value,
                'confidence' => $result->confidence,
                'needs_manual_review' => $result->needsManualReview,
                'outcome' => 'success',
                'latency_ms' => $this->ms($started),
            ]);
        } catch (Throwable $e) {
            return AiTriage::create($row + [
                'model' => $row['model'] ?? config('cohers.ai.gemini_model'),
                'outcome' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'latency_ms' => $this->ms($started),
            ]);
        }
    }

    /**
     * Only the minimum text needed: description, count and timing. No names,
     * phone numbers or Telegram identifiers (section 13).
     */
    public function minimize(Report $report): string
    {
        $text = $report->original_text;
        $text = preg_replace('/\+?\d[\d\s\-]{7,}\d/u', '[number]', $text);   // phone-like numbers
        $text = preg_replace('/@\w{3,}/u', '[handle]', $text);
        $text = preg_replace('/\S+@\S+\.\S+/u', '[email]', $text);

        $parts = ['Description: '.$text];
        if ($report->affected_count) {
            $parts[] = 'Affected: '.$report->affected_count;
        }
        if ($report->observed_text) {
            $parts[] = 'Observed: '.$report->observed_text;
        }

        return implode("\n", $parts);
    }

    private function callGemini(string $input, array $allowed): array
    {
        $key = config('cohers.ai.gemini_api_key');
        if (! $key) {
            throw new \RuntimeException('GEMINI_API_KEY is not configured.');
        }
        $model = config('cohers.ai.gemini_model');

        $prompt = "You are an advisory triage assistant for a One Health community event-reporting system in Cambodia. "
            ."Reports may be in Khmer or English. Classify the report into exactly one category code from: "
            .implode(', ', $allowed).". Suggest a priority (LOW, MEDIUM, HIGH, CRITICAL) based on potential public-health "
            ."risk, number affected and speed of spread. Set needs_manual_review=true when the report is ambiguous, "
            ."off-topic, or you are unsure. Do not diagnose. Respond with JSON only.\n\nREPORT:\n".$input;

        $response = Http::timeout(config('cohers.ai.timeout_seconds'))
            ->withHeaders(['x-goog-api-key' => $key])
            ->post(config('cohers.ai.gemini_endpoint')."/models/{$model}:generateContent", [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'category' => ['type' => 'STRING', 'enum' => $allowed],
                            'subcategory' => ['type' => 'STRING'],
                            'suggested_priority' => ['type' => 'STRING', 'enum' => ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']],
                            'confidence' => ['type' => 'NUMBER'],
                            'needs_manual_review' => ['type' => 'BOOLEAN'],
                        ],
                        'required' => ['category', 'suggested_priority', 'confidence', 'needs_manual_review'],
                    ],
                ],
            ]);

        $response->throw();
        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text)) {
            throw new \RuntimeException('Gemini returned no content.');
        }

        return [$text, data_get($response->json(), 'modelVersion', $model)];
    }

    private function stripFences(string $raw): string
    {
        return trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)));
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
