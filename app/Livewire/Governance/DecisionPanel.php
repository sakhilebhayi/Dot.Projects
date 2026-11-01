<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectDecision;
use App\Services\ActivityFeed;
use App\Services\ChangeControl;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One decision: the question, its context and the options weighed; for a proposed one, who decides
 * and by when, and recording the outcome; for a decided one, marking it superseded by a later one.
 */
class DecisionPanel extends Component
{
    public Project $project;

    public ProjectDecision $decision;

    public string $title = '';

    public string $context = '';

    public string $options = '';

    public string $owner = '';

    public string $decideBy = '';

    public string $outcome = '';

    public string $replacedBy = '';

    public function mount(Project $project, ProjectDecision $decision): void
    {
        abort_unless($decision->project_id === $project->id, 404);
        $this->authorize('view', $decision);
        $this->project = $project;
        $this->decision = $decision;
        $this->fillFromDecision();
    }

    private function fillFromDecision(): void
    {
        $d = $this->decision;
        $this->title = $d->title;
        $this->context = (string) $d->context;
        $this->options = (string) $d->options;
        $this->owner = (string) $d->owner_id;
        $this->decideBy = (string) $d->decide_by?->toDateString();
        $this->outcome = (string) $d->outcome;
    }

    public function save(ChangeControl $cc): void
    {
        $this->authorize('update', $this->decision);
        $this->resetErrorBag();
        $t = fn (string $v) => trim($v) === '' ? null : trim($v);
        $cc->updateDecision($this->decision, [
            'title' => trim($this->title), 'context' => $t($this->context), 'options' => $t($this->options),
            'owner_id' => $this->owner === '' ? null : (int) $this->owner, 'decide_by' => $t($this->decideBy),
        ]);
        $this->decision->refresh();
        $this->fillFromDecision();
        session()->flash('saved', 'Saved.');
    }

    public function decide(ChangeControl $cc): void
    {
        $this->authorize('update', $this->decision);
        $this->resetErrorBag();
        $cc->decideDecision($this->decision, auth()->user(), $this->outcome);
        $this->decision->refresh();
        $this->fillFromDecision();
    }

    public function supersede(ChangeControl $cc): void
    {
        $this->authorize('update', $this->decision);
        $this->resetErrorBag();
        $cc->supersede($this->decision, $this->replacedBy === '' ? null : (int) $this->replacedBy);
        $this->decision->refresh();
    }

    public function render(): View
    {
        return view('livewire.governance.decision-panel', [
            'people' => $this->project->people(),
            'later' => $this->project->decisions()->where('status', 'decided')->where('number', '>', $this->decision->number)->orderBy('number')->get(['id', 'number', 'title']),
            'history' => ActivityFeed::forSubject($this->decision),
            'canEdit' => auth()->user()->can('update', $this->decision),
        ]);
    }
}
