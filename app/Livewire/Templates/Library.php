<?php

namespace App\Livewire\Templates;

use App\Models\ProjectTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\ProjectTemplates;
use App\Support\StarterTemplates;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The business's own project templates and the starter library (R-34 P13a). Everyone in the business can look and
 * start a project from one; whoever saved a template, and the owner and admins, rename or remove it. Templates are
 * saved from a project's settings.
 */
class Library extends Component
{
    public ?int $editing = null;

    public string $name = '';

    public string $description = '';

    private function me(): User
    {
        /** @var User $me */
        $me = auth()->user();

        return $me;
    }

    private function team(): Team
    {
        /** @var Team|null $team */
        $team = $this->me()->currentTeam;
        abort_if($team === null, 403);

        return $team;
    }

    private function template(int $id): ProjectTemplate
    {
        return ProjectTemplate::query()->where('team_id', $this->team()->id)->findOrFail($id);
    }

    public function edit(int $id): void
    {
        $t = $this->template($id);
        abort_unless(app(ProjectTemplates::class)->canManage($this->me(), $t), 403);
        $this->editing = $t->id;
        $this->name = $t->name;
        $this->description = (string) $t->description;
        $this->resetErrorBag();
    }

    public function save(ProjectTemplates $templates): void
    {
        if ($this->editing === null) {
            return;
        }
        try {
            $templates->rename($this->template($this->editing), $this->me(), $this->name, $this->description);
            $this->editing = null;
        } catch (ValidationException $e) {
            $this->addError('name', (string) collect($e->errors())->flatten()->first());
        }
    }

    public function remove(int $id, ProjectTemplates $templates): void
    {
        $templates->remove($this->template($id), $this->me());
        $this->editing = null;
    }

    public function render(ProjectTemplates $templates): View
    {
        $team = $this->team();

        return view('livewire.templates.library', [
            'own' => $templates->forTeam($team),
            'starters' => collect(StarterTemplates::all())->groupBy('industry', true),
            'canCreate' => $this->me()->hasTeamPermission($team, 'create'),
        ]);
    }
}
