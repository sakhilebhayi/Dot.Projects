<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectChange;
use App\Models\ProjectDecision;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Change requests and the decision register. A change moves draft, waiting for approval, then
 * approved or rejected (or withdrawn), and approved changes can re-baseline the plan and be marked
 * implemented. Approving or rejecting records a decision. The activity log holds statuses, ids,
 * days and amounts; written text is logged by field name only.
 */
final class ChangeControl
{
    private const CHANGE_TEXT = ['title', 'reason', 'description', 'scope_impact', 'resource_impact', 'risk_impact'];

    /** People who may approve a change: members who can change the business's projects. @return Collection<int, User> */
    public function approvers(Project $project): Collection
    {
        return $project->team->allUsers()->filter(fn (User $u) => $u->hasTeamPermission($project->team, 'update'))->sortBy('name')->values();
    }

    /** @param array<string, mixed> $data */
    public function addChange(Project $project, User $by, array $data): ProjectChange
    {
        $v = $this->validateChange($data + ['kind' => 'scope'], true);

        return DB::transaction(function () use ($project, $by, $v) {
            $change = new ProjectChange($v);
            $change->forceFill(['project_id' => $project->id, 'number' => $this->next(ProjectChange::class, $project), 'status' => 'draft', 'requested_by' => $by->id])->save();
            Activity::record('change.added', $change, ['kind' => $change->kind], $project);

            return $change;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateChange(ProjectChange $change, array $data): void
    {
        if ($change->status !== 'draft') {
            throw ValidationException::withMessages(['change' => 'Only a draft can be edited. Withdraw it and raise a new one to change what was asked.']);
        }
        $change->fill($this->validateChange($data, false));
        $changes = [];
        foreach (['kind', 'schedule_days', 'cost_cents'] as $f) {
            if ($change->isDirty($f)) {
                $changes[$f] = ['from' => $change->getOriginal($f), 'to' => $change->getAttribute($f)];
            }
        }
        $edited = array_values(array_filter(self::CHANGE_TEXT, fn ($f) => $change->isDirty($f)));
        if ($edited !== []) {
            $changes['fields'] = $edited;
        }
        if ($changes === []) {
            return;
        }
        $change->save();
        Activity::record('change.updated', $change, $changes, $change->project);
    }

    public function submit(ProjectChange $change, User $by, ?int $approverId): void
    {
        if ($change->status !== 'draft') {
            throw ValidationException::withMessages(['change' => 'This change has already been submitted.']);
        }
        if (trim((string) $change->reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the change is needed before asking for approval.']);
        }
        $approver = $this->approvers($change->project)->firstWhere('id', $approverId);
        if ($approver === null) {
            throw ValidationException::withMessages(['approver' => 'Choose who approves it: someone in this business who can change projects.']);
        }
        $change->forceFill(['status' => 'submitted', 'approver_id' => $approver->id, 'submitted_at' => Carbon::now()])->save();
        Activity::record('change.submitted', $change, ['approver_id' => $approver->id], $change->project);
        if ($approver->id !== $by->id) {
            $approver->notify(new GovernanceNotification('change_submitted', 'A change waits for your approval', $by->name.' asks you to approve '.$change->ref().' "'.$change->title.'" on "'.$change->project->name.'".', $change->project_id, $this->url($change)));
        }
    }

    /** Approves or rejects, by the named approver, and records the decision. */
    public function decide(ProjectChange $change, User $by, bool $approve, ?string $note): void
    {
        if ($change->status !== 'submitted') {
            throw ValidationException::withMessages(['decision' => 'Only a change waiting for approval can be decided.']);
        }
        $note = trim((string) $note);
        if (! $approve && $note === '') {
            throw ValidationException::withMessages(['decision' => 'Say why it is rejected, so the requester knows what would change your mind.']);
        }
        if (mb_strlen($note) > 5000) {
            throw ValidationException::withMessages(['decision' => 'Keep the note under 5000 characters.']);
        }

        DB::transaction(function () use ($change, $by, $approve, $note) {
            $decision = new ProjectDecision([
                'title' => ($approve ? 'Approve ' : 'Reject ').$change->ref().': '.$change->title,
                'context' => $change->reason,
                // A rejection always carries a note, so only an approval can arrive without one.
                'outcome' => $note !== '' ? $note : 'Approved.',
            ]);
            $decision->forceFill(['project_id' => $change->project_id, 'number' => $this->next(ProjectDecision::class, $change->project), 'status' => 'decided', 'decided_by' => $by->id, 'decided_on' => Carbon::today(), 'created_by' => $by->id])->save();
            $change->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $by->id,
                'decided_at' => Carbon::now(),
                'decision_note' => $note !== '' ? $note : null,
                'self_approved' => $approve && $by->id === $change->requested_by,
                'decision_id' => $decision->id,
            ])->save();
            Activity::record($approve ? 'change.approved' : 'change.rejected', $change, ['decision_id' => $decision->id], $change->project);
            Activity::record('decision.recorded', $decision, ['change_id' => $change->id], $change->project);
        });

        if ($change->requested_by && $change->requested_by !== $by->id) {
            $change->requester?->notify(new GovernanceNotification($approve ? 'change_approved' : 'change_rejected', 'Change '.($approve ? 'approved' : 'rejected'), $by->name.($approve ? ' approved ' : ' rejected ').$change->ref().' "'.$change->title.'".', $change->project_id, $this->url($change)));
        }
    }

    public function withdraw(ProjectChange $change): void
    {
        if (! in_array($change->status, ['draft', 'submitted'], true)) {
            throw ValidationException::withMessages(['change' => 'A decided change cannot be withdrawn.']);
        }
        $from = $change->status;
        $change->forceFill(['status' => 'withdrawn'])->save();
        Activity::record('change.withdrawn', $change, ['status' => ['from' => $from, 'to' => 'withdrawn']], $change->project);
    }

    /** Takes a new baseline once an approved change is in the plan: later slippage is measured from it. */
    public function rebaseline(ProjectChange $change, User $by): void
    {
        if (! $change->accepted() || $change->baseline_id !== null) {
            throw ValidationException::withMessages(['change' => 'Only an approved change that has not re-baselined the plan can do so.']);
        }
        $baseline = app(ProjectWork::class)->setBaseline($change->project, $by, 'After '.$change->ref().': '.mb_strimwidth($change->title, 0, 200, '…'));
        $change->forceFill(['baseline_id' => $baseline->id])->save();
        Activity::record('change.rebaselined', $change, ['baseline' => $baseline->number], $change->project);
    }

    public function implement(ProjectChange $change): void
    {
        if ($change->status !== 'approved') {
            throw ValidationException::withMessages(['change' => 'Only an approved change can be marked implemented.']);
        }
        $change->forceFill(['status' => 'implemented', 'implemented_at' => Carbon::now()])->save();
        Activity::record('change.implemented', $change, ['status' => ['from' => 'approved', 'to' => 'implemented']], $change->project);
    }

    /**
     * Adds a decision: proposed (an owner decides by a date) or already decided (with its outcome).
     *
     * @param  array<string, mixed>  $data
     */
    public function addDecision(Project $project, User $by, array $data, bool $decidedAlready): ProjectDecision
    {
        $v = $this->validateDecision($project, $data, true);
        if ($decidedAlready && trim((string) ($v['outcome'] ?? '')) === '') {
            throw ValidationException::withMessages(['outcome' => 'Say what was decided.']);
        }

        return DB::transaction(function () use ($project, $by, $v, $decidedAlready) {
            $d = new ProjectDecision($v);
            $d->forceFill(['project_id' => $project->id, 'number' => $this->next(ProjectDecision::class, $project), 'status' => $decidedAlready ? 'decided' : 'proposed', 'created_by' => $by->id]
                + ($decidedAlready ? ['decided_by' => $by->id, 'decided_on' => Carbon::today()] : []))->save();
            Activity::record($decidedAlready ? 'decision.recorded' : 'decision.proposed', $d, [], $project);
            if (! $decidedAlready && $d->owner_id && $d->owner_id !== $by->id) {
                $d->owner?->notify(new GovernanceNotification('decision_owned', 'A decision is yours to make', $by->name.' asks you to decide '.$d->ref().' "'.$d->title.'"'.($d->decide_by ? ' by '.$d->decide_by->format('j M') : '').'.', $project->id, route('projects.decisions.show', [$project, $d])));
            }

            return $d;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDecision(ProjectDecision $d, array $data): void
    {
        if ($d->status !== 'proposed') {
            throw ValidationException::withMessages(['decision' => 'A decision on record is not edited. Record a new decision and mark this one superseded by it.']);
        }
        unset($data['outcome']);
        $d->fill($this->validateDecision($d->project, $data, false));
        if (! $d->isDirty()) {
            return;
        }
        $changes = [];
        foreach (['owner_id', 'decide_by'] as $f) {
            if ($d->isDirty($f)) {
                $o = $d->getOriginal($f);
                $n = $d->getAttribute($f);
                $changes[$f] = ['from' => $o instanceof Carbon ? $o->toDateString() : $o, 'to' => $n instanceof Carbon ? $n->toDateString() : $n];
            }
        }
        $edited = array_values(array_filter(['title', 'context', 'options', 'outcome'], fn ($f) => $d->isDirty($f)));
        if ($edited !== []) {
            $changes['fields'] = $edited;
        }
        $d->save();
        Activity::record('decision.updated', $d, $changes, $d->project);
    }

    public function decideDecision(ProjectDecision $d, User $by, string $outcome): void
    {
        if ($d->status !== 'proposed') {
            throw ValidationException::withMessages(['outcome' => 'This has already been decided.']);
        }
        $outcome = trim($outcome);
        if ($outcome === '' || mb_strlen($outcome) > 5000) {
            throw ValidationException::withMessages(['outcome' => 'Say what was decided, in up to 5000 characters.']);
        }
        $d->forceFill(['outcome' => $outcome, 'status' => 'decided', 'decided_by' => $by->id, 'decided_on' => Carbon::today()])->save();
        Activity::record('decision.decided', $d, ['status' => ['from' => 'proposed', 'to' => 'decided']], $d->project);
    }

    /** Marks a decision as replaced by a later one in the same project. */
    public function supersede(ProjectDecision $d, ?int $byDecisionId): void
    {
        $newer = ProjectDecision::query()->where('project_id', $d->project_id)->where('status', 'decided')->where('number', '>', $d->number)->find($byDecisionId);
        if ($d->status !== 'decided' || $newer === null) {
            throw ValidationException::withMessages(['supersede' => 'Choose the later, decided entry that replaces this one.']);
        }
        $d->forceFill(['status' => 'superseded', 'superseded_by' => $newer->id])->save();
        Activity::record('decision.superseded', $d, ['superseded_by' => $newer->id], $d->project);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateChange(array $data, bool $creating): array
    {
        return Validator::make($data, [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(array_keys(ProjectChange::KINDS))],
            'reason' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'schedule_days' => ['sometimes', 'nullable', 'integer', 'between:-3650,3650'],
            'cost_cents' => ['sometimes', 'nullable', 'integer', 'between:-100000000000,100000000000'],
            'scope_impact' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'resource_impact' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'risk_impact' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], ['schedule_days.between' => 'Give the schedule effect in days, up to ten years either way.'])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateDecision(Project $project, array $data, bool $creating): array
    {
        return Validator::make($data, [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'context' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'options' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::in($project->team->allUsers()->pluck('id')->map(fn ($id) => (int) $id)->all())],
            'decide_by' => ['sometimes', 'nullable', 'date'],
        ], ['owner_id.in' => 'Choose someone in this business.'])->validate();
    }

    /** @param class-string<ProjectChange|ProjectDecision> $model */
    private function next(string $model, Project $project): int
    {
        return (int) $model::query()->where('project_id', $project->id)->lockForUpdate()->max('number') + 1;
    }

    private function url(ProjectChange $change): string
    {
        return route('projects.changes.show', [$change->project_id, $change]);
    }
}
