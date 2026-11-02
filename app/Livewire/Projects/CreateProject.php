<?php

namespace App\Livewire\Projects;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Services\Activity;
use App\Services\AiProjectPlannerService;
use App\Services\ProjectTemplates;
use App\Support\Methodologies;
use App\Support\StarterTemplates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateProject extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|in:planning,active,on_hold,completed')]
    public string $status = 'planning';

    #[Validate('nullable|date')]
    public string $startDate = '';

    #[Validate('nullable|date|after_or_equal:startDate')]
    public string $dueDate = '';

    /** Who can see the new project: everyone in the business, or only the people on it. */
    #[Validate('required|in:team,private')]
    public string $visibility = 'team';

    /** How the project starts: '' blank, "team:{id}" one of the business's templates, "starter:{key}" a starter. */
    public string $source = '';

    /** How the project is run (Methodologies::ALL), or '' for not said. */
    public string $methodology = '';

    public bool $generating = false;

    public ?string $aiError = null;

    /**
     * currentTeam is null whenever the user's current_team_id has been reset
     * (e.g. removed from their current team, or that team was deleted) and
     * they have no personal team to fall back to. Both action methods below
     * are wire:click-reachable on an already-rendered page, so we can't
     * redirect mid-action — abort(403) matches the ecosystem convention.
     */
    private function resolveCurrentTeam(): ?Team
    {
        return Auth::user()?->currentTeam;
    }

    public function mount(): void
    {
        // The Templates page links here with ?template=… to start from one.
        $ref = (string) request()->query('template', '');
        if ($ref !== '' && ($team = $this->resolveCurrentTeam()) && app(ProjectTemplates::class)->find($team, $ref)) {
            $this->source = $ref;
            $this->updatedSource();
        }
    }

    /** Choosing a template brings its methodology, and a start date to lay its plan out from. */
    public function updatedSource(): void
    {
        $team = $this->resolveCurrentTeam();
        $template = $team && $this->source !== '' ? app(ProjectTemplates::class)->find($team, $this->source) : null;
        if ($template === null) {
            $this->source = '';

            return;
        }
        $this->methodology = (string) ($template['methodology'] ?? '');
        if ($this->startDate === '') {
            $this->startDate = Carbon::today()->toDateString();
        }
    }

    public function save(): void
    {
        $this->authorize('create', Project::class);
        $this->validate();
        $this->validate(['methodology' => ['nullable', Rule::in(array_keys(Methodologies::ALL))]]);

        $team = $this->resolveCurrentTeam();
        if (! $team) {
            abort(403, 'No active team selected.');
        }

        if ($this->source !== '') {
            $this->validate(['startDate' => ['required', 'date']], ['startDate.required' => 'Choose when it starts: the template\'s plan is laid out from that day.']);
            $project = app(ProjectTemplates::class)->create($team, auth()->user(), $this->source, [
                'name' => trim($this->name), 'description' => trim($this->description) ?: null, 'visibility' => $this->visibility,
                'methodology' => $this->methodology ?: null, 'start' => Carbon::parse($this->startDate), 'due' => $this->dueDate !== '' ? Carbon::parse($this->dueDate) : null,
            ]);
            session()->flash('flash.banner', 'Made from the template. Every task is not started yet, with no one assigned: change anything to fit.');
            $this->redirect(route('projects.show', $project));

            return;
        }

        $project = Project::create([
            'team_id' => $team->id,
            'owner_id' => auth()->id(),
            'name' => $this->name,
            'description' => $this->description ?: null,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'methodology' => $this->methodology ?: null,
            'start_date' => $this->startDate ?: null,
            'due_date' => $this->dueDate ?: null,
        ]);

        Activity::record('project.created', $project, ['status' => $project->status]);

        $this->redirect(route('projects.show', $project));
    }

    /** Whether AI planning is set up here; without it the button offers a labelled example plan. */
    public function aiReady(): bool
    {
        return app(AiProjectPlannerService::class)->isConfigured();
    }

    public function generatePlan(): void
    {
        $this->authorize('create', Project::class);
        $this->validate();

        $team = $this->resolveCurrentTeam();
        if (! $team) {
            abort(403, 'No active team selected.');
        }

        // Each plan costs an AI call: ten an hour per person is plenty for real use.
        if (RateLimiter::tooManyAttempts('ai-plan:'.auth()->id(), 10)) {
            $this->aiError = 'You have drafted several plans in the last hour. Create the project now and try again later.';

            return;
        }
        RateLimiter::hit('ai-plan:'.auth()->id(), 3600);

        $this->generating = true;
        $this->aiError = null;

        $project = Project::create([
            'team_id' => $team->id,
            'owner_id' => auth()->id(),
            'name' => $this->name,
            'description' => $this->description ?: null,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'methodology' => $this->methodology ?: null,
            'start_date' => $this->startDate ?: null,
            'due_date' => $this->dueDate ?: null,
        ]);

        $service = app(AiProjectPlannerService::class);
        $example = ! $service->isConfigured();
        $plan = $example ? $service->examplePlan($project) : $service->generatePlan($project, auth()->id());

        if ($plan === null) {
            $this->generating = false;
            session()->flash('flash.banner', 'The AI could not draft a plan, so the project has none yet.');
            session()->flash('flash.bannerStyle', 'warning');
            $this->redirect(route('projects.show', $project));

            return;
        }

        DB::transaction(function () use ($plan, $project) {
            foreach ($plan['milestones'] ?? [] as $i => $ms) {
                $milestone = Milestone::create([
                    'project_id' => $project->id,
                    'title' => $ms['title'],
                    'description' => $ms['description'] ?? null,
                    'sort_order' => $i,
                ]);

                foreach ($ms['tasks'] ?? [] as $j => $t) {
                    ProjectTask::create([
                        'project_id' => $project->id,
                        'milestone_id' => $milestone->id,
                        'title' => $t['title'],
                        'priority' => in_array($t['priority'] ?? null, ['low', 'medium', 'high', 'urgent'], true) ? $t['priority'] : 'medium',
                        'status' => 'backlog',
                        'sort_order' => $j,
                    ]);
                }
            }
        });

        Activity::record($example ? 'project.example_plan_added' : 'project.ai_plan_added', $project);
        session()->flash('flash.banner', $example
            ? 'No AI here yet: a generic example plan was added. Edit it to fit.'
            : 'AI drafted this plan. It is a proposal: check every task.');
        session()->flash('flash.bannerStyle', $example ? 'warning' : 'success');

        $this->generating = false;
        $this->redirect(route('projects.show', $project));
    }

    public function render(): View
    {
        $team = $this->resolveCurrentTeam();
        $templates = app(ProjectTemplates::class);

        return view('livewire.projects.create-project', [
            'own' => $team ? $templates->forTeam($team) : collect(),
            'starters' => collect(StarterTemplates::all())->groupBy('industry', true),
            'chosen' => $team && $this->source !== '' ? $templates->find($team, $this->source) : null,
        ]);
    }
}
