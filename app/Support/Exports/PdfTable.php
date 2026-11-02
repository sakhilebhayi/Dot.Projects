<?php

namespace App\Support\Exports;

/**
 * A report as a PDF: landscape A4, a title and the filters that made it, then the table, with
 * the header repeated on every page, a totals row, the report's notes and "Page n of N". Written
 * directly in PDF 1.4 with the standard Helvetica faces, so no library, font file or extension is
 * needed. Text is set in Windows-1252, which covers the business's languages in Latin script;
 * anything outside it prints as a question mark rather than breaking the file.
 */
final class PdfTable
{
    private const W = 842.0;

    private const H = 595.0;

    private const M = 36.0;

    /** Helvetica advance widths (per 1000 em) for characters 32 to 126. */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** Helvetica widths of the punctuation outside ASCII that reports use. */
    private const WIDE = ['…' => 1000, '–' => 556, '—' => 1000, '“' => 333, '”' => 333, '‘' => 222, '’' => 222];

    /**
     * @param  list<string>  $subtitle  lines under the title
     * @param  list<array{label: string, right: bool}>  $columns  right: a number, never shortened
     * @param  list<list<string>>  $rows  already formatted for reading
     * @param  list<string>|null  $totals
     * @param  list<string>  $notes
     */
    public static function build(string $title, array $subtitle, array $columns, array $rows, ?array $totals, array $notes, string $stamp): string
    {
        [$size, $groups] = self::layout($columns, $rows, $totals, self::W - 2 * self::M);
        $rh = $size * 1.875;
        $pages = [];
        $ops = '';
        $y = self::H - self::M;
        $cols = [];
        $widths = [];
        $newPage = function () use (&$ops, &$y, &$pages) {
            $pages[] = $ops;
            $ops = '';
            $y = self::H - self::M;
        };
        $header = function () use (&$ops, &$y, &$cols, &$widths, $size, $rh) {
            $ops .= self::rowOps($cols, array_column($cols, 'label'), $widths, $y, true, $size);
            $ops .= self::rule($y - 5, true);
            $y -= $rh + 2;
        };

        $ops .= self::text(self::M, $y - 14, $title, 16, true, '0.05 0.06 0.07');
        $y -= 30;
        if (count($groups) > 1) {
            $subtitle[] = 'The table is wider than the page, so it is printed in '.count($groups).' parts, each repeating the first column.';
        }
        foreach ($subtitle as $line) {
            $ops .= self::text(self::M, $y, self::fit($line, self::W - 2 * self::M, 9, false), 9, false, '0.37 0.39 0.42');
            $y -= 13;
        }
        $y -= 10;

        foreach ($groups as $g => [$idx, $groupWidths]) {
            $cols = array_map(fn ($i) => $columns[$i], $idx);
            $widths = $groupWidths;
            $pick = fn (array $cells) => array_map(fn ($i) => (string) ($cells[$i] ?? ''), $idx);
            if ($g > 0) {
                $newPage();
                $ops .= self::text(self::M, $y, $title.', part '.($g + 1).' of '.count($groups), 9, true, '0.37 0.39 0.42');
                $y -= 20;
            }
            $header();
            foreach ($rows as $row) {
                if ($y < self::M + 30) {
                    $newPage();
                    $header();
                }
                $ops .= self::rowOps($cols, $pick($row), $widths, $y, false, $size);
                $ops .= self::rule($y - 5, false);
                $y -= $rh;
            }
            if ($rows === []) {
                $ops .= self::text(self::M, $y, 'Nothing matches these filters.', $size, false, '0.37 0.39 0.42');
                $y -= $rh;
            }
            if ($totals !== null) {
                if ($y < self::M + 30) {
                    $newPage();
                    $header();
                }
                $ops .= self::rule($y + 9, true);
                $ops .= self::rowOps($cols, $pick($totals), $widths, $y, true, $size);
                $y -= $rh;
            }
        }
        $y -= 8;
        foreach ($notes as $note) {
            foreach (self::wrap($note, self::W - 2 * self::M, 8) as $line) {
                if ($y < self::M + 14) {
                    $newPage();
                }
                $ops .= self::text(self::M, $y, $line, 8, false, '0.37 0.39 0.42');
                $y -= 11;
            }
        }
        $pages[] = $ops;

        $count = count($pages);
        foreach ($pages as $n => &$page) {
            $page .= self::text(self::M, self::M - 16, $stamp, 7.5, false, '0.37 0.39 0.42');
            $label = 'Page '.($n + 1).' of '.$count;
            $page .= self::text(self::W - self::M - self::width($label, 7.5, false), self::M - 16, $label, 7.5, false, '0.37 0.39 0.42');
        }
        unset($page);

        return self::document($pages);
    }

    private static function rule(float $y, bool $strong): string
    {
        return sprintf("%s RG %s w %.2f %.2f m %.2f %.2f l S\n", $strong ? '0.05 0.06 0.07' : '0.89 0.9 0.92', $strong ? '0.8' : '0.4', self::M, $y, self::W - self::M, $y);
    }

    /**
     * The type size and the column groups. At 8pt, then 7pt, the table is fitted to the page by
     * shortening only text columns (never below 60pt); numbers always keep their full width. When
     * even that fails, the columns are split into parts, each repeating the first column.
     *
     * @param  list<array{label: string, right: bool}>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<string>|null  $totals
     * @return array{0: float, 1: list<array{0: list<int>, 1: list<float>}>}
     */
    private static function layout(array $columns, array $rows, ?array $totals, float $available): array
    {
        foreach ([8.0, 7.0] as $size) {
            $natural = self::natural($columns, $rows, $totals, $size);
            $fitted = array_sum($natural) <= $available ? $natural : self::squeeze($columns, $natural, $available);
            if ($fitted !== null) {
                return [$size, [[array_keys($columns), $fitted]]];
            }
        }
        $size = 7.0;
        $natural = array_map(fn ($w, $i) => $columns[$i]['right'] ? $w : min($w, 160.0), self::natural($columns, $rows, $totals, $size), array_keys($columns));
        $first = $natural[0];
        $groups = [];
        [$idx, $ws, $sum] = [[0], [$first], $first];
        for ($i = 1; $i < count($columns); $i++) {
            if ($sum + $natural[$i] > $available && count($idx) > 1) {
                $groups[] = [$idx, $ws];
                [$idx, $ws, $sum] = [[0], [$first], $first];
            }
            $idx[] = $i;
            $ws[] = $natural[$i];
            $sum += $natural[$i];
        }
        $groups[] = [$idx, $ws];

        return [$size, $groups];
    }

    /**
     * @param  list<array{label: string, right: bool}>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<string>|null  $totals
     * @return list<float> each column's natural width: header and widest cell, plus padding
     */
    private static function natural(array $columns, array $rows, ?array $totals, float $size): array
    {
        $natural = [];
        foreach ($columns as $i => $c) {
            $w = self::width($c['label'], $size, true);
            foreach (array_slice($rows, 0, 400) as $row) {
                $w = max($w, self::width((string) ($row[$i] ?? ''), $size, false));
            }
            if ($totals !== null) {
                $w = max($w, self::width((string) ($totals[$i] ?? ''), $size, true));
            }
            $natural[] = min($w, 220.0) + 12;
        }

        return $natural;
    }

    /**
     * Narrows the widest text columns to one cap so the table fits; null when the cap would fall
     * below 60pt.
     *
     * @param  list<array{label: string, right: bool}>  $columns
     * @param  list<float>  $natural
     * @return list<float>|null
     */
    private static function squeeze(array $columns, array $natural, float $available): ?array
    {
        $text = array_keys(array_filter($columns, fn ($c) => ! $c['right']));
        $room = $available - array_sum(array_diff_key($natural, array_flip($text)));
        $wants = array_map(fn ($i) => $natural[$i], $text);
        sort($wants);
        $cap = null;
        foreach ($wants as $n => $w) {
            $share = $room / (count($wants) - $n);
            if ($w > $share) {
                $cap = $share;
                break;
            }
            $room -= $w;
        }
        if ($cap === null) {
            return $natural;
        }

        return $cap < 60 ? null : array_map(fn ($w, $i) => in_array($i, $text, true) ? min($w, $cap) : $w, $natural, array_keys($natural));
    }

    /**
     * @param  list<array{label: string, right: bool}>  $columns
     * @param  list<string>  $cells
     * @param  list<float>  $widths
     */
    private static function rowOps(array $columns, array $cells, array $widths, float $y, bool $bold, float $size): string
    {
        $ops = '';
        $x = self::M;
        foreach ($columns as $i => $c) {
            $room = $widths[$i] - 12;
            $text = $c['right'] ? (string) ($cells[$i] ?? '') : self::fit((string) ($cells[$i] ?? ''), $room, $size, $bold);
            $at = $c['right'] ? $x + $widths[$i] - 12 - self::width($text, $size, $bold) : $x;
            $ops .= self::text($at, $y, $text, $size, $bold, '0.05 0.06 0.07');
            $x += $widths[$i];
        }

        return $ops;
    }

    private static function text(float $x, float $y, string $s, float $size, bool $bold, string $rgb): string
    {
        return sprintf("BT %s rg /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $rgb, $bold ? 'F2' : 'F1', $size, $x, $y, self::escape(self::encode($s)));
    }

    /** Text shortened with an ellipsis so it fits $room points. */
    private static function fit(string $s, float $room, float $size, bool $bold): string
    {
        if (self::width($s, $size, $bold) <= $room) {
            return $s;
        }
        while ($s !== '' && self::width($s.'…', $size, $bold) > $room) {
            $s = mb_substr($s, 0, -1);
        }

        return rtrim($s).'…';
    }

    /** @return list<string> lines of at most $room points */
    private static function wrap(string $s, float $room, float $size): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && self::width($try, $size, false) > $room) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }

        return $line === '' ? $lines : [...$lines, $line];
    }

    /** Width in points; bold is set a tenth wider, which keeps the estimate on the safe side. */
    public static function width(string $s, float $size, bool $bold): float
    {
        $units = 0;
        foreach (mb_str_split($s) as $ch) {
            $o = mb_ord($ch);
            $units += ($o >= 32 && $o <= 126) ? self::WIDTHS[$o - 32] : (self::WIDE[$ch] ?? 556);
        }

        return $units * $size / 1000 * ($bold ? 1.1 : 1.0);
    }

    private static function encode(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }

    /** @param  list<string>  $pages  content streams */
    private static function document(array $pages): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $next = 5;
        foreach ($pages as $content) {
            $pageId = $next++;
            $streamId = $next++;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', (int) self::W, (int) self::H, $streamId);
            $objects[$streamId] = '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
