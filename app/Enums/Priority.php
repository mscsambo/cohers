<?php

namespace App\Enums;

enum Priority: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    public function rank(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }

    public static function max(?self $a, ?self $b): ?self
    {
        if (! $a) {
            return $b;
        }
        if (! $b) {
            return $a;
        }

        return $a->rank() >= $b->rank() ? $a : $b;
    }

    /** Acknowledgement target in minutes: admin setting, else config (section 09). */
    public function ackMinutes(): int
    {
        return (int) \App\Models\SystemSetting::get_value('ack_targets.'.$this->value, 240);
    }

    public function color(): string
    {
        return match ($this) {
            self::LOW => 'slate',
            self::MEDIUM => 'sky',
            self::HIGH => 'orange',
            self::CRITICAL => 'rose',
        };
    }
}
