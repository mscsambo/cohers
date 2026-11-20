<?php

namespace App\Enums;

enum Sector: string
{
    case HUMAN = 'HUMAN';
    case ANIMAL = 'ANIMAL';
    case ENVIRONMENT = 'ENVIRONMENT';
    case UNKNOWN = 'UNKNOWN';

    /** Which focal-point subtype handles this sector (section 06). */
    public function officerType(): ?OfficerType
    {
        return match ($this) {
            self::HUMAN => OfficerType::VHSG,
            self::ANIMAL, self::ENVIRONMENT => OfficerType::CAO,
            self::UNKNOWN => null,
        };
    }
}
