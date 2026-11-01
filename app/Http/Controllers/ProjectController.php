<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ActivityFeed;
use App\Services\ProjectSummary;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /** The overview: when the project lands, what is in the way, milestones, work, people, activity. */
    public function show(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return view('projects.show', [
            'project' => $project->load('owner'),
            'summary' => new ProjectSummary($project),
            'activity' => ActivityFeed::forProject($project),
        ]);
    }

    /** The board view: the same tasks in five columns, with quick add for people who can edit. */
    public function board(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return view('projects.board', ['project' => $project->load('owner'), 'summary' => new ProjectSummary($project)]);
    }
}
