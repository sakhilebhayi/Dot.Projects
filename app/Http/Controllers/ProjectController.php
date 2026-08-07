<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function show(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        $project->load(['owner', 'milestones.tasks', 'members']);

        return view('projects.show', compact('project'));
    }
}
