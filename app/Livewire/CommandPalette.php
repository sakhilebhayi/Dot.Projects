<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\User;
use App\Services\CommandSearch;
use App\Services\ProjectWork;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The command palette (R-34 P13b, brief §17): Ctrl or Cmd and K, or the search button in the top bar. Finds
 * projects, tasks, documents, reports and pages the person can open, and offers what they may do here: start a
 * project, or add what they typed as a task in the project they are looking at.
 */
class CommandPalette extends Component
{
    public string $q = '';

    /** Set when the palette is first opened: until then it finds nothing, so pages carry no results or queries. */
    public bool $open = false;

    /** The project on screen when the palette opened, if any; checked again before anything is done with it. */
    public ?int $projectId = null;

    public function mount(?int $projectId = null): void
    {
        $this->projectId = $projectId;
    }

    private function me(): User
    {
        /** @var User $me */
        $me = auth()->user();

        return $me;
    }

    /** The project in context, only when the person can still see it. */
    private function here(): ?Project
    {
        return $this->projectId !== null ? Project::query()->find($this->projectId) : null;
    }

    public function addTask(ProjectWork $work): void
    {
        $project = $this->here();
        abort_if($project === null, 404);
        $this->authorize('update', $project);
        $this->validate(['q' => ['required', 'string', 'max:255']], [], ['q' => 'task']);
        $task = $work->addTask($project, $this->q);
        $this->redirect(route('projects.tasks.show', [$project, $task]));
    }

    public function render(CommandSearch $search): View
    {
        $here = $this->here();
        $me = $this->me();

        return view('livewire.command-palette', [
            'groups' => $this->open ? $search->search($me, $this->q) : [],
            'here' => $here,
            'canAdd' => $this->open && $here !== null && $me->can('update', $here) && trim($this->q) !== '',
            'canCreate' => $this->open && $me->currentTeam !== null && $me->can('create', Project::class),
        ]);
    }
}
