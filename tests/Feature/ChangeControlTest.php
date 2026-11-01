<?php

namespace Tests\Feature;

use App\Livewire\Governance\ChangeLog;
use App\Livewire\Governance\ChangePanel;
use App\Livewire\Governance\DecisionPanel;
use App\Livewire\Governance\DecisionRegister;
use App\Models\Activity;
use App\Models\Baseline;
use App\Models\Project;
use App\Models\ProjectChange;
use App\Models\ProjectDecision;
use App\Models\User;
use App\Services\ChangeControl;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P5b: change requests with an impact assessment and a named approver, approval recorded as a
 * decision, re-baselining after an approved change, and the decision register.
 */
class ChangeControlTest extends TestCase
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
        $project = Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Kathu go-live', 'status' => 'active', 'due_date' => '2026-12-15']);

        return ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project->fresh()];
    }

    public function test_rand_amounts_read_and_show_the_south_african_way(): void
    {
        $this->assertSame('R 45 000', Money::rand(4500000));
        $this->assertSame('+R 1 250,50', Money::rand(125050, true));
        $this->assertSame('−R 12 500', Money::rand(-1250000, true));
        foreach (['45000' => 4500000, 'R45 000' => 4500000, '1 250,50' => 125050, '1,250.50' => 125050, '45,000' => 4500000, '-12 500' => -1250000] as $text => $cents) {
            $this->assertSame($cents, Money::parse($text), $text);
        }
        $this->assertNull(Money::parse('forty'));
        $this->assertNull(Money::parse(''));
    }

    public function test_a_change_is_assessed_sent_to_its_approver_decided_and_rebaselined(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project] = $this->team();

        Livewire::actingAs($editor)->test(ChangeLog::class, ['project' => $project])->set('title', 'Add a second loading bay')->set('kind', 'scope')->call('add')->assertRedirect();
        $change = ProjectChange::query()->sole();
        $this->assertSame(['CR-01', 'draft', $editor->id], [$change->ref(), $change->status, $change->requested_by]);

        $panel = Livewire::actingAs($editor)->test(ChangePanel::class, ['project' => $project, 'change' => $change]);
        $panel->set('scheduleDays', '12')->set('cost', 'R45 000')->set('approver', (string) $owner->id)->call('submit')->assertHasErrors('reason');
        $this->assertSame('draft', $change->fresh()->status);
        $this->assertSame([12, 4500000], [$change->fresh()->schedule_days, $change->fresh()->cost_cents], 'the draft was saved on the way');
        $panel->set('cost', 'forty thousand')->call('save')->assertHasErrors('cost');
        $panel->set('cost', '45 000')->set('reason', 'The client needs trucks loading at night; one bay is a bottleneck')->set('approver', (string) $viewer->id)->call('submit')->assertHasErrors('approver');
        $panel->set('approver', (string) $owner->id)->call('submit')->assertHasNoErrors();
        $change->refresh();
        $this->assertSame(['submitted', $owner->id], [$change->status, $change->approver_id]);
        $this->assertSame(1, $owner->notifications()->where('data->type', 'change_submitted')->count());

        $this->expectExceptionOnce(fn () => app(ChangeControl::class)->updateChange($change, ['title' => 'Sneaky edit']), 'change');
        Livewire::actingAs($editor)->test(ChangePanel::class, ['project' => $project, 'change' => $change])->call('approve')->assertForbidden();
        $this->assertSame('submitted', $change->fresh()->status, 'only the named approver decides');

        $decide = Livewire::actingAs($owner)->test(ChangePanel::class, ['project' => $project, 'change' => $change]);
        $decide->call('reject')->assertHasErrors('decision');
        $decide->set('note', 'Approved if the bay is ready before the December shutdown')->call('approve')->assertHasNoErrors();
        $change->refresh();
        $this->assertSame(['approved', $owner->id, false], [$change->status, $change->decided_by, $change->self_approved]);
        $decision = ProjectDecision::query()->sole();
        $this->assertSame(['D-01', 'decided', $change->decision_id, 'Approve CR-01: Add a second loading bay'], [$decision->ref(), $decision->status, $decision->id, $decision->title]);
        $this->assertSame(1, $editor->notifications()->where('data->type', 'change_approved')->count());
        $this->assertStringNotContainsString('bottleneck', Activity::query()->pluck('changes')->toJson(), 'written text never enters the log');

        $decide->call('rebaseline')->assertHasNoErrors();
        $baseline = Baseline::query()->sole();
        $this->assertSame([1, 'After CR-01: Add a second loading bay', $baseline->id], [$baseline->number, $baseline->note, $change->fresh()->baseline_id]);
        $decide->call('rebaseline')->assertHasErrors('change');
        $decide->call('implement');
        $this->assertSame('implemented', $change->fresh()->status);
        $decide->call('withdraw')->assertHasErrors('change');

        $this->actingAs($owner)->get(route('projects.changes', $project))->assertOk()
            ->assertSee('Approved changes move the finish 12 days later and add R 45 000 to the cost', false)->assertSee('CR-01');
        $this->actingAs($owner)->get(route('projects.changes.show', [$project, $change]))->assertSee('Approved')->assertSee('baseline 1')->assertSee('approved CR-01');
        Livewire::actingAs($viewer)->test(ChangeLog::class, ['project' => $project])->set('title', 'Nope')->call('add')->assertForbidden();
    }

    public function test_self_approval_is_recorded_and_waiting_changes_reach_the_overview(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'project' => $project] = $this->team();
        $this->actingAs($owner);
        $cc = app(ChangeControl::class);
        $change = $cc->addChange($project, $owner, ['title' => 'Move go-live a week', 'kind' => 'schedule', 'reason' => 'Rail strike', 'schedule_days' => 7]);
        $cc->submit($change, $owner, $editor->id);
        $this->get(route('projects.show', $project))->assertSee('Move go-live a week')->assertSee('Waiting on Sipho Editor');

        $own = $cc->addChange($project, $owner, ['title' => 'Swap the paint supplier', 'reason' => 'Price']);
        $cc->submit($own, $owner, $owner->id);
        Livewire::actingAs($owner)->test(ChangePanel::class, ['project' => $project, 'change' => $own->fresh()])->call('approve')->assertHasNoErrors();
        $this->assertTrue($own->fresh()->self_approved);
        $this->get(route('projects.changes.show', [$project, $own]))->assertSee('who also asked for it');

        $draft = $cc->addChange($project, $owner, ['title' => 'Never mind']);
        $cc->withdraw($draft);
        $this->assertSame('withdrawn', $draft->fresh()->status);
    }

    public function test_the_decision_register_asks_records_and_supersedes(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project] = $this->team();

        Livewire::actingAs($editor)->test(DecisionRegister::class, ['project' => $project])
            ->set('title', 'Use the second supplier for conveyor parts?')->set('owner', (string) $owner->id)->set('decideBy', '2026-10-30')->call('add')->assertRedirect();
        $asked = ProjectDecision::query()->sole();
        $this->assertSame(['proposed', $owner->id], [$asked->status, $asked->owner_id]);
        $this->assertTrue($asked->overdue());
        $this->assertSame(1, $owner->notifications()->where('data->type', 'decision_owned')->count());

        Livewire::actingAs($editor)->test(DecisionRegister::class, ['project' => $project])->set('mode', 'record')->set('title', 'Night shifts start in week 3')->call('add')->assertHasErrors('outcome');
        Livewire::actingAs($editor)->test(DecisionRegister::class, ['project' => $project])->set('mode', 'record')->set('title', 'Night shifts start in week 3')->set('outcome', 'Agreed at the site meeting')->call('add');
        $recorded = ProjectDecision::query()->where('number', 2)->sole();
        $this->assertSame(['decided', $editor->id], [$recorded->status, $recorded->decided_by]);

        $register = Livewire::actingAs($owner)->test(DecisionRegister::class, ['project' => $project]);
        $this->assertSame(['D-01', 'D-02'], $register->instance()->decisions->map->ref()->all(), 'what needs deciding comes first');
        $this->actingAs($owner)->get(route('projects.decisions', $project))->assertSee('1 decision needs making')->assertSee('1 past its date');

        $panel = Livewire::actingAs($owner)->test(DecisionPanel::class, ['project' => $project, 'decision' => $asked]);
        $panel->call('decide')->assertHasErrors('outcome');
        $panel->set('outcome', 'Yes, for the March order only')->call('decide')->assertHasNoErrors();
        $this->assertSame(['decided', $owner->id, '2026-11-02'], [$asked->fresh()->status, $asked->fresh()->decided_by, $asked->fresh()->decided_on->toDateString()]);
        $panel->set('title', 'Rewritten')->call('save')->assertHasErrors('decision');
        $this->assertSame('Use the second supplier for conveyor parts?', $asked->fresh()->title, 'a decision on record is not edited');

        $panel->set('replacedBy', (string) $recorded->id)->call('supersede')->assertHasNoErrors();
        $this->assertSame(['superseded', $recorded->id], [$asked->fresh()->status, $asked->fresh()->superseded_by]);
        Livewire::actingAs($owner)->test(DecisionPanel::class, ['project' => $project, 'decision' => $recorded])->set('replacedBy', (string) $asked->id)->call('supersede')->assertHasErrors('supersede');

        Livewire::actingAs($viewer)->test(DecisionPanel::class, ['project' => $project, 'decision' => $recorded])->set('outcome', 'x')->call('decide')->assertForbidden();
    }

    public function test_changes_and_decisions_are_scoped_to_their_project_and_team(): void
    {
        ['owner' => $owner, 'project' => $project] = $this->team();
        $this->actingAs($owner);
        $change = app(ChangeControl::class)->addChange($project, $owner, ['title' => 'Paint it blue']);
        $other = Project::create(['team_id' => $project->team_id, 'owner_id' => $owner->id, 'name' => 'Other', 'status' => 'active']);
        $this->get(route('projects.changes.show', [$other, $change]))->assertNotFound();

        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger)->get(route('projects.changes', $project))->assertNotFound();
        $this->actingAs($stranger)->get(route('projects.decisions', $project))->assertNotFound();
    }

    /** Asserts the call throws a validation error on $key, without ending the test. */
    private function expectExceptionOnce(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail('expected a validation error on '.$key);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }
}
