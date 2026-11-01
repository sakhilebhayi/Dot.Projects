<?php

namespace App\Services;

use App\Models\GateCheck;
use App\Models\Project;
use App\Models\ProjectDecision;
use App\Models\ProjectGate;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Stage gates and their governance checklists. A gate is prepared (its checklist ticked) by the
 * team and decided by its named approver: passed only when every check is met, passed with
 * conditions (written) when some are not, or held (with a reason) until it is put forward again.
 * Every decision is also written to the decision register.
 */
final class Gates
{
    /** Starting checklists; a gate's checks are its own once added and can be edited freely. */
    public const TEMPLATES = [
        'start' => ['title' => 'Ready to start', 'checks' => ['The business case or reason for the project is agreed', 'A sponsor is named', 'Scope and objectives are written down', 'Money for planning is approved']],
        'execute' => ['title' => 'Ready to execute', 'checks' => ['The schedule is baselined', 'The budget is approved', 'Risks are assessed and each has an owner', 'People and suppliers are confirmed', 'Stakeholders know the plan']],
        'handover' => ['title' => 'Ready to hand over', 'checks' => ['The client has accepted the deliverables', 'Open issues are closed or handed on', 'Documentation is handed over', 'Users are trained']],
        'close' => ['title' => 'Ready to close', 'checks' => ['Final costs are reconciled', 'Lessons learned are recorded', 'Contracts are closed', 'The team is released']],
    ];

    /** @param array<string, mixed> $data */
    public function add(Project $project, User $by, array $data, ?string $template): ProjectGate
    {
        $v = $this->validate($project, $data, true);
        $checks = $template !== null && isset(self::TEMPLATES[$template]) ? self::TEMPLATES[$template]['checks'] : [];

        $gate = DB::transaction(function () use ($project, $by, $v, $checks) {
            $gate = new ProjectGate($v);
            $gate->forceFill(['project_id' => $project->id, 'number' => (int) ProjectGate::query()->where('project_id', $project->id)->lockForUpdate()->max('number') + 1, 'status' => 'pending', 'created_by' => $by->id])->save();
            foreach ($checks as $i => $body) {
                $gate->checks()->create(['body' => $body, 'sort_order' => $i + 1]);
            }
            Activity::record('gate.added', $gate, ['checks' => count($checks)], $project);

            return $gate;
        });
        $this->tellApprover($gate, $by, null);

        return $gate;
    }

    /** @param array<string, mixed> $data */
    public function update(ProjectGate $gate, User $by, array $data): void
    {
        $this->assertOpen($gate);
        $before = $gate->approver_id;
        $gate->fill($this->validate($gate->project, $data, false));
        if (! $gate->isDirty()) {
            return;
        }
        $changes = [];
        foreach (['milestone_id', 'due_date', 'approver_id'] as $f) {
            if ($gate->isDirty($f)) {
                $o = $gate->getOriginal($f);
                $n = $gate->getAttribute($f);
                $changes[$f] = ['from' => $o instanceof Carbon ? $o->toDateString() : $o, 'to' => $n instanceof Carbon ? $n->toDateString() : $n];
            }
        }
        $edited = array_values(array_filter(['title', 'purpose'], fn ($f) => $gate->isDirty($f)));
        if ($edited !== []) {
            $changes['fields'] = $edited;
        }
        $gate->save();
        Activity::record('gate.updated', $gate, $changes, $gate->project);
        $this->tellApprover($gate, $by, $before);
    }

    public function addCheck(ProjectGate $gate, string $body): GateCheck
    {
        $this->assertOpen($gate);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 255) {
            throw ValidationException::withMessages(['check' => 'Write the check in up to 255 characters.']);
        }
        $check = $gate->checks()->create(['body' => $body, 'sort_order' => (int) $gate->checks()->max('sort_order') + 1]);
        Activity::record('gate.check_added', $gate, [], $gate->project);

        return $check;
    }

    public function toggleCheck(GateCheck $check, User $by): void
    {
        $this->assertOpen($check->gate);
        $done = $check->done_at === null;
        $check->forceFill(['done_at' => $done ? Carbon::now() : null, 'done_by' => $done ? $by->id : null])->save();
        Activity::record($done ? 'gate.check_ticked' : 'gate.check_unticked', $check->gate, [], $check->gate->project);
    }

    public function removeCheck(GateCheck $check): void
    {
        $this->assertOpen($check->gate);
        $gate = $check->gate;
        $check->delete();
        Activity::record('gate.check_removed', $gate, [], $gate->project);
    }

    /**
     * The approver's decision: passed (every check met), conditional (conditions written) or held
     * (a reason written). Recorded in the decision register.
     */
    public function decide(ProjectGate $gate, User $by, string $outcome, ?string $note): void
    {
        if ($gate->status !== 'pending') {
            throw ValidationException::withMessages(['decision' => 'Only a gate waiting for a decision can be decided.']);
        }
        $note = trim((string) $note);
        $open = $gate->checks()->whereNull('done_at')->count();
        $error = match (true) {
            ! in_array($outcome, ['passed', 'conditional', 'held'], true) => 'Choose pass, pass with conditions or hold.',
            $outcome === 'passed' && $open > 0 => $open.' '.str('check')->plural($open).' '.($open === 1 ? 'is' : 'are').' not met. Pass with conditions, or hold the gate.',
            $outcome === 'conditional' && $note === '' => 'Write the conditions the project must still meet.',
            $outcome === 'held' && $note === '' => 'Say why the gate is held and what would let it pass.',
            mb_strlen($note) > 5000 => 'Keep the note under 5000 characters.',
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['decision' => $error]);
        }

        DB::transaction(function () use ($gate, $by, $outcome, $note) {
            $verb = ['passed' => 'passed', 'conditional' => 'passed with conditions', 'held' => 'held'][$outcome];
            $decision = new ProjectDecision(['title' => $gate->ref().' '.$gate->title.' '.$verb, 'context' => $gate->purpose, 'outcome' => $note !== '' ? $note : 'Every check met; passed.']);
            $decision->forceFill(['project_id' => $gate->project_id, 'number' => (int) ProjectDecision::query()->where('project_id', $gate->project_id)->lockForUpdate()->max('number') + 1, 'status' => 'decided', 'decided_by' => $by->id, 'decided_on' => Carbon::today(), 'created_by' => $by->id])->save();
            $gate->forceFill(['status' => $outcome, 'decision_note' => $note !== '' ? $note : null, 'decided_by' => $by->id, 'decided_at' => Carbon::now(), 'decision_id' => $decision->id])->save();
            Activity::record('gate.'.$outcome, $gate, ['decision_id' => $decision->id], $gate->project);
            Activity::record('decision.recorded', $decision, ['gate_id' => $gate->id], $gate->project);
        });
    }

    /** A held gate is put forward again once its reasons are dealt with. */
    public function reopen(ProjectGate $gate): void
    {
        if ($gate->status !== 'held') {
            throw ValidationException::withMessages(['decision' => 'Only a held gate is put forward again.']);
        }
        $gate->forceFill(['status' => 'pending', 'decided_by' => null, 'decided_at' => null])->save();
        Activity::record('gate.reopened', $gate, ['status' => ['from' => 'held', 'to' => 'pending']], $gate->project);
    }

    private function assertOpen(ProjectGate $gate): void
    {
        if (! $gate->open()) {
            throw ValidationException::withMessages(['gate' => 'This gate has been passed; its record no longer changes.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(Project $project, array $data, bool $creating): array
    {
        $approvers = app(ChangeControl::class)->approvers($project)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return Validator::make($data, [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'milestone_id' => ['sometimes', 'nullable', 'integer', Rule::in($project->milestones()->pluck('id')->map(fn ($id) => (int) $id)->all())],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'approver_id' => ['sometimes', 'nullable', 'integer', Rule::in($approvers)],
        ], ['approver_id.in' => 'Choose someone in this business who can change projects.', 'milestone_id.in' => 'Choose a milestone of this project.'])->validate();
    }

    private function tellApprover(ProjectGate $gate, User $by, ?int $before): void
    {
        if ($gate->approver_id === null || $gate->approver_id === $before || $gate->approver_id === $by->id) {
            return;
        }
        $gate->approver?->notify(new GovernanceNotification('gate_approver', 'A gate is yours to decide', $by->name.' named you to decide '.$gate->ref().' "'.$gate->title.'" on "'.$gate->project->name.'".', $gate->project_id, route('projects.gates.show', [$gate->project_id, $gate])));
    }
}
