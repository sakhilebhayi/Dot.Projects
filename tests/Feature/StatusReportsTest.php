<?php

namespace Tests\Feature;

use App\Livewire\Governance\ReportList;
use App\Livewire\Governance\ReportPanel;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\User;
use App\Services\Governance;
use App\Services\StatusReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P5d: status reports. A draft shows the live facts and a suggested rating with its reasons;
 * the manager rates and writes; publishing freezes the facts and tells the team.
 */
class StatusReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
    }

    /** @return array{owner: User, editor: User, viewer: User, project: Project} */
    private function team(): array
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Owner']);
        $editor = User::factory()->create(['name' => 'Sipho Editor', 'current_team_id' => $owner->currentTeam->id]);
        $viewer = User::factory()->create(['name' => 'Vusi Viewer', 'current_team_id' => $owner->currentTeam->id]);
        $owner->currentTeam->users()->attach($editor, ['role' => 'editor']);
        $owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $project = Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Kathu go-live', 'status' => 'active', 'due_date' => '2026-11-30']);

        return ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project->fresh()];
    }

    public function test_the_suggested_rating_follows_the_facts(): void
    {
        ['owner' => $owner, 'project' => $project] = $this->team();
        $this->actingAs($owner);
        $reports = app(StatusReports::class);
        $facts = fn () => $reports->facts($project->fresh(), Carbon::parse('2026-10-27'), Carbon::parse('2026-11-02'));

        $this->assertSame('on_track', $reports->suggest($facts())['rating']);

        app(Governance::class)->addRisk($project, $owner, ['title' => 'Customs hold', 'probability' => 4, 'impact' => 4]);
        $s = $reports->suggest($facts());
        $this->assertSame(['at_risk', ['1 risk rated high or critical']], [$s['rating'], $s['reasons']]);

        $task = $project->tasks()->create(['title' => 'Commission the line', 'status' => 'todo', 'priority' => 'high', 'due_date' => '2026-12-05', 'completed_at' => null]);
        $f = $facts();
        $this->assertSame(['2026-12-05', 5], [$f['schedule']['forecast'], $f['schedule']['days_late']]);
        $s = $reports->suggest($f);
        $this->assertSame('off_track', $s['rating']);
        $this->assertSame('The tasks as dated finish 5 days after the due date', $s['reasons'][0]);

        $task->update(['status' => 'done', 'completed_at' => '2026-10-29 10:00:00', 'due_date' => '2026-11-20']);
        $this->assertSame(1, $facts()['work']['done_in_period']);
    }

    public function test_a_report_is_drafted_rated_published_and_then_frozen(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project] = $this->team();

        Livewire::actingAs($viewer)->test(ReportList::class, ['project' => $project])->call('start')->assertForbidden();
        Livewire::actingAs($editor)->test(ReportList::class, ['project' => $project])->call('start')->assertRedirect();
        $report = ProjectReport::query()->sole();
        $this->assertSame(['SR-01', 'draft', '2026-10-27', '2026-11-02'], [$report->ref(), $report->status, $report->period_from->toDateString(), $report->period_to->toDateString()]);
        Livewire::actingAs($owner)->test(ReportList::class, ['project' => $project])->call('start');
        $this->assertSame(1, ProjectReport::query()->count(), 'one draft at a time');

        $this->actingAs($owner);
        app(Governance::class)->addIssue($project, $owner, ['title' => 'Crane failed inspection', 'severity' => 'critical']);

        $panel = Livewire::actingAs($editor)->test(ReportPanel::class, ['project' => $project, 'report' => $report]);
        $panel->assertSee('The facts suggest')->assertSee('1 critical issue open');
        $panel->set('to', '2026-10-01')->call('save')->assertHasErrors('period_to');
        $panel->set('to', '2026-11-02')->call('publish')->assertHasErrors('publish');
        $panel->set('overall', 'off_track')->set('summary', 'Off track until the crane is recertified; the sponsor must approve a hire.')->set('helpNeeded', 'Approve CR-01')->call('publish')->assertHasNoErrors();

        $report->refresh();
        $this->assertSame(['published', 'off_track', $editor->id], [$report->status, $report->overall, $report->published_by]);
        $this->assertSame(1, $report->snapshot['issues']['critical']);
        $this->assertSame('off_track', $report->snapshot['suggestion']['rating']);
        $this->assertSame(1, $owner->notifications()->where('data->type', 'report_published')->count());
        $this->assertSame(0, $editor->notifications()->where('data->type', 'report_published')->count());
        $this->assertStringNotContainsString('recertified', Activity::query()->pluck('changes')->toJson());

        app(Governance::class)->addIssue($project, $owner, ['title' => 'Second failure', 'severity' => 'critical']);
        $this->assertSame(1, $report->fresh()->snapshot['issues']['critical'], 'published facts do not move');
        Livewire::actingAs($editor)->test(ReportPanel::class, ['project' => $project, 'report' => $report->fresh()])->set('summary', 'Rewritten')->call('save')->assertHasErrors('report');

        $this->actingAs($owner)->get(route('projects.reports.show', [$project, $report]))->assertOk()
            ->assertSee('Status report SR-01')->assertSee('Off track')->assertSee('Help needed')->assertSee('kept as they were');
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('SR-01')->assertSee('off track');

        Carbon::setTestNow('2026-11-09 09:00:00');
        $next = app(StatusReports::class)->start($project, $owner);
        $this->assertSame(['SR-02', '2026-11-03', '2026-11-09'], [$next->ref(), $next->period_from->toDateString(), $next->period_to->toDateString()]);
        Livewire::actingAs($owner)->test(ReportPanel::class, ['project' => $project, 'report' => $next])->call('discard')->assertRedirect();
        $this->assertNull(ProjectReport::query()->find($next->id));
    }

    public function test_reports_are_scoped_to_their_project_and_team(): void
    {
        ['owner' => $owner, 'project' => $project] = $this->team();
        $this->actingAs($owner);
        $report = app(StatusReports::class)->start($project, $owner);
        $other = Project::create(['team_id' => $project->team_id, 'owner_id' => $owner->id, 'name' => 'Other', 'status' => 'active']);
        $this->get(route('projects.reports.show', [$other, $report]))->assertNotFound();

        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger)->get(route('projects.reports', $project))->assertNotFound();
        $this->actingAs($stranger)->get(route('projects.reports.show', [$project, $report]))->assertNotFound();
    }
}
