<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A project's task statuses (R-34 P13c, "statuses as data"). Every status belongs to one of five fixed categories,
 * and the category is what every rule reads: done is done, a blocked task cannot move into a started category,
 * earned value, reports, automations and the overview count categories. So a business can name its own workflow
 * ("Awaiting client", "QA", "Ready to invoice") without any of that changing meaning.
 *
 * A project with no statuses of its own uses the five standard ones. A status is addressed by a key: the category
 * name for a standard status, "s{id}" for one of the project's own.
 */
final class ProjectStatuses
{
    public const CATEGORIES = ProjectSummary::STATUSES;

    /** What each category means, for the workflow settings. */
    public const MEANS = [
        'backlog' => 'not planned yet',
        'todo' => 'ready to start',
        'in_progress' => 'being worked on',
        'review' => 'being checked',
        'done' => 'finished: counts as done everywhere',
    ];

    private const MAX = 20;

    /** @return Collection<int, array<string, mixed>> in board order */
    public function for(Project $project): Collection
    {
        $own = $project->relationLoaded('statuses') ? $project->statuses : $project->statuses()->get();
        /** @var list<array<string, mixed>> $rows */
        $rows = [];
        if ($own->isEmpty()) {
            foreach (self::CATEGORIES as $category => $name) {
                $rows[] = ['key' => $category, 'name' => $name, 'category' => $category, 'id' => null];
            }
        } else {
            foreach ($own->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $st) {
                $rows[] = ['key' => 's'.$st->id, 'name' => $st->name, 'category' => $st->category, 'id' => $st->id];
            }
        }

        /** @var Collection<int, array<string, mixed>> $out */
        $out = new Collection($rows);

        return $out;
    }

    public function custom(Project $project): bool
    {
        return $project->statuses()->exists();
    }

    /**
     * What a key means in this project, or null when it means nothing here. A bare category ("done") also works on a
     * project with its own statuses, meaning the first of them in that category, so rules and older callers that
     * speak in categories keep working.
     *
     * @return array{category: string, id: ?int}|null
     */
    public function resolve(Project $project, string $key): ?array
    {
        $statuses = $this->for($project);
        $row = $statuses->firstWhere('key', $key) ?? (isset(self::CATEGORIES[$key]) ? $statuses->firstWhere('category', $key) : null);

        return $row === null ? null : ['category' => $row['category'], 'id' => $row['id']];
    }

    /** The key a task is shown under: its own status, or the first of the project's statuses in its category. */
    public function keyOf(ProjectTask $task, Collection $statuses): string
    {
        if ($task->status_id !== null && $statuses->contains('id', $task->status_id)) {
            return 's'.$task->status_id;
        }

        return $statuses->firstWhere('category', $task->status)['key'] ?? $statuses->first()['key'] ?? $task->status;
    }

    /** @return array{status: string, status_id: ?int} where a new task starts: the first status in the backlog category, else the first */
    public function initial(Project $project): array
    {
        $statuses = $this->for($project);
        $first = $statuses->firstWhere('category', 'backlog') ?? $statuses->first();

        return ['status' => $first['category'], 'status_id' => $first['id']];
    }

    /**
     * Makes the standard five the project's own, so they can be renamed, reordered, added to and removed. Tasks keep
     * their category and are placed in the matching status.
     */
    public function customise(Project $project, User $by): void
    {
        if ($this->custom($project)) {
            return;
        }
        DB::transaction(function () use ($project) {
            $i = 0;
            foreach (self::CATEGORIES as $category => $name) {
                $s = $project->statuses()->create(['name' => $name, 'category' => $category, 'sort_order' => ++$i]);
                $project->tasks()->where('status', $category)->update(['status_id' => $s->id]);
            }
            Activity::record('workflow.customised', $project, [], $project);
        });
        unset($by);
        $project->unsetRelation('statuses');
    }

    public function add(Project $project, User $by, string $name, string $category): ProjectStatus
    {
        $this->customise($project, $by);
        $name = $this->name($project, $name);
        $this->category($category);
        if ($project->statuses()->count() >= self::MAX) {
            throw ValidationException::withMessages(['status' => 'A project can have up to '.self::MAX.' statuses.']);
        }
        // A new status goes after the last one in its category, so the board keeps its flow.
        $after = $project->statuses()->get()->filter(fn ($s) => array_search($s->category, array_keys(self::CATEGORIES), true) <= array_search($category, array_keys(self::CATEGORIES), true))->max('sort_order') ?? 0;
        $project->statuses()->where('sort_order', '>', $after)->increment('sort_order');
        $status = $project->statuses()->create(['name' => $name, 'category' => $category, 'sort_order' => $after + 1]);
        Activity::record('workflow.status_added', $project, ['status_id' => $status->id, 'category' => $category], $project);
        $project->unsetRelation('statuses');

        return $status;
    }

    public function rename(ProjectStatus $status, User $by, string $name): void
    {
        $status->forceFill(['name' => $this->name($status->project, $name, $status->id)])->save();
        Activity::record('workflow.status_renamed', $status->project, ['status_id' => $status->id], $status->project);
        unset($by);
    }

    /** Moves a status one place earlier ($by -1) or later (+1) on the board. */
    public function move(ProjectStatus $status, int $by): void
    {
        $list = $status->project->statuses()->orderBy('sort_order')->orderBy('id')->get()->values();
        $at = $list->search(fn ($s) => $s->id === $status->id);
        $to = $at + ($by < 0 ? -1 : 1);
        if ($at === false || $to < 0 || $to >= $list->count()) {
            return;
        }
        $other = $list[$to];
        DB::transaction(function () use ($list, $at, $to, $status, $other) {
            foreach ($list as $i => $s) {
                $s->forceFill(['sort_order' => $i === $at ? $to + 1 : ($i === $to ? $at + 1 : $i + 1)])->save();
            }
            unset($status, $other);
        });
    }

    /** Removes a status; its tasks move to $toKey (another of the project's statuses) and take its category. */
    public function remove(ProjectStatus $status, User $by, string $toKey): void
    {
        $project = $status->project;
        $statuses = $this->for($project);
        $target = $statuses->firstWhere('key', $toKey);
        if ($target === null || $target['id'] === $status->id) {
            throw ValidationException::withMessages(['status' => 'Choose another status for its tasks.']);
        }
        $left = $statuses->reject(fn ($s) => $s['id'] === $status->id);
        if (! $left->contains('category', 'done')) {
            throw ValidationException::withMessages(['status' => 'Keep at least one status that means done.']);
        }
        if ($left->every(fn ($s) => $s['category'] === 'done')) {
            throw ValidationException::withMessages(['status' => 'Keep at least one status for work that is not done.']);
        }
        DB::transaction(function () use ($status, $target, $project, $by) {
            $moving = $project->tasks()->where('status_id', $status->id)->get();
            foreach ($moving as $task) {
                app(ProjectWork::class)->updateTask($task, ['status' => $target['key']], $by);
            }
            Activity::record('workflow.status_removed', $project, ['status_id' => $status->id, 'moved' => $moving->count(), 'to' => $target['id']], $project);
            $status->delete();
        });
        $project->unsetRelation('statuses');
    }

    /** Back to the standard five: every task keeps its category; the project's own statuses are removed. */
    public function reset(Project $project, User $by): void
    {
        DB::transaction(function () use ($project) {
            $project->tasks()->update(['status_id' => null]);
            $project->statuses()->delete();
            Activity::record('workflow.reset', $project, [], $project);
        });
        unset($by);
        $project->unsetRelation('statuses');
    }

    /** @return array<string, string> key => name, for labels; ids of statuses no longer there fall back to their category */
    public function names(Project $project): array
    {
        return $this->for($project)->pluck('name', 'key')->all();
    }

    private function name(Project $project, string $name, ?int $except = null): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 40) {
            throw ValidationException::withMessages(['status' => 'Name the status in up to 40 characters.']);
        }
        if ($project->statuses()->when($except, fn ($q) => $q->whereKeyNot($except))->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['status' => 'This project already has a status called “'.$name.'”.']);
        }

        return $name;
    }

    private function category(string $category): void
    {
        if (! isset(self::CATEGORIES[$category])) {
            throw ValidationException::withMessages(['status' => 'Choose what the status means.']);
        }
    }
}
