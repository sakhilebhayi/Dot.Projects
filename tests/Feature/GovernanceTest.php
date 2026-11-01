<?php

namespace Tests\Feature;

use App\Livewire\Governance\IssueLog;
use App\Livewire\Governance\IssuePanel;
use App\Livewire\Governance\MatrixSettings;
use App\Livewire\Governance\RiskPanel;
use App\Livewire\Governance\RiskRegister;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProjectIssue;
use App\Models\ProjectRisk;
use App\Models\User;
use App\Services\Governance;
use App\Services\ProjectWork;
use App\Services\RiskMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P5a: the risk register on a configurable matrix, the issue log, escalation to the project
 * owner, a risk that happens becoming an issue, and a history kept in the activity log without
 * the text people wrote.
 */
class GovernanceTest extends TestCase
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
        $viewer = User::factory()->create(['current_team_id' => $owner->currentTeam->id]);
        $owner->currentTeam->users()->attach($editor, ['role' => 'editor']);
        $owner->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $project = Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Kathu go-live', 'status' => 'active']);

        return ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project->fresh()];
    }

    public function test_the_matrix_bands_scores_and_refuses_bad_settings(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'project' => $project] = $this->team();
        $m = RiskMatrix::forProject($project);
        $this->assertSame([5, 'low', 'medium', 'high', 'critical'], [$m->size, $m->band(4), $m->band(5), $m->band(12), $m->band(25)]);

        foreach ([['size' => 6], ['size' => 3, 'probability' => ['a', 'b'], 'impact' => ['a', 'b', 'c']], ['size' => 3, 'probability' => ['a', 'b', 'c'], 'impact' => ['a', 'b', 'c'], 'bands' => ['medium' => 4, 'high' => 4, 'critical' => 9]]] as $bad) {
            try {
                RiskMatrix::fromArray($bad);
                $this->fail('a bad matrix was accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('matrix', $e->errors());
            }
        }

        $this->actingAs($owner);
        app(Governance::class)->addRisk($project, $owner, ['title' => 'Customs hold', 'probability' => 4, 'impact' => 5]);
        $settings = Livewire::actingAs($owner)->test(MatrixSettings::class, ['project' => $project]);
        $settings->set('size', 3)->assertSet('probability', ['Unlikely', 'Possible', 'Likely'])->call('save')->assertHasErrors('matrix');
        $this->assertNull($project->fresh()->risk_matrix, 'a smaller matrix is refused while a risk sits above it');

        $settings->set('size', 4)->set('critical', 13)->call('save')->assertHasErrors('matrix');
        $settings->set('size', 5)->set('impact.4', 'Catastrophic')->set('critical', 16)->call('save')->assertHasNoErrors();
        $this->assertSame(['size' => 5, 'impact' => 'Catastrophic', 'critical' => 16], ['size' => $project->fresh()->risk_matrix['size'], 'impact' => $project->fresh()->risk_matrix['impact'][4], 'critical' => $project->fresh()->risk_matrix['bands']['critical']]);
        $this->assertSame('high', RiskMatrix::forProject($project->fresh())->band(15));
        $this->assertSame(1, Activity::query()->where('action', 'matrix.changed')->count());

        Livewire::actingAs($viewer)->test(MatrixSettings::class, ['project' => $project])->call('save')->assertForbidden();
    }

    public function test_risks_are_numbered_rated_owned_and_logged_without_their_text(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project] = $this->team();

        Livewire::actingAs($editor)->test(RiskRegister::class, ['project' => $project])
            ->set('title', 'Customs holds the trucks')->set('probability', '4')->set('impact', '5')->set('category', 'suppliers')->set('owner', (string) $owner->id)->set('review', '2026-11-09')
            ->call('add')->assertHasNoErrors()->assertRedirect();
        Livewire::actingAs($editor)->test(RiskRegister::class, ['project' => $project])->set('title', 'Rain delays the slab')->set('probability', '2')->set('impact', '2')->call('add');
        $first = ProjectRisk::query()->where('number', 1)->sole();
        $this->assertSame(['R-01', 20, 'critical'], [$first->ref(), $first->score(), $first->band(RiskMatrix::forProject($project))]);
        $this->assertSame('R-02', ProjectRisk::query()->where('number', 2)->sole()->ref());
        $this->assertSame(1, $owner->notifications()->where('data->type', 'risk_assigned')->count(), 'the owner hears they own it');

        Livewire::actingAs($editor)->test(RiskRegister::class, ['project' => $project])->set('title', 'Bad')->set('probability', '6')->set('impact', '1')->call('add')->assertHasErrors('probability');
        $stranger = User::factory()->withPersonalTeam()->create();
        Livewire::actingAs($editor)->test(RiskRegister::class, ['project' => $project])->set('title', 'Bad')->set('probability', '1')->set('impact', '1')->set('owner', (string) $stranger->id)->call('add')->assertHasErrors('owner_id');
        Livewire::actingAs($viewer)->test(RiskRegister::class, ['project' => $project])->set('title', 'Nope')->set('probability', '1')->set('impact', '1')->call('add')->assertForbidden();
        $this->assertSame(2, ProjectRisk::query()->count());

        $panel = Livewire::actingAs($editor)->test(RiskPanel::class, ['project' => $project, 'risk' => $first]);
        $panel->set('mitigation', 'Book a second clearing agent in Durban')->set('residualProbability', '2')->call('save')->assertHasErrors('residual');
        $panel->set('residualImpact', '4')->set('probability', '3')->call('save')->assertHasNoErrors();
        $first->refresh();
        $this->assertSame([3, 2, 4, 8], [$first->probability, $first->residual_probability, $first->residual_impact, $first->currentScore()]);
        $line = Activity::query()->where('action', 'risk.updated')->sole();
        $this->assertSame(['from' => 4, 'to' => 3], $line->changes['probability']);
        $this->assertSame(['mitigation'], $line->changes['fields']);
        $this->assertStringNotContainsString('Durban', json_encode($line->changes), 'written text never enters the log');

        $panel->set('status', 'occurred')->call('save')->assertHasErrors('status');
        $panel->set('status', 'closed')->call('save')->assertHasNoErrors();
        $this->assertNotNull($first->fresh()->closed_at);
        $panel->set('status', 'open')->call('save');
        $this->assertNull($first->fresh()->closed_at);

        $this->actingAs($owner)->get(route('projects.risks.show', [$project, $first]))->assertOk()
            ->assertSee('re-rated R-01 “Customs holds the trucks”', false)->assertSee('set R-01 “Customs holds the trucks” to closed', false);
        Livewire::actingAs($viewer)->test(RiskPanel::class, ['project' => $project, 'risk' => $first])->call('save')->assertForbidden();
    }

    public function test_escalation_reaches_the_project_owner_and_a_risk_that_happens_becomes_an_issue(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'project' => $project] = $this->team();
        $this->actingAs($editor);
        $risk = app(Governance::class)->addRisk($project, $editor, ['title' => 'Crane fails inspection', 'probability' => 3, 'impact' => 4, 'owner_id' => $editor->id]);

        $panel = Livewire::actingAs($editor)->test(RiskPanel::class, ['project' => $project, 'risk' => $risk]);
        $panel->call('escalate')->assertHasErrors('escalation');
        $panel->set('escalationNote', 'Need approval to book a standby crane')->call('escalate')->assertHasNoErrors();
        $this->assertNotNull($risk->fresh()->escalated_at);
        $note = $owner->notifications()->where('data->type', 'risk_escalated')->sole();
        $this->assertStringContainsString('R-01', $note->data['message']);
        $this->actingAs($owner)->get(route('projects.risks', $project))->assertSee('Escalated by Sipho Editor');
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('Risks and issues')->assertSee('Crane fails inspection')->assertSee('Escalated');
        $panel->call('withdraw');
        $this->assertNull($risk->fresh()->escalated_at);

        Livewire::actingAs($editor)->test(RiskPanel::class, ['project' => $project, 'risk' => $risk->fresh()])->call('occurred')->assertRedirect();
        $issue = ProjectIssue::query()->sole();
        $this->assertSame(['I-01', $risk->id, 'high', 'high', $editor->id], [$issue->ref(), $issue->risk_id, $issue->severity, $issue->priority, $issue->owner_id]);
        $this->assertSame('occurred', $risk->fresh()->status);
        $this->assertFalse($risk->fresh()->isLive());
        $this->expectException(ValidationException::class);
        app(Governance::class)->riskOccurred($risk->fresh(), $editor);
    }

    public function test_issues_need_a_resolution_to_close_and_are_read_most_serious_first(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project] = $this->team();
        $log = fn (string $title, string $sev, ?string $target = null) => Livewire::actingAs($editor)->test(IssueLog::class, ['project' => $project])
            ->set('title', $title)->set('newSeverity', $sev)->set('target', (string) $target)->call('add');
        $log('Late steel delivery', 'medium', '2026-10-30');
        $log('Site access road washed away', 'critical');
        $log('Typo in the safety file', 'low');

        $list = Livewire::actingAs($owner)->test(IssueLog::class, ['project' => $project]);
        $this->assertSame(['Site access road washed away', 'Late steel delivery', 'Typo in the safety file'], $list->instance()->issues->pluck('title')->all());
        $steel = ProjectIssue::query()->where('title', 'Late steel delivery')->sole();
        $this->assertTrue($steel->late());
        $this->actingAs($owner)->get(route('projects.issues', $project))->assertSee('3 open issues')->assertSee('1 high or critical, 1 past the target date');

        $panel = Livewire::actingAs($editor)->test(IssuePanel::class, ['project' => $project, 'issue' => $steel]);
        $panel->set('status', 'resolved')->call('save')->assertHasErrors('resolution');
        $panel->set('rootCause', 'Supplier double-booked the truck')->set('resolution', 'Delivered 1 Nov')->set('status', 'resolved')->call('save')->assertHasNoErrors();
        $steel->refresh();
        $this->assertSame('resolved', $steel->status);
        $this->assertNotNull($steel->resolved_at);
        $this->assertFalse($steel->late());
        $this->assertSame(['root_cause', 'resolution'], Activity::query()->where('action', 'issue.updated')->sole()->changes['fields']);

        $panel->set('status', 'open')->call('save');
        $this->assertNull($steel->fresh()->resolved_at);

        Livewire::actingAs($viewer)->test(IssueLog::class, ['project' => $project])->set('title', 'Nope')->call('add')->assertForbidden();
        $this->actingAs($owner);
        app(ProjectWork::class)->archive($project, $owner);
        Livewire::actingAs($editor)->test(IssuePanel::class, ['project' => $project->fresh(), 'issue' => $steel->fresh()])->set('title', 'Changed')->call('save')->assertForbidden();
        $this->assertSame('Late steel delivery', $steel->fresh()->title);
    }

    public function test_governance_is_team_private_and_scoped_to_its_project(): void
    {
        ['owner' => $owner, 'project' => $project] = $this->team();
        $this->actingAs($owner);
        $risk = app(Governance::class)->addRisk($project, $owner, ['title' => 'Customs hold', 'probability' => 2, 'impact' => 2]);
        $other = Project::create(['team_id' => $project->team_id, 'owner_id' => $owner->id, 'name' => 'Other', 'status' => 'active']);

        $this->get(route('projects.risks.show', [$other, $risk]))->assertNotFound();
        $this->get(route('projects.risks', $project))->assertOk()->assertSee('1 live risk')->assertSee('R-01');

        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger)->get(route('projects.risks', $project))->assertNotFound();
        $this->actingAs($stranger)->get(route('projects.risks.show', [$project, $risk]))->assertNotFound();
    }
}
