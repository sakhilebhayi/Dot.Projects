<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectTask;
use App\Models\SavedReport;
use App\Models\Team;
use App\Models\User;

/**
 * What the command palette finds (R-34 P13b, brief §17): projects, tasks, documents, reports and pages, only among
 * what the person can already open. Projects come through the team and private-project scopes; tasks and
 * documents only from those projects; saved reports only the person's own and the business's shared ones.
 */
final class CommandSearch
{
    private const LIMITS = ['projects' => 5, 'tasks' => 6, 'documents' => 4, 'reports' => 4, 'pages' => 6];

    /**
     * @return array<string, list<array{label: string, sub: ?string, url: string, icon: string}>> group => items
     */
    public function search(User $me, string $q): array
    {
        $q = trim(mb_substr($q, 0, 80));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
        $match = fn ($query, string $column) => $q === '' ? $query : $query->whereRaw('lower('.$column.") like ? escape '\\'", [$like]);
        /** @var Team|null $team */
        $team = $me->currentTeam;
        if ($team === null) {
            return [];
        }

        $visible = Project::query()->pluck('id');
        $projects = $match(Project::query(), 'name')->orderByRaw('archived_at is not null')->orderByDesc('updated_at')->limit(self::LIMITS['projects'])->get(['id', 'name', 'status', 'archived_at']);
        $out = [
            'Projects' => $projects->map(fn (Project $p) => ['label' => $p->name, 'sub' => $p->archived_at ? 'Archived' : null, 'url' => route('projects.show', $p), 'icon' => 'folder_open'])->all(),
        ];

        if ($q !== '') {
            $tasks = $match(ProjectTask::query()->whereIn('project_id', $visible), 'title')->with('project:id,name')->orderByRaw("status = 'done'")->orderByDesc('updated_at')
                ->limit(self::LIMITS['tasks'])->get(['id', 'project_id', 'title', 'status']);
            $out['Tasks'] = $tasks->map(fn (ProjectTask $t) => ['label' => $t->title, 'sub' => $t->project->name.($t->status === 'done' ? ', done' : ''), 'url' => route('projects.tasks.show', [$t->project_id, $t->id]), 'icon' => $t->status === 'done' ? 'check_circle' : 'radio_button_unchecked'])->all();

            $docs = $match(ProjectDocument::query()->whereIn('project_id', $visible), 'name')->with('project:id,name')->orderByDesc('updated_at')->limit(self::LIMITS['documents'])->get(['id', 'project_id', 'name']);
            $out['Documents'] = $docs->map(fn (ProjectDocument $d) => ['label' => $d->name, 'sub' => $d->project->name, 'url' => route('projects.documents', $d->project_id), 'icon' => 'description'])->all();
        }

        $saved = $match(SavedReport::query()->where('team_id', $team->id)->where(fn ($w) => $w->where('user_id', $me->id)->orWhere('shared', true)), 'name')->orderBy('name')->limit(self::LIMITS['reports'])->get(['id', 'name', 'type']);
        $reports = $saved->map(fn (SavedReport $r) => ['label' => $r->name, 'sub' => 'Saved report', 'url' => route('reporting.index', ['saved' => $r->id]), 'icon' => 'table_chart'])->all();
        foreach (Reporting::REPORTS as $type => $r) {
            if ($q === '' ? false : str_contains(mb_strtolower($r['label']), mb_strtolower($q))) {
                $reports[] = ['label' => $r['label'], 'sub' => 'Report', 'url' => route('reporting.index', ['type' => $type]), 'icon' => 'table_chart'];
            }
        }
        $out['Reports'] = array_slice($reports, 0, self::LIMITS['reports']);

        $pages = array_values(array_filter($this->pages($me), fn ($p) => $q === '' || str_contains(mb_strtolower($p['label'].' '.$p['keys']), mb_strtolower($q))));
        $out['Go to'] = array_map(fn ($p) => ['label' => $p['label'], 'sub' => null, 'url' => $p['url'], 'icon' => $p['icon']], array_slice($pages, 0, $q === '' ? 8 : self::LIMITS['pages']));

        return array_filter($out);
    }

    /** @return list<array{label: string, keys: string, url: string, icon: string}> the places a person can go, with words that find them */
    private function pages(User $me): array
    {
        $pages = [
            ['Projects', 'all projects, the list', 'dashboard', 'folder_open'],
            ['Dashboards', 'custom dashboards', 'dashboards.index', 'space_dashboard'],
            ['Portfolio', 'executive view, programmes', 'portfolio.index', 'account_tree'],
            ['Reports', 'export csv excel pdf', 'reporting.index', 'table_chart'],
            ['Automations', 'rules', 'automations.index', 'bolt'],
            ['My time', 'timesheet, timer', 'time.index', 'timer'],
            ['Workload', 'capacity, people', 'workload.index', 'stacked_bar_chart'],
            ['Notifications', 'notices', 'notifications.index', 'notifications'],
            ['Templates', 'project templates, starters', 'templates.index', 'library_books'],
        ];
        /** @var Team|null $team */
        $team = $me->currentTeam;
        if ($team !== null && Workload::oversees($me, $team)) {
            $pages[] = ['Integrations', 'other Dot apps', 'integrations.index', 'hub'];
            $pages[] = ['Data and security', 'retention, protection', 'security.index', 'shield'];
        }

        return array_map(fn ($p) => ['label' => $p[0], 'keys' => $p[1], 'url' => route($p[2]), 'icon' => $p[3]], $pages);
    }
}
