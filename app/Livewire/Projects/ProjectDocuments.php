<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Models\User;
use App\Services\Integrations\FilesClient;
use App\Services\ProjectDocuments as Documents;
use App\Services\Workload;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A project's documents (or one task's), filed in Dot.Files and listed here: add one, attach it
 * to a task, upload a new version, open it in Dot.Files, download it, or move it to Dot.Files'
 * trash. Shown on the project's Documents tab and on the task page.
 */
class ProjectDocuments extends Component
{
    use WithFileUploads;

    public Project $project;

    public ?ProjectTask $task = null;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $taskFor = '';

    public bool $acknowledged = false;

    public ?int $replacing = null;

    /** @var TemporaryUploadedFile|null */
    public $replacement = null;

    public function mount(Project $project, ?ProjectTask $task = null): void
    {
        abort_unless($project->canBeSeenBy($this->me()), 403);
        abort_if($task !== null && $task->project_id !== $project->id, 404);
        [$this->project, $this->task] = [$project, $task];
    }

    private function me(): User
    {
        /** @var User $me */
        $me = auth()->user();

        return $me;
    }

    private function doc(int $id): ProjectDocument
    {
        $doc = ProjectDocument::query()->where('project_id', $this->project->id)->find($id);
        abort_if($doc === null, 404);

        return $doc;
    }

    public function add(Documents $documents): void
    {
        $this->resetErrorBag();
        $this->validate(['upload' => ['required', 'file', 'max:25600']], [], ['upload' => 'file']);
        $task = $this->task ?? ($this->taskFor === '' ? null : ProjectTask::query()->where('project_id', $this->project->id)->find((int) $this->taskFor));
        try {
            $documents->add($this->project, $this->me(), $this->upload, $task, $this->acknowledged);
        } catch (ValidationException $e) {
            $this->addError('document', (string) collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('upload', 'taskFor', 'acknowledged');
    }

    public function startReplace(int $id): void
    {
        $this->resetErrorBag();
        $this->replacing = $this->doc($id)->id;
        $this->replacement = null;
    }

    public function replace(Documents $documents): void
    {
        $this->resetErrorBag();
        $this->validate(['replacement' => ['required', 'file', 'max:25600']], [], ['replacement' => 'file']);
        try {
            $documents->replace($this->doc((int) $this->replacing), $this->me(), $this->replacement);
        } catch (ValidationException $e) {
            $this->addError('replace', (string) collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('replacing', 'replacement');
    }

    public function remove(int $id, Documents $documents): void
    {
        $this->resetErrorBag();
        try {
            $documents->remove($this->doc($id), $this->me());
        } catch (ValidationException $e) {
            $this->addError('document', (string) collect($e->errors())->flatten()->first());
        }
    }

    public function render(FilesClient $files): View
    {
        /** @var Team|null $team */
        $team = Team::query()->find($this->project->team_id);

        return view('livewire.projects.project-documents', [
            'docs' => ProjectDocument::query()->where('project_id', $this->project->id)->when($this->task, fn ($q) => $q->where('task_id', $this->task->id))
                ->with(['task:id,title', 'uploader:id,name'])->latest('id')->get(),
            'tasks' => $this->task ? collect() : $this->project->tasks()->whereNull('parent_id')->orderBy('title')->get(['id', 'title']),
            'canEdit' => $this->project->canBeChangedBy($this->me()),
            'ready' => $files->enabled() && filled($team?->infodot_team_uuid),
            'filesOn' => $files->enabled(),
            'oversees' => $team !== null && Workload::oversees($this->me(), $team),
        ]);
    }
}
