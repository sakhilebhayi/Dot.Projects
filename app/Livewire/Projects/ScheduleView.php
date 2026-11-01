<?php

namespace App\Livewire\Projects;

use App\Models\Baseline;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectWork;
use App\Services\Schedule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The timeline: tasks as bars on their dates, the critical path in the one blue, the chosen
 * baseline underneath, conflicts named. Bars move by drag or arrow keys; their end resizes by drag
 * or Shift and arrow keys. Every move is checked and saved on the server.
 */
class ScheduleView extends Component
{
    public Project $project;

    #[Url(as: 'zoom', except: 'weeks')]
    public string $zoom = 'weeks';

    #[Url(as: 'baseline', except: '')]
    public string $baseline = '';

    public string $baselineNote = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    public function reschedule(int $taskId, ?string $start, string $due, ProjectWork $work): void
    {
        $task = ProjectTask::query()->where('project_id', $this->project->id)->find($taskId);
        abort_if($task === null, 404);
        $this->authorize('update', $task);
        if ($task->status === 'done') {
            $this->addError('schedule', 'Done work keeps the dates it was finished on.');

            return;
        }
        validator(['start' => $start, 'due' => $due], ['start' => ['nullable', 'date'], 'due' => ['required', 'date']])->validate();
        $work->reschedule($task, $start, $due);
    }

    public function setBaseline(ProjectWork $work): void
    {
        $this->authorize('update', $this->project);
        $this->validate(['baselineNote' => ['nullable', 'string', 'max:255']]);
        $baseline = $work->setBaseline($this->project, auth()->user(), $this->baselineNote);
        $this->baseline = (string) $baseline->id;
        $this->reset('baselineNote');
    }

    public function render(): View
    {
        $baselines = $this->project->baselines()->with('creator:id,name')->get();
        $chosen = $this->baseline === '' ? $baselines->first() : $baselines->firstWhere('id', (int) $this->baseline);

        return view('livewire.projects.schedule-view', [
            'schedule' => new Schedule($this->project),
            'baselines' => $baselines,
            'chosen' => $chosen,
            'base' => Schedule::baselineMap($chosen instanceof Baseline ? $chosen : null),
            'ppd' => $this->zoom === 'months' ? 9 : 26,
            'canEdit' => auth()->user()->can('update', $this->project),
        ]);
    }
}
