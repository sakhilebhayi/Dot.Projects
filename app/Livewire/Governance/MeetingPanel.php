<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\ProjectMeeting;
use App\Services\ActivityFeed;
use App\Services\Meetings;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One meeting: when, where, who came, the agenda and the minutes; the decisions taken (into the
 * decision register) and the actions agreed (into the action register), each recorded from here.
 */
class MeetingPanel extends Component
{
    public Project $project;

    public ProjectMeeting $meeting;

    public string $title = '';

    public string $kind = 'team';

    public string $heldAt = '';

    public string $location = '';

    public string $guests = '';

    public string $agenda = '';

    public string $minutes = '';

    /** @var list<string> */
    public array $attendees = [];

    public string $actionTitle = '';

    public string $actionOwner = '';

    public string $actionDue = '';

    public string $decisionTitle = '';

    public string $decisionOutcome = '';

    public function mount(Project $project, ProjectMeeting $meeting): void
    {
        abort_unless($meeting->project_id === $project->id, 404);
        $this->authorize('view', $meeting);
        $this->project = $project;
        $this->meeting = $meeting;
        $this->fillFromMeeting();
    }

    private function fillFromMeeting(): void
    {
        $m = $this->meeting;
        $this->title = $m->title;
        $this->kind = $m->kind;
        $this->heldAt = $m->held_at->format('Y-m-d\TH:i');
        $this->location = (string) $m->location;
        $this->guests = (string) $m->guests;
        $this->agenda = (string) $m->agenda;
        $this->minutes = (string) $m->minutes;
        $this->attendees = $m->attendees()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
    }

    public function save(Meetings $meetings): void
    {
        $this->authorize('update', $this->meeting);
        $this->resetErrorBag();
        $t = fn (string $v) => trim($v) === '' ? null : trim($v);
        $meetings->update($this->meeting, [
            'title' => trim($this->title),
            'kind' => $this->kind,
            'held_at' => $this->heldAt === '' ? null : $this->heldAt,
            'location' => $t($this->location),
            'guests' => $t($this->guests),
            'agenda' => $t($this->agenda),
            'minutes' => $t($this->minutes),
        ], array_map('intval', $this->attendees));
        $this->meeting->refresh();
        $this->fillFromMeeting();
        session()->flash('saved', 'Saved.');
    }

    public function addAction(Meetings $meetings): void
    {
        $this->authorize('update', $this->meeting);
        $this->resetErrorBag();
        $this->prefixed('action', fn () => $meetings->addAction($this->project, auth()->user(), [
            'title' => trim($this->actionTitle),
            'owner_id' => $this->actionOwner === '' ? null : (int) $this->actionOwner,
            'due_date' => $this->actionDue === '' ? null : $this->actionDue,
        ], $this->meeting));
        $this->reset('actionTitle', 'actionOwner', 'actionDue');
    }

    public function toggleAction(int $id, Meetings $meetings): void
    {
        $this->authorize('update', $this->meeting);
        $action = ProjectAction::query()->where('meeting_id', $this->meeting->id)->find($id);
        abort_if($action === null, 404);
        $meetings->toggleAction($action);
    }

    public function addDecision(Meetings $meetings): void
    {
        $this->authorize('update', $this->meeting);
        $this->resetErrorBag();
        $this->prefixed('decision', fn () => $meetings->recordDecision($this->meeting, auth()->user(), $this->decisionTitle, $this->decisionOutcome));
        $this->reset('decisionTitle', 'decisionOutcome');
    }

    /** The side forms share field names with the record, so their errors are reported apart. */
    private function prefixed(string $form, callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$form.'_'.$k => $m])->all());
        }
    }

    public function render(): View
    {
        return view('livewire.governance.meeting-panel', [
            'people' => $this->project->people(),
            'actions' => $this->meeting->actions()->with('owner:id,name')->get(),
            'decisions' => $this->meeting->decisions()->get(),
            'history' => ActivityFeed::forSubject($this->meeting),
            'canEdit' => auth()->user()->can('update', $this->meeting),
        ]);
    }
}
