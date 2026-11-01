<?php

namespace App\Services;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * What a project overview says, computed from the project's real records only: the finding
 * sentence and its action, milestones with their task counts, the work in the way, the status
 * split and who holds open work. Nothing here is estimated; when a fact is missing (no due date,
 * no milestones) the summary says so instead of filling it in.
 */
final class ProjectSummary
{
    public const STATUSES = ['backlog' => 'Backlog', 'todo' => 'To do', 'in_progress' => 'In progress', 'review' => 'In review', 'done' => 'Done'];

    public readonly Carbon $today;

    /** @var Collection<int, ProjectTask> */
    public readonly Collection $tasks;

    /** @var Collection<int, Milestone> */
    public readonly Collection $milestones;

    public function __construct(public readonly Project $project)
    {
        $this->today = Carbon::today();
        $this->tasks = $project->tasks()->with(['assignee', 'milestone', 'predecessors:id,title,status'])->orderBy('sort_order')->get();
        $this->milestones = $project->milestones()->get();
    }

    /** @return array<string, int> status => count, in board order */
    public function counts(): array
    {
        $by = $this->tasks->countBy('status');

        return collect(self::STATUSES)->map(fn ($label, $status) => (int) $by->get($status, 0))->all();
    }

    public function done(): int
    {
        return $this->counts()['done'];
    }

    /** @return Collection<int, ProjectTask> open tasks past their due date, oldest first */
    public function overdue(): Collection
    {
        return $this->tasks->filter(fn (ProjectTask $t) => $t->status !== 'done' && $t->due_date && $t->due_date->lt($this->today))
            ->sortBy(fn (ProjectTask $t) => $t->due_date->timestamp)->values();
    }

    /** @return Collection<int, ProjectTask> open tasks waiting on unfinished work */
    public function blocked(): Collection
    {
        return $this->tasks->filter(fn (ProjectTask $t) => $t->isBlocked())->values();
    }

    /** @return Collection<int, ProjectTask> overdue first, then blocked, then urgent open work, at most $limit */
    public function inTheWay(int $limit = 5): Collection
    {
        $urgent = $this->tasks->filter(fn (ProjectTask $t) => $t->status !== 'done' && $t->priority === 'urgent');

        return $this->overdue()->concat($this->blocked())->concat($urgent)->unique('id')->take($limit)->values();
    }

    /** @return Collection<int, array{milestone: Milestone, done: int, total: int, late: bool}> in planned order */
    public function milestoneRows(): Collection
    {
        $byMilestone = $this->tasks->groupBy('milestone_id');

        return $this->milestones->toBase()->map(function (Milestone $m) use ($byMilestone) {
            $tasks = $byMilestone->get($m->id, collect());

            return [
                'milestone' => $m,
                'done' => $tasks->where('status', 'done')->count(),
                'total' => $tasks->count(),
                'late' => $m->status !== 'completed' && $m->due_date && $m->due_date->lt($this->today),
            ];
        });
    }

    /** @return array{milestone: Milestone, done: int, total: int, late: bool}|null the earliest open milestone with a date */
    public function nextMilestone(): ?array
    {
        return $this->milestoneRows()
            ->filter(fn ($r) => $r['milestone']->status !== 'completed' && $r['milestone']->due_date)
            ->sortBy(fn ($r) => $r['milestone']->due_date->timestamp)
            ->first();
    }

    /** @return Collection<int, array{name: string, initials: string, open: int<0, max>, overdue: int<0, max>}> people holding open work */
    public function people(): Collection
    {
        $overdueIds = $this->overdue()->pluck('id');

        return $this->tasks->where('status', '!=', 'done')->whereNotNull('assignee_id')->groupBy('assignee_id')
            ->map(fn (Collection $tasks) => [
                'name' => (string) $tasks->first()->assignee?->name,
                'initials' => self::initials((string) $tasks->first()->assignee?->name),
                'open' => $tasks->count(),
                'overdue' => $tasks->whereIn('id', $overdueIds)->count(),
            ])->sortByDesc('open')->values();
    }

    public function unassignedOpen(): int
    {
        return $this->tasks->where('status', '!=', 'done')->whereNull('assignee_id')->count();
    }

    /** Days from today to the project's due date: negative when it has passed, null without one. */
    public function daysToDue(): ?int
    {
        return $this->project->due_date ? (int) $this->today->diffInDays($this->project->due_date, false) : null;
    }

    /**
     * The one sentence under the title, and the action it drives: [sentence, action label, action key].
     *
     * @return array{0: HtmlString, 1: string, 2: string}
     */
    public function finding(): array
    {
        $b = fn (string $s) => '<b>'.e($s).'</b>';
        $overdue = $this->overdue();

        if ($this->project->status === 'pending_closure_approval') {
            return [new HtmlString('Every milestone is complete. '.e($this->project->owner->name).' needs to confirm closing the project.'), 'Review closure', 'board'];
        }

        $next = $this->nextMilestone();
        if ($next && $next['late']) {
            $days = (int) $next['milestone']->due_date->diffInDays($this->today);
            $open = $next['total'] - $next['done'];

            return [new HtmlString($b($next['milestone']->title).' was due '.e($next['milestone']->due_date->format('j M')).', '.$days.' '.str('day')->plural($days).' ago; '.$open.' of its '.$next['total'].' '.str('task')->plural($next['total']).' '.($open === 1 ? 'is' : 'are').' still open.'), 'Open the board', 'board'];
        }

        if ($overdue->isNotEmpty()) {
            $oldest = (int) $overdue->first()->due_date->diffInDays($this->today);

            return [new HtmlString($b($overdue->count().' '.str('task')->plural($overdue->count())).' '.($overdue->count() === 1 ? 'is' : 'are').' overdue, the oldest by '.$oldest.' '.str('day')->plural($oldest).'.'.($next ? ' Next milestone: '.e($next['milestone']->title).' on '.e($next['milestone']->due_date->format('j M')).'.' : '')), 'Open the board', 'board'];
        }

        if ($next) {
            return [new HtmlString('Next milestone: '.$b($next['milestone']->title).' on '.e($next['milestone']->due_date->format('j M')).', with '.$next['done'].' of '.$next['total'].' '.str('task')->plural($next['total']).' done. Nothing is overdue.'), 'Open the board', 'board'];
        }

        if ($this->milestones->isEmpty()) {
            return [new HtmlString('No milestones yet. Add the first one to see when this project lands.'), 'Add a milestone', 'board'];
        }

        return [new HtmlString('No open milestone has a date. Give the next one a due date to see when this project lands.'), 'Open the board', 'board'];
    }

    public static function initials(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    }
}
