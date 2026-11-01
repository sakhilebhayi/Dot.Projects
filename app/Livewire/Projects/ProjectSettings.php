<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\ProjectWork;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * A project's details, archive and restore, and duplicate. Completing a project stays with the
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

    public string $copyName = '';

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
        $this->copyName = 'Copy of '.$project->name;
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
        ], ['dueDate.after_or_equal' => 'The due date cannot be before the start date.']);

        $work->updateProject($this->project, [
            'name' => trim($data['name']),
            'description' => trim((string) $data['description']) ?: null,
            'start_date' => $data['startDate'] ?: null,
            'due_date' => $data['dueDate'] ?: null,
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

    public function render(): View
    {
        return view('livewire.projects.project-settings', ['statuses' => self::EDITABLE_STATUSES]);
    }
}
