<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectIssue;
use App\Models\ProjectRisk;
use App\Models\ProjectTask;
use App\Services\ActivityFeed;
use App\Services\ProjectSummary;
use App\Services\Schedule;
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
            'schedule' => new Schedule($project),
            'baseline' => $baseline = $project->baselines()->first(),
            'base' => Schedule::baselineMap($baseline),
            'liveRisks' => $project->risks()->whereIn('status', ProjectRisk::LIVE)->get(),
            'openIssues' => $project->issues()->whereIn('status', ProjectIssue::OPEN)->get(),
        ]);
    }

    /** The work view: every task in a table grouped by milestone, with filters. */
    public function work(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return view('projects.work', ['project' => $project->load('owner'), 'summary' => new ProjectSummary($project)]);
    }

    /** The timeline: dates, critical path, baselines, conflicts. */
    public function schedule(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return view('projects.schedule', ['project' => $project->load('owner'), 'summary' => new ProjectSummary($project)]);
    }

    /** One task: details, checklist and its own comments. */
    public function task(Request $request, Project $project, ProjectTask $task): View
    {
        $this->authorize('view', $task);

        return view('projects.task', ['project' => $project->load('owner'), 'task' => $task, 'summary' => new ProjectSummary($project)]);
    }

    /** Details, archive and restore, duplicate. */
    public function settings(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return view('projects.settings', ['project' => $project->load('owner'), 'summary' => new ProjectSummary($project)]);
    }

    /** The board view: the same tasks in five columns, with quick add for people who can edit. */
    public function board(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return view('projects.board', ['project' => $project->load('owner'), 'summary' => new ProjectSummary($project)]);
    }
}
