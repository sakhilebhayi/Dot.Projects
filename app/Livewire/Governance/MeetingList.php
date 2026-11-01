<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectMeeting;
use App\Services\Meetings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * @property-read Collection<int, ProjectMeeting> $meetings
 *
 * Meetings coming up and held, with how many actions and decisions each produced and whether its
 * minutes are written. Steering committee meetings are marked by kind.
 */
class MeetingList extends Component
{
    public Project $project;

    public string $title = '';

    public string $kind = 'team';

    public string $heldAt = '';

    public string $location = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->heldAt = Carbon::now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');
    }

    #[Computed]
    public function meetings(): Collection
    {
        return $this->project->meetings()->withCount(['actions', 'decisions', 'attendees'])->orderByDesc('held_at')->get();
    }

    public function add(Meetings $meetings): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $m = $meetings->add($this->project, auth()->user(), [
            'title' => trim($this->title),
            'kind' => $this->kind,
            'held_at' => $this->heldAt === '' ? null : $this->heldAt,
            'location' => trim($this->location) === '' ? null : trim($this->location),
        ]);
        $this->redirectRoute('projects.meetings.show', [$this->project, $m], navigate: false);
    }

    public function render(): View
    {
        return view('livewire.governance.meeting-list', ['canEdit' => auth()->user()->can('update', $this->project)]);
    }
}
