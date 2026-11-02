<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\Project;
use App\Models\ProjectIssue;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * What an automation can listen for, check and do, how a rule is checked before it is saved, and
 * how it reads as a sentence, so people know what a rule will do before they switch it on.
 * The engine that runs rules is App\Services\Automations.
 */
final class AutomationCatalog
{
    /**
     * Triggers: what happened, to what, and the one setting some of them take.
     *
     * @var array<string, array{label: string, subject: string, param: string|null}>
     */
    public const TRIGGERS = [
        'task_created' => ['label' => 'a task is added', 'subject' => 'task', 'param' => null],
        'task_status' => ['label' => 'a task moves to', 'subject' => 'task', 'param' => 'status'],
        'task_assigned' => ['label' => 'a task is given to someone', 'subject' => 'task', 'param' => null],
        'task_blocked' => ['label' => 'a task starts waiting on unfinished work', 'subject' => 'task', 'param' => null],
        'milestone_completed' => ['label' => 'a milestone is completed', 'subject' => 'milestone', 'param' => null],
        'risk_rated' => ['label' => 'a risk is rated', 'subject' => 'risk', 'param' => 'band'],
        'issue_logged' => ['label' => 'an issue is logged', 'subject' => 'issue', 'param' => null],
        'member_added' => ['label' => 'someone is added to the project', 'subject' => 'member', 'param' => null],
        'gate_passed' => ['label' => 'a stage gate is passed', 'subject' => 'gate', 'param' => null],
        'project_status' => ['label' => 'the project\'s status changes to', 'subject' => 'project', 'param' => 'project_status'],
        'change_submitted' => ['label' => 'a change request is submitted', 'subject' => 'change', 'param' => null],
        'budget_threshold' => ['label' => 'spending and commitments reach', 'subject' => 'project', 'param' => 'percent'],
    ];

    /** The choices each trigger setting offers. @var array<string, array<string, string>> */
    public const PARAMS = [
        'status' => ProjectSummary::STATUSES,
        'band' => ['high' => 'high or critical', 'critical' => 'critical'],
        'project_status' => ['planning' => 'Planning', 'active' => 'Active', 'on_hold' => 'On hold'],
        'percent' => ['75' => '75% of the budget', '90' => '90% of the budget', '100' => '100% of the budget', '110' => '110% of the budget'],
    ];

    /** Conditions each subject can be narrowed by: field => [label, choices]. @var array<string, array<string, array{0: string, 1: array<string, string>}>> */
    public const CONDITIONS = [
        'task' => ['priority' => ['its priority is', ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent']]],
        'risk' => ['category' => ['its category is', Governance::CATEGORIES]],
        'issue' => ['severity' => ['its severity is', ProjectIssue::SEVERITIES], 'category' => ['its category is', Governance::CATEGORIES]],
        'member' => ['role' => ['their role is', Project::ROLES]],
        'project' => [],
        'milestone' => [],
        'gate' => [],
        'change' => [],
    ];

    /**
     * Actions, and the subjects each one makes sense for (null: any).
     *
     * @var array<string, array{label: string, subjects: list<string>|null}>
     */
    public const ACTIONS = [
        'notify' => ['label' => 'Notify', 'subjects' => null],
        'escalate' => ['label' => 'Escalate', 'subjects' => ['task', 'risk', 'issue']],
        'create_tasks' => ['label' => 'Add tasks', 'subjects' => null],
        'set_priority' => ['label' => 'Set the task\'s priority', 'subjects' => ['task']],
        'add_label' => ['label' => 'Label the task', 'subjects' => ['task']],
        'assign' => ['label' => 'Give the task to', 'subjects' => ['task']],
    ];

    /** Who a notice can go to. @var array<string, string> */
    public const RECIPIENTS = [
        'project_owner' => 'the project owner',
        'project_managers' => 'the project managers',
        'subject_person' => 'the person it concerns',
        'business_owner' => 'the business owner',
    ];

    /** Who added tasks can go to. @var array<string, string> */
    public const ASSIGNEES = ['none' => 'nobody yet', 'subject_person' => 'the person it concerns', 'project_owner' => 'the project owner'];

    public const MAX_ACTIONS = 6;

    /**
     * Ready-made rules from the brief's examples, opened in the builder to adjust before saving.
     * The task titles are suggestions to edit, not a standard.
     *
     * @var array<string, array{name: string, trigger: string, param: string, conditions: list<array{field: string, values: list<string>}>, actions: list<array<string, mixed>>}>
     */
    public const TEMPLATES = [
        'milestone_done' => ['name' => 'Tell the managers when a milestone is completed', 'trigger' => 'milestone_completed', 'param' => '', 'conditions' => [],
            'actions' => [['type' => 'notify', 'to' => ['project_owner', 'project_managers'], 'note' => '']]],
        'blocked_task' => ['name' => 'Escalate important work that starts waiting', 'trigger' => 'task_blocked', 'param' => '', 'conditions' => [['field' => 'priority', 'values' => ['high', 'urgent']]],
            'actions' => [['type' => 'escalate'], ['type' => 'notify', 'to' => ['subject_person'], 'note' => '']]],
        'critical_risk' => ['name' => 'Alert leadership when a risk becomes critical', 'trigger' => 'risk_rated', 'param' => 'critical', 'conditions' => [],
            'actions' => [['type' => 'notify', 'to' => ['project_owner', 'project_managers', 'business_owner'], 'note' => ''], ['type' => 'escalate']]],
        'critical_issue' => ['name' => 'Escalate a critical issue', 'trigger' => 'issue_logged', 'param' => '', 'conditions' => [['field' => 'severity', 'values' => ['critical']]],
            'actions' => [['type' => 'escalate'], ['type' => 'notify', 'to' => ['business_owner'], 'note' => '']]],
        'onboarding' => ['name' => 'Onboarding tasks for people added to the project', 'trigger' => 'member_added', 'param' => '', 'conditions' => [],
            'actions' => [['type' => 'create_tasks', 'titles' => "Read the project brief and plan\nMeet the project owner\nCheck you can open the project's documents", 'due_days' => 3, 'assign' => 'subject_person', 'priority' => 'medium']]],
        'phase_tasks' => ['name' => 'Plan the next phase when a stage gate passes', 'trigger' => 'gate_passed', 'param' => '', 'conditions' => [],
            'actions' => [['type' => 'create_tasks', 'titles' => "Plan the next phase's work\nUpdate the schedule and set a new baseline\nTell stakeholders the gate has passed", 'due_days' => 5, 'assign' => 'project_owner', 'priority' => 'high']]],
        'budget_90' => ['name' => 'Warn when spending reaches 90% of the budget', 'trigger' => 'budget_threshold', 'param' => '90', 'conditions' => [],
            'actions' => [['type' => 'notify', 'to' => ['project_owner', 'project_managers'], 'note' => 'Review the cost report before more is committed.']]],
    ];

    /** Who "the person it concerns" is, per subject. @var array<string, string> */
    public const SUBJECT_PERSON = [
        'task' => 'the task\'s assignee', 'risk' => 'the risk\'s owner', 'issue' => 'the issue\'s owner', 'member' => 'the person added',
        'milestone' => 'nobody', 'gate' => 'nobody', 'project' => 'nobody', 'change' => 'the person who submitted it',
    ];

    /**
     * Checks and tidies a rule before it is saved. Throws with the field that is wrong.
     *
     * @param  array<string, mixed>  $in  name, project_id ('' for every project), trigger, param, conditions, actions
     * @return array{name: string, project_id: int|null, trigger: string, params: array<string, string>, conditions: list<array{field: string, values: list<string>}>, actions: list<array<string, mixed>>}
     */
    public static function clean(array $in, Team $team, User $by): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Give the rule a name of up to 120 characters.']);
        }
        $projectId = ($in['project_id'] ?? '') === '' ? null : (int) $in['project_id'];
        if ($projectId === null) {
            if (! Workload::oversees($by, $team)) {
                throw ValidationException::withMessages(['project_id' => 'Only the business owner and admins make rules for every project. Choose one of your projects.']);
            }
        } else {
            $project = Project::withoutTeamScope(fn () => Project::query()->with('team')->find($projectId));
            if ($project === null || $project->team_id !== $team->id || ! $project->canBeManagedBy($by)) {
                throw ValidationException::withMessages(['project_id' => 'Choose a project you manage.']);
            }
        }

        $trigger = (string) ($in['trigger'] ?? '');
        $def = self::TRIGGERS[$trigger] ?? throw ValidationException::withMessages(['trigger' => 'Choose what starts the rule.']);
        $params = [];
        if ($def['param'] !== null) {
            $value = (string) ($in['param'] ?? '');
            if (! isset(self::PARAMS[$def['param']][$value])) {
                throw ValidationException::withMessages(['trigger' => 'Choose '.($def['param'] === 'percent' ? 'the share of the budget' : 'which one').'.']);
            }
            $params[$def['param']] = $value;
        }
        if ($trigger === 'budget_threshold' && $projectId !== null && ! Project::withoutTeamScope(fn () => Project::query()->find($projectId))?->moneyVisibleTo($by)) {
            throw ValidationException::withMessages(['trigger' => 'Only people who may see this project\'s money can watch its budget.']);
        }

        $subject = $def['subject'];
        $conditions = [];
        foreach ((array) ($in['conditions'] ?? []) as $c) {
            $field = (string) ($c['field'] ?? '');
            $choices = self::CONDITIONS[$subject][$field][1] ?? null;
            $values = array_values(array_intersect(array_map('strval', (array) ($c['values'] ?? [])), array_keys($choices ?? [])));
            if ($choices === null || $values === []) {
                throw ValidationException::withMessages(['conditions' => 'Each condition needs at least one value.']);
            }
            $conditions[] = ['field' => $field, 'values' => $values];
        }

        $actions = [];
        foreach (array_values((array) ($in['actions'] ?? [])) as $i => $a) {
            $actions[] = self::cleanAction((array) $a, $subject, $i + 1);
        }
        if ($actions === []) {
            throw ValidationException::withMessages(['actions' => 'Add at least one thing for the rule to do.']);
        }
        if (count($actions) > self::MAX_ACTIONS) {
            throw ValidationException::withMessages(['actions' => 'A rule does at most '.self::MAX_ACTIONS.' things.']);
        }

        return ['name' => $name, 'project_id' => $projectId, 'trigger' => $trigger, 'params' => $params, 'conditions' => $conditions, 'actions' => $actions];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private static function cleanAction(array $a, string $subject, int $n): array
    {
        $type = (string) ($a['type'] ?? '');
        $def = self::ACTIONS[$type] ?? null;
        $fail = fn (string $why) => throw ValidationException::withMessages(['actions' => 'Step '.$n.': '.$why]);
        if ($def === null || ($def['subjects'] !== null && ! in_array($subject, $def['subjects'], true))) {
            $fail('choose something this trigger can do.');
        }

        return match ($type) {
            'notify' => (function () use ($a, $fail) {
                $to = array_values(array_intersect(array_map('strval', (array) ($a['to'] ?? [])), array_keys(self::RECIPIENTS)));
                $note = trim((string) ($a['note'] ?? ''));
                if ($to === []) {
                    $fail('choose who to notify.');
                }
                if (mb_strlen($note) > 300) {
                    $fail('keep the note under 300 characters.');
                }

                return ['type' => 'notify', 'to' => $to, 'note' => $note];
            })(),
            'escalate' => ['type' => 'escalate'],
            'create_tasks' => (function () use ($a, $fail) {
                $titles = array_values(array_filter(array_map(fn ($t) => trim((string) $t), preg_split('/\R/', (string) ($a['titles'] ?? '')) ?: [])));
                $due = ($a['due_days'] ?? '') === '' || $a['due_days'] === null ? null : (int) $a['due_days'];
                $assign = (string) ($a['assign'] ?? 'none');
                $priority = (string) ($a['priority'] ?? 'medium');
                if ($titles === [] || count($titles) > 10 || collect($titles)->contains(fn ($t) => mb_strlen($t) > 255)) {
                    $fail('write one to ten task titles, one per line, each under 255 characters.');
                }
                if ($due !== null && ($due < 0 || $due > 365)) {
                    $fail('the due date is 0 to 365 days after the rule runs.');
                }
                if (! isset(self::ASSIGNEES[$assign]) || ! in_array($priority, ProjectWork::PRIORITIES, true)) {
                    $fail('choose who the tasks go to and their priority.');
                }

                return ['type' => 'create_tasks', 'titles' => $titles, 'due_days' => $due, 'assign' => $assign, 'priority' => $priority];
            })(),
            'set_priority' => in_array($a['priority'] ?? '', ProjectWork::PRIORITIES, true) ? ['type' => 'set_priority', 'priority' => (string) $a['priority']] : $fail('choose a priority.'),
            'add_label' => (function () use ($a, $fail) {
                $label = trim((string) ($a['label'] ?? ''));

                return $label === '' || mb_strlen($label) > 40 ? $fail('a label is 1 to 40 characters.') : ['type' => 'add_label', 'label' => $label];
            })(),
            'assign' => ($a['to'] ?? '') === 'project_owner' ? ['type' => 'assign', 'to' => 'project_owner'] : $fail('choose who gets the task.'),
            default => $fail('choose something this trigger can do.'),
        };
    }

    /** The rule as a sentence: "When a task moves to Done in Kiln reline, if …, then …". */
    public static function describe(AutomationRule $rule, ?string $projectName = null): string
    {
        $def = self::TRIGGERS[$rule->trigger] ?? null;
        if ($def === null) {
            return 'This rule\'s trigger is no longer available.';
        }
        $when = 'When '.$def['label'];
        if ($def['param'] !== null) {
            $label = self::PARAMS[$def['param']][$rule->params[$def['param']] ?? ''] ?? '?';
            $when .= ' '.($def['param'] === 'status' ? $label : mb_strtolower($label)); // task statuses keep their names
        }
        $when .= $rule->project_id === null ? ' in any project' : ' in '.($projectName ?? 'its project');
        $subject = $def['subject'];
        $if = array_map(fn ($c) => (self::CONDITIONS[$subject][$c['field']][0] ?? $c['field']).' '.self::orList(array_map(fn ($v) => self::CONDITIONS[$subject][$c['field']][1][$v] ?? $v, $c['values'])), $rule->conditions);
        $then = array_map(fn ($a) => self::describeAction($a, $subject), $rule->actions);

        return $when.($if ? ', if '.implode(' and ', $if) : '').', then '.implode(', then ', $then).'.';
    }

    /** @param  array<string, mixed>  $a */
    public static function describeAction(array $a, string $subject): string
    {
        $who = fn (string $r) => $r === 'subject_person' ? self::SUBJECT_PERSON[$subject] : self::RECIPIENTS[$r];

        return match ($a['type']) {
            'notify' => 'notify '.self::andList(array_map($who, $a['to'])).($a['note'] !== '' ? ' with the note “'.$a['note'].'”' : ''),
            'escalate' => match ($subject) {
                'task' => 'make the task urgent and tell the project owner',
                default => 'escalate it to the project owner',
            },
            'create_tasks' => 'add '.(count($a['titles']) === 1 ? 'the task “'.$a['titles'][0].'”' : count($a['titles']).' tasks (“'.implode('”, “', $a['titles']).'”)')
                .($a['assign'] !== 'none' ? ' for '.self::ASSIGNEES[$a['assign']] : '')
                .($a['due_days'] !== null ? ', due '.($a['due_days'] === 0 ? 'the same day' : $a['due_days'].' '.str('day')->plural($a['due_days']).' later') : '')
                .($a['priority'] !== 'medium' ? ', '.$a['priority'].' priority' : ''),
            'set_priority' => 'set the task\'s priority to '.$a['priority'],
            'add_label' => 'label the task “'.$a['label'].'”',
            'assign' => 'give the task to the project owner',
            default => $a['type'],
        };
    }

    /** @param  list<string>  $items */
    private static function andList(array $items): string
    {
        return count($items) < 2 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }

    /** @param  list<string>  $items */
    private static function orList(array $items): string
    {
        return count($items) < 2 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)).' or '.end($items);
    }
}
