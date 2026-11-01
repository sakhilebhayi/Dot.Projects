<?php

namespace App\Services;

use App\Models\Activity as ActivityLine;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns activity log lines into sentences a person reads ("Lerato moved “Survey” from To do to
 * Done"). The log stores no free text, so names and titles are read from the records as they are now.
 */
final class ActivityFeed
{
    /** @return list<array{at: Carbon, who: string|null, text: string}> */
    public static function forProject(Project $project, int $limit = 8): array
    {
        $lines = ActivityLine::query()->where('project_id', $project->id)->with('user')->latest('id')->limit($limit)->get();
        $tasks = ProjectTask::query()->whereIn('id', $lines->where('subject_type', (new ProjectTask)->getMorphClass())->pluck('subject_id'))->pluck('title', 'id');
        $milestones = Milestone::query()->whereIn('id', $lines->where('subject_type', (new Milestone)->getMorphClass())->pluck('subject_id'))->pluck('title', 'id');
        $assignees = User::query()->whereIn('id', $lines->where('action', 'task.assigned')->map(fn ($l) => $l->changes['assignee_id']['to'] ?? null)->filter())->pluck('name', 'id');
        $status = ProjectSummary::STATUSES;
        $q = fn (?string $title) => $title === null ? 'a deleted item' : '“'.$title.'”';

        $out = [];
        foreach ($lines as $l) {
            $c = $l->changes ?? [];
            $text = match ($l->action) {
                'project.created' => 'created the project',
                'project.ai_plan_added' => 'added a plan drafted by AI',
                'project.example_plan_added' => 'added the example plan',
                'project.closure_proposed' => 'Every milestone is complete, so closing the project was proposed',
                'project.closed' => 'closed the project',
                'project.closure_rejected' => 'kept the project open',
                'task.created' => 'added '.$q($tasks->get($l->subject_id)),
                'task.moved' => 'moved '.$q($tasks->get($l->subject_id)).' from '.($status[$c['status']['from'] ?? ''] ?? '?').' to '.($status[$c['status']['to'] ?? ''] ?? '?'),
                'task.assigned' => ($c['assignee_id']['to'] ?? null) ? 'gave '.$q($tasks->get($l->subject_id)).' to '.($assignees->get($c['assignee_id']['to']) ?? 'someone') : 'unassigned '.$q($tasks->get($l->subject_id)),
                'milestone.created' => 'added the milestone '.$q($milestones->get($l->subject_id)),
                'milestone.status_changed' => 'Milestone '.$q($milestones->get($l->subject_id)).' is now '.str_replace('_', ' ', (string) ($c['status']['to'] ?? '')),
                'comment.added' => 'commented',
                default => str_replace(['.', '_'], ' ', $l->action),
            };

            // Lines that describe the system's own reaction read as a sentence with no person.
            $who = in_array($l->action, ['project.closure_proposed', 'milestone.status_changed'], true) ? null : $l->user?->name;

            $out[] = ['at' => Carbon::parse($l->created_at), 'who' => $who === null ? null : (string) $who, 'text' => (string) ($who ? $text : ucfirst($text))];
        }

        return $out;
    }
}
