<?php

namespace App\Support;

/**
 * Rand amounts held as whole cents. Shown the South African way (R 45 000, R 1 250,50); read from
 * what people type ("45000", "R45 000", "1 250,50", "1,250.50").
 */
final class Money
{
    public static function rand(int $cents, bool $signed = false): string
    {
        $sign = $cents < 0 ? '−' : ($signed && $cents > 0 ? '+' : '');
        $abs = abs($cents);
        $rands = number_format(intdiv($abs, 100), 0, ',', ' ');
        $rest = $abs % 100;

        return $sign.'R '.$rands.($rest ? ','.str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
    }

    /** Whole cents from typed text, or null when it is not an amount. */
    public static function parse(?string $text): ?int
    {
        $t = str_replace(["\u{2212}", ' ', "\u{00A0}", 'R', 'r'], ['-', '', '', '', ''], trim((string) $text));
        if ($t === '') {
            return null;
        }
        $comma = strrpos($t, ',');
        $dot = strrpos($t, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $t = str_replace($decimal === ',' ? '.' : ',', '', $t);
            $t = str_replace(',', '.', $t);
        } elseif ($comma !== false) {
            $t = strlen($t) - $comma - 1 <= 2 ? str_replace(',', '.', $t) : str_replace(',', '', $t);
        }
        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $t)) {
            return null;
        }

        return (int) round(((float) $t) * 100);
    }
}
