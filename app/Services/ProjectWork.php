<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
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
            if (! $assignee || ! $assignee->belongsToTeam($project->team)) {
                throw ValidationException::withMessages(['assignee_id' => 'Choose someone in this business.']);
            }
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
     * A new project with the same milestones, tasks and checklists, all reset to not started.
     * Dates, people, priorities and estimates are kept; comments, activity and history are not.
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
            foreach ($source->tasks()->with('checklist')->get() as $t) {
                $task = ProjectTask::create([
                    'project_id' => $copy->id, 'milestone_id' => $t->milestone_id ? ($milestoneMap[$t->milestone_id] ?? null) : null,
                    'assignee_id' => $t->assignee_id, 'title' => $t->title, 'description' => $t->description,
                    'status' => 'backlog', 'priority' => $t->priority, 'start_date' => $t->start_date, 'due_date' => $t->due_date,
                    'estimate_minutes' => $t->estimate_minutes, 'sort_order' => $t->sort_order,
                ]);
                foreach ($t->checklist as $item) {
                    ChecklistItem::create(['project_task_id' => $task->id, 'body' => $item->body, 'position' => $item->position]);
                }
            }
            Activity::record('project.created', $copy, ['duplicated_from' => $source->id]);
            Activity::record('project.duplicated', $source, ['copy' => $copy->id]);

            return $copy;
        });
    }
}
