<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Services\ProjectStatuses;
use App\Services\ProjectTemplates;
use App\Services\ProjectWork;
use App\Support\Methodologies;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * A project's details and how it is run, archive and restore, duplicate, and saving it as a template. Completing a project stays with the
 * closure confirmation on the board, so it is not a status anyone sets here.
 */
class ProjectSettings extends Component
{
    public const EDITABLE_STATUSES = ['planning' => 'Planning', 'active' => 'Active', 'on_hold' => 'On hold'];

    public Project $project;

    public string $name = '';

    public string $description = '';

    public string $status = 'planning';

    public string $startDate = '';

    public string $dueDate = '';

    public string $methodology = '';

    public string $copyName = '';

    public string $templateName = '';

    public string $templateDescription = '';

    /** Workflow (R-34 P13c): the status being renamed or removed, and the add form. */
    public ?int $renaming = null;

    public string $statusName = '';

    public ?int $removing = null;

    public string $moveTo = '';

    public string $newStatusName = '';

    public string $newStatusCategory = 'in_progress';

    public bool $confirmingArchive = false;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->name = $project->name;
        $this->description = (string) $project->description;
        $this->status = $project->status;
        $this->startDate = (string) $project->start_date?->toDateString();
        $this->dueDate = (string) $project->due_date?->toDateString();
        $this->methodology = (string) $project->methodology;
        $this->copyName = 'Copy of '.$project->name;
        $this->templateName = $project->name;
    }

    public function save(ProjectWork $work): void
    {
        $this->authorize('update', $this->project);
        $editable = array_key_exists($this->project->status, self::EDITABLE_STATUSES);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => $editable ? ['required', Rule::in(array_keys(self::EDITABLE_STATUSES))] : ['nullable'],
            'startDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'methodology' => ['nullable', Rule::in(array_keys(Methodologies::ALL))],
        ], ['dueDate.after_or_equal' => 'The due date cannot be before the start date.']);

        $work->updateProject($this->project, [
            'name' => trim($data['name']),
            'description' => trim((string) $data['description']) ?: null,
            'start_date' => $data['startDate'] ?: null,
            'due_date' => $data['dueDate'] ?: null,
            'methodology' => $data['methodology'] ?: null,
        ] + ($editable ? ['status' => $data['status']] : []));

        session()->flash('settings-saved', 'Saved.');
    }

    public function archive(ProjectWork $work): void
    {
        $this->authorize('archive', $this->project);
        $work->archive($this->project, auth()->user());
        session()->flash('flash.banner', $this->project->name.' is archived. It is read-only until someone restores it.');
        $this->redirect(route('projects.settings', $this->project));
    }

    public function restore(ProjectWork $work): void
    {
        $this->authorize('archive', $this->project);
        $work->restore($this->project);
        session()->flash('flash.banner', $this->project->name.' is restored.');
        $this->redirect(route('projects.settings', $this->project));
    }

    public function duplicate(ProjectWork $work): void
    {
        $this->authorize('duplicate', $this->project);
        $this->validate(['copyName' => ['required', 'string', 'max:255']], [], ['copyName' => 'name']);
        $copy = $work->duplicate($this->project, auth()->user(), trim($this->copyName));
        session()->flash('flash.banner', 'Made '.$copy->name.' with the same milestones and tasks, all not started.');
        $this->redirect(route('projects.show', $copy));
    }

    private function workflowStatus(int $id): ProjectStatus
    {
        $this->authorize('manageMembers', $this->project);

        /** @var ProjectStatus $status */
        $status = $this->project->statuses()->findOrFail($id);

        return $status;
    }

    /** Runs a workflow change and shows its refusal, if any, beside the workflow. */
    private function workflow(callable $change): void
    {
        $this->resetErrorBag('workflow');
        try {
            $change();
        } catch (ValidationException $e) {
            $this->addError('workflow', (string) collect($e->errors())->flatten()->first());
        }
        $this->project->unsetRelation('statuses');
    }

    public function customiseWorkflow(ProjectStatuses $statuses): void
    {
        $this->authorize('manageMembers', $this->project);
        $this->workflow(fn () => $statuses->customise($this->project, auth()->user()));
    }

    public function addStatus(ProjectStatuses $statuses): void
    {
        $this->authorize('manageMembers', $this->project);
        $this->workflow(function () use ($statuses) {
            $statuses->add($this->project, auth()->user(), $this->newStatusName, $this->newStatusCategory);
            $this->newStatusName = '';
        });
    }

    public function renameStatus(int $id): void
    {
        $this->renaming = $this->workflowStatus($id)->id;
        $this->statusName = $this->workflowStatus($id)->name;
        $this->removing = null;
    }

    public function saveStatusName(ProjectStatuses $statuses): void
    {
        if ($this->renaming === null) {
            return;
        }
        $status = $this->workflowStatus($this->renaming);
        $this->workflow(function () use ($statuses, $status) {
            $statuses->rename($status, auth()->user(), $this->statusName);
            $this->renaming = null;
        });
    }

    public function moveStatus(int $id, int $by, ProjectStatuses $statuses): void
    {
        $status = $this->workflowStatus($id);
        $this->workflow(fn () => $statuses->move($status, $by));
    }

    public function removeStatus(int $id, ProjectStatuses $statuses): void
    {
        $status = $this->workflowStatus($id);
        $this->removing = $status->id;
        $this->renaming = null;
        $this->moveTo = (string) ($statuses->for($this->project)->first(fn ($s) => $s['id'] !== $status->id && $s['category'] === $status->category)['key']
            ?? $statuses->for($this->project)->first(fn ($s) => $s['id'] !== $status->id)['key'] ?? '');
    }

    public function confirmRemoveStatus(ProjectStatuses $statuses): void
    {
        if ($this->removing === null) {
            return;
        }
        $status = $this->workflowStatus($this->removing);
        $this->workflow(function () use ($statuses, $status) {
            $statuses->remove($status, auth()->user(), $this->moveTo);
            $this->removing = null;
        });
    }

    public function resetWorkflow(ProjectStatuses $statuses): void
    {
        $this->authorize('manageMembers', $this->project);
        $this->workflow(fn () => $statuses->reset($this->project, auth()->user()));
    }

    public function saveTemplate(ProjectTemplates $templates): void
    {
        try {
            $template = $templates->capture($this->project, auth()->user(), $this->templateName, $this->templateDescription);
        } catch (ValidationException $e) {
            $this->addError('templateName', (string) collect($e->errors())->flatten()->first());

            return;
        }
        session()->flash('flash.banner', 'Saved '.$template->name.' as a template. Anyone in the business can now start a project from it.');
        $this->redirect(route('templates.index'));
    }

    public function render(): View
    {
        $workflow = app(ProjectStatuses::class)->for($this->project);

        return view('livewire.projects.project-settings', [
            'statuses' => self::EDITABLE_STATUSES,
            'canTemplate' => app(ProjectTemplates::class)->canSave(auth()->user(), $this->project),
            'workflow' => $workflow,
            'customWorkflow' => $workflow->first()['id'] !== null,
            'canWorkflow' => auth()->user()->can('manageMembers', $this->project) && ! $this->project->isArchived(),
            'taskCounts' => DB::table('project_tasks')->where('project_id', $this->project->id)->selectRaw('status_id, status, count(*) as n')->groupBy('status_id', 'status')->get()
                ->reduce(function (array $carry, $row) use ($workflow) {
                    $key = $row->status_id !== null && $workflow->contains('id', $row->status_id) ? 's'.$row->status_id : ($workflow->firstWhere('category', $row->status)['key'] ?? null);
                    if ($key !== null) {
                        $carry[$key] = ($carry[$key] ?? 0) + (int) $row->n;
                    }

                    return $carry;
                }, []),
        ]);
    }
}
