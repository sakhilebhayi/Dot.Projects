<?php

namespace Tests\Feature;

use App\Livewire\Governance\ActionRegister;
use App\Livewire\Governance\GateList;
use App\Livewire\Governance\GatePanel;
use App\Livewire\Governance\MeetingList;
use App\Livewire\Governance\MeetingPanel;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\ProjectDecision;
use App\Models\ProjectGate;
use App\Models\ProjectMeeting;
use App\Models\User;
use App\Services\Gates;
use App\Services\Meetings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P5c: stage gates with a governance checklist decided by a named approver (and written to
 * the decision register), meeting records with attendees and minutes, and the action register.
 */
class GatesMeetingsTest extends TestCase
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
        $project = Project::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Kathu go-live', 'status' => 'active']);

        return ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project->fresh()];
    }

    public function test_a_gate_passes_only_on_its_checklist_and_its_approvers_word(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project] = $this->team();

        Livewire::actingAs($editor)->test(GateList::class, ['project' => $project])->set('approver', (string) $viewer->id)->call('add')->assertHasErrors('approver_id');
        Livewire::actingAs($editor)->test(GateList::class, ['project' => $project])->assertSet('title', 'Ready to execute')->set('approver', (string) $owner->id)->set('due', '2026-11-20')->call('add')->assertRedirect();
        $gate = ProjectGate::query()->sole();
        $this->assertSame(['G1', 5, 'pending'], [$gate->ref(), $gate->checks()->count(), $gate->status]);
        $this->assertSame(1, $owner->notifications()->where('data->type', 'gate_approver')->count());

        $checks = $gate->checks()->get();
        Livewire::actingAs($viewer)->test(GatePanel::class, ['project' => $project, 'gate' => $gate])->call('toggleCheck', $checks[0]->id)->assertForbidden();
        $prep = Livewire::actingAs($editor)->test(GatePanel::class, ['project' => $project, 'gate' => $gate]);
        $prep->call('toggleCheck', $checks[0]->id)->call('toggleCheck', $checks[1]->id);
        $this->assertSame($editor->id, $checks[0]->fresh()->done_by);
        $prep->set('outcome', 'passed')->call('decide')->assertForbidden();

        $decide = Livewire::actingAs($owner)->test(GatePanel::class, ['project' => $project, 'gate' => $gate->fresh()]);
        $decide->assertSet('outcome', 'conditional');
        $decide->set('outcome', 'passed')->call('decide')->assertHasErrors('decision');
        $decide->set('outcome', 'conditional')->call('decide')->assertHasErrors('decision');
        $decide->set('note', 'Go, provided the crane booking is confirmed by 10 Nov')->call('decide')->assertHasNoErrors();
        $gate->refresh();
        $this->assertSame(['conditional', $owner->id], [$gate->status, $gate->decided_by]);
        $decision = ProjectDecision::query()->sole();
        $this->assertSame([$gate->decision_id, 'G1 Ready to execute passed with conditions'], [$decision->id, $decision->title]);
        $this->assertStringNotContainsString('crane', Activity::query()->pluck('changes')->toJson());

        Livewire::actingAs($editor)->test(GatePanel::class, ['project' => $project, 'gate' => $gate])->set('newCheck', 'Late addition')->call('addCheck')->assertHasErrors('gate');
        $this->assertSame(5, $gate->checks()->count(), 'a decided gate keeps its record');

        $this->actingAs($owner);
        $gates = app(Gates::class);
        $second = $gates->add($project, $owner, ['title' => 'Ready to hand over', 'approver_id' => $owner->id], 'handover');
        $panel = Livewire::actingAs($owner)->test(GatePanel::class, ['project' => $project, 'gate' => $second]);
        $panel->set('outcome', 'held')->call('decide')->assertHasErrors('decision');
        $panel->set('note', 'The client has not signed the acceptance')->call('decide')->assertHasNoErrors();
        $this->assertSame('held', $second->fresh()->status);
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('Gate held');
        $panel->call('reopen');
        $this->assertSame('pending', $second->fresh()->status);
        foreach ($second->checks()->get() as $c) {
            $gates->toggleCheck($c, $owner);
        }
        Livewire::actingAs($owner)->test(GatePanel::class, ['project' => $project, 'gate' => $second->fresh()])->assertSet('outcome', 'passed')->call('decide')->assertHasNoErrors();
        $this->assertSame('passed', $second->fresh()->status);
        $this->assertSame('Every check met; passed.', $second->fresh()->decision->outcome);
        $this->actingAs($owner)->get(route('projects.gates', $project))->assertSee('Every gate is passed.');
    }

    public function test_a_meeting_records_attendees_minutes_decisions_and_actions(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'project' => $project] = $this->team();

        Livewire::actingAs($editor)->test(MeetingList::class, ['project' => $project])->set('title', 'Shutdown readiness review')->set('kind', 'steering')->set('heldAt', '2026-11-03T09:00')->call('add')->assertRedirect();
        $m = ProjectMeeting::query()->sole();
        $this->assertSame(['M-01', 'steering', [$editor->id]], [$m->ref(), $m->kind, $m->attendees()->pluck('users.id')->all()]);

        $panel = Livewire::actingAs($editor)->test(MeetingPanel::class, ['project' => $project, 'meeting' => $m]);
        $stranger = User::factory()->withPersonalTeam()->create();
        $panel->set('attendees', [(string) $editor->id, (string) $stranger->id])->call('save')->assertHasErrors('attendees');
        $panel->set('attendees', [(string) $editor->id, (string) $owner->id])->set('guests', 'Mr Naidoo, crane supplier')->set('minutes', 'Kiln work clashes with the gearbox lift.')->call('save')->assertHasNoErrors();
        $this->assertSame(2, $m->attendees()->count());
        $this->assertStringNotContainsString('Kiln', Activity::query()->pluck('changes')->toJson());

        $panel->call('addDecision')->assertHasErrors('decision_title');
        $panel->assertHasNoErrors('title');
        $panel->set('decisionTitle', 'Hire a standby crane')->set('decisionOutcome', 'Agreed for week 2')->call('addDecision')->assertHasNoErrors();
        $this->assertSame($m->id, ProjectDecision::query()->sole()->meeting_id);

        $panel->set('actionTitle', 'Confirm crane booking')->set('actionOwner', (string) $owner->id)->set('actionDue', '2026-11-05')->call('addAction')->assertHasNoErrors();
        $action = ProjectAction::query()->sole();
        $this->assertSame(['A-01', $m->id, $owner->id], [$action->ref(), $action->meeting_id, $action->owner_id]);
        $this->assertSame(1, $owner->notifications()->where('data->type', 'action_assigned')->count());

        $this->actingAs($owner)->get(route('projects.meetings.show', [$project, $m]))->assertOk()->assertSee('wrote the minutes of M-01')->assertSee('Hire a standby crane')->assertSee('Confirm crane booking');
        $this->actingAs($owner)->get(route('projects.meetings', $project))->assertSee('Steering committee');
    }

    public function test_the_action_register_orders_flags_and_updates_actions(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'viewer' => $viewer, 'project' => $project] = $this->team();
        $this->actingAs($owner);
        $meetings = app(Meetings::class);
        $late = $meetings->addAction($project, $owner, ['title' => 'Send the method statement', 'due_date' => '2026-10-30']);
        $later = $meetings->addAction($project, $owner, ['title' => 'Book the cranes', 'due_date' => '2026-11-20', 'owner_id' => $editor->id]);
        $register = Livewire::actingAs($editor)->test(ActionRegister::class, ['project' => $project]);
        $register->set('title', 'Check the scaffolding')->call('add')->assertHasNoErrors();
        $this->assertSame(['A-01', 'A-02', 'A-03'], $register->instance()->actions->map->ref()->all(), 'by due date, undated last');
        $this->assertTrue($late->overdue());
        $this->actingAs($owner)->get(route('projects.actions', $project))->assertSeeText('3 open actions, 1 late, 2 with nobody on them');
        $this->actingAs($owner)->get(route('projects.show', $project))->assertSee('1 late action');

        $register->call('toggle', $late->id);
        $this->assertSame('done', $late->fresh()->status);
        $register->call('setOwner', $late->id, (string) $owner->id)->call('setDue', $later->id, '2026-11-25');
        $this->assertSame([$owner->id, '2026-11-25'], [$late->fresh()->owner_id, $later->fresh()->due_date->toDateString()]);
        $register->set('who', (string) $editor->id);
        $this->assertSame(['A-02'], $register->instance()->actions->map->ref()->all());

        Livewire::actingAs($viewer)->test(ActionRegister::class, ['project' => $project])->call('toggle', $later->id)->assertForbidden();
        $other = Project::create(['team_id' => $project->team_id, 'owner_id' => $owner->id, 'name' => 'Other', 'status' => 'active']);
        $foreign = $meetings->addAction($other, $owner, ['title' => 'Elsewhere']);
        Livewire::actingAs($owner)->test(ActionRegister::class, ['project' => $project])->call('toggle', $foreign->id)->assertNotFound();
        $this->assertSame('open', $foreign->fresh()->status);
    }

    public function test_gates_and_meetings_are_scoped_to_their_project_and_team(): void
    {
        ['owner' => $owner, 'project' => $project] = $this->team();
        $this->actingAs($owner);
        $gate = app(Gates::class)->add($project, $owner, ['title' => 'Ready to start'], 'start');
        $meeting = app(Meetings::class)->add($project, $owner, ['title' => 'Kick-off', 'kind' => 'team', 'held_at' => '2026-11-04 09:00']);
        $other = Project::create(['team_id' => $project->team_id, 'owner_id' => $owner->id, 'name' => 'Other', 'status' => 'active']);
        $this->get(route('projects.gates.show', [$other, $gate]))->assertNotFound();
        $this->get(route('projects.meetings.show', [$other, $meeting]))->assertNotFound();

        $stranger = User::factory()->withPersonalTeam()->create();
        foreach (['projects.gates', 'projects.meetings', 'projects.actions'] as $route) {
            $this->actingAs($stranger)->get(route($route, $project))->assertNotFound();
        }
    }
}
