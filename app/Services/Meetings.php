<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\ProjectDecision;
use App\Models\ProjectMeeting;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Meeting records and the action register. Minutes and agendas are text people write, so the
 * activity log records only that they changed. Actions belong to one owner with a date; decisions
 * taken in a meeting go into the decision register linked to it.
 */
final class Meetings
{
    /** @param array<string, mixed> $data */
    public function add(Project $project, User $by, array $data): ProjectMeeting
    {
        $v = $this->validate($data, true);

        return DB::transaction(function () use ($project, $by, $v) {
            $m = new ProjectMeeting($v);
            $m->forceFill(['project_id' => $project->id, 'number' => (int) ProjectMeeting::query()->where('project_id', $project->id)->lockForUpdate()->max('number') + 1, 'created_by' => $by->id])->save();
            $m->attendees()->sync([$by->id]);
            Activity::record('meeting.added', $m, ['kind' => $m->kind], $project);

            return $m;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>|null  $attendees  member ids, or null to leave as they are
     */
    public function update(ProjectMeeting $m, array $data, ?array $attendees): void
    {
        $m->fill($this->validate($data, false));
        $changes = [];
        if ($m->isDirty('kind')) {
            $changes['kind'] = ['from' => $m->getOriginal('kind'), 'to' => $m->kind];
        }
        if ($m->isDirty('held_at')) {
            $changes['held_at'] = ['from' => Carbon::parse($m->getOriginal('held_at'))->toDateTimeString(), 'to' => $m->held_at->toDateTimeString()];
        }
        $edited = array_values(array_filter(['title', 'location', 'guests', 'agenda', 'minutes'], fn ($f) => $m->isDirty($f)));
        if ($edited !== []) {
            $changes['fields'] = $edited;
        }
        if ($attendees !== null) {
            $members = $m->project->team->allUsers()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $attendees = array_values(array_unique(array_map('intval', $attendees)));
            if (array_diff($attendees, $members) !== []) {
                throw ValidationException::withMessages(['attendees' => 'Attendees are people in this business; add guests by name.']);
            }
            $sync = $m->attendees()->sync($attendees);
            if (array_filter($sync)) {
                $changes['attendees'] = count($attendees);
            }
        }
        if ($changes === []) {
            return;
        }
        $m->save();
        Activity::record('meeting.updated', $m, $changes, $m->project);
    }

    /** A decision taken in the meeting, recorded in the register and linked to it. */
    public function recordDecision(ProjectMeeting $m, User $by, string $title, string $outcome): ProjectDecision
    {
        $d = app(ChangeControl::class)->addDecision($m->project, $by, ['title' => trim($title), 'outcome' => trim($outcome)], true);
        $d->forceFill(['meeting_id' => $m->id])->save();

        return $d;
    }

    /** @param array<string, mixed> $data */
    public function addAction(Project $project, User $by, array $data, ?ProjectMeeting $meeting = null): ProjectAction
    {
        $v = $this->validateAction($project, $data, true);

        $action = DB::transaction(function () use ($project, $by, $v, $meeting) {
            $a = new ProjectAction($v);
            $a->forceFill(['project_id' => $project->id, 'number' => (int) ProjectAction::query()->where('project_id', $project->id)->lockForUpdate()->max('number') + 1, 'status' => 'open', 'meeting_id' => $meeting?->id, 'created_by' => $by->id])->save();
            Activity::record('action.added', $a, ['meeting_id' => $meeting?->id], $project);

            return $a;
        });
        if ($action->owner_id && $action->owner_id !== $by->id) {
            $action->owner?->notify(new GovernanceNotification('action_assigned', 'An action is yours', 'You agreed to '.$action->ref().' "'.$action->title.'"'.($action->due_date ? ' by '.$action->due_date->format('j M') : '').' on "'.$project->name.'".', $project->id, route('projects.actions', $project)));
        }

        return $action;
    }

    /** @param array<string, mixed> $data */
    public function updateAction(ProjectAction $a, array $data): void
    {
        $a->fill($this->validateAction($a->project, $data, false));
        if (! $a->isDirty()) {
            return;
        }
        $changes = [];
        foreach (['owner_id', 'due_date'] as $f) {
            if ($a->isDirty($f)) {
                $o = $a->getOriginal($f);
                $n = $a->getAttribute($f);
                $changes[$f] = ['from' => $o instanceof Carbon ? $o->toDateString() : $o, 'to' => $n instanceof Carbon ? $n->toDateString() : $n];
            }
        }
        if ($a->isDirty('title')) {
            $changes['fields'] = ['title'];
        }
        $a->save();
        Activity::record('action.updated', $a, $changes, $a->project);
    }

    public function toggleAction(ProjectAction $a): void
    {
        $done = $a->status === 'open';
        $a->forceFill(['status' => $done ? 'done' : 'open', 'done_at' => $done ? Carbon::now() : null])->save();
        Activity::record($done ? 'action.done' : 'action.reopened', $a, ['status' => ['from' => $done ? 'open' : 'done', 'to' => $done ? 'done' : 'open']], $a->project);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return Validator::make($data, [
            'title' => [$req, 'string', 'max:255'],
            'kind' => [$req, Rule::in(array_keys(ProjectMeeting::KINDS))],
            'held_at' => [$req, 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guests' => ['sometimes', 'nullable', 'string', 'max:500'],
            'agenda' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'minutes' => ['sometimes', 'nullable', 'string', 'max:50000'],
        ], ['held_at.required' => 'Give the date and time of the meeting.'])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateAction(Project $project, array $data, bool $creating): array
    {
        return Validator::make($data, [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::in($project->team->allUsers()->pluck('id')->map(fn ($id) => (int) $id)->all())],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ], ['owner_id.in' => 'Choose someone in this business.'])->validate();
    }
}
