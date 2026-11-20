<?php

namespace App\Enums;

enum OfficerType: string
{
    case VHSG = 'VHSG';
    case CAO = 'CAO';

    public function label(): string
    {
        return match ($this) {
            self::VHSG => 'Village Health Support Group',
            self::CAO => 'Commune Agriculture Officer',
        };
    }
}
