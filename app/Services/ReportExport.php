<?php

namespace App\Services;

use App\Models\Portfolio;
use App\Models\Team;
use App\Models\User;
use App\Support\Exports\PdfTable;
use App\Support\Exports\Xlsx;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * A report's rows as a file: CSV, Excel or PDF. The file carries what the page shows for the same
 * reader (the same rows, columns, order, totals and notes) and says above the table which filters
 * made it, for whom and when. CSV cells that a spreadsheet would read as a formula are written as
 * text; Excel cells are never formulas.
 */
final class ReportExport
{
    public const FORMATS = ['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'];

    /**
     * @param  array{filters: array<string, mixed>, columns: list<string>, sort: string|null, desc: bool}  $config
     * @param  array{rows: list<array<string, mixed>>, notes: list<string>, totals: array<string, mixed>|null, from: Carbon|null, to: Carbon|null, projects: int}  $result
     */
    public function download(string $format, string $type, ?string $name, array $config, array $result, Team $team, User $viewer): Response
    {
        $def = Reporting::REPORTS[$type];
        $title = $name ?? $def['label'];
        $cols = array_map(fn ($c) => ['key' => $c, 'label' => $def['columns'][$c][0], 'type' => $def['columns'][$c][1]], $config['columns']);
        $about = self::describe($type, $config['filters'], $result, $team);
        $stamp = 'Made for '.$viewer->name.', '.$team->name.', on '.Carbon::now()->format('j M Y \a\t H:i T').'. It shows only what '.$viewer->name.' may see.';
        $file = str($title)->slug().'-'.Carbon::today()->toDateString().'.'.$format;
        $rows = $result['rows'];

        if ($format === 'csv') {
            $out = fopen('php://temp', 'r+');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => Reporting::exportLabel($c['label'], $c['type']), $cols));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($c) => self::csvCell(Reporting::export($row[$c['key']] ?? null, $c['type'])), $cols));
            }
            if ($result['totals'] !== null) {
                fputcsv($out, array_map(fn ($c, $i) => $i === 0 ? 'Total' : self::csvCell(isset($result['totals'][$c['key']]) ? Reporting::export($result['totals'][$c['key']], $c['type']) : null), $cols, array_keys($cols)));
            }
            rewind($out);
            $body = (string) stream_get_contents($out);
            fclose($out);

            return response($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$file.'"']);
        }

        if ($format === 'xlsx') {
            $kind = fn (string $t) => match ($t) {
                'money' => 'money', 'hours', 'ratio' => 'decimal', 'date' => 'date', 'int', 'percent', 'days' => 'int', default => 'text',
            };
            $body = Xlsx::build(
                $def['label'],
                [$title, $about, ...$result['notes'], $stamp],
                array_map(fn ($c) => ['label' => Reporting::exportLabel($c['label'], $c['type']), 'kind' => $kind($c['type'])], $cols),
                array_map(fn ($row) => array_map(fn ($c) => Reporting::export($row[$c['key']] ?? null, $c['type']), $cols), $rows),
                $result['totals'] === null ? null : array_map(fn ($c, $i) => $i === 0 ? 'Total' : (isset($result['totals'][$c['key']]) ? Reporting::export($result['totals'][$c['key']], $c['type']) : null), $cols, array_keys($cols)),
            );

            return response($body, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$file.'"']);
        }

        $right = fn (string $t) => in_array($t, ['money', 'int', 'percent', 'ratio', 'hours', 'days'], true);
        $body = PdfTable::build(
            $title,
            [$about],
            array_map(fn ($c) => ['label' => $c['label'], 'right' => $right($c['type'])], $cols),
            array_map(fn ($row) => array_map(fn ($c) => Reporting::show($row[$c['key']] ?? null, $c['type']), $cols), $rows),
            $result['totals'] === null ? null : array_map(fn ($c, $i) => $i === 0 ? 'Total' : (isset($result['totals'][$c['key']]) ? Reporting::show($result['totals'][$c['key']], $c['type']) : ''), $cols, array_keys($cols)),
            $result['notes'],
            $stamp,
        );

        return response($body, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$file.'"']);
    }

    /**
     * Which projects and period the report covers, in a sentence.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{rows: list<array<string, mixed>>, from: Carbon|null, to: Carbon|null, projects: int}  $result
     */
    public static function describe(string $type, array $filters, array $result, Team $team): string
    {
        $def = Reporting::REPORTS[$type];
        if ($type === 'utilisation') {
            return 'People in '.$team->name.'; '.count($result['rows']).' '.str('row')->plural(count($result['rows'])).'.';
        }
        $parts = [Reporting::SCOPES[$filters['scope']] ?? 'Open projects'];
        if ($filters['portfolio'] !== '') {
            $parts[0] .= ' in '.(Portfolio::query()->where('team_id', $team->id)->whereKey((int) $filters['portfolio'])->value('name') ?? 'a portfolio');
        }
        if ($filters['projects'] !== []) {
            $parts[] = count($filters['projects']).' chosen';
        }
        $parts[] = $result['projects'].' '.str('project')->plural($result['projects']).' you can see';
        if ($def['period'] && $result['from'] && $result['to']) {
            $parts[] = 'from '.$result['from']->format('j M Y').' to '.$result['to']->format('j M Y');
        }

        return implode('; ', $parts).'. One row per '.$def['row'].'.';
    }

    private static function csvCell(string|int|float|null $v): string|int|float|null
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
