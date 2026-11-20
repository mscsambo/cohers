<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case PENDING = 'PENDING';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case VERIFYING = 'VERIFYING';
    case COMPLETED = 'COMPLETED';
    case REASSIGNED = 'REASSIGNED';
    case CANCELLED = 'CANCELLED';

    public static function active(): array
    {
        return [self::PENDING->value, self::ACKNOWLEDGED->value, self::VERIFYING->value];
    }
}
