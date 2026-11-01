<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectIssue;
use App\Models\ProjectRisk;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every write to the risk register, the issue log and the risk matrix. Each change is checked here
 * and recorded in the activity log with enumerated values only (ratings, statuses, ids, dates);
 * written text such as mitigation plans is logged by field name, never by content.
 */
final class Governance
{
    public const CATEGORIES = [
        'scope' => 'Scope', 'schedule' => 'Schedule', 'cost' => 'Cost', 'quality' => 'Quality',
        'resources' => 'People and resources', 'safety' => 'Health and safety', 'compliance' => 'Legal and compliance',
        'suppliers' => 'Suppliers', 'technical' => 'Technical', 'stakeholders' => 'Stakeholders', 'external' => 'External', 'other' => 'Other',
    ];

    /** Enumerated fields: logged with from and to. */
    private const RISK_LOGGED = ['category', 'owner_id', 'probability', 'impact', 'residual_probability', 'residual_impact', 'status', 'review_date'];

    private const RISK_TEXT = ['title', 'description', 'mitigation', 'contingency'];

    private const ISSUE_LOGGED = ['category', 'severity', 'priority', 'owner_id', 'status', 'target_date'];

    private const ISSUE_TEXT = ['title', 'description', 'root_cause', 'corrective_action', 'resolution'];

    /** @param array<string, mixed> $data */
    public function addRisk(Project $project, User $by, array $data): ProjectRisk
    {
        $matrix = RiskMatrix::forProject($project);
        $v = $this->validateRisk($project, $matrix, $data + ['category' => 'other'], true);

        $risk = DB::transaction(function () use ($project, $by, $v) {
            $risk = new ProjectRisk($v);
            $risk->forceFill(['project_id' => $project->id, 'number' => $this->next(ProjectRisk::class, $project), 'status' => 'open', 'created_by' => $by->id])->save();
            Activity::record('risk.added', $risk, ['probability' => $risk->probability, 'impact' => $risk->impact, 'category' => $risk->category], $project);

            return $risk;
        });
        $this->tellOwner($risk, $by, null);

        return $risk;
    }

    /** @param array<string, mixed> $data */
    public function updateRisk(ProjectRisk $risk, User $by, array $data): void
    {
        $matrix = RiskMatrix::forProject($risk->project);
        $v = $this->validateRisk($risk->project, $matrix, $data, false);
        if (($v['status'] ?? $risk->status) === 'occurred' && $risk->status !== 'occurred') {
            throw ValidationException::withMessages(['status' => 'Use "It happened" to record that a risk occurred; it opens an issue.']);
        }
        $rp = array_key_exists('residual_probability', $v) ? $v['residual_probability'] : $risk->residual_probability;
        $ri = array_key_exists('residual_impact', $v) ? $v['residual_impact'] : $risk->residual_impact;
        if (($rp === null) !== ($ri === null)) {
            throw ValidationException::withMessages(['residual' => 'Rate both probability and impact after mitigation, or neither.']);
        }

        $before = $risk->owner_id;
        $risk->fill($v);
        if ($risk->isDirty('status')) {
            $risk->closed_at = in_array($risk->status, ['closed', 'occurred'], true) ? Carbon::now() : null;
        }
        $changes = $this->changes($risk, self::RISK_LOGGED, self::RISK_TEXT);
        if ($changes === []) {
            return;
        }
        $risk->save();
        Activity::record('risk.updated', $risk, $changes, $risk->project);
        $this->tellOwner($risk, $by, $before);
    }

    /** The risk happened: it becomes an issue with the same owner and a severity from its rating. */
    public function riskOccurred(ProjectRisk $risk, User $by): ProjectIssue
    {
        if (! $risk->isLive()) {
            throw ValidationException::withMessages(['status' => 'Only a live risk can be recorded as having happened.']);
        }
        $band = $risk->band(RiskMatrix::forProject($risk->project));

        $issue = DB::transaction(function () use ($risk, $by, $band) {
            $issue = new ProjectIssue(['title' => $risk->title, 'description' => $risk->description, 'category' => $risk->category, 'severity' => $band, 'priority' => $band === 'critical' ? 'urgent' : ($band === 'low' ? 'medium' : $band), 'owner_id' => $risk->owner_id, 'status' => 'open', 'risk_id' => $risk->id]);
            $issue->forceFill(['project_id' => $risk->project_id, 'number' => $this->next(ProjectIssue::class, $risk->project), 'created_by' => $by->id])->save();
            $risk->forceFill(['status' => 'occurred', 'closed_at' => Carbon::now()])->save();
            Activity::record('risk.occurred', $risk, ['status' => ['from' => 'open', 'to' => 'occurred'], 'issue_id' => $issue->id], $risk->project);
            Activity::record('issue.added', $issue, ['severity' => $issue->severity, 'risk_id' => $risk->id], $risk->project);

            return $issue;
        });
        $this->tellOwner($issue, $by, null);

        return $issue;
    }

    /** @param array<string, mixed> $data */
    public function addIssue(Project $project, User $by, array $data): ProjectIssue
    {
        $v = $this->validateIssue($project, $data + ['category' => 'other', 'severity' => 'medium', 'priority' => 'medium'], true);

        $issue = DB::transaction(function () use ($project, $by, $v) {
            $issue = new ProjectIssue($v);
            $issue->forceFill(['project_id' => $project->id, 'number' => $this->next(ProjectIssue::class, $project), 'status' => 'open', 'created_by' => $by->id])->save();
            Activity::record('issue.added', $issue, ['severity' => $issue->severity], $project);

            return $issue;
        });
        $this->tellOwner($issue, $by, null);

        return $issue;
    }

    /** @param array<string, mixed> $data */
    public function updateIssue(ProjectIssue $issue, User $by, array $data): void
    {
        $v = $this->validateIssue($issue->project, $data, false);
        $before = $issue->owner_id;
        $issue->fill($v);
        if ($issue->isDirty('status')) {
            $done = in_array($issue->status, ['resolved', 'closed'], true);
            if ($done && trim((string) $issue->resolution) === '') {
                throw ValidationException::withMessages(['resolution' => 'Say how it was resolved before marking it resolved or closed.']);
            }
            $issue->resolved_at = $done ? ($issue->resolved_at ?? Carbon::now()) : null;
        }
        $changes = $this->changes($issue, self::ISSUE_LOGGED, self::ISSUE_TEXT);
        if ($changes === []) {
            return;
        }
        $issue->save();
        Activity::record('issue.updated', $issue, $changes, $issue->project);
        $this->tellOwner($issue, $by, $before);
    }

    /** Flags a risk or issue for the project owner's attention, with a reason they read. */
    public function escalate(ProjectRisk|ProjectIssue $item, User $by, string $note): void
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['escalation' => 'Say in up to 500 characters what you need from the project owner.']);
        }
        if ($item->escalated_at !== null) {
            throw ValidationException::withMessages(['escalation' => 'This is already escalated.']);
        }
        $item->forceFill(['escalated_at' => Carbon::now(), 'escalated_by' => $by->id, 'escalation_note' => $note])->save();
        $kind = $item instanceof ProjectRisk ? 'risk' : 'issue';
        Activity::record($kind.'.escalated', $item, [], $item->project);

        // Read fresh: the project's owner may have left the business since it was created.
        $owner = User::query()->find($item->project->owner_id);
        if ($owner !== null && $owner->id !== $by->id) {
            $owner->notify(new GovernanceNotification($kind.'_escalated', ucfirst($kind).' escalated to you', $by->name.' escalated '.$item->ref().' "'.$item->title.'" on "'.$item->project->name.'".', $item->project_id, $this->url($item)));
        }
    }

    public function withdrawEscalation(ProjectRisk|ProjectIssue $item): void
    {
        if ($item->escalated_at === null) {
            return;
        }
        $item->forceFill(['escalated_at' => null, 'escalated_by' => null, 'escalation_note' => null])->save();
        Activity::record(($item instanceof ProjectRisk ? 'risk' : 'issue').'.escalation_closed', $item, [], $item->project);
    }

    /**
     * Sets the project's matrix. A smaller matrix is refused while a risk is rated above its top
     * step, rather than quietly re-rating it.
     *
     * @param  array<string, mixed>  $raw
     */
    public function setMatrix(Project $project, array $raw): RiskMatrix
    {
        $matrix = RiskMatrix::fromArray($raw);
        $over = $project->risks()->where(fn ($q) => $q->where('probability', '>', $matrix->size)->orWhere('impact', '>', $matrix->size)
            ->orWhere('residual_probability', '>', $matrix->size)->orWhere('residual_impact', '>', $matrix->size))->count();
        if ($over > 0) {
            throw ValidationException::withMessages(['matrix' => $over.' '.str('risk')->plural($over).' '.($over === 1 ? 'is' : 'are').' rated above step '.$matrix->size.'. Re-rate '.($over === 1 ? 'it' : 'them').' first.']);
        }
        $old = RiskMatrix::forProject($project);
        $project->forceFill(['risk_matrix' => $matrix->toArray()])->save();
        Activity::record('matrix.changed', $project, ['size' => ['from' => $old->size, 'to' => $matrix->size]]);

        return $matrix;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateRisk(Project $project, RiskMatrix $matrix, array $data, bool $creating): array
    {
        $step = ['integer', 'between:1,'.$matrix->size];
        $req = $creating ? 'required' : 'sometimes';

        return Validator::make($data, [
            'title' => [$req, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category' => ['sometimes', Rule::in(array_keys(self::CATEGORIES))],
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::in($this->members($project))],
            'probability' => [$req, ...$step],
            'impact' => [$req, ...$step],
            'residual_probability' => ['sometimes', 'nullable', ...$step],
            'residual_impact' => ['sometimes', 'nullable', ...$step],
            'mitigation' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'contingency' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(array_keys(ProjectRisk::STATUSES))],
            'review_date' => ['sometimes', 'nullable', 'date'],
        ], [
            'owner_id.in' => 'Choose someone in this business.',
            'probability.between' => 'Choose a probability on the matrix.',
            'impact.between' => 'Choose an impact on the matrix.',
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateIssue(Project $project, array $data, bool $creating): array
    {
        return Validator::make($data, [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category' => ['sometimes', Rule::in(array_keys(self::CATEGORIES))],
            'severity' => ['sometimes', Rule::in(array_keys(ProjectIssue::SEVERITIES))],
            'priority' => ['sometimes', Rule::in(ProjectWork::PRIORITIES)],
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::in($this->members($project))],
            'status' => ['sometimes', Rule::in(array_keys(ProjectIssue::STATUSES))],
            'root_cause' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'corrective_action' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_date' => ['sometimes', 'nullable', 'date'],
        ], ['owner_id.in' => 'Choose someone in this business.'])->validate();
    }

    /** @return list<int> */
    private function members(Project $project): array
    {
        return $project->team->allUsers()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @param class-string<ProjectRisk|ProjectIssue> $model */
    private function next(string $model, Project $project): int
    {
        return (int) $model::query()->where('project_id', $project->id)->lockForUpdate()->max('number') + 1;
    }

    /**
     * @param  list<string>  $logged
     * @param  list<string>  $text
     * @return array<string, mixed>
     */
    private function changes(ProjectRisk|ProjectIssue $item, array $logged, array $text): array
    {
        $changes = [];
        foreach ($logged as $f) {
            if ($item->isDirty($f)) {
                $from = $item->getOriginal($f);
                $to = $item->getAttribute($f);
                $changes[$f] = ['from' => $from instanceof Carbon ? $from->toDateString() : $from, 'to' => $to instanceof Carbon ? $to->toDateString() : $to];
            }
        }
        $edited = array_values(array_filter($text, fn ($f) => $item->isDirty($f)));
        if ($edited !== []) {
            $changes['fields'] = $edited;
        }

        return $changes;
    }

    private function tellOwner(ProjectRisk|ProjectIssue $item, User $by, ?int $before): void
    {
        if ($item->owner_id === null || $item->owner_id === $before || $item->owner_id === $by->id) {
            return;
        }
        $kind = $item instanceof ProjectRisk ? 'risk' : 'issue';
        $item->owner?->notify(new GovernanceNotification($kind.'_assigned', ucfirst($kind).' given to you', 'You own '.$item->ref().' "'.$item->title.'" on "'.$item->project->name.'".', $item->project_id, $this->url($item)));
    }

    private function url(ProjectRisk|ProjectIssue $item): string
    {
        return $item instanceof ProjectRisk ? route('projects.risks.show', [$item->project_id, $item]) : route('projects.issues.show', [$item->project_id, $item]);
    }
}
