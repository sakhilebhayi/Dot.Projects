<?php

namespace App\Services;

use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\ChecklistItem;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectLabel;
use App\Models\ProjectTask;
use App\Models\SavedView;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change people make to a project's work, in one place: tasks, checklists, milestones,
 * project details, archive and restore, duplicate. Callers authorise first; this keeps the data
 * consistent (milestone status follows its tasks, completion is stamped) and writes the activity
 * log with enumerated values only.
 */
final class ProjectWork
{
    public const STATUSES = ['backlog', 'todo', 'in_progress', 'review', 'done'];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /** @param array<string, mixed> $data validated task fields */
    public function updateTask(ProjectTask $task, array $data, User $by): ProjectTask
    {
        $project = $task->project;
        if (isset($data['milestone_id']) && ! Milestone::query()->where('project_id', $project->id)->whereKey($data['milestone_id'])->exists()) {
            throw ValidationException::withMessages(['milestone_id' => 'Choose a milestone of this project.']);
        }
        if (isset($data['assignee_id'])) {
            $assignee = User::query()->find($data['assignee_id']);
            if (! $assignee || ! $project->canBeSeenBy($assignee)) {
                throw ValidationException::withMessages(['assignee_id' => 'Choose someone in this business.']);
            }
        }

        if (isset($data['status']) && $data['status'] !== $task->status) {
            $this->assertCanStart($task, $data['status']);
        }

        return DB::transaction(function () use ($task, $data, $by, $project) {
            $before = $task->only(['status', 'priority', 'assignee_id', 'milestone_id', 'start_date', 'due_date', 'estimate_minutes']);
            $oldMilestone = $task->milestone_id;
            $task->fill($data);
            if ($task->isDirty('status')) {
                $task->completed_at = $task->status === 'done' ? Carbon::now() : null;
            }
            $dirty = array_keys($task->getDirty());
            $task->save();

            if ($dirty !== []) {
                $changes = [];
                foreach (array_intersect($dirty, ['status', 'priority', 'assignee_id', 'milestone_id']) as $field) {
                    $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $task->{$field}];
                }
                // Text fields (title, description) and dates are named, never copied into the log.
                foreach (array_intersect($dirty, ['title', 'description', 'start_date', 'due_date', 'estimate_minutes']) as $field) {
                    $changes[$field] = 'changed';
                }
                Activity::record('task.updated', $task, $changes, $project);
            }

            foreach (array_unique(array_filter([$oldMilestone, $task->milestone_id])) as $mid) {
                Milestone::query()->find($mid)?->syncStatusFromTasks();
            }
            if (in_array('assignee_id', $dirty, true) && $task->assignee_id && $task->assignee_id !== $by->id) {
                $task->assignee?->notify(new TaskAssignedNotification($task));
            }

            return $task;
        });
    }

    public function deleteTask(ProjectTask $task): void
    {
        DB::transaction(function () use ($task) {
            $project = $task->project;
            $milestone = $task->milestone_id;
            Activity::record('task.deleted', $task, [], $project);
            ProjectComment::query()->where('commentable_type', $task->getMorphClass())->where('commentable_id', $task->id)->delete();
            $task->delete();
            if ($milestone) {
                Milestone::query()->find($milestone)?->syncStatusFromTasks();
            }
        });
    }

    public function addChecklistItem(ProjectTask $task, string $body): ChecklistItem
    {
        $item = ChecklistItem::create([
            'project_task_id' => $task->id,
            'body' => trim($body),
            'position' => (int) $task->checklist()->max('position') + 1,
        ]);
        Activity::record('checklist.added', $task, [], $task->project);

        return $item;
    }

    public function toggleChecklistItem(ChecklistItem $item, User $by): void
    {
        $item->forceFill($item->done_at ? ['done_at' => null, 'done_by' => null] : ['done_at' => Carbon::now(), 'done_by' => $by->id])->save();
        Activity::record($item->done_at ? 'checklist.ticked' : 'checklist.unticked', $item->task, [], $item->task->project);
    }

    public function removeChecklistItem(ChecklistItem $item): void
    {
        Activity::record('checklist.removed', $item->task, [], $item->task->project);
        $item->delete();
    }

    /** @param array{title: string, due_date: string|null} $data */
    public function updateMilestone(Milestone $milestone, array $data): void
    {
        $milestone->fill(['title' => trim($data['title']), 'due_date' => $data['due_date'] ?: null]);
        $dirty = array_keys($milestone->getDirty());
        $milestone->save();
        if ($dirty !== []) {
            Activity::record('milestone.updated', $milestone, array_fill_keys($dirty, 'changed'), $milestone->project);
        }
    }

    public function moveMilestone(Milestone $milestone, string $direction): void
    {
        $siblings = Milestone::query()->where('project_id', $milestone->project_id)->orderBy('sort_order')->orderBy('id')->get()->values();
        $i = $siblings->search(fn (Milestone $m) => $m->id === $milestone->id);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if ($i === false || ! isset($siblings[$j])) {
            return;
        }
        DB::transaction(function () use ($siblings, $i, $j) {
            $order = $siblings->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            foreach ($order as $position => $m) {
                $m->forceFill(['sort_order' => $position])->save();
            }
        });
    }

    /** Deleting a milestone keeps its tasks; they simply have no milestone afterwards. */
    public function deleteMilestone(Milestone $milestone): void
    {
        DB::transaction(function () use ($milestone) {
            Activity::record('milestone.deleted', $milestone, [], $milestone->project);
            ProjectTask::query()->where('milestone_id', $milestone->id)->update(['milestone_id' => null]);
            $milestone->delete();
        });
    }

    /** @param array<string, mixed> $data validated project fields */
    public function updateProject(Project $project, array $data): void
    {
        $project->fill($data);
        $dirty = array_keys($project->getDirty());
        $project->save();
        if ($dirty !== []) {
            $changes = array_fill_keys(array_diff($dirty, ['status']), 'changed');
            if (in_array('status', $dirty, true)) {
                $changes['status'] = ['from' => $project->getOriginal('status'), 'to' => $project->status];
            }
            Activity::record('project.updated', $project, $changes);
        }
    }

    public function archive(Project $project, User $by): void
    {
        $project->forceFill(['archived_at' => Carbon::now(), 'archived_by' => $by->id])->save();
        Activity::record('project.archived', $project);
    }

    public function restore(Project $project): void
    {
        $project->forceFill(['archived_at' => null, 'archived_by' => null])->save();
        Activity::record('project.restored', $project);
    }

    /**
     * A new project with the same milestones, tasks, subtasks, checklists, labels and dependencies,
     * all reset to not started. Dates, people, priorities and estimates are kept; comments,
     * activity and history are not.
     */
    public function duplicate(Project $source, User $by, string $name): Project
    {
        return DB::transaction(function () use ($source, $by, $name) {
            $copy = Project::create([
                'team_id' => $source->team_id, 'owner_id' => $by->id, 'name' => $name,
                'description' => $source->description, 'status' => 'planning',
                'start_date' => $source->start_date, 'due_date' => $source->due_date,
            ]);
            $milestoneMap = [];
            foreach ($source->milestones()->get() as $m) {
                $milestoneMap[$m->id] = Milestone::create([
                    'project_id' => $copy->id, 'title' => $m->title, 'description' => $m->description,
                    'due_date' => $m->due_date, 'status' => 'pending', 'sort_order' => $m->sort_order,
                ])->id;
            }
            $taskMap = [];
            $sourceTasks = $source->tasks()->with(['checklist', 'labels'])->get();
            $labelMap = [];
            foreach ($source->labels()->get() as $label) {
                $labelMap[$label->id] = ProjectLabel::create(['project_id' => $copy->id, 'name' => $label->name])->id;
            }
            foreach ($sourceTasks as $t) {
                $task = ProjectTask::create([
                    'project_id' => $copy->id, 'milestone_id' => $t->milestone_id ? ($milestoneMap[$t->milestone_id] ?? null) : null,
                    'assignee_id' => $t->assignee_id, 'title' => $t->title, 'description' => $t->description,
                    'status' => 'backlog', 'priority' => $t->priority, 'start_date' => $t->start_date, 'due_date' => $t->due_date,
                    'estimate_minutes' => $t->estimate_minutes, 'sort_order' => $t->sort_order,
                ]);
                foreach ($t->checklist as $item) {
                    ChecklistItem::create(['project_task_id' => $task->id, 'body' => $item->body, 'position' => $item->position]);
                }
                $task->labels()->sync($t->labels->map(fn ($l) => $labelMap[$l->id] ?? null)->filter()->all());
                $taskMap[$t->id] = $task->id;
            }
            // Second pass, once every copy exists: subtask parents and dependencies point at copies.
            foreach ($sourceTasks->whereNotNull('parent_id') as $t) {
                ProjectTask::query()->whereKey($taskMap[$t->id])->update(['parent_id' => $taskMap[$t->parent_id] ?? null]);
            }
            $edges = DB::table('task_dependencies')->whereIn('successor_id', array_keys($taskMap))->get(['predecessor_id', 'successor_id', 'type']);
            foreach ($edges as $e) {
                if (isset($taskMap[$e->predecessor_id], $taskMap[$e->successor_id])) {
                    DB::table('task_dependencies')->insert(['predecessor_id' => $taskMap[$e->predecessor_id], 'successor_id' => $taskMap[$e->successor_id], 'type' => $e->type, 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            Activity::record('project.created', $copy, ['duplicated_from' => $source->id]);
            Activity::record('project.duplicated', $source, ['copy' => $copy->id]);

            return $copy;
        });
    }

    /** Statuses that mean work has started; a blocked task cannot move into them. */
    public const STARTED = ['in_progress', 'review', 'done'];

    /** Refuses moving a task that still waits on unfinished work into a started status. */
    public function assertCanStart(ProjectTask $task, string $status): void
    {
        if (! in_array($status, self::STARTED, true)) {
            return;
        }
        $waiting = $task->predecessors()->where('status', '!=', 'done')->pluck('title');
        if ($waiting->isNotEmpty()) {
            throw ValidationException::withMessages(['status' => 'Waiting on '.$waiting->map(fn ($t) => '“'.$t.'”')->join(', ', ' and ').'. Finish '.($waiting->count() === 1 ? 'it' : 'them').' first, or remove the dependency.']);
        }
    }

    /** $successor waits until $predecessor is done. Same project only, and never a loop. */
    public function addDependency(ProjectTask $successor, ProjectTask $predecessor, User $by): void
    {
        if ($predecessor->project_id !== $successor->project_id) {
            throw ValidationException::withMessages(['dependency' => 'Choose a task in this project.']);
        }
        if ($predecessor->id === $successor->id) {
            throw ValidationException::withMessages(['dependency' => 'A task cannot wait for itself.']);
        }
        if ($successor->predecessors()->whereKey($predecessor->id)->exists()) {
            throw ValidationException::withMessages(['dependency' => 'It already waits for that task.']);
        }
        if ($this->reaches($successor->project_id, $successor->id, $predecessor->id)) {
            throw ValidationException::withMessages(['dependency' => '“'.$predecessor->title.'” already waits, directly or through other tasks, for this one. That would be a loop.']);
        }

        DB::transaction(function () use ($successor, $predecessor, $by) {
            $successor->predecessors()->attach($predecessor->id, ['type' => 'finish_to_start', 'created_by' => $by->id]);
            Activity::record('dependency.added', $successor, ['predecessor_id' => $predecessor->id], $successor->project);
        });
    }

    public function removeDependency(ProjectTask $successor, int $predecessorId): void
    {
        if ($successor->predecessors()->detach($predecessorId) > 0) {
            Activity::record('dependency.removed', $successor, ['predecessor_id' => $predecessorId], $successor->project);
        }
    }

    /** Whether $to can be reached from $from by following "is waited for by" edges in one project. */
    private function reaches(int $projectId, int $from, int $to): bool
    {
        $edges = DB::table('task_dependencies')
            ->join('project_tasks', 'project_tasks.id', '=', 'task_dependencies.predecessor_id')
            ->where('project_tasks.project_id', $projectId)
            ->get(['predecessor_id', 'successor_id'])
            ->groupBy('predecessor_id');
        $seen = [$from => true];
        $queue = [$from];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($edges->get($current, collect()) as $edge) {
                $next = (int) $edge->successor_id;
                if ($next === $to) {
                    return true;
                }
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }

    /** A subtask sits one level under its parent and shares its milestone. */
    public function addSubtask(ProjectTask $parent, string $title): ProjectTask
    {
        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages(['subtask' => 'A subtask cannot have its own subtasks. Add it to “'.$parent->parent?->title.'” instead.']);
        }

        return DB::transaction(function () use ($parent, $title) {
            $sub = ProjectTask::create([
                'project_id' => $parent->project_id, 'milestone_id' => $parent->milestone_id, 'parent_id' => $parent->id,
                'title' => trim($title), 'status' => 'backlog', 'priority' => 'medium',
                'sort_order' => (int) $parent->subtasks()->max('sort_order') + 1,
            ]);
            Activity::record('subtask.added', $sub, ['parent_id' => $parent->id], $parent->project);
            $sub->milestone?->syncStatusFromTasks();

            return $sub;
        });
    }

    /** Adds a label by name, reusing the project's label of the same name in any letter case. */
    public function addLabel(ProjectTask $task, string $name): void
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 40) {
            throw ValidationException::withMessages(['label' => 'A label is 1 to 40 characters.']);
        }
        $label = ProjectLabel::query()->where('project_id', $task->project_id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? ProjectLabel::create(['project_id' => $task->project_id, 'name' => $name]);
        $task->labels()->syncWithoutDetaching([$label->id]);
        Activity::record('label.added', $task, ['label_id' => $label->id], $task->project);
    }

    public function removeLabel(ProjectTask $task, int $labelId): void
    {
        if ($task->labels()->detach($labelId) > 0) {
            Activity::record('label.removed', $task, ['label_id' => $labelId], $task->project);
        }
    }

    /** @param array<string, mixed> $filters */
    public function saveView(User $user, Project $project, string $name, array $filters): SavedView
    {
        $clean = array_filter(array_intersect_key($filters, array_flip(['status', 'who', 'late', 'q', 'label'])), fn ($v) => $v !== '' && $v !== null && $v !== false);

        return SavedView::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => trim($name), 'filters' => $clean]);
    }

    /**
     * Move a task on the schedule (dragged or resized on the timeline). The due date may not be
     * before the start; conflicts with dependencies are shown on the schedule, not refused, because
     * people often move one task and then the next.
     */
    public function reschedule(ProjectTask $task, ?string $start, string $due): void
    {
        $startDate = $start ? Carbon::parse($start)->startOfDay() : null;
        $dueDate = Carbon::parse($due)->startOfDay();
        if ($startDate && $dueDate->lt($startDate)) {
            throw ValidationException::withMessages(['schedule' => 'A task cannot be due before it starts.']);
        }
        $from = [$task->start_date?->toDateString(), $task->due_date?->toDateString()];
        $task->forceFill(['start_date' => $startDate, 'due_date' => $dueDate])->save();
        Activity::record('task.rescheduled', $task, [
            'start_date' => ['from' => $from[0], 'to' => $startDate?->toDateString()],
            'due_date' => ['from' => $from[1], 'to' => $dueDate->toDateString()],
        ], $task->project);
    }

    /** Takes the next numbered baseline: every task's start and due date, milestone and project due dates. */
    public function setBaseline(Project $project, User $by, ?string $note): Baseline
    {
        return DB::transaction(function () use ($project, $by, $note) {
            $baseline = Baseline::create([
                'project_id' => $project->id,
                'number' => (int) Baseline::query()->where('project_id', $project->id)->max('number') + 1,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'created_by' => $by->id,
            ]);
            $rows = [['project_baseline_id' => $baseline->id, 'item_type' => 'project', 'item_id' => $project->id, 'start_date' => $project->start_date?->toDateString(), 'due_date' => $project->due_date?->toDateString()]];
            foreach ($project->milestones()->get() as $m) {
                $rows[] = ['project_baseline_id' => $baseline->id, 'item_type' => 'milestone', 'item_id' => $m->id, 'start_date' => null, 'due_date' => $m->due_date?->toDateString()];
            }
            foreach ($project->tasks()->get(['id', 'start_date', 'due_date']) as $t) {
                $rows[] = ['project_baseline_id' => $baseline->id, 'item_type' => 'task', 'item_id' => $t->id, 'start_date' => $t->start_date?->toDateString(), 'due_date' => $t->due_date?->toDateString()];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                BaselineItem::query()->insert($chunk);
            }
            Activity::record('baseline.set', $project, ['number' => $baseline->number]);

            return $baseline;
        });
    }
}
