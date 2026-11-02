<?php

namespace App\Services;

use App\Models\Activity as ActivityLine;
use App\Models\AutomationApproval;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectChange;
use App\Models\ProjectGate;
use App\Models\ProjectIssue;
use App\Models\ProjectRisk;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The automation engine. Every activity line is an event: once its transaction has committed,
 * the business's active rules for that project are matched against it, and each rule that matches
 * runs its actions in order, as the person who made the rule.
 *
 * Rules (documented in docs/programme/R34_AUDIT.md):
 *  - Idempotent: a run's key is unique per rule. Most rules key on the event, so an event runs a
 *    rule once; "a risk is rated", "a milestone is completed", "someone is added" and "spending
 *    and commitments reach" key on the risk, milestone, person or share of the budget, so they
 *    run once for each, however often the record changes afterwards. Spending is actual cost
 *    plus open commitments, against the current budget.
 *  - No chains: what an automation does is recorded (attributed to the rule, with no person) but
 *    never triggers another automation, so rules cannot loop.
 *  - Failure handling: each action is a step; a failing step is recorded with its reason and the
 *    rest still run. A retry repeats only the steps that did not finish.
 *  - Notices go only to people who can see the project, never to the person whose action set the
 *    rule off, and say which rule sent them.
 */
final class Automations
{
    private static ?int $running = null;

    /** The rule being run now, if any. Activity::record attributes lines to it. */
    public static function running(): ?int
    {
        return self::$running;
    }

    /** Called for every activity line; matches rules after the change has been committed. */
    public static function queue(ActivityLine $line): void
    {
        if (self::$running !== null || $line->project_id === null) {
            return;
        }
        DB::afterCommit(function () use ($line) {
            if (self::$running === null) {
                app(self::class)->handle($line);
            }
        });
    }

    public function handle(ActivityLine $line): void
    {
        $rules = AutomationRule::query()->where('team_id', $line->team_id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $line->project_id))->orderBy('id')->get();
        foreach ($rules as $rule) {
            // A rule must never break the change that set it off: anything unexpected is reported
            // and the run (if one was written) stays failed, to be retried.
            try {
                $ctx = $this->match($rule, $line);
                if ($ctx !== null) {
                    $this->fire($rule, $ctx, $line->id);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** The events each trigger listens to. @var array<string, list<string>> */
    public const EVENTS = [
        'task_created' => ['task.created'],
        'task_status' => ['task.moved', 'task.updated'],
        'task_assigned' => ['task.assigned', 'task.updated'],
        'task_blocked' => ['dependency.added'],
        'milestone_completed' => ['milestone.status_changed'],
        'risk_rated' => ['risk.added', 'risk.updated'],
        'issue_logged' => ['issue.added'],
        'member_added' => ['member.added'],
        'gate_passed' => ['gate.passed', 'gate.conditional'],
        'project_status' => ['project.updated'],
        'change_submitted' => ['change.submitted'],
        'budget_threshold' => ['cost.actual_added', 'cost.commitment_added', 'cost.actual_removed', 'cost.commitment_removed', 'budget.line_added', 'budget.revised', 'budget.forecast_set'],
    ];

    /**
     * Whether a line sets the rule off, and if so what about: the project, the record, the person
     * it concerns, the run's key and a sentence saying what happened. Conditions are checked here.
     *
     * @return array{project: Project, subject: Model, person: User|null, key: string, what: string}|null
     */
    public function match(AutomationRule $rule, ActivityLine $line): ?array
    {
        if (! in_array($line->action, self::EVENTS[$rule->trigger] ?? [], true)) {
            return null;
        }
        $project = Project::withoutTeamScope(fn () => Project::query()->with('team')->find($line->project_id));
        if ($project === null || $project->isArchived() || ($rule->project_id !== null && $rule->project_id !== $project->id)) {
            return null;
        }
        $c = $line->changes ?? [];
        $p = $rule->params;
        $byEvent = 'a:'.$line->id;
        $find = fn (string $class) => $line->subject_type === (new $class)->getMorphClass() ? $class::query()->find($line->subject_id) : null;
        $user = fn ($id) => $id ? User::query()->find((int) $id) : null;

        $ctx = match ($rule->trigger) {
            'task_created', 'task_status', 'task_assigned', 'task_blocked' => (function () use ($rule, $find, $c, $p, $byEvent, $user) {
                /** @var ProjectTask|null $task */
                $task = $find(ProjectTask::class);
                if ($task === null) {
                    return null;
                }
                $name = '“'.$task->title.'”';

                return match ($rule->trigger) {
                    'task_created' => [$task, $user($task->assignee_id), 'task:'.$task->id, 'Task '.$name.' was added'],
                    'task_status' => ($c['status']['to'] ?? null) === ($p['status'] ?? '') ? [$task, $user($task->assignee_id), $byEvent, 'Task '.$name.' moved to '.(ProjectSummary::STATUSES[$p['status']] ?? $p['status'])] : null,
                    'task_assigned' => ($c['assignee_id']['to'] ?? null) ? [$task, $user($c['assignee_id']['to']), $byEvent, 'Task '.$name.' was given to '.($user($c['assignee_id']['to'])->name ?? 'someone')] : null,
                    default => $task->isBlocked() ? [$task, $user($task->assignee_id), $byEvent, 'Task '.$name.' now waits on unfinished work'] : null, // task_blocked
                };
            })(),
            'milestone_completed' => ($c['status']['to'] ?? null) === 'completed' && ($m = $find(Milestone::class)) ? [$m, null, 'milestone:'.$m->id, 'Milestone “'.$m->title.'” is complete'] : null,
            'risk_rated' => (function () use ($find, $p, $user) {
                /** @var ProjectRisk|null $risk */
                $risk = $find(ProjectRisk::class);
                if ($risk === null || ! $risk->isLive()) {
                    return null;
                }
                $band = $risk->band(RiskMatrix::forProject($risk->project));
                $ok = $p['band'] === 'critical' ? $band === 'critical' : in_array($band, ['high', 'critical'], true);

                return $ok ? [$risk, $user($risk->owner_id), 'risk:'.$risk->id, 'Risk '.$risk->ref().' “'.$risk->title.'” is rated '.$band] : null;
            })(),
            'issue_logged' => ($i = $find(ProjectIssue::class)) ? [$i, $user($i->owner_id), 'issue:'.$i->id, 'Issue '.$i->ref().' “'.$i->title.'” was logged ('.mb_strtolower(ProjectIssue::SEVERITIES[$i->severity] ?? $i->severity).')'] : null,
            'member_added' => ($who = $user($c['user_id'] ?? null)) ? [$project, $who, 'member:'.$who->id, $who->name.' was added to the project as '.mb_strtolower(Project::ROLES[$c['role'] ?? ''] ?? 'a member')] : null,
            'gate_passed' => ($g = $find(ProjectGate::class)) ? [$g, null, 'gate:'.$g->id, 'Stage gate '.$g->ref().' “'.$g->title.'” was passed'.($line->action === 'gate.conditional' ? ' with conditions' : '')] : null,
            'project_status' => ($c['status']['to'] ?? null) === ($p['project_status'] ?? '') ? [$project, null, $byEvent, 'The project is now '.mb_strtolower(AutomationCatalog::PARAMS['project_status'][$p['project_status']] ?? '')] : null,
            'change_submitted' => ($ch = $find(ProjectChange::class)) ? [$ch, $user($ch->requested_by), $byEvent, 'Change request '.$ch->ref().' “'.$ch->title.'” was submitted for approval'] : null,
            'budget_threshold' => (function () use ($project, $p) {
                if (! $project->budgetLines()->exists()) {
                    return null;
                }
                // Spent and committed, not the forecast: an unspent line forecasts its whole budget.
                $t = app(CostLedger::class)->ledger($project)['total'];
                $share = $t['budget'] > 0 ? (int) floor(($t['actual'] + $t['open']) * 100 / $t['budget']) : 0;

                return $t['budget'] > 0 && $share >= (int) $p['percent'] ? [$project, null, 'budget:'.$p['percent'], 'Spending and commitments have reached '.$share.'% of the budget'] : null;
            })(),
            default => null,
        };
        if ($ctx === null) {
            return null;
        }
        [$subject, $person, $key, $what] = $ctx;

        return $this->passes($rule, $subject, $c['role'] ?? null) ? ['project' => $project, 'subject' => $subject, 'person' => $person, 'key' => $key, 'what' => $what] : null;
    }

    /** Whether the record meets every condition of the rule. */
    private function passes(AutomationRule $rule, Model $subject, ?string $role): bool
    {
        foreach ($rule->conditions as $cond) {
            $value = $cond['field'] === 'role' ? $role : $subject->getAttribute($cond['field']);
            if (! in_array((string) $value, $cond['values'], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array{project: Project, subject: Model, person: User|null, key: string, what: string}  $ctx */
    private function fire(AutomationRule $rule, array $ctx, ?int $activityId): bool
    {
        $run = new AutomationRun;
        $run->forceFill([
            'rule_id' => $rule->id, 'team_id' => $rule->team_id, 'project_id' => $ctx['project']->id, 'activity_id' => $activityId, 'key' => $ctx['key'],
            'subject_type' => $ctx['subject']->getMorphClass(), 'subject_id' => $ctx['subject']->getKey(), 'what' => mb_substr($ctx['what'], 0, 300), 'status' => 'failed', 'steps' => [],
        ]);
        try {
            $run->save();
        } catch (UniqueConstraintViolationException) {
            return false; // it already ran for this event or record
        }
        $this->execute($rule, $run, $ctx);

        return true;
    }

    /**
     * Runs the rule's actions for a run, skipping steps that already finished.
     *
     * @param  array{project: Project, subject: Model, person: User|null, key: string, what: string}  $ctx
     */
    private function execute(AutomationRule $rule, AutomationRun $run, array $ctx): void
    {
        $by = $rule->created_by ? User::query()->find($rule->created_by) : null;
        $steps = $run->steps;
        if ($by === null || ! $by->belongsToTeam($ctx['project']->team)) {
            $run->forceFill(['status' => 'failed', 'error' => 'The person who made this rule is no longer in the business. Someone who manages the project should make it again.'])->save();

            return;
        }
        $previous = self::$running;
        self::$running = $rule->id;
        $waitUntil = null;
        $awaiting = false;
        try {
            foreach ($rule->actions as $i => $action) {
                if (($steps[$i]['ok'] ?? false) === true) {
                    continue;
                }
                $skip = $this->guard($rule, $i, $action, $steps, $ctx);
                if ($skip !== null) {
                    $steps[$i] = ['action' => $action['type'], 'ok' => true, 'did' => false, 'summary' => $skip];

                    continue;
                }
                if ($action['type'] === 'approval') {
                    try {
                        $steps[$i] = ['action' => 'approval', 'ok' => true, 'did' => true, 'pending' => true, 'summary' => $this->askApproval($action, $rule, $ctx, $run, $i)];
                        $awaiting = true;
                    } catch (Throwable $e) {
                        $steps[$i] = ['action' => 'approval', 'ok' => false, 'did' => false, 'summary' => $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : 'Something went wrong; the step can be retried.'];
                        if (! $e instanceof ValidationException) {
                            report($e);
                        }
                    }
                    break;
                }
                if ($action['type'] === 'wait') {
                    $waitUntil = Carbon::now()->addDays((int) $action['days']);
                    $steps[$i] = ['action' => 'wait', 'ok' => true, 'did' => false, 'summary' => 'Wait until '.$waitUntil->format('j M, H:i').' UTC'];
                    break;
                }
                try {
                    // Outside the scopes: the sweep runs with nobody signed in, and the engine checks
                    // who may change the project and who may see it itself (act(), recipients()).
                    [$summary, $did] = Project::withoutTeamScope(fn () => DB::transaction(fn () => $this->act($action, $rule, $ctx, $by)));
                    $steps[$i] = ['action' => $action['type'], 'ok' => true, 'did' => $did, 'summary' => $summary];
                } catch (Throwable $e) {
                    $why = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : 'Something went wrong; the step can be retried.';
                    $steps[$i] = ['action' => $action['type'], 'ok' => false, 'did' => false, 'summary' => $why];
                    if (! $e instanceof ValidationException) {
                        report($e);
                    }
                }
            }
        } finally {
            self::$running = $previous;
        }
        ksort($steps);
        $failed = collect($steps)->first(fn ($s) => ! $s['ok']);
        $run->forceFill([
            'steps' => array_values($steps),
            'status' => $failed ? 'failed' : ($awaiting ? 'awaiting' : ($waitUntil ? 'waiting' : (collect($steps)->contains('did', true) ? 'done' : 'skipped'))),
            'error' => $failed ? mb_substr($failed['summary'], 0, 500) : null,
            'resume_at' => $failed ? null : $waitUntil,
        ])->save();
    }

    /**
     * Carries on a waiting run once its wait is over: unless the rule was switched off, what it
     * was about was deleted, or (when the wait says so) it no longer applies.
     */
    private function resume(AutomationRun $run): void
    {
        $rule = $run->rule;
        $ctx = $rule->active ? $this->rebuild($run) : null;
        $waits = array_keys(array_filter($rule->actions, fn ($a) => $a['type'] === 'wait'));
        $lastWait = collect($waits)->filter(fn ($i) => ($run->steps[$i]['ok'] ?? false) === true)->last();
        $why = match (true) {
            ! $rule->active => 'Stopped after the wait: the rule was switched off.',
            $ctx === null => 'Stopped after the wait: what it was about has been deleted.',
            $lastWait !== null && ($rule->actions[$lastWait]['recheck'] ?? false) && ! $this->stillApplies($rule, $ctx['subject']) => 'Stopped after the wait: it no longer applies.',
            default => null,
        };
        if ($why !== null) {
            $steps = $run->steps;
            $steps[] = ['action' => 'stop', 'ok' => true, 'did' => false, 'summary' => $why];
            $run->forceFill(['steps' => $steps, 'resume_at' => null, 'status' => collect($steps)->contains('did', true) ? 'done' : 'skipped'])->save();

            return;
        }
        /** @var array{project: Project, subject: Model, person: User|null, key: string, what: string} $ctx */
        $run->forceFill(['resume_at' => null])->save();
        $this->execute($rule, $run, $ctx);
    }

    /**
     * Why a step is skipped, or null when it runs: its branch after the rule's approval (only if
     * approved, by default) and its own condition, read from the record as it is now.
     *
     * @param  array<string, mixed>  $action
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array{project: Project, subject: Model, person: User|null, key: string, what: string}  $ctx
     */
    private function guard(AutomationRule $rule, int $i, array $action, array $steps, array $ctx): ?string
    {
        $when = $action['when'] ?? [];
        $asked = collect($rule->actions)->search(fn ($a) => $a['type'] === 'approval');
        if ($asked !== false && $i > $asked) {
            $want = $when['approval'] ?? 'approved';
            $decision = $steps[$asked]['decision'] ?? null;
            if ($want !== 'any' && $decision !== $want) {
                return $decision === 'approved' ? 'Skipped: it was approved' : 'Skipped: it was not approved';
            }
        }
        if (! empty($when['field'])) {
            $subject = Project::withoutTeamScope(fn () => $ctx['subject']->fresh()) ?? $ctx['subject'];
            $value = (string) $subject->getAttribute($when['field']['field']);
            if (! in_array($value, $when['field']['values'], true)) {
                $subjectKind = AutomationCatalog::TRIGGERS[$rule->trigger]['subject'] ?? '';
                $label = AutomationCatalog::CONDITIONS[$subjectKind][$when['field']['field']][0] ?? $when['field']['field'];

                return 'Skipped: '.$label.' '.(AutomationCatalog::CONDITIONS[$subjectKind][$when['field']['field']][1][$value] ?? $value);
            }
        }

        return null;
    }

    /**
     * Writes the approval requests for a step and tells the people asked. Throws when nobody who
     * can see the project could answer.
     *
     * @param  array<string, mixed>  $action
     * @param  array{project: Project, subject: Model, person: User|null, key: string, what: string}  $ctx
     */
    private function askApproval(array $action, AutomationRule $rule, array $ctx, AutomationRun $run, int $step): string
    {
        $project = $ctx['project'];
        $ids = match ($action['approver']) {
            'project_owner' => [$project->owner_id],
            'project_managers' => DB::table('project_members')->where('project_id', $project->id)->where('role', 'manager')->pluck('user_id')->all(),
            default => [$project->team->user_id],
        };
        $people = User::query()->whereIn('id', array_filter($ids))->orderBy('name')->get()->filter(fn (User $u) => $project->canBeSeenBy($u))->values()->all();
        if ($people === []) {
            throw ValidationException::withMessages(['step' => 'Nobody could answer the approval: '.AutomationCatalog::APPROVERS[$action['approver']].' is not set or cannot see the project.']);
        }
        $expires = Carbon::now()->addDays((int) $action['expire_days']);
        foreach ($people as $u) {
            AutomationApproval::query()->firstOrCreate(['run_id' => $run->id, 'step' => $step, 'user_id' => $u->id], ['status' => 'pending', 'expires_at' => $expires]);
            $u->notify(new GovernanceNotification('automation_approval', 'Approval needed: '.$rule->name, $ctx['what'].' on “'.$project->name.'”. '.$action['question'], $project->id, route('automations.index', ['approvals' => 1])));
        }

        return 'Asked '.self::names($people).' to approve, by '.$expires->format('j M');
    }

    /** Answers an approval step for everyone asked, and carries the run on down the chosen branch. */
    public function decide(AutomationApproval $request, User $by, bool $approve, ?string $reason): void
    {
        $reason = trim((string) $reason);
        $all = AutomationApproval::query()->where('run_id', $request->run_id)->where('step', $request->step)->get();
        if (! $all->contains('user_id', $by->id)) {
            throw ValidationException::withMessages(['approval' => 'You were not asked to approve this.']);
        }
        if ($all->contains(fn ($a) => $a->status !== 'pending')) {
            throw ValidationException::withMessages(['approval' => 'This has already been answered.']);
        }
        if (! $approve && $reason === '') {
            throw ValidationException::withMessages(['approval' => 'Say why you are not approving it, so the project knows what to do next.']);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['approval' => 'Keep the reason under 500 characters.']);
        }
        $run = $request->run;
        DB::transaction(function () use ($request, $by, $approve, $reason, $run) {
            AutomationApproval::query()->where('run_id', $request->run_id)->where('step', $request->step)
                ->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by->id, 'decided_at' => Carbon::now(), 'reason' => $reason !== '' ? $reason : null]);
            $project = Project::withoutTeamScope(fn () => Project::query()->find($run->project_id));
            if ($project !== null) {
                Activity::record('automation.'.($approve ? 'approved' : 'rejected'), $run, ['automation_rule' => $run->rule_id], $project);
            }
        });
        $this->carryOn($run, $request->step, $approve ? 'approved' : 'rejected', ($approve ? 'approved' : 'not approved').' by '.$by->name.($reason !== '' ? ': '.$reason : ''));
    }

    /** Records the answer on the run and runs the steps after the approval. */
    private function carryOn(AutomationRun $run, int $step, string $decision, string $note): void
    {
        $run->refresh();
        $steps = $run->steps;
        $steps[$step] = ['decision' => $decision, 'pending' => false, 'summary' => ($steps[$step]['summary'] ?? 'Asked for approval').'; '.$note] + ($steps[$step] ?? []);
        $run->forceFill(['steps' => $steps])->save();
        $rule = $run->rule;
        $ctx = $rule->active ? $this->rebuild($run) : null;
        if ($ctx === null) {
            $steps[] = ['action' => 'stop', 'ok' => true, 'did' => false, 'summary' => $rule->active ? 'Stopped: what it was about has been deleted.' : 'Stopped: the rule was switched off.'];
            $run->forceFill(['steps' => $steps, 'status' => 'done'])->save();

            return;
        }
        $this->execute($rule, $run, $ctx);
    }

    /** Approval requests nobody answered in time count as not approved; the run carries on. */
    private function expireApprovals(Carbon $now): int
    {
        $due = AutomationApproval::query()->where('status', 'pending')->where('expires_at', '<=', $now)->get()->unique(fn ($a) => $a->run_id.':'.$a->step);
        foreach ($due as $a) {
            AutomationApproval::query()->where('run_id', $a->run_id)->where('step', $a->step)->update(['status' => 'expired', 'decided_at' => $now]);
            $this->carryOn($a->run, $a->step, 'rejected', 'no answer by '.$a->expires_at->format('j M').', so treated as not approved');
        }

        return $due->count();
    }

    /** Whether what set the rule off is still so, for a wait that stops when it is not. */
    private function stillApplies(AutomationRule $rule, Model $s): bool
    {
        $today = Carbon::today();

        return match (true) {
            $s instanceof ProjectTask => match ($rule->trigger) {
                'task_status' => $s->status === ($rule->params['status'] ?? ''),
                'task_blocked' => $s->isBlocked(),
                'task_overdue' => $s->status !== 'done' && $s->due_date !== null && $s->due_date->lt($today),
                default => $s->status !== 'done',
            },
            $s instanceof Milestone => $s->status !== 'completed',
            $s instanceof ProjectRisk => $s->isLive() && ($rule->trigger !== 'risk_review_due' || ($s->review_date !== null && $s->review_date->lte($today))),
            $s instanceof ProjectIssue => $s->isOpen(),
            $s instanceof Project => ! $s->isArchived() && $s->status !== 'completed',
            default => true,
        };
    }

    /** The hours (UTC) the sweep acts in: 07:00 to 19:00 in South Africa, so nobody is woken by a rule. */
    public const DAY = [5, 17];

    /**
     * The hourly sweep: dated rules act on what is due or overdue today, and waiting runs whose
     * wait is over carry on. Both only in working hours (DAY); everything is keyed, so running the
     * sweep again never repeats a run.
     *
     * @return array{fired: int, resumed: int}
     */
    public function sweep(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $out = ['fired' => 0, 'resumed' => 0];
        if ($now->hour < self::DAY[0] || $now->hour >= self::DAY[1]) {
            return $out;
        }
        foreach (AutomationRule::query()->where('active', true)->whereIn('trigger', AutomationCatalog::DATED)->orderBy('id')->get() as $rule) {
            try {
                foreach ($this->candidates($rule, $now->copy()->startOfDay()) as $ctx) {
                    $out['fired'] += $this->fire($rule, $ctx, null) ? 1 : 0;
                }
            } catch (Throwable $e) {
                report($e); // one broken rule does not stop the others
            }
        }
        foreach (AutomationRun::query()->where('status', 'waiting')->where('resume_at', '<=', $now)->with('rule')->orderBy('id')->get() as $run) {
            $this->resume($run);
            $out['resumed']++;
        }
        $out['resumed'] += $this->expireApprovals($now);

        return $out;
    }

    /**
     * What a dated rule acts on today, each with the key that makes it act once: once per task
     * and due date (moving the date re-arms it), per milestone and date, per risk and review date,
     * per project and due date, and per project and week for weekly rules.
     *
     * @return list<array{project: Project, subject: Model, person: User|null, key: string, what: string}>
     */
    public function candidates(AutomationRule $rule, Carbon $today): array
    {
        $projects = Project::withoutTeamScope(fn () => Project::query()->with('team')->where('team_id', $rule->team_id)->whereNull('archived_at')->where('status', '!=', 'completed')
            ->when($rule->project_id !== null, fn ($q) => $q->whereKey($rule->project_id))->orderBy('id')->get());
        $p = $rule->params;
        $d = $today->toDateString();
        $until = fn (string $param) => $today->copy()->addDays((int) ($p[$param] ?? 0))->toDateString();
        $user = fn ($id) => $id ? User::query()->find((int) $id) : null;
        $out = [];
        foreach ($projects as $project) {
            $items = match ($rule->trigger) {
                'task_overdue' => $project->tasks()->where('status', '!=', 'done')->whereNotNull('due_date')->whereDate('due_date', '<', $d)->orderBy('due_date')->get()
                    ->map(fn (ProjectTask $t) => [$t, $user($t->assignee_id), 'overdue:'.$t->id.':'.$t->due_date->toDateString(), 'Task “'.$t->title.'” is overdue (it was due '.$t->due_date->format('j M').')']),
                'task_due_soon' => $project->tasks()->where('status', '!=', 'done')->whereNotNull('due_date')->whereDate('due_date', '>=', $d)->whereDate('due_date', '<=', $until('days'))->orderBy('due_date')->get()
                    ->map(fn (ProjectTask $t) => [$t, $user($t->assignee_id), 'due:'.$t->id.':'.$t->due_date->toDateString(), 'Task “'.$t->title.'” is due '.($t->due_date->isSameDay($today) ? 'today' : $t->due_date->format('j M'))]),
                'milestone_overdue' => $project->milestones()->where('status', '!=', 'completed')->whereNotNull('due_date')->whereDate('due_date', '<', $d)->get()
                    ->map(fn (Milestone $m) => [$m, null, 'm-overdue:'.$m->id.':'.$m->due_date->toDateString(), 'Milestone “'.$m->title.'” passed its date ('.$m->due_date->format('j M').') unfinished']),
                'milestone_due_soon' => $project->milestones()->where('status', '!=', 'completed')->whereNotNull('due_date')->whereDate('due_date', '>=', $d)->whereDate('due_date', '<=', $until('days'))->get()
                    ->map(fn (Milestone $m) => [$m, null, 'm-due:'.$m->id.':'.$m->due_date->toDateString(), 'Milestone “'.$m->title.'” is due '.($m->due_date->isSameDay($today) ? 'today' : $m->due_date->format('j M'))]),
                'project_deadline' => $project->due_date !== null && $project->due_date->toDateString() >= $d && $project->due_date->toDateString() <= $until('deadline')
                    ? collect([[$project, null, 'deadline:'.$project->due_date->toDateString(), 'The project is due on '.$project->due_date->format('j M').', in '.(int) $today->diffInDays($project->due_date).' '.str('day')->plural((int) $today->diffInDays($project->due_date))]]) : collect(),
                'risk_review_due' => $project->risks()->whereIn('status', ProjectRisk::LIVE)->whereNotNull('review_date')->whereDate('review_date', '<=', $d)->get()
                    ->map(fn (ProjectRisk $r) => [$r, $user($r->owner_id), 'review:'.$r->id.':'.$r->review_date->toDateString(), 'Risk '.$r->ref().' “'.$r->title.'” is due for review ('.$r->review_date->format('j M').')']),
                'weekly' => $today->dayOfWeekIso === (int) ($p['weekday'] ?? 0) ? collect([[$project, null, 'week:'.$today->format('o-\WW'), 'It is '.$today->format('l j M')]]) : collect(),
                default => collect(),
            };
            foreach ($items as [$subject, $person, $key, $what]) {
                if ($this->passes($rule, $subject, null)) {
                    $out[] = ['project' => $project, 'subject' => $subject, 'person' => $person, 'key' => $key, 'what' => $what];
                }
            }
        }

        return $out;
    }

    /** Repeats the steps of a failed run that did not finish, as its rule now stands. */
    public function retry(AutomationRun $run, User $by): void
    {
        $rule = $run->rule;
        if (! self::canManage($rule, $by)) {
            throw ValidationException::withMessages(['run' => 'Only someone who manages this rule can retry it.']);
        }
        if ($run->status !== 'failed') {
            throw ValidationException::withMessages(['run' => 'Only a failed run can be retried.']);
        }
        $ctx = $this->rebuild($run);
        if ($ctx === null) {
            throw ValidationException::withMessages(['run' => 'What this run was about has been deleted, so there is nothing to retry.']);
        }
        $run->forceFill(['attempts' => $run->attempts + 1])->save();
        $this->execute($rule, $run, $ctx);
    }

    /** @return array{project: Project, subject: Model, person: User|null, key: string, what: string}|null */
    private function rebuild(AutomationRun $run): ?array
    {
        $line = $run->activity_id ? ActivityLine::query()->find($run->activity_id) : null;
        if ($line !== null) {
            $ctx = $this->match($run->rule, $line);
            if ($ctx !== null) {
                return $ctx;
            }
        }
        $project = Project::withoutTeamScope(fn () => Project::query()->with('team')->find($run->project_id));
        $class = $run->subject_type ? Model::getActualClassNameForMorph($run->subject_type) : null;
        $subject = $class && class_exists($class) ? Project::withoutTeamScope(fn () => $class::query()->find($run->subject_id)) : null;
        if ($project === null || ! $subject instanceof Model) {
            return null;
        }
        $person = match (true) {
            $subject instanceof ProjectTask => $subject->assignee_id ? User::query()->find($subject->assignee_id) : null,
            $subject instanceof ProjectRisk, $subject instanceof ProjectIssue => $subject->owner_id ? User::query()->find($subject->owner_id) : null,
            default => null,
        };

        return ['project' => $project, 'subject' => $subject, 'person' => $person, 'key' => $run->key, 'what' => $run->what ?? 'A rule ran'];
    }

    /**
     * One action. Returns a summary for the run's history and whether it did anything.
     *
     * @param  array<string, mixed>  $a
     * @param  array{project: Project, subject: Model, person: User|null, key: string, what: string}  $ctx
     * @return array{0: string, 1: bool}
     */
    private function act(array $a, AutomationRule $rule, array $ctx, User $by): array
    {
        $project = $ctx['project'];
        $subject = $ctx['subject'];
        $changes = $a['type'] !== 'notify';
        if ($changes && ! $project->canBeChangedBy($by)) {
            throw ValidationException::withMessages(['step' => $by->name.', who made this rule, can no longer change this project.']);
        }

        switch ($a['type']) {
            case 'notify':
                $people = $this->recipients($a['to'], $project, $ctx['person']);
                $message = $ctx['what'].' on “'.$project->name.'”.'.($a['note'] !== '' ? ' '.$a['note'] : '');
                foreach ($people as $u) {
                    $u->notify(new GovernanceNotification('automation', 'Rule: '.$rule->name, $message, $project->id, $this->url($subject, $project)));
                }

                return $people === [] ? ['Nobody to notify', false] : ['Notified '.self::names($people), true];

            case 'escalate':
                if ($subject instanceof ProjectTask) {
                    if ($subject->status !== 'done' && $subject->priority !== 'urgent') {
                        app(ProjectWork::class)->updateTask($subject, ['priority' => 'urgent'], $by);
                    }
                    $owner = $this->recipients(['project_owner'], $project, null);
                    foreach ($owner as $u) {
                        $u->notify(new GovernanceNotification('automation', 'Escalated by rule: '.$rule->name, $ctx['what'].' on “'.$project->name.'”. It is now urgent.', $project->id, $this->url($subject, $project)));
                    }

                    return ['Made the task urgent'.($owner ? ' and told '.self::names($owner) : ''), true];
                }
                if (($subject instanceof ProjectRisk || $subject instanceof ProjectIssue) && $subject->escalated_at === null) {
                    app(Governance::class)->escalate($subject, $by, 'Escalated by the rule “'.mb_substr($rule->name, 0, 400).'”.');

                    return ['Escalated '.$subject->ref().' to the project owner', true];
                }

                return ['Already escalated', false];

            case 'create_tasks':
                $assignee = match ($a['assign']) {
                    'subject_person' => $ctx['person'],
                    'project_owner' => User::query()->find($project->owner_id),
                    default => null,
                };
                if ($assignee !== null && ! $project->canBeSeenBy($assignee)) {
                    $assignee = null;
                }
                $made = [];
                foreach ($a['titles'] as $title) {
                    $task = ProjectTask::create([
                        'project_id' => $project->id, 'title' => $title, 'status' => 'todo', 'priority' => $a['priority'],
                        'assignee_id' => $assignee?->id,
                        'due_date' => $a['due_days'] === null ? null : Carbon::today()->addDays($a['due_days']),
                        'sort_order' => (int) $project->tasks()->where('status', 'todo')->max('sort_order') + 1,
                    ]);
                    Activity::record('task.created', $task, [], $project);
                    if ($assignee !== null && $assignee->id !== Auth::id()) {
                        $assignee->notify(new TaskAssignedNotification($task));
                    }
                    $made[] = $task->id;
                }

                return ['Added '.count($made).' '.str('task')->plural(count($made)).($assignee ? ' for '.$assignee->name : ''), true];

            case 'set_priority':
                if (! $subject instanceof ProjectTask || $subject->priority === $a['priority']) {
                    return ['The priority was already '.$a['priority'], false];
                }
                app(ProjectWork::class)->updateTask($subject, ['priority' => $a['priority']], $by);

                return ['Set the priority to '.$a['priority'], true];

            case 'add_label':
                if (! $subject instanceof ProjectTask) {
                    return ['Not a task', false];
                }
                app(ProjectWork::class)->addLabel($subject, $a['label']);

                return ['Labelled it “'.$a['label'].'”', true];

            case 'start_report':
                $open = $project->reports()->where('status', 'draft')->exists();
                $report = app(StatusReports::class)->start($project, $by);

                return [$open ? 'A progress review was already open ('.$report->ref().')' : 'Started progress review '.$report->ref().' as a draft', ! $open];

            case 'assign':
                $owner = User::query()->find($project->owner_id);
                if (! $subject instanceof ProjectTask || $owner === null || $subject->assignee_id === $owner->id) {
                    return ['Already with the project owner', false];
                }
                app(ProjectWork::class)->updateTask($subject, ['assignee_id' => $owner->id], $by);

                return ['Gave it to '.$owner->name, true];
        }

        return ['Nothing to do', false];
    }

    /**
     * The people a notice goes to: those named who can still see the project, never the person
     * whose action set the rule off.
     *
     * @param  list<string>  $to
     * @return list<User>
     */
    private function recipients(array $to, Project $project, ?User $person): array
    {
        $ids = [];
        foreach ($to as $r) {
            $ids = array_merge($ids, match ($r) {
                'project_owner' => [$project->owner_id],
                'project_managers' => DB::table('project_members')->where('project_id', $project->id)->where('role', 'manager')->pluck('user_id')->all(),
                'subject_person' => [$person?->id],
                'business_owner' => [$project->team->user_id],
                default => [],
            });
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids)), fn ($id) => $id !== (int) Auth::id())));

        return User::query()->whereIn('id', $ids)->orderBy('name')->get()->filter(fn (User $u) => $project->canBeSeenBy($u))->values()->all();
    }

    /** @param  list<User>  $people */
    private static function names(array $people): string
    {
        $n = array_map(fn (User $u) => $u->name, $people);

        return count($n) < 2 ? implode('', $n) : implode(', ', array_slice($n, 0, -1)).' and '.end($n);
    }

    private function url(Model $subject, Project $project): string
    {
        return match (true) {
            $subject instanceof ProjectTask => route('projects.tasks.show', [$project, $subject]),
            $subject instanceof ProjectRisk => route('projects.risks.show', [$project, $subject]),
            $subject instanceof ProjectIssue => route('projects.issues.show', [$project, $subject]),
            $subject instanceof ProjectGate => route('projects.gates.show', [$project, $subject]),
            $subject instanceof ProjectChange => route('projects.changes.show', [$project, $subject]),
            $subject instanceof Milestone => route('projects.schedule', $project),
            default => route('projects.show', $project),
        };
    }

    /** Whether $by may change, switch on or off, retry or delete the rule. */
    public static function canManage(AutomationRule $rule, User $by): bool
    {
        if (! $by->belongsToTeam($rule->team)) {
            return false;
        }
        if ($rule->project_id === null) {
            return Workload::oversees($by, $rule->team);
        }
        $project = Project::withoutTeamScope(fn () => Project::query()->with('team')->find($rule->project_id));

        return $project !== null && $project->canBeManagedBy($by);
    }

    /**
     * What the rule would have done over the last 30 days, worked out without doing anything:
     * the events it would have matched (at most $limit, newest first) and the total.
     *
     * @return array{items: list<array{at: Carbon, what: string, project: string, steps: list<string>, done: bool}>, total: int, scanned: int, dated: bool}
     */
    public function preview(AutomationRule $rule, Team $team, int $limit = 8): array
    {
        $subject = AutomationCatalog::TRIGGERS[$rule->trigger]['subject'] ?? 'project';
        if (in_array($rule->trigger, AutomationCatalog::DATED, true)) {
            // A dated rule acts on what is due now, at its next check, not on past events.
            $found = $this->candidates($rule, Carbon::today());
            $items = array_map(fn ($ctx) => [
                'at' => Carbon::today(), 'what' => $ctx['what'], 'project' => $ctx['project']->name,
                'steps' => array_map(fn ($a) => AutomationCatalog::describeStep($a, $subject), $rule->actions),
                'done' => $rule->exists && AutomationRun::query()->where('rule_id', $rule->id)->where('key', $ctx['key'])->exists(),
            ], array_slice($found, 0, $limit));

            return ['items' => $items, 'total' => count($found), 'scanned' => 0, 'dated' => true];
        }
        $lines = ActivityLine::query()->where('team_id', $team->id)->whereIn('action', self::EVENTS[$rule->trigger] ?? [])
            ->when($rule->project_id !== null, fn ($q) => $q->where('project_id', $rule->project_id))
            ->where('created_at', '>=', Carbon::now()->subDays(30))->latest('id')->limit(500)->get();
        $items = [];
        $total = 0;
        $seen = [];
        foreach ($lines as $line) {
            $ctx = $this->match($rule, $line);
            if ($ctx === null || isset($seen[$ctx['key']])) {
                continue;
            }
            $seen[$ctx['key']] = true;
            $total++;
            if (count($items) < $limit) {
                $items[] = [
                    'at' => Carbon::parse($line->created_at),
                    'what' => $ctx['what'],
                    'project' => $ctx['project']->name,
                    'steps' => array_map(fn ($a) => AutomationCatalog::describeStep($a, $subject), $rule->actions),
                    'done' => $rule->exists && AutomationRun::query()->where('rule_id', $rule->id)->where('key', $ctx['key'])->exists(),
                ];
            }
        }

        return ['items' => $items, 'total' => $total, 'scanned' => $lines->count(), 'dated' => false];
    }

    /**
     * Saves a rule (new or changed). Saved rules stay as they were switched; a new rule is off.
     *
     * @param  array<string, mixed>  $in
     */
    public function save(?AutomationRule $rule, Team $team, User $by, array $in): AutomationRule
    {
        if ($rule !== null && ! self::canManage($rule, $by)) {
            throw ValidationException::withMessages(['rule' => 'Only someone who manages this rule can change it.']);
        }
        $v = AutomationCatalog::clean($in, $team, $by);
        $rule ??= new AutomationRule;
        $rule->fill(['name' => $v['name'], 'trigger' => $v['trigger'], 'params' => $v['params'], 'conditions' => $v['conditions'], 'actions' => $v['actions']]);
        $rule->forceFill(['team_id' => $team->id, 'project_id' => $v['project_id'], 'created_by' => $rule->created_by ?? $by->id]);
        if ($rule->isDirty() && $rule->exists) {
            $rule->created_by = $by->id; // whoever changed it last answers for it, and it runs as them
        }
        $rule->save();

        return $rule;
    }

    public function setActive(AutomationRule $rule, User $by, bool $on): void
    {
        if (! self::canManage($rule, $by)) {
            throw ValidationException::withMessages(['rule' => 'Only someone who manages this rule can switch it on or off.']);
        }
        $rule->forceFill(['active' => $on, 'activated_at' => $on ? Carbon::now() : $rule->activated_at])->save();
    }

    public function delete(AutomationRule $rule, User $by): void
    {
        if (! self::canManage($rule, $by)) {
            throw ValidationException::withMessages(['rule' => 'Only someone who manages this rule can delete it.']);
        }
        $rule->delete();
    }
}
