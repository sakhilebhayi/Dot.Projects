<?php

namespace App\Livewire\Governance;

use App\Models\Project;
use App\Models\ProjectIssue;
use App\Services\Governance;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @property-read Collection<int, ProjectIssue> $all
 * @property-read Collection<int, ProjectIssue> $issues
 *
 * The issue log: open issues by severity and priority, with late target dates and escalations
 * called out. Logging an issue asks for what triages it; the rest is on the issue's own page.
 */
class IssueLog extends Component
{
    public Project $project;

    #[Url(as: 'show', except: 'open')]
    public string $show = 'open';

    #[Url(as: 'severity', except: '')]
    public string $severity = '';

    public string $title = '';

    public string $category = 'other';

    public string $newSeverity = 'medium';

    public string $priority = 'medium';

    public string $owner = '';

    public string $target = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    #[Computed]
    public function all(): Collection
    {
        return $this->project->issues()->with(['owner:id,name', 'risk:id,number,title'])->get()
            ->sortBy([fn ($a, $b) => (int) $b->isOpen() <=> (int) $a->isOpen(), fn ($a, $b) => $b->weight() <=> $a->weight(), fn ($a, $b) => $a->number <=> $b->number])->values();
    }

    #[Computed]
    public function issues(): Collection
    {
        return $this->all
            ->filter(fn (ProjectIssue $i) => match ($this->show) {
                'done' => ! $i->isOpen(), 'all' => true, default => $i->isOpen()
            })
            ->filter(fn (ProjectIssue $i) => $this->severity === '' || $i->severity === $this->severity)
            ->values();
    }

    public function add(Governance $gov): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $issue = $gov->addIssue($this->project, auth()->user(), [
            'title' => trim($this->title),
            'category' => $this->category,
            'severity' => $this->newSeverity,
            'priority' => $this->priority,
            'owner_id' => $this->owner === '' ? null : (int) $this->owner,
            'target_date' => $this->target === '' ? null : $this->target,
        ]);
        $this->redirectRoute('projects.issues.show', [$this->project, $issue], navigate: false);
    }

    public function render(): View
    {
        return view('livewire.governance.issue-log', [
            'people' => $this->project->team->allUsers()->sortBy('name')->values(),
            'canEdit' => auth()->user()->can('update', $this->project),
        ]);
    }
}
