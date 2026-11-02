<?php

namespace App\Http\Controllers;

use App\Models\SavedReport;
use App\Models\Team;
use App\Models\User;
use App\Services\ReportExport;
use App\Services\Reporting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report files: a report as set on the Reports page, or a saved one, as CSV, Excel or PDF. Each
 * is worked out for the person downloading it, so a shared report never hands over more than
 * that person may see.
 */
class ReportController extends Controller
{
    public function export(Request $request, string $format, Reporting $reporting, ReportExport $export): Response
    {
        abort_unless(isset(ReportExport::FORMATS[$format]), 404);
        $type = (string) $request->query('type');
        try {
            $config = Reporting::normalise($type, [
                'filters' => (array) $request->query('filters', []),
                'columns' => (array) $request->query('columns', []) ?: null,
                'sort' => $request->query('sort'),
                'desc' => $request->boolean('desc'),
            ]);
        } catch (ValidationException) {
            abort(404);
        }
        [$team, $me] = $this->who($request);

        return $export->download($format, $type, null, $config, $reporting->run($type, $team, $me, $config), $team, $me);
    }

    public function saved(Request $request, SavedReport $saved, string $format, Reporting $reporting, ReportExport $export): Response
    {
        abort_unless(isset(ReportExport::FORMATS[$format]), 404);
        [$team, $me] = $this->who($request);
        abort_unless($saved->team_id === $team->id && $saved->readableBy($me), 404);
        $config = Reporting::normalise($saved->type, $saved->config);

        return $export->download($format, $saved->type, $saved->name, $config, $reporting->run($saved->type, $team, $me, $config), $team, $me);
    }

    /** @return array{0: Team, 1: User} */
    private function who(Request $request): array
    {
        /** @var User $me */
        $me = $request->user();
        /** @var Team|null $team */
        $team = $me->currentTeam;
        abort_if($team === null, 404);

        return [$team, $me];
    }
}
