<?php

namespace App\Enums;

enum ReportStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case TRIAGE_REVIEW = 'TRIAGE_REVIEW';
    case ASSIGNED = 'ASSIGNED';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case VERIFYING = 'VERIFYING';
    case NEEDS_INFO = 'NEEDS_INFO';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';
    case DUPLICATE = 'DUPLICATE';
    case CLOSED = 'CLOSED';

    /** Human-readable wording safe to show reporters (section 05). */
    public function reporterKey(): string
    {
        return match ($this) {
            self::SUBMITTED => 'received',
            self::TRIAGE_REVIEW => 'being_reviewed',
            self::ASSIGNED, self::ACKNOWLEDGED => 'assigned',
            self::VERIFYING => 'under_verification',
            self::NEEDS_INFO => 'more_info_needed',
            self::VERIFIED, self::REJECTED, self::DUPLICATE => 'reviewed',
            self::CLOSED => 'closed',
        };
    }

    public function label(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->value)));
    }

    public function color(): string
    {
        return match ($this) {
            self::SUBMITTED => 'slate',
            self::TRIAGE_REVIEW => 'amber',
            self::ASSIGNED => 'sky',
            self::ACKNOWLEDGED => 'indigo',
            self::VERIFYING => 'violet',
            self::NEEDS_INFO => 'orange',
            self::VERIFIED => 'emerald',
            self::REJECTED => 'rose',
            self::DUPLICATE => 'stone',
            self::CLOSED => 'zinc',
        };
    }

    /** Outcomes after which a district officer may approve closure. */
    public function isClosable(): bool
    {
        return in_array($this, [self::VERIFIED, self::REJECTED, self::DUPLICATE], true);
    }

    public function isOpen(): bool
    {
        return $this !== self::CLOSED;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
