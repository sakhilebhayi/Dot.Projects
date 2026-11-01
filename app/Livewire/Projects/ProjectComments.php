<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
use App\Notifications\NewCommentNotification;
use App\Services\Activity;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ProjectComments extends Component
{
    public Project $project;

    /** When set, the thread belongs to this task instead of the whole project. */
    public ?ProjectTask $task = null;

    #[Validate('required|string|max:2000')]
    public string $body = '';

    public function mount(Project $project, ?ProjectTask $task = null): void
    {
        $this->authorize('view', $project);
        abort_if($task !== null && $task->project_id !== $project->id, 404);

        $this->project = $project;
        $this->task = $task;
    }

    #[Computed]
    public function comments()
    {
        return ($this->task ?? $this->project)->comments()->with('user')->latest()->get();
    }

    public function addComment(): void
    {
        $this->authorize('comment', $this->project);
        $this->validate();

        $comment = ProjectComment::create([
            'commentable_type' => ($this->task ?? $this->project)->getMorphClass(),
            'commentable_id' => ($this->task ?? $this->project)->id,
            'user_id' => auth()->id(),
            'body' => $this->body,
        ]);
        Activity::record('comment.added', $comment, [], $this->project);

        $this->body = '';
        unset($this->comments);

        // A task's thread tells the person doing the task; the project's thread tells its owner.
        $recipient = $this->task ? $this->task->assignee : $this->project->owner;
        if ($recipient && $recipient->id !== auth()->id()) {
            $recipient->notify(new NewCommentNotification($comment, $this->project, $this->task));
        }
    }

    public function render(): View
    {
        return view('livewire.projects.project-comments');
    }
}
