<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Display helpers. Storage is UTC; display is Asia/Phnom_Penh (section 11). */
class Fmt
{
    public static function dt(?CarbonInterface $at, string $format = 'd M Y H:i'): string
    {
        return $at ? $at->copy()->setTimezone(config('cohers.display_timezone'))->format($format) : '—';
    }

    public static function minutes(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 60) {
            return round($minutes).'m';
        }
        if ($minutes < 60 * 48) {
            return round($minutes / 60, 1).'h';
        }

        return round($minutes / 1440, 1).'d';
    }

    public static function h(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
