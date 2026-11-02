<?php

namespace App\Services;

use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The project's schedule as it stands, computed from the dates people entered. Nothing is
 * estimated: a task without a due date is not on the schedule, and the page says so.
 *
 * Rules (calendar days, documented in docs/programme/R34_AUDIT.md):
 *  - A scheduled task has a due date. It runs from its start date (or its due date when it has
 *    none) to its due date, inclusive.
 *  - The target is the project's due date, or, without one, the latest due date of any task.
 *  - Backward pass over finish-to-start dependencies: a task must finish by the target, and by
 *    the day before each task that waits for it has to start. Its latest finish minus its due
 *    date is its float, in days.
 *  - Critical: an open task with float of zero or less; on it, any slip moves the finish (or it is
 *    already late). Done tasks are history and are never critical.
 *  - Conflict: a task that starts before something it waits for finishes.
 *  - Other projects (R-34 P8b): a task here may wait for a task in another project of the same
 *    business. That task is not drawn, but a start before its due date is a conflict; and a task
 *    here that another project waits for must finish the day before that task starts, which
 *    counts in its float. Tasks in projects the viewer cannot see are never named.
 *  - Forecast: a milestone lands when its last dated task is due; the project when its last dated
 *    task is due.
 */
final class Schedule
{
    /** @var Collection<int, ProjectTask> */
    public readonly Collection $tasks;

    /** @var Collection<int, Milestone> */
    public readonly Collection $milestones;

    /** @var array<int, array{start: Carbon, finish: Carbon, float: int|null, critical: bool, conflicts: list<string>}> keyed by task id */
    private array $rows = [];

    public readonly ?Carbon $target;

    public function __construct(public readonly Project $project)
    {
        $this->tasks = $project->tasks()->with(['predecessors:id,project_id,title,status,start_date,due_date', 'successors:id,project_id,title,status,start_date,due_date', 'assignee:id,name', 'milestone:id,title'])
            ->orderByRaw('start_date is null')->orderBy('start_date')->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('sort_order')->get();
        $this->milestones = $project->milestones()->get();
        $scheduled = $this->tasks->filter(fn (ProjectTask $t) => $t->due_date !== null);
        $this->target = $project->due_date?->copy() ?? $scheduled->max(fn (ProjectTask $t) => $t->due_date)?->copy();
        $this->compute($scheduled);
    }

    /** @param Collection<int, ProjectTask> $scheduled */
    private function compute(Collection $scheduled): void
    {
        $byId = $scheduled->keyBy('id');
        $successors = [];
        foreach ($scheduled as $t) {
            foreach ($t->predecessors as $p) {
                if ($byId->has($p->id)) {
                    $successors[$p->id][] = $t->id;
                }
            }
        }

        // Reverse topological order (successors before predecessors). Loops are refused when
        // dependencies are added; a guard keeps any stray one from hanging the page.
        $order = [];
        $visited = [];
        $visit = function (int $id) use (&$visit, &$order, &$visited, $successors) {
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;
            foreach ($successors[$id] ?? [] as $s) {
                $visit($s);
            }
            $order[] = $id;
        };
        foreach ($byId->keys() as $id) {
            $visit((int) $id);
        }

        $latestFinish = [];
        foreach ($order as $id) {
            $t = $byId[$id];
            $start = ($t->start_date ?? $t->due_date)->copy();
            $finish = $t->due_date->copy();
            if ($start->gt($finish)) {
                $start = $finish->copy();
            }
            $lf = $this->target?->copy() ?? $finish->copy();
            foreach ($this->outside($t->successors) as $ext) {
                $limit = ($ext->start_date ?? $ext->due_date)?->copy()->subDay();
                if ($ext->status !== 'done' && $limit !== null && $limit->lt($lf)) {
                    $lf = $limit;
                }
            }
            foreach ($successors[$id] ?? [] as $s) {
                $succ = $byId[$s];
                $succLatestStart = $latestFinish[$s]->copy()->subDays((int) ($succ->start_date ?? $succ->due_date)->diffInDays($succ->due_date));
                $limit = $succLatestStart->subDay();
                if ($limit->lt($lf)) {
                    $lf = $limit;
                }
            }
            $latestFinish[$id] = $lf;
            $open = $t->status !== 'done';
            $float = $open ? (int) $finish->diffInDays($lf, false) : null;
            $this->rows[$id] = ['start' => $start, 'finish' => $finish, 'float' => $float, 'critical' => $open && $float !== null && $float <= 0, 'conflicts' => []];
        }

        $viewer = Auth::user();
        foreach ($scheduled as $t) {
            foreach ($t->predecessors as $p) {
                if ($p->due_date && $byId->has($p->id) && $this->rows[$t->id]['start']->lte($p->due_date)) {
                    $this->rows[$t->id]['conflicts'][] = 'Starts before “'.$p->title.'” finishes on '.$p->due_date->format('j M').'.';
                }
            }
            foreach ($this->outside($t->predecessors) as $p) {
                if ($p->status === 'done' || $p->due_date === null || $this->rows[$t->id]['start']->gt($p->due_date)) {
                    continue;
                }
                $seen = $viewer instanceof User ? $p->projectSeenBy($viewer) : null;
                $this->rows[$t->id]['conflicts'][] = $seen === null
                    ? 'Starts before a task it waits for in a project you cannot see is due.'
                    : 'Starts before “'.$p->title.'” in '.$seen->name.' finishes on '.$p->due_date->format('j M').'.';
            }
        }
    }

    /**
     * Linked tasks that sit in other projects.
     *
     * @param  Collection<int, ProjectTask>  $linked
     * @return Collection<int, ProjectTask>
     */
    private function outside(Collection $linked): Collection
    {
        return $linked->filter(fn (ProjectTask $o) => (int) $o->project_id !== $this->project->id);
    }

    /** @return array{waits: int, open: int, holds: int} tasks here waiting on other projects (and how many of those are not done), and tasks here other projects wait on */
    public function crossProject(): array
    {
        $waits = $this->tasks->filter(fn (ProjectTask $t) => $this->outside($t->predecessors)->isNotEmpty());

        return [
            'waits' => $waits->count(),
            'open' => $waits->filter(fn (ProjectTask $t) => $t->status !== 'done' && $this->outside($t->predecessors)->contains(fn ($p) => $p->status !== 'done'))->count(),
            'holds' => $this->tasks->filter(fn (ProjectTask $t) => $this->outside($t->successors)->isNotEmpty())->count(),
        ];
    }

    /** @return array{start: Carbon, finish: Carbon, float: int|null, critical: bool, conflicts: list<string>}|null */
    public function row(ProjectTask $task): ?array
    {
        return $this->rows[$task->id] ?? null;
    }

    /** @return Collection<int, ProjectTask> open tasks on the critical path, earliest first */
    public function critical(): Collection
    {
        return $this->tasks->filter(fn (ProjectTask $t) => $this->rows[$t->id]['critical'] ?? false)->values();
    }

    /** @return Collection<int, ProjectTask> tasks without a due date, so not on the schedule */
    public function unscheduled(): Collection
    {
        return $this->tasks->filter(fn (ProjectTask $t) => $t->due_date === null)->values();
    }

    public function conflictCount(): int
    {
        return collect($this->rows)->sum(fn ($r) => count($r['conflicts']));
    }

    /** The last due date of any dated task: when the work, as planned now, finishes. */
    public function forecastFinish(): ?Carbon
    {
        return $this->tasks->filter(fn (ProjectTask $t) => $t->due_date !== null)->max(fn (ProjectTask $t) => $t->due_date);
    }

    /** When a milestone lands as planned now: its last dated task's due date, or its own date. */
    public function milestoneForecast(Milestone $m): ?Carbon
    {
        $last = $this->tasks->where('milestone_id', $m->id)->filter(fn (ProjectTask $t) => $t->due_date !== null)->max(fn (ProjectTask $t) => $t->due_date);

        return $last ?? $m->due_date;
    }

    /** The earliest and latest dates the timeline must show, padded to whole weeks. */
    public function range(): array
    {
        $dates = collect([$this->project->start_date, $this->project->due_date, Carbon::today()])
            ->concat($this->tasks->pluck('start_date'))->concat($this->tasks->pluck('due_date'))
            ->concat($this->milestones->pluck('due_date'))->filter();

        return [$dates->min()->copy()->startOfWeek(), $dates->max()->copy()->endOfWeek()->startOfDay()];
    }

    /** @return array<string, array<int, BaselineItem>> type => item id => item, for one baseline */
    public static function baselineMap(?Baseline $baseline): array
    {
        if ($baseline === null) {
            return [];
        }
        $map = [];
        foreach ($baseline->items()->get() as $item) {
            $map[$item->item_type][$item->item_id] = $item;
        }

        return $map;
    }
}
