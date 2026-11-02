<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectTask;
use App\Models\Team;
use App\Models\User;
use App\Services\Integrations\FilesClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project documents, kept in the business's Dot.Files workspace (one folder per project, named
 * "Dot.Projects - <project>"), referenced here. People who can change a project add, replace and
 * remove its documents; everyone who can see the project can download them, always through
 * Dot.Projects, which checks the project first. Removing moves the file to the business's
 * Dot.Files trash (30 days, owners restore it there); nothing is deleted for good from here.
 * The activity log records each change by document id only.
 */
final class ProjectDocuments
{
    public function __construct(private FilesClient $files) {}

    public function add(Project $project, User $by, UploadedFile $upload, ?ProjectTask $task, bool $privateAcknowledged): ProjectDocument
    {
        $this->assertCanChange($project, $by);
        if ($task !== null && $task->project_id !== $project->id) {
            throw ValidationException::withMessages(['document' => 'Choose a task in this project.']);
        }
        if ($project->isPrivate() && ! $privateAcknowledged) {
            throw ValidationException::withMessages(['document' => 'This project is private, but Dot.Files shows documents to whoever may open its folder there. Tick the box to file it anyway.']);
        }
        [$name, $bytes] = $this->read($upload);
        $stored = $this->files->store($this->team($project), $name, $bytes, 'Dot.Projects - '.$project->name);

        $doc = new ProjectDocument([
            'task_id' => $task?->id, 'files_uuid' => (string) $stored['uuid'], 'name' => (string) ($stored['name'] ?? $name), 'size' => (int) ($stored['size'] ?? strlen($bytes)),
            'mime' => $stored['mime'] ?? null, 'reference' => $stored['reference'] ?? null, 'open_url' => $stored['open_url'] ?? null, 'version' => 1,
        ]);
        $doc->forceFill(['project_id' => $project->id, 'uploaded_by' => $by->id])->save();
        Activity::record('document.added', $doc, $task ? ['task_id' => $task->id] : [], $project);

        return $doc;
    }

    /** Saves a new version in Dot.Files, refused if someone saved a newer one there meanwhile. */
    public function replace(ProjectDocument $doc, User $by, UploadedFile $upload): void
    {
        $project = $doc->project;
        $this->assertCanChange($project, $by);
        [, $bytes] = $this->read($upload);
        $saved = $this->files->update($this->team($project), $doc->files_uuid, $bytes, $doc->version);
        $doc->update([
            'size' => (int) ($saved['size'] ?? strlen($bytes)), 'mime' => $saved['mime'] ?? $doc->mime,
            'version' => isset($saved['version']) ? (int) $saved['version'] : ($doc->version ?? 1) + 1, 'reference' => $saved['reference'] ?? $doc->reference,
        ]);
        Activity::record('document.updated', $doc, ['version' => $doc->version], $project);
    }

    /** Moves the file to the business's Dot.Files trash and forgets it here. */
    public function remove(ProjectDocument $doc, User $by): void
    {
        $project = $doc->project;
        $this->assertCanChange($project, $by);
        try {
            $this->files->trash($this->team($project), $doc->files_uuid);
        } catch (ValidationException $e) {
            if ($e->status !== 422 || ! str_contains((string) collect($e->errors())->flatten()->first(), 'no longer has this document')) {
                throw $e; // a document already gone from Dot.Files can still be removed here
            }
        }
        Activity::record('document.removed', $doc, [], $project);
        $doc->delete();
    }

    /** The file, fetched from Dot.Files for someone who may see the project. */
    public function download(ProjectDocument $doc, User $by): Response
    {
        abort_unless($doc->project->canBeSeenBy($by), 403);
        $file = $this->files->fetch($this->team($doc->project), $doc->files_uuid);
        $bytes = base64_decode((string) ($file['content_b64'] ?? ''), true);
        abort_if($bytes === false, 502);
        $name = (string) ($file['name'] ?? $doc->name);

        return response($bytes, 200, [
            'Content-Type' => (string) ($file['mime'] ?? 'application/octet-stream'),
            'Content-Disposition' => 'attachment; filename="'.str_replace(['"', "\r", "\n"], '', $name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0: string, 1: string} the cleaned name and the bytes */
    private function read(UploadedFile $upload): array
    {
        $name = trim(basename(str_replace('\\', '/', $upload->getClientOriginalName())));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || ! in_array($ext, FilesClient::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['document' => 'Dot.Files accepts '.implode(', ', FilesClient::EXTENSIONS).'.']);
        }
        if ((int) $upload->getSize() > FilesClient::MAX_BYTES) {
            throw ValidationException::withMessages(['document' => 'Files over 25 MB go into Dot.Files directly.']);
        }
        $bytes = (string) $upload->get(); // works for Livewire's temporary uploads on a storage disk too
        if ($bytes === '') {
            throw ValidationException::withMessages(['document' => 'That file is empty.']);
        }

        return [mb_substr($name, 0, 255), $bytes];
    }

    private function team(Project $project): Team
    {
        return Team::query()->findOrFail($project->team_id);
    }

    private function assertCanChange(Project $project, User $by): void
    {
        if (! $project->canBeChangedBy($by)) {
            throw ValidationException::withMessages(['document' => 'Only people who can change this project can add or remove its documents.']);
        }
    }
}
