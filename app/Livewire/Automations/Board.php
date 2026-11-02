<?php

namespace App\Livewire\Automations;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\AutomationCatalog;
use App\Services\Automations;
use App\Services\Workload;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Automations: rules that act when something happens. Build one from a template or from nothing
 * as "When … if … then …", read it as a sentence and see what it would have done over the last
 * 30 days before switching it on; then follow its runs and retry a failed one.
 */
class Board extends Component
{
    #[Url(as: 'rule')]
    public string $ruleId = '';

    public bool $building = false;

    public ?int $editId = null;

    /** @var array{name: string, project_id: string, trigger: string, param: string, conditions: list<array{field: string, values: list<string>}>, actions: list<array<string, mixed>>} */
    public array $form = ['name' => '', 'project_id' => '', 'trigger' => 'task_status', 'param' => 'done', 'conditions' => [], 'actions' => []];

    public string $newAction = 'notify';

    private function me(): User
    {
        /** @var User $me */
        $me = auth()->user();

        return $me;
    }

    private function team(): Team
    {
        /** @var Team|null $team */
        $team = $this->me()->currentTeam;
        abort_if($team === null, 404);

        return $team;
    }

    /** Rules this person can see: every business-wide rule, and rules for projects they can see. @return Collection<int, AutomationRule> */
    private function rules(): Collection
    {
        $visible = Project::query()->where('team_id', $this->team()->id)->pluck('name', 'id');

        return AutomationRule::query()->where('team_id', $this->team()->id)->with('creator:id,name')->orderByDesc('active')->orderBy('name')->get()
            ->filter(fn (AutomationRule $r) => $r->project_id === null || $visible->has($r->project_id))
            ->each(fn (AutomationRule $r) => $r->setAttribute('project_name', $r->project_id ? $visible[$r->project_id] : null))
            ->values();
    }

    private function selected(): ?AutomationRule
    {
        return $this->ruleId === '' ? null : $this->rules()->firstWhere('id', (int) $this->ruleId);
    }

    private function manageable(int $id): AutomationRule
    {
        abort_if($this->rules()->firstWhere('id', $id) === null, 404);
        $rule = AutomationRule::query()->findOrFail($id); // fresh: the listed copies carry display-only attributes
        abort_unless(Automations::canManage($rule, $this->me()), 403);

        return $rule;
    }

    /** @return Collection<int, Project> projects this person may make rules for */
    private function scopes(): Collection
    {
        return Project::query()->where('team_id', $this->team()->id)->whereNull('archived_at')->orderBy('name')->get()
            ->filter(fn (Project $p) => $p->canBeManagedBy($this->me()))->values();
    }

    public function select(int $id): void
    {
        abort_if($this->rules()->firstWhere('id', $id) === null, 404);
        $this->ruleId = (string) $id;
        $this->building = false;
        $this->resetErrorBag();
    }

    public function showTemplates(): void
    {
        $this->reset('ruleId', 'building', 'editId');
    }

    public function startNew(?string $template = null): void
    {
        $t = $template !== null ? (AutomationCatalog::TEMPLATES[$template] ?? null) : null;
        abort_if($template !== null && $t === null, 404);
        $oversees = Workload::oversees($this->me(), $this->team());
        $first = $this->scopes()->first();
        abort_if(! $oversees && $first === null, 403);
        $this->form = [
            'name' => $t['name'] ?? '',
            'project_id' => $first ? (string) $first->id : '',
            'trigger' => $t['trigger'] ?? 'task_status',
            'param' => $t['param'] ?? 'done',
            'conditions' => $t['conditions'] ?? [],
            'actions' => $t['actions'] ?? [['type' => 'notify', 'to' => ['project_owner'], 'note' => '']],
        ];
        $this->editId = null;
        $this->building = true;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $rule = $this->manageable($id);
        $param = AutomationCatalog::TRIGGERS[$rule->trigger]['param'] ?? null;
        $this->form = [
            'name' => $rule->name,
            'project_id' => $rule->project_id === null ? '' : (string) $rule->project_id,
            'trigger' => $rule->trigger,
            'param' => $param ? (string) ($rule->params[$param] ?? '') : '',
            'conditions' => $rule->conditions,
            'actions' => array_map(fn ($a) => $a['type'] === 'create_tasks' ? ['titles' => implode("\n", $a['titles'])] + $a : $a, $rule->actions),
        ];
        $this->editId = $rule->id;
        $this->building = true;
        $this->resetErrorBag();
    }

    /** A new trigger keeps only the conditions and actions that still make sense for it. */
    public function updatedFormTrigger(): void
    {
        $def = AutomationCatalog::TRIGGERS[$this->form['trigger']] ?? null;
        if ($def === null) {
            return;
        }
        $this->form['param'] = $def['param'] === null ? '' : (string) array_key_first(AutomationCatalog::PARAMS[$def['param']]);
        $this->form['conditions'] = array_values(array_filter($this->form['conditions'], fn ($c) => isset(AutomationCatalog::CONDITIONS[$def['subject']][$c['field']])));
        $this->form['actions'] = array_values(array_filter($this->form['actions'], fn ($a) => (AutomationCatalog::ACTIONS[$a['type']]['subjects'] ?? null) === null || in_array($def['subject'], AutomationCatalog::ACTIONS[$a['type']]['subjects'], true)));
    }

    public function addCondition(string $field): void
    {
        $subject = AutomationCatalog::TRIGGERS[$this->form['trigger']]['subject'] ?? '';
        abort_unless(isset(AutomationCatalog::CONDITIONS[$subject][$field]), 404);
        $this->form['conditions'][] = ['field' => $field, 'values' => []];
    }

    public function removeCondition(int $i): void
    {
        unset($this->form['conditions'][$i]);
        $this->form['conditions'] = array_values($this->form['conditions']);
    }

    public function addAction(): void
    {
        abort_unless(isset(AutomationCatalog::ACTIONS[$this->newAction]), 404);
        if (count($this->form['actions']) >= AutomationCatalog::MAX_ACTIONS) {
            return;
        }
        $this->form['actions'][] = match ($this->newAction) {
            'notify' => ['type' => 'notify', 'to' => ['project_owner'], 'note' => ''],
            'create_tasks' => ['type' => 'create_tasks', 'titles' => '', 'due_days' => '', 'assign' => 'none', 'priority' => 'medium'],
            'set_priority' => ['type' => 'set_priority', 'priority' => 'high'],
            'add_label' => ['type' => 'add_label', 'label' => ''],
            'assign' => ['type' => 'assign', 'to' => 'project_owner'],
            default => ['type' => $this->newAction],
        };
    }

    public function removeAction(int $i): void
    {
        unset($this->form['actions'][$i]);
        $this->form['actions'] = array_values($this->form['actions']);
    }

    public function moveAction(int $i, int $step): void
    {
        $j = $i + ($step < 0 ? -1 : 1);
        if (isset($this->form['actions'][$i], $this->form['actions'][$j])) {
            [$this->form['actions'][$i], $this->form['actions'][$j]] = [$this->form['actions'][$j], $this->form['actions'][$i]];
        }
    }

    public function save(Automations $automations): void
    {
        $this->resetErrorBag();
        $rule = $this->editId ? $this->manageable($this->editId) : null;
        $rule = $automations->save($rule, $this->team(), $this->me(), $this->form);
        $this->ruleId = (string) $rule->id;
        $this->building = false;
    }

    public function toggle(int $id, Automations $automations): void
    {
        $rule = $this->manageable($id);
        $automations->setActive($rule, $this->me(), ! $rule->active);
    }

    public function remove(int $id, Automations $automations): void
    {
        $automations->delete($this->manageable($id), $this->me());
        $this->reset('ruleId', 'building', 'editId');
    }

    public function retry(int $runId, Automations $automations): void
    {
        $run = AutomationRun::query()->where('team_id', $this->team()->id)->find($runId);
        abort_if($run === null, 404);
        $this->manageable($run->rule_id);
        try {
            $automations->retry($run, $this->me());
        } catch (ValidationException $e) {
            $this->addError('run', (string) collect($e->errors())->flatten()->first());
        }
    }

    public function render(Automations $automations): View
    {
        $rules = $this->rules();
        $team = $this->team();
        $me = $this->me();
        $draft = null;
        $draftError = null;
        $preview = null;
        if ($this->building) {
            try {
                $v = AutomationCatalog::clean($this->form, $team, $me);
                $draft = new AutomationRule(['name' => $v['name'], 'trigger' => $v['trigger'], 'params' => $v['params'], 'conditions' => $v['conditions'], 'actions' => $v['actions']]);
                $draft->project_id = $v['project_id'];
                $preview = $automations->preview($draft, $team);
            } catch (ValidationException $e) {
                $draftError = (string) collect($e->errors())->flatten()->first();
            }
        }
        $selected = $this->building ? null : $this->selected();
        $scopeName = fn (?AutomationRule $r) => $r?->project_id ? ($r->getAttribute('project_name') ?? Project::withoutTeamScope(fn () => Project::query()->whereKey($r->project_id)->value('name'))) : null;

        return view('livewire.automations.board', [
            'rules' => $rules,
            'selected' => $selected,
            'canManage' => $selected ? Automations::canManage($selected, $me) : false,
            'sentence' => $selected ? AutomationCatalog::describe($selected, $scopeName($selected)) : null,
            'selectedPreview' => $selected ? $automations->preview($selected, $team) : null,
            'runs' => $selected ? $selected->runs()->limit(20)->get() : collect(),
            'draft' => $draft,
            'draftSentence' => $draft ? AutomationCatalog::describe($draft, $draft->project_id ? $this->scopes()->firstWhere('id', $draft->project_id)?->name : null) : null,
            'draftError' => $draftError,
            'preview' => $preview,
            'scopes' => $this->scopes(),
            'oversees' => Workload::oversees($me, $team),
            'subject' => AutomationCatalog::TRIGGERS[$this->form['trigger']]['subject'] ?? 'task',
            'templates' => collect(AutomationCatalog::TEMPLATES)->map(fn ($t) => AutomationCatalog::describe($this->templateRule($t), 'the project you choose'))->all(),
        ]);
    }

    /** @param  array{name: string, trigger: string, param: string, conditions: list<array{field: string, values: list<string>}>, actions: list<array<string, mixed>>}  $t */
    private function templateRule(array $t): AutomationRule
    {
        $param = AutomationCatalog::TRIGGERS[$t['trigger']]['param'];
        $rule = new AutomationRule([
            'name' => $t['name'], 'trigger' => $t['trigger'], 'params' => $param ? [$param => $t['param']] : [], 'conditions' => $t['conditions'],
            'actions' => array_map(fn ($a) => $a['type'] === 'create_tasks' ? ['titles' => preg_split('/\R/', $a['titles'])] + $a : $a, $t['actions']),
        ]);
        $rule->project_id = 0;

        return $rule;
    }
}
