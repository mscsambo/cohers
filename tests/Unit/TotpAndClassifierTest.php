<?php

namespace Tests\Unit;

use App\Enums\Priority;
use App\Services\AI\KeywordClassifier;
use App\Services\AI\TriageResult;
use App\Services\Security\TotpService;
use PHPUnit\Framework\TestCase;

class TotpAndClassifierTest extends TestCase
{
    public function test_totp_matches_rfc6238_vector(): void
    {
        // RFC 6238 SHA1 secret "12345678901234567890" at T=59s → 94287082 (last 6 digits 287082)
        $totp = new TotpService;
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', $totp->code($secret, intdiv(59, 30)));
        $this->assertTrue($totp->verify($secret, $totp->code($secret)));
        $this->assertFalse($totp->verify($secret, '000000') && $totp->code($secret) !== '000000');
    }

    public function test_schema_validation_rejects_out_of_vocabulary(): void
    {
        $allowed = ['ANIMAL_HEALTH', 'HUMAN_HEALTH', 'ENVIRONMENT', 'UNKNOWN'];
        $ok = TriageResult::fromArray(['category' => 'ANIMAL_HEALTH', 'suggested_priority' => 'HIGH', 'confidence' => 0.9, 'needs_manual_review' => false], $allowed);
        $this->assertSame(Priority::HIGH, $ok->suggestedPriority);

        $this->assertNull(TriageResult::fromArray(['category' => 'ALIENS', 'suggested_priority' => 'HIGH', 'confidence' => 0.9, 'needs_manual_review' => false], $allowed));
        $this->assertNull(TriageResult::fromArray(['category' => 'ANIMAL_HEALTH', 'suggested_priority' => 'URGENT', 'confidence' => 0.9, 'needs_manual_review' => false], $allowed));
        $this->assertNull(TriageResult::fromArray(['category' => 'ANIMAL_HEALTH', 'suggested_priority' => 'HIGH', 'confidence' => 1.5, 'needs_manual_review' => false], $allowed));
        $this->assertNull(TriageResult::fromArray(['category' => 'ANIMAL_HEALTH', 'suggested_priority' => 'HIGH', 'confidence' => 0.5], $allowed));
    }

    public function test_keyword_classifier_handles_khmer(): void
    {
        $out = (new KeywordClassifier)->classify('មាន់ ៣០ ក្បាលងាប់ភ្លាមៗ');
        $this->assertSame('ANIMAL_HEALTH', $out['category']);
        $this->assertSame('UNKNOWN', (new KeywordClassifier)->classify('hello there')['category']);
    }
}
