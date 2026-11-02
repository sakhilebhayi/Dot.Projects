<?php

namespace App\Support\Exports;

use Illuminate\Support\Carbon;

/**
 * A one-sheet Excel workbook (.xlsx), written directly: a few XML parts in a zip archive. No
 * library and no PHP zip extension, so it works on any host. Text is stored as inline strings,
 * never formulas, so a cell can never run as one. Numbers stay numbers (money in rand to two
 * places, hours to two places), dates are real dates, the header row is bold and frozen.
 */
final class Xlsx
{
    /** Cell styles, by index in styles.xml. */
    private const STYLE = ['text' => 0, 'head' => 1, 'money' => 2, 'date' => 3, 'decimal' => 4, 'int' => 5, 'title' => 6, 'total' => 7, 'totalmoney' => 8, 'totaldecimal' => 9];

    /**
     * @param  list<string>  $preface  lines above the table (title first)
     * @param  list<array{label: string, kind: string}>  $columns  kind: text, money, date, decimal or int
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<string|int|float|null>|null  $totals
     */
    public static function build(string $sheet, array $preface, array $columns, array $rows, ?array $totals = null): string
    {
        $xml = [];
        $r = 0;
        foreach ($preface as $i => $line) {
            $r++;
            $xml[] = '<row r="'.$r.'">'.self::str('A'.$r, $line, $i === 0 ? self::STYLE['title'] : 0).'</row>';
        }
        $r += $preface === [] ? 0 : 1;
        $head = ++$r;
        $xml[] = '<row r="'.$head.'">'.implode('', array_map(fn ($c, $i) => self::str(self::col($i).$head, $c['label'], self::STYLE['head']), $columns, array_keys($columns))).'</row>';
        foreach ($rows as $row) {
            $r++;
            $xml[] = '<row r="'.$r.'">'.self::cells($r, $columns, $row, false).'</row>';
        }
        if ($totals !== null) {
            $r++;
            $xml[] = '<row r="'.$r.'">'.self::cells($r, $columns, $totals, true).'</row>';
        }

        $widths = [];
        foreach ($columns as $i => $c) {
            $longest = mb_strlen($c['label']);
            foreach ($rows as $row) {
                $longest = max($longest, mb_strlen((string) ($row[$i] ?? '')));
            }
            $widths[] = '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.min(60, max(9, $longest + 2)).'" customWidth="1"/>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$head.'" topLeftCell="A'.($head + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.implode('', $widths).'</cols><sheetData>'.implode('', $xml).'</sheetData></worksheet>';

        return Zip::build([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.self::esc(self::sheetName($sheet)).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ]);
    }

    /**
     * @param  list<array{label: string, kind: string}>  $columns
     * @param  list<string|int|float|null>  $row
     */
    private static function cells(int $r, array $columns, array $row, bool $total): string
    {
        $out = '';
        foreach ($columns as $i => $c) {
            $v = $row[$i] ?? null;
            $ref = self::col($i).$r;
            if ($v === null || $v === '') {
                continue;
            }
            $kind = $c['kind'];
            if ($kind === 'date' && is_string($v)) {
                $serial = Carbon::parse($v)->startOfDay()->timestamp / 86400 + 25569;
                $out .= '<c r="'.$ref.'" s="'.self::STYLE['date'].'"><v>'.(int) $serial.'</v></c>';
            } elseif (in_array($kind, ['money', 'decimal', 'int'], true) && is_numeric($v)) {
                $style = $total ? ['money' => 'totalmoney', 'decimal' => 'totaldecimal', 'int' => 'total'][$kind] : $kind;
                $out .= '<c r="'.$ref.'" s="'.self::STYLE[$style].'"><v>'.$v.'</v></c>';
            } else {
                $out .= self::str($ref, (string) $v, $total ? self::STYLE['total'] : 0);
            }
        }

        return $out;
    }

    private static function str(string $ref, string $text, int $style): string
    {
        return '<c r="'.$ref.'" t="inlineStr"'.($style ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.self::esc($text).'</t></is></c>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="d mmm yyyy"/></numFmts>'
            .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="2"><border/><border><bottom style="thin"><color rgb="FF0D0F12"/></bottom></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="10">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="1" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            .'<xf numFmtId="2" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            .'</cellXfs></styleSheet>';
    }

    /** A, B, ... Z, AA, AB ... for a zero-based column index. */
    private static function col(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    /** Excel refuses sheet names over 31 characters or with []:*?/\ in them. */
    private static function sheetName(string $name): string
    {
        return mb_substr(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)) ?: 'Report', 0, 31);
    }

    /** XML text, without the control characters XML 1.0 cannot carry. */
    private static function esc(string $s): string
    {
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
