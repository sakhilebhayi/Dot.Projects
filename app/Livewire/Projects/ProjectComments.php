<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectComment;
use App\Notifications\NewCommentNotification;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ProjectComments extends Component
{
    public Project $project;

    #[Validate('required|string|max:2000')]
    public string $body = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    #[Computed]
    public function comments()
    {
        return $this->project->comments()->with('user')->latest()->get();
    }

    public function addComment(): void
    {
        $this->authorize('view', $this->project);
        $this->validate();

        $comment = ProjectComment::create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => auth()->id(),
            'body' => $this->body,
        ]);

        $this->body = '';
        unset($this->comments);

        if ($this->project->owner && $this->project->owner->id !== auth()->id()) {
            $this->project->owner->notify(new NewCommentNotification($comment, $this->project));
        }
    }

    public function render(): View
    {
        return view('livewire.projects.project-comments');
    }
}
