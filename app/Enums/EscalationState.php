<?php

namespace App\Enums;

enum EscalationState: string
{
    case NONE = 'NONE';
    case OPEN = 'OPEN';
    case IN_REVIEW = 'IN_REVIEW';
    case RESOLVED = 'RESOLVED';

    public function color(): string
    {
        return match ($this) {
            self::NONE => 'zinc',
            self::OPEN => 'rose',
            self::IN_REVIEW => 'amber',
            self::RESOLVED => 'emerald',
        };
    }
}
