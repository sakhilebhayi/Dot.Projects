<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectChange;
use App\Models\ProjectDecision;
use App\Models\ProjectIssue;
use App\Models\ProjectRisk;
use App\Services\ProjectSummary;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The Governance tab: risks, issues, change requests and decisions, each with its own page. */
class GovernanceController extends Controller
{
    public function risks(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return $this->page($project, 'risks');
    }

    public function risk(Request $request, Project $project, ProjectRisk $risk): View
    {
        $this->authorize('view', $risk);

        return $this->page($project, 'risks', ['risk' => $risk]);
    }

    public function issues(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return $this->page($project, 'issues');
    }

    public function issue(Request $request, Project $project, ProjectIssue $issue): View
    {
        $this->authorize('view', $issue);

        return $this->page($project, 'issues', ['issue' => $issue]);
    }

    public function changes(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return $this->page($project, 'changes');
    }

    public function change(Request $request, Project $project, ProjectChange $change): View
    {
        $this->authorize('view', $change);

        return $this->page($project, 'changes', ['change' => $change]);
    }

    public function decisions(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return $this->page($project, 'decisions');
    }

    public function decision(Request $request, Project $project, ProjectDecision $decision): View
    {
        $this->authorize('view', $decision);

        return $this->page($project, 'decisions', ['decision' => $decision]);
    }

    /** @param array<string, mixed> $extra */
    private function page(Project $project, string $section, array $extra = []): View
    {
        return view('governance.page', [
            'project' => $project->load('owner'),
            'summary' => new ProjectSummary($project),
            'section' => $section,
            'liveRisks' => $project->risks()->whereIn('status', ProjectRisk::LIVE)->count(),
            'openIssues' => $project->issues()->whereIn('status', ProjectIssue::OPEN)->count(),
            'pendingChanges' => $project->changes()->whereIn('status', ['draft', 'submitted'])->count(),
            'openDecisions' => $project->decisions()->where('status', 'proposed')->count(),
        ] + $extra);
    }
}
