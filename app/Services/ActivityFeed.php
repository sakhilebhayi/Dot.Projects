<?php

namespace App\Services;

use App\Models\Activity as ActivityLine;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectChange;
use App\Models\ProjectDecision;
use App\Models\ProjectIssue;
use App\Models\ProjectRisk;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns activity log lines into sentences a person reads ("Lerato moved “Survey” from To do to
 * Done"). The log stores no free text, so names and titles are read from the records as they are now.
 */
final class ActivityFeed
{
    /** @return list<array{at: Carbon, who: string|null, text: string}> */
    public static function forProject(Project $project, int $limit = 8): array
    {
        return self::render(ActivityLine::query()->where('project_id', $project->id)->with('user')->latest('id')->limit($limit)->get());
    }

    /**
     * One record's own history, newest first: a risk's re-ratings, an issue's status changes.
     *
     * @return list<array{at: Carbon, who: string|null, text: string}>
     */
    public static function forSubject(Model $subject, int $limit = 30): array
    {
        return self::render(ActivityLine::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->with('user')->latest('id')->limit($limit)->get());
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, ActivityLine>  $lines
     * @return list<array{at: Carbon, who: string|null, text: string}>
     */
    private static function render($lines): array
    {
        $tasks = ProjectTask::query()->whereIn('id', $lines->where('subject_type', (new ProjectTask)->getMorphClass())->pluck('subject_id'))->pluck('title', 'id');
        $milestones = Milestone::query()->whereIn('id', $lines->where('subject_type', (new Milestone)->getMorphClass())->pluck('subject_id'))->pluck('title', 'id');
        $risks = ProjectRisk::query()->whereIn('id', $lines->where('subject_type', (new ProjectRisk)->getMorphClass())->pluck('subject_id'))->get(['id', 'number', 'title'])->mapWithKeys(fn ($r) => [$r->id => $r->ref().' “'.$r->title.'”']);
        $issues = ProjectIssue::query()->whereIn('id', $lines->where('subject_type', (new ProjectIssue)->getMorphClass())->pluck('subject_id'))->get(['id', 'number', 'title'])->mapWithKeys(fn ($i) => [$i->id => $i->ref().' “'.$i->title.'”']);
        $changes = ProjectChange::query()->whereIn('id', $lines->where('subject_type', (new ProjectChange)->getMorphClass())->pluck('subject_id'))->get(['id', 'number', 'title'])->mapWithKeys(fn ($c) => [$c->id => $c->ref().' “'.$c->title.'”']);
        $decisions = ProjectDecision::query()->whereIn('id', $lines->where('subject_type', (new ProjectDecision)->getMorphClass())->pluck('subject_id'))->get(['id', 'number', 'title'])->mapWithKeys(fn ($d) => [$d->id => $d->ref().' “'.$d->title.'”']);
        $assignees = User::query()->whereIn('id', $lines->filter(fn ($l) => in_array($l->action, ['task.assigned', 'risk.updated', 'issue.updated'], true))
            ->map(fn ($l) => $l->changes['assignee_id']['to'] ?? $l->changes['owner_id']['to'] ?? null)->filter())->pluck('name', 'id');
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
                'task.updated' => self::taskUpdate($q($tasks->get($l->subject_id)), $c, $status, $assignees),
                'task.deleted' => 'deleted a task',
                'checklist.added' => 'added a checklist step to '.$q($tasks->get($l->subject_id)),
                'checklist.ticked' => 'ticked a step on '.$q($tasks->get($l->subject_id)),
                'checklist.unticked' => 'unticked a step on '.$q($tasks->get($l->subject_id)),
                'checklist.removed' => 'removed a checklist step from '.$q($tasks->get($l->subject_id)),
                'milestone.updated' => 'changed the milestone '.$q($milestones->get($l->subject_id)),
                'milestone.deleted' => 'deleted a milestone; its tasks were kept',
                'project.updated' => isset($c['status']['to']) ? 'set the project to '.str_replace('_', ' ', (string) $c['status']['to']) : 'changed the project details',
                'project.archived' => 'archived the project',
                'project.restored' => 'restored the project',
                'project.duplicated' => 'duplicated the project',
                'dependency.added' => 'made '.$q($tasks->get($l->subject_id)).' wait for another task',
                'dependency.removed' => 'removed a dependency from '.$q($tasks->get($l->subject_id)),
                'subtask.added' => 'added the subtask '.$q($tasks->get($l->subject_id)),
                'label.added' => 'labelled '.$q($tasks->get($l->subject_id)),
                'label.removed' => 'removed a label from '.$q($tasks->get($l->subject_id)),
                'task.rescheduled' => 'rescheduled '.$q($tasks->get($l->subject_id)).(isset($c['due_date']['to']) ? ' to finish '.Carbon::parse($c['due_date']['to'])->format('j M') : ''),
                'baseline.set' => 'set baseline '.($c['number'] ?? ''),
                'risk.added' => 'added the risk '.($risks->get($l->subject_id) ?? 'a deleted risk'),
                'risk.updated' => self::governanceUpdate($risks->get($l->subject_id) ?? 'a deleted risk', $c, ProjectRisk::STATUSES, $assignees),
                'risk.occurred' => 'recorded that '.($risks->get($l->subject_id) ?? 'a risk').' happened; it is now an issue',
                'risk.escalated' => 'escalated '.($risks->get($l->subject_id) ?? 'a risk').' to the project owner',
                'risk.escalation_closed' => 'closed the escalation on '.($risks->get($l->subject_id) ?? 'a risk'),
                'issue.added' => 'logged the issue '.($issues->get($l->subject_id) ?? 'a deleted issue'),
                'issue.updated' => self::governanceUpdate($issues->get($l->subject_id) ?? 'a deleted issue', $c, ProjectIssue::STATUSES, $assignees),
                'issue.escalated' => 'escalated '.($issues->get($l->subject_id) ?? 'an issue').' to the project owner',
                'issue.escalation_closed' => 'closed the escalation on '.($issues->get($l->subject_id) ?? 'an issue'),
                'change.added' => 'raised the change '.($changes->get($l->subject_id) ?? 'a change'),
                'change.updated' => 'edited '.($changes->get($l->subject_id) ?? 'a change'),
                'change.submitted' => 'asked for approval of '.($changes->get($l->subject_id) ?? 'a change'),
                'change.approved' => 'approved '.($changes->get($l->subject_id) ?? 'a change'),
                'change.rejected' => 'rejected '.($changes->get($l->subject_id) ?? 'a change'),
                'change.withdrawn' => 'withdrew '.($changes->get($l->subject_id) ?? 'a change'),
                'change.rebaselined' => 're-baselined the plan after '.($changes->get($l->subject_id) ?? 'a change').(isset($c['baseline']) ? ' (baseline '.$c['baseline'].')' : ''),
                'change.implemented' => 'marked '.($changes->get($l->subject_id) ?? 'a change').' implemented',
                'decision.proposed' => 'asked for the decision '.($decisions->get($l->subject_id) ?? 'a decision'),
                'decision.recorded' => 'recorded the decision '.($decisions->get($l->subject_id) ?? 'a decision'),
                'decision.decided' => 'decided '.($decisions->get($l->subject_id) ?? 'a decision'),
                'decision.updated' => 'edited '.($decisions->get($l->subject_id) ?? 'a decision'),
                'decision.superseded' => 'marked '.($decisions->get($l->subject_id) ?? 'a decision').' as superseded',
                'matrix.changed' => 'set the risk matrix to '.($c['size']['to'] ?? '?').' by '.($c['size']['to'] ?? '?'),
                default => str_replace(['.', '_'], ' ', $l->action),
            };

            // Lines that describe the system's own reaction read as a sentence with no person.
            $who = in_array($l->action, ['project.closure_proposed', 'milestone.status_changed'], true) ? null : $l->user?->name;

            $out[] = ['at' => Carbon::parse($l->created_at), 'who' => $who === null ? null : (string) $who, 'text' => (string) ($who ? $text : ucfirst($text))];
        }

        return $out;
    }

    /**
     * The most telling change first: status, then rating, severity, owner, then any other edit.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, string>  $statuses
     * @param  Collection<int|string, mixed>  $people
     */
    private static function governanceUpdate(string $what, array $c, array $statuses, $people): string
    {
        if (isset($c['status']['to'])) {
            return 'set '.$what.' to '.mb_strtolower($statuses[$c['status']['to']] ?? (string) $c['status']['to']);
        }
        if (isset($c['probability']) || isset($c['impact'])) {
            return 're-rated '.$what;
        }
        if (isset($c['residual_probability']) || isset($c['residual_impact'])) {
            return 'rated '.$what.' after mitigation';
        }
        if (isset($c['severity']['to'])) {
            return 'made '.$what.' '.$c['severity']['to'].' severity';
        }
        if (array_key_exists('owner_id', $c)) {
            return ($c['owner_id']['to'] ?? null) ? 'gave '.$what.' to '.($people->get($c['owner_id']['to']) ?? 'someone') : 'left '.$what.' without an owner';
        }
        if (isset($c['review_date'])) {
            return 'set the next review of '.$what;
        }
        if (isset($c['target_date'])) {
            return 'set the target date of '.$what;
        }

        return 'edited '.$what;
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, string>  $status
     * @param  Collection<int|string, mixed>  $assignees
     */
    private static function taskUpdate(string $title, array $c, array $status, $assignees): string
    {
        if (isset($c['status']['to'])) {
            return 'moved '.$title.' from '.($status[$c['status']['from'] ?? ''] ?? '?').' to '.($status[$c['status']['to']] ?? '?');
        }
        if (array_key_exists('assignee_id', $c)) {
            return 'reassigned '.$title;
        }

        return 'edited '.$title;
    }
}
