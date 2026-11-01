<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectIssue;
use App\Services\ActivityFeed;
use App\Services\Governance;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One issue: what happened, how bad, who owns it, the root cause and the corrective action, the
 * target date, how it was resolved, and escalation. Saved with one button.
 */
class IssuePanel extends Component
{
    public Project $project;

    public ProjectIssue $issue;

    public string $title = '';

    public string $description = '';

    public string $category = 'other';

    public string $severity = 'medium';

    public string $priority = 'medium';

    public string $owner = '';

    public string $status = 'open';

    public string $rootCause = '';

    public string $correctiveAction = '';

    public string $target = '';

    public string $resolution = '';

    public string $escalationNote = '';

    public function mount(Project $project, ProjectIssue $issue): void
    {
        abort_unless($issue->project_id === $project->id, 404);
        $this->authorize('view', $issue);
        $this->project = $project;
        $this->issue = $issue;
        $this->fillFromIssue();
    }

    private function fillFromIssue(): void
    {
        $i = $this->issue;
        $this->title = $i->title;
        $this->description = (string) $i->description;
        $this->category = $i->category;
        $this->severity = $i->severity;
        $this->priority = $i->priority;
        $this->owner = (string) $i->owner_id;
        $this->status = $i->status;
        $this->rootCause = (string) $i->root_cause;
        $this->correctiveAction = (string) $i->corrective_action;
        $this->target = (string) $i->target_date?->toDateString();
        $this->resolution = (string) $i->resolution;
    }

    public function save(Governance $gov): void
    {
        $this->authorize('update', $this->issue);
        $this->resetErrorBag();
        $t = fn (string $v) => trim($v) === '' ? null : trim($v);
        $gov->updateIssue($this->issue, auth()->user(), [
            'title' => trim($this->title),
            'description' => $t($this->description),
            'category' => $this->category,
            'severity' => $this->severity,
            'priority' => $this->priority,
            'owner_id' => $this->owner === '' ? null : (int) $this->owner,
            'status' => $this->status,
            'root_cause' => $t($this->rootCause),
            'corrective_action' => $t($this->correctiveAction),
            'target_date' => $t($this->target),
            'resolution' => $t($this->resolution),
        ]);
        $this->issue->refresh();
        $this->fillFromIssue();
        session()->flash('saved', 'Saved.');
    }

    public function escalate(Governance $gov): void
    {
        $this->authorize('update', $this->issue);
        $this->resetErrorBag();
        $gov->escalate($this->issue, auth()->user(), $this->escalationNote);
        $this->reset('escalationNote');
        $this->issue->refresh();
    }

    public function withdraw(Governance $gov): void
    {
        $this->authorize('update', $this->issue);
        $gov->withdrawEscalation($this->issue);
        $this->issue->refresh();
    }

    public function render(): View
    {
        return view('livewire.governance.issue-panel', [
            'people' => $this->project->people(),
            'history' => ActivityFeed::forSubject($this->issue),
            'canEdit' => auth()->user()->can('update', $this->issue),
        ]);
    }
}
