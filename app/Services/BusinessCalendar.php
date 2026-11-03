<?php

namespace App\Services;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\ProjectDecision;
use App\Models\ProjectGate;
use App\Models\ProjectMeeting;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The business's calendar (R-34 P13d): the dated events of every open project the person can see, in one month.
 * One query per kind of record across all those projects, never one per project, so it stays quick as the
 * business grows. The critical path is worked out per project, so it is shown on each project's own calendar,
 * not here; everything else (late, done, yours) reads the same as there.
 */
final class BusinessCalendar
{
    /** @return Collection<int, Project> the open projects the person can see, by name */
    public function projects(): Collection
    {
        return Project::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'team_id', 'owner_id', 'name', 'status', 'visibility', 'start_date', 'due_date', 'archived_at']);
    }

    /**
     * @return array<string, list<array<string, mixed>>> keyed by date, each item as ProjectCalendar's with the project's name
     */
    public function events(User $viewer, Carbon $from, Carbon $to, ?int $projectId = null): array
    {
        $today = Carbon::today();
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $projects = $this->projects()->when($projectId !== null, fn ($c) => $c->where('id', $projectId))->keyBy('id');
        $ids = $projects->keys();
        // Project::canBeChangedBy, worked out for every project at once (one query, not one per project). These
        // projects are already the visible, open ones; a move is still checked by the task policy on the server.
        $team = $viewer->currentTeam;
        $mayUpdate = $team !== null && $viewer->hasTeamPermission($team, 'update');
        $ownsTeam = $team !== null && $viewer->ownsTeam($team);
        $roles = DB::table('project_members')->whereIn('project_id', $projects->filter(fn (Project $p) => $p->isPrivate())->keys())->where('user_id', $viewer->id)->pluck('role', 'project_id');
        $changeable = $projects->map(fn (Project $p) => $mayUpdate && (! $p->isPrivate() || $p->owner_id === $viewer->id || $ownsTeam || in_array($roles[$p->id] ?? null, ['manager', 'member'], true)));
        $out = [];
        $add = function (?Carbon $date, Project $p, array $e) use (&$out, $from, $to) {
            if ($date !== null && $date->betweenIncluded($from, $to)) {
                $out[$date->toDateString()][] = $e + ['project' => $p->name, 'id' => null, 'time' => null, 'done' => false, 'late' => false, 'critical' => false, 'mine' => false, 'movable' => false];
            }
        };

        foreach ($projects as $p) {
            $add($p->start_date, $p, ['kind' => 'milestone', 'type' => 'Project starts', 'title' => $p->name.' starts', 'url' => route('projects.show', $p)]);
            $add($p->due_date, $p, ['kind' => 'milestone', 'type' => 'Project due', 'title' => $p->name.' due', 'url' => route('projects.show', $p),
                'done' => $p->status === 'completed', 'late' => $p->status !== 'completed' && $p->due_date !== null && $p->due_date->lt($today)]);
        }
        foreach (Milestone::query()->whereIn('project_id', $ids)->whereBetween('due_date', [$from, $to])->get() as $m) {
            $add($m->due_date, $projects[$m->project_id], ['kind' => 'milestone', 'type' => 'Milestone', 'title' => $m->title, 'url' => route('projects.work', $m->project_id),
                'done' => $m->status === 'completed', 'late' => $m->status !== 'completed' && $m->due_date->lt($today)]);
        }
        foreach (ProjectTask::query()->whereIn('project_id', $ids)->whereBetween('due_date', [$from, $to])->orderBy('sort_order')->get(['id', 'project_id', 'title', 'status', 'due_date', 'assignee_id']) as $t) {
            $done = $t->status === 'done';
            $add($t->due_date, $projects[$t->project_id], ['kind' => 'task', 'type' => 'Task due', 'title' => $t->title, 'url' => route('projects.tasks.show', [$t->project_id, $t->id]), 'id' => $t->id,
                'done' => $done, 'late' => ! $done && $t->due_date->lt($today), 'mine' => $t->assignee_id === $viewer->id, 'movable' => ! $done && ($changeable[$t->project_id] ?? false)]);
        }
        foreach (ProjectMeeting::query()->whereIn('project_id', $ids)->whereBetween('held_at', [$from, $to])->orderBy('held_at')->get() as $mt) {
            $add($mt->held_at, $projects[$mt->project_id], ['kind' => 'meeting', 'type' => 'Meeting', 'title' => $mt->title, 'url' => route('projects.meetings.show', [$mt->project_id, $mt->id]),
                'time' => $mt->held_at->format('H:i'), 'done' => $mt->held_at->lt(Carbon::now())]);
        }
        foreach (ProjectGate::query()->whereIn('project_id', $ids)->whereBetween('due_date', [$from, $to])->get() as $g) {
            $add($g->due_date, $projects[$g->project_id], ['kind' => 'governance', 'type' => 'Gate', 'title' => $g->title, 'url' => route('projects.gates.show', [$g->project_id, $g->id]),
                'done' => ! $g->open(), 'late' => $g->overdue($today)]);
        }
        foreach (ProjectAction::query()->whereIn('project_id', $ids)->whereBetween('due_date', [$from, $to])->get() as $a) {
            $add($a->due_date, $projects[$a->project_id], ['kind' => 'governance', 'type' => 'Action', 'title' => $a->title, 'url' => route('projects.actions', $a->project_id),
                'done' => $a->status !== 'open', 'late' => $a->overdue($today), 'mine' => $a->owner_id === $viewer->id]);
        }
        foreach (ProjectDecision::query()->whereIn('project_id', $ids)->whereBetween('decide_by', [$from, $to])->get() as $d) {
            $add($d->decide_by, $projects[$d->project_id], ['kind' => 'governance', 'type' => 'Decision due', 'title' => $d->title, 'url' => route('projects.decisions.show', [$d->project_id, $d->id]),
                'done' => $d->status !== 'proposed', 'late' => $d->overdue($today), 'mine' => $d->owner_id === $viewer->id]);
        }

        foreach ($out as &$day) {
            usort($day, fn ($a, $b) => [$a['time'] === null, ! $a['late'], $a['done'], $a['time'], $a['project']] <=> [$b['time'] === null, ! $b['late'], $b['done'], $b['time'], $b['project']]);
        }

        return $out;
    }
}
