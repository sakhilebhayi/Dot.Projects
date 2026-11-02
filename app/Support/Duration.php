<?php

namespace App\Support;

/**
 * Durations as whole minutes, typed the ways people write them ("1.5", "1:30", "90m", "1h 30m",
 * "2h") and shown as hours and minutes ("1h 30m").
 */
final class Duration
{
    public static function parse(?string $text): ?int
    {
        $t = strtolower(str_replace(' ', '', trim((string) $text)));
        $t = str_replace(',', '.', $t);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $t, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (preg_match('/^(?:(\d+(?:\.\d+)?)h)?(?:(\d+)m(?:in)?)?$/', $t, $m) && ($m[1] ?? '').($m[2] ?? '') !== '') {
            return (int) round(((float) ($m[1] ?? 0)) * 60) + (int) ($m[2] ?? 0);
        }
        if (preg_match('/^\d+(\.\d+)?$/', $t)) {
            return (int) round((float) $t * 60);
        }

        return null;
    }

    public static function format(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return match (true) {
            $h === 0 => $m.'m',
            $m === 0 => $h.'h',
            default => $h.'h '.$m.'m',
        };
    }
}
