<?php

namespace App\Services\AI;

use App\Enums\Priority;

/** Validated advisory output (section 06 schema). */
final class TriageResult
{
    public function __construct(
        public readonly string $category,
        public readonly ?string $subcategory,
        public readonly Priority $suggestedPriority,
        public readonly float $confidence,
        public readonly bool $needsManualReview,
    ) {}

    /**
     * Strictly validate a decoded model response. Returns null for anything
     * that does not match the controlled vocabulary.
     */
    public static function fromArray(array $data, array $allowedCategories): ?self
    {
        $category = strtoupper((string) ($data['category'] ?? ''));
        $priority = Priority::tryFrom(strtoupper((string) ($data['suggested_priority'] ?? '')));
        $confidence = $data['confidence'] ?? null;

        if (! in_array($category, $allowedCategories, true) || ! $priority
            || ! is_numeric($confidence) || $confidence < 0 || $confidence > 1
            || ! array_key_exists('needs_manual_review', $data) || ! is_bool($data['needs_manual_review'])) {
            return null;
        }

        $sub = isset($data['subcategory']) && is_string($data['subcategory'])
            ? mb_substr(trim($data['subcategory']), 0, 120) : null;

        return new self($category, $sub ?: null, $priority, (float) $confidence, $data['needs_manual_review']);
    }

    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'suggested_priority' => $this->suggestedPriority->value,
            'confidence' => $this->confidence,
            'needs_manual_review' => $this->needsManualReview,
        ];
    }
}
