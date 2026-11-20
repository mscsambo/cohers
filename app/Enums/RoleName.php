<?php

namespace App\Enums;

enum RoleName: string
{
    case REPORTER = 'reporter';
    case FOCAL_POINT = 'focal_point';
    case DISTRICT_OFFICER = 'district_officer';
    case SYSTEM_ADMIN = 'system_admin';

    public function label(): string
    {
        return match ($this) {
            self::REPORTER => 'Community Reporter',
            self::FOCAL_POINT => 'Local Focal Point',
            self::DISTRICT_OFFICER => 'District Officer',
            self::SYSTEM_ADMIN => 'System Administrator',
        };
    }
}
