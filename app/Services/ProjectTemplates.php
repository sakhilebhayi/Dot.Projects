<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\ChecklistItem;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectLabel;
use App\Models\ProjectRisk;
use App\Models\ProjectTask;
use App\Models\ProjectTemplate;
use App\Models\Team;
use App\Models\User;
use App\Support\Methodologies;
use App\Support\StarterTemplates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project templates (R-34 P13a, brief §5.1, §5.2 and §15). A template is the structure a new project starts with,
 * never a separate kind of project: once made, the project is ordinary and every view works on it.
 *
 * Structure (version 1), every date a whole number of days from the project's start:
 *  - phases: [{title, due}] become milestones;
 *  - tasks: [{title, phase, parent, priority, estimate (minutes), start, due, checklist: [text], labels: [name],
 *    after: [task index]}], a flat list; parent and after point at other tasks by position;
 *  - gates: [{title, phase, due, checks: [text]}], risks: [{title, category, probability, impact}],
 *    budget: [{category, name}] (names only: amounts are never kept), labels: [name], days (the usual length).
 *
 * Kept out of every template: people, statuses, time, costs and amounts, comments, documents and history.
 */
final class ProjectTemplates
{
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    private const MAX_TASKS = 600;

    private const MAX_DAYS = 3650;

    /** @return Collection<int, ProjectTemplate> the business's own, newest first */
    public function forTeam(Team $team): Collection
    {
        return ProjectTemplate::query()->where('team_id', $team->id)->with('creator:id,name')->orderByDesc('updated_at')->get();
    }

    /**
     * A template by reference: "team:{id}" (this business's own) or "starter:{key}".
     *
     * @return array{ref: string, name: string, description: ?string, methodology: ?string, industry: ?string, structure: array<string, mixed>, model: ?ProjectTemplate}|null
     */
    public function find(Team $team, string $ref): ?array
    {
        [$kind, $key] = array_pad(explode(':', $ref, 2), 2, '');
        if ($kind === 'starter' && isset(StarterTemplates::all()[$key])) {
            $s = StarterTemplates::all()[$key];

            return ['ref' => $ref, 'name' => $s['name'], 'description' => $s['description'], 'methodology' => $s['methodology'], 'industry' => $s['industry'], 'structure' => $s['structure'], 'model' => null];
        }
        if ($kind === 'team' && ctype_digit($key)) {
            $t = ProjectTemplate::query()->where('team_id', $team->id)->find((int) $key);

            return $t ? ['ref' => $ref, 'name' => $t->name, 'description' => $t->description, 'methodology' => $t->methodology, 'industry' => null, 'structure' => $t->structure, 'model' => $t] : null;
        }

        return null;
    }

    /**
     * What a template adds, counted for its row and preview.
     *
     * @param  array<string, mixed>  $s
     * @return array{phases: int, tasks: int, gates: int, risks: int, budget: int, days: ?int}
     */
    public static function counts(array $s): array
    {
        return ['phases' => count($s['phases'] ?? []), 'tasks' => count($s['tasks'] ?? []), 'gates' => count($s['gates'] ?? []),
            'risks' => count($s['risks'] ?? []), 'budget' => count($s['budget'] ?? []), 'days' => isset($s['days']) ? (int) $s['days'] : null];
    }

    /**
     * A new project from a template, its structure dated from $start. With no due date the project takes the
     * template's usual length.
     *
     * @param  array{name: string, description?: ?string, visibility?: string, methodology?: ?string, start: Carbon, due?: ?Carbon}  $data
     */
    public function create(Team $team, User $by, string $ref, array $data): Project
    {
        $template = $this->find($team, $ref) ?? throw ValidationException::withMessages(['template' => 'Choose one of the templates offered.']);
        $s = $this->clean($template['structure']);
        $start = $data['start']->copy()->startOfDay();
        $due = $data['due'] ?? (isset($s['days']) ? $start->copy()->addDays((int) $s['days']) : null);
        $methodology = $data['methodology'] ?? $template['methodology'];

        return DB::transaction(function () use ($team, $by, $template, $s, $start, $due, $methodology, $data) {
            $project = Project::create([
                'team_id' => $team->id, 'owner_id' => $by->id, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'status' => 'planning', 'visibility' => $data['visibility'] ?? 'team', 'start_date' => $start, 'due_date' => $due,
                'methodology' => isset(Methodologies::ALL[(string) $methodology]) ? $methodology : null,
            ]);
            Activity::record('project.created', $project, ['status' => 'planning', 'template' => $template['name']]);
            $this->apply($project, $by, $s, $start);
            $template['model']?->increment('uses');

            return $project;
        });
    }

    /** @param array<string, mixed> $s a cleaned structure */
    private function apply(Project $project, User $by, array $s, Carbon $start): void
    {
        $at = fn ($offset) => $offset === null ? null : $start->copy()->addDays((int) $offset);

        $milestones = [];
        foreach ($s['phases'] as $i => $p) {
            $milestones[$i] = Milestone::create(['project_id' => $project->id, 'title' => $p['title'], 'due_date' => $at($p['due']), 'sort_order' => $i])->id;
        }
        $labels = [];
        foreach ($s['labels'] as $name) {
            $labels[$name] = ProjectLabel::create(['project_id' => $project->id, 'name' => $name])->id;
        }

        $ids = [];
        foreach ($s['tasks'] as $i => $t) {
            $task = ProjectTask::create([
                'project_id' => $project->id, 'milestone_id' => $t['phase'] !== null ? ($milestones[$t['phase']] ?? null) : null,
                'title' => $t['title'], 'status' => 'backlog', 'priority' => $t['priority'], 'estimate_minutes' => $t['estimate'],
                'start_date' => $at($t['start']), 'due_date' => $at($t['due']), 'sort_order' => $i,
            ]);
            foreach ($t['checklist'] as $n => $body) {
                ChecklistItem::create(['project_task_id' => $task->id, 'body' => $body, 'position' => $n + 1]);
            }
            $task->labels()->sync(array_values(array_filter(array_map(fn ($l) => $labels[$l] ?? null, $t['labels']))));
            $ids[$i] = $task->id;
        }
        // Once every task exists: subtasks under their parents, and dependencies between them (the structure was
        // checked for loops when it was cleaned).
        $edges = [];
        foreach ($s['tasks'] as $i => $t) {
            if ($t['parent'] !== null) {
                ProjectTask::query()->whereKey($ids[$i])->update(['parent_id' => $ids[$t['parent']]]);
            }
            foreach ($t['after'] as $a) {
                $edges[] = ['predecessor_id' => $ids[$a], 'successor_id' => $ids[$i], 'type' => 'finish_to_start', 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        if ($edges !== []) {
            DB::table('task_dependencies')->insert($edges);
        }

        foreach ($s['gates'] as $g) {
            $gate = app(Gates::class)->add($project, $by, array_filter(['title' => $g['title'], 'due_date' => $at($g['due'])?->toDateString(),
                'milestone_id' => $g['phase'] !== null ? ($milestones[$g['phase']] ?? null) : null]), null);
            foreach ($g['checks'] as $n => $body) {
                $gate->checks()->create(['body' => $body, 'sort_order' => $n + 1]);
            }
        }
        foreach ($s['risks'] as $r) {
            app(Governance::class)->addRisk($project, $by, $r);
        }
        if ($s['budget'] !== [] && $by->can('editMoney', $project)) {
            foreach ($s['budget'] as $b) {
                app(CostLedger::class)->addLine($project, $by, $b['category'], $b['name'], 0, null);
            }
        }
    }

    /** Whether this person may save the project as one of the business's templates. */
    public function canSave(User $by, Project $project): bool
    {
        return $project->canBeChangedBy($by) && $by->hasTeamPermission($project->team, 'create');
    }

    /** Whether this person may rename or remove a template: whoever saved it, and the business's owner and admins. */
    public function canManage(User $by, ProjectTemplate $template): bool
    {
        return $template->created_by === $by->id || (($team = Team::query()->find($template->team_id)) !== null && Workload::oversees($by, $team));
    }

    /** Saves a project's structure as one of its business's templates. */
    public function capture(Project $project, User $by, string $name, ?string $description): ProjectTemplate
    {
        if (! $this->canSave($by, $project)) {
            throw ValidationException::withMessages(['template' => 'Only people who can change this project and create projects can save it as a template.']);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['templateName' => 'Name the template in up to 120 characters.']);
        }

        $template = new ProjectTemplate(['name' => $name, 'description' => $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 500) : null, 'methodology' => $project->methodology]);
        $template->forceFill(['team_id' => $project->team_id, 'structure' => $this->structureOf($project), 'created_by' => $by->id, 'source_project_id' => $project->id])->save();
        Activity::record('template.saved', $project, ['template_id' => $template->id, 'name' => $template->name]);

        return $template;
    }

    public function rename(ProjectTemplate $template, User $by, string $name, ?string $description): void
    {
        if (! $this->canManage($by, $template)) {
            throw ValidationException::withMessages(['template' => 'Only whoever saved it, the business owner and admins can change this template.']);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Name the template in up to 120 characters.']);
        }
        $template->fill(['name' => $name, 'description' => $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 500) : null])->save();
    }

    public function remove(ProjectTemplate $template, User $by): void
    {
        if (! $this->canManage($by, $template)) {
            throw ValidationException::withMessages(['template' => 'Only whoever saved it, the business owner and admins can remove this template.']);
        }
        $template->delete();
    }

    /** @return array<string, mixed> the project's structure, dated from its start (or its earliest date) */
    public function structureOf(Project $project): array
    {
        $milestones = $project->milestones()->orderBy('sort_order')->orderBy('id')->get();
        $tasks = $project->tasks()->with(['checklist', 'labels'])->orderBy('sort_order')->orderBy('id')->get();
        $gates = $project->gates()->with('checks')->orderBy('number')->get();
        $origin = collect([$project->start_date, $tasks->min('start_date'), $tasks->min('due_date'), $milestones->min('due_date')])->filter()->min();
        $off = fn ($d) => $d !== null && $origin !== null ? max(0, min(self::MAX_DAYS, (int) $origin->diffInDays($d, false))) : null;

        $phaseAt = $milestones->pluck('id')->flip();
        $taskAt = $tasks->pluck('id')->flip();
        $edges = DB::table('task_dependencies')->whereIn('successor_id', $tasks->pluck('id'))->whereIn('predecessor_id', $tasks->pluck('id'))->get(['predecessor_id', 'successor_id']);
        $after = $edges->groupBy('successor_id')->map(fn ($g) => $g->map(fn ($e) => $taskAt[$e->predecessor_id])->values()->all());

        return [
            'v' => 1,
            'days' => $project->due_date !== null ? $off($project->due_date) : null,
            'phases' => $milestones->map(fn (Milestone $m) => ['title' => $m->title, 'due' => $off($m->due_date)])->values()->all(),
            'tasks' => $tasks->map(fn (ProjectTask $t) => [
                'title' => $t->title, 'phase' => $t->milestone_id !== null ? ($phaseAt[$t->milestone_id] ?? null) : null,
                'parent' => $t->parent_id !== null ? ($taskAt[$t->parent_id] ?? null) : null, 'priority' => $t->priority,
                'estimate' => $t->estimate_minutes, 'start' => $off($t->start_date), 'due' => $off($t->due_date),
                'checklist' => $t->checklist->pluck('body')->values()->all(), 'labels' => $t->labels->pluck('name')->values()->all(),
                'after' => $after[$t->id] ?? [],
            ])->values()->all(),
            'gates' => $gates->map(fn ($g) => ['title' => $g->title, 'phase' => $g->milestone_id !== null ? ($phaseAt[$g->milestone_id] ?? null) : null,
                'due' => $off($g->due_date), 'checks' => $g->checks->pluck('body')->values()->all()])->values()->all(),
            'risks' => $project->risks()->orderBy('number')->get()->filter(fn (ProjectRisk $r) => $r->isLive())
                ->map(fn (ProjectRisk $r) => ['title' => $r->title, 'category' => $r->category, 'probability' => $r->probability, 'impact' => $r->impact])->values()->all(),
            'budget' => $project->budgetLines()->orderBy('sort_order')->get()->map(fn (BudgetLine $b) => ['category' => $b->category, 'name' => $b->name])->values()->all(),
            'labels' => $project->labels()->orderBy('name')->pluck('name')->values()->all(),
        ];
    }

    /**
     * A structure made safe to apply: every field present and bounded, every reference pointing at something that
     * exists, no subtask under itself and no dependency loop.
     *
     * @param  array<string, mixed>  $s
     * @return array{v: int, days: ?int, phases: list<array{title: string, due: ?int}>, tasks: list<array<string, mixed>>, gates: list<array<string, mixed>>, risks: list<array<string, mixed>>, budget: list<array{category: string, name: string}>, labels: list<string>}
     */
    public function clean(array $s): array
    {
        $text = fn ($v, int $max) => mb_substr(trim((string) $v), 0, $max);
        $day = fn ($v) => is_numeric($v) ? max(0, min(self::MAX_DAYS, (int) $v)) : null;
        $phases = array_values(array_filter(array_map(fn ($p) => ['title' => $text($p['title'] ?? '', 255), 'due' => $day($p['due'] ?? null)], array_slice((array) ($s['phases'] ?? []), 0, 60)), fn ($p) => $p['title'] !== ''));
        $labels = array_values(array_unique(array_filter(array_map(fn ($l) => $text($l, 40), (array) ($s['labels'] ?? [])))));
        $raw = array_slice((array) ($s['tasks'] ?? []), 0, self::MAX_TASKS);
        $n = count($raw);
        $index = fn ($v) => is_int($v) && $v >= 0 && $v < $n ? $v : null;
        $phase = fn ($v) => is_int($v) && isset($phases[$v]) ? $v : null;

        $tasks = [];
        foreach (array_values($raw) as $i => $t) {
            $parent = $index($t['parent'] ?? null);
            $tasks[] = [
                'title' => $text($t['title'] ?? '', 255) ?: 'Task '.($i + 1),
                'phase' => $phase($t['phase'] ?? null),
                'parent' => $parent === $i ? null : $parent,
                'priority' => in_array($t['priority'] ?? null, self::PRIORITIES, true) ? $t['priority'] : 'medium',
                'estimate' => is_numeric($t['estimate'] ?? null) && (int) $t['estimate'] > 0 ? min(100000, (int) $t['estimate']) : null,
                'start' => $day($t['start'] ?? null),
                'due' => $day($t['due'] ?? null),
                'checklist' => array_values(array_filter(array_map(fn ($c) => $text($c, 255), array_slice((array) ($t['checklist'] ?? []), 0, 50)))),
                'labels' => array_values(array_intersect(array_map(fn ($l) => $text($l, 40), (array) ($t['labels'] ?? [])), $labels)),
                'after' => array_values(array_unique(array_filter((array) ($t['after'] ?? []), fn ($a) => $index($a) !== null && $a !== $i))),
            ];
        }
        // No subtask chain may come back to itself: break any loop at the task that closes it.
        foreach ($tasks as $i => $t) {
            $seen = [$i => true];
            for ($p = $t['parent']; $p !== null; $p = $tasks[$p]['parent']) {
                if (isset($seen[$p])) {
                    $tasks[$i]['parent'] = null;
                    break;
                }
                $seen[$p] = true;
            }
        }
        // Dependencies must form no loop: keep only edges that respect a topological order.
        $state = [];
        $visit = function (int $i) use (&$visit, &$state, &$tasks): void {
            $state[$i] = 1;
            foreach ($tasks[$i]['after'] as $k => $a) {
                if (($state[$a] ?? 0) === 1) {
                    unset($tasks[$i]['after'][$k]);
                } elseif (($state[$a] ?? 0) === 0) {
                    $visit($a);
                }
            }
            $tasks[$i]['after'] = array_values($tasks[$i]['after']);
            $state[$i] = 2;
        };
        foreach (array_keys($tasks) as $i) {
            if (($state[$i] ?? 0) === 0) {
                $visit($i);
            }
        }

        return [
            'v' => 1,
            'days' => $day($s['days'] ?? null),
            'phases' => $phases,
            'tasks' => $tasks,
            'gates' => array_values(array_filter(array_map(fn ($g) => ['title' => $text($g['title'] ?? '', 255), 'phase' => $phase($g['phase'] ?? null), 'due' => $day($g['due'] ?? null),
                'checks' => array_values(array_filter(array_map(fn ($c) => $text($c, 255), array_slice((array) ($g['checks'] ?? []), 0, 30))))], array_slice((array) ($s['gates'] ?? []), 0, 20)), fn ($g) => $g['title'] !== '')),
            'risks' => array_values(array_filter(array_map(fn ($r) => ['title' => $text($r['title'] ?? '', 255),
                'category' => isset(Governance::CATEGORIES[$r['category'] ?? '']) ? $r['category'] : 'other',
                'probability' => max(1, min(5, (int) ($r['probability'] ?? 3))), 'impact' => max(1, min(5, (int) ($r['impact'] ?? 3)))], array_slice((array) ($s['risks'] ?? []), 0, 50)), fn ($r) => $r['title'] !== '')),
            'budget' => array_values(array_filter(array_map(fn ($b) => ['category' => (string) ($b['category'] ?? ''), 'name' => $text($b['name'] ?? '', 160)], array_slice((array) ($s['budget'] ?? []), 0, 30)),
                fn ($b) => $b['name'] !== '' && isset(BudgetLine::CATEGORIES[$b['category']]))),
            'labels' => $labels,
        ];
    }
}
