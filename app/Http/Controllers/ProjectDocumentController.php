<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\ProjectDocuments;
use App\Services\ProjectSummary;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** A project's Documents tab, and a document's download, through Dot.Files (R-34 P11b). */
class ProjectDocumentController extends Controller
{
    public function index(Project $project): View
    {
        $this->authorize('view', $project);

        return view('projects.documents', ['project' => $project->load('owner'), 'summary' => new ProjectSummary($project)]);
    }

    public function download(Request $request, Project $project, ProjectDocument $document, ProjectDocuments $documents): Response
    {
        $this->authorize('view', $project);
        abort_unless($document->project_id === $project->id, 404);
        /** @var User $me */
        $me = $request->user();
        try {
            return $documents->download($document, $me);
        } catch (ValidationException $e) {
            return redirect()->route('projects.documents', $project)->with('documents_error', (string) collect($e->errors())->flatten()->first());
        }
    }
}
