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

/**
 * A project's dated events for a stretch of days (R-34 P13b, the calendar view, brief §6): the project's start and
 * due date, milestones, task due dates (critical-path work marked), meetings, gates, actions and decisions waiting
 * on a date. Every event comes from the records the other views read; nothing is stored for the calendar itself.
 */
final class ProjectCalendar
{
    public const KINDS = ['task' => 'Tasks', 'milestone' => 'Milestones', 'meeting' => 'Meetings', 'governance' => 'Gates, actions and decisions'];

    /**
     * @return array<string, list<array{kind: string, type: string, title: string, url: string, id: ?int, time: ?string, done: bool, late: bool, critical: bool, mine: bool, movable: bool}>> keyed by date
     */
    public function events(Project $project, Carbon $from, Carbon $to, User $viewer): array
    {
        $today = Carbon::today();
        $in = fn (?Carbon $d) => $d !== null && $d->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay());
        $out = [];
        $add = function (?Carbon $date, array $e) use (&$out, $in) {
            if ($in($date)) {
                $out[$date->toDateString()][] = $e + ['id' => null, 'time' => null, 'done' => false, 'late' => false, 'critical' => false, 'mine' => false, 'movable' => false];
            }
        };

        $add($project->start_date, ['kind' => 'milestone', 'type' => $project->name, 'title' => 'Project starts', 'url' => route('projects.show', $project)]);
        $add($project->due_date, ['kind' => 'milestone', 'type' => $project->name, 'title' => 'Project due', 'url' => route('projects.show', $project),
            'late' => $project->due_date !== null && $project->due_date->lt($today) && $project->status !== 'completed', 'done' => $project->status === 'completed']);

        foreach ($project->milestones()->whereBetween('due_date', [$from, $to])->get() as $m) {
            /** @var Milestone $m */
            $add($m->due_date, ['kind' => 'milestone', 'type' => 'Milestone', 'title' => $m->title, 'url' => route('projects.work', $project),
                'done' => $m->status === 'completed', 'late' => $m->status !== 'completed' && $m->due_date->lt($today)]);
        }

        $critical = (new Schedule($project))->critical()->pluck('id')->flip();
        $canChange = $project->canBeChangedBy($viewer);
        foreach ($project->tasks()->whereBetween('due_date', [$from, $to])->orderBy('sort_order')->get(['id', 'project_id', 'title', 'status', 'due_date', 'assignee_id']) as $t) {
            /** @var ProjectTask $t */
            $done = $t->status === 'done';
            $add($t->due_date, ['kind' => 'task', 'type' => 'Task due', 'title' => $t->title, 'url' => route('projects.tasks.show', [$project, $t]), 'id' => $t->id,
                'done' => $done, 'late' => ! $done && $t->due_date->lt($today), 'critical' => ! $done && $critical->has($t->id),
                'mine' => $t->assignee_id === $viewer->id, 'movable' => $canChange && ! $done]);
        }

        foreach ($project->meetings()->whereBetween('held_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('held_at')->get() as $mt) {
            /** @var ProjectMeeting $mt */
            $add($mt->held_at, ['kind' => 'meeting', 'type' => 'Meeting', 'title' => $mt->title, 'url' => route('projects.meetings.show', [$project, $mt]),
                'time' => $mt->held_at->format('H:i'), 'done' => $mt->held_at->lt(Carbon::now())]);
        }
        foreach ($project->gates()->whereBetween('due_date', [$from, $to])->get() as $g) {
            /** @var ProjectGate $g */
            $add($g->due_date, ['kind' => 'governance', 'type' => 'Gate', 'title' => $g->title, 'url' => route('projects.gates.show', [$project, $g]),
                'done' => ! $g->open(), 'late' => $g->overdue($today)]);
        }
        foreach ($project->actions()->whereBetween('due_date', [$from, $to])->get() as $a) {
            /** @var ProjectAction $a */
            $add($a->due_date, ['kind' => 'governance', 'type' => 'Action', 'title' => $a->title, 'url' => route('projects.actions', $project),
                'done' => $a->status !== 'open', 'late' => $a->overdue($today), 'mine' => $a->owner_id === $viewer->id]);
        }
        foreach ($project->decisions()->whereBetween('decide_by', [$from, $to])->get() as $d) {
            /** @var ProjectDecision $d */
            $add($d->decide_by, ['kind' => 'governance', 'type' => 'Decision due', 'title' => $d->title, 'url' => route('projects.decisions.show', [$project, $d]),
                'done' => $d->status !== 'proposed', 'late' => $d->overdue($today), 'mine' => $d->owner_id === $viewer->id]);
        }

        // Within a day: dated times first, then late, critical, open, done.
        foreach ($out as &$day) {
            usort($day, fn ($a, $b) => [$a['time'] === null, ! $a['late'], ! $a['critical'], $a['done'], $a['time']] <=> [$b['time'] === null, ! $b['late'], ! $b['critical'], $b['done'], $b['time']]);
        }

        return $out;
    }
}
