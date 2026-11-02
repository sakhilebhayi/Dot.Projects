<?php

namespace Tests\Feature;

use App\Livewire\Security\DataPage;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Project;
use App\Models\RetentionPolicy;
use App\Models\Team;
use App\Models\User;
use App\Services\DataRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** R-34 P12b: each business decides how long what accumulates is kept, and only that is ever removed. */
class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-05 09:00:00');
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->team = $this->owner->currentTeam;
        $this->project = Project::create(['team_id' => $this->team->id, 'owner_id' => $this->owner->id, 'name' => 'Kiln reline', 'status' => 'active']);
        $this->actingAs($this->owner);
    }

    private function activity(Team $team, string $at): void
    {
        DB::table('activities')->insert(['team_id' => $team->id, 'action' => 'task.created', 'subject_type' => 'task', 'subject_id' => 1, 'created_at' => $at]);
    }

    private function notice(User $to, int $projectId, ?string $readAt): void
    {
        DB::table('notifications')->insert(['id' => (string) str()->uuid(), 'type' => 'x', 'notifiable_type' => $to->getMorphClass(), 'notifiable_id' => $to->id,
            'data' => json_encode(['title' => 'Due', 'project_id' => $projectId]), 'read_at' => $readAt, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
    }

    private function automationRun(Team $team, string $status, string $at): void
    {
        $rule = AutomationRule::query()->forceCreate(['team_id' => $team->id, 'name' => 'R', 'trigger' => 'task.created', 'params' => [], 'conditions' => [], 'actions' => []]);
        AutomationRun::query()->forceCreate(['rule_id' => $rule->id, 'team_id' => $team->id, 'key' => str()->random(12), 'status' => $status, 'steps' => [], 'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_by_default_the_activity_history_is_kept_for_ever(): void
    {
        $this->activity($this->team, '2015-01-01');
        $removed = app(DataRetention::class)->prune($this->team);

        $this->assertSame(0, $removed['activity']);
        $this->assertSame(1, DB::table('activities')->where('created_at', '2015-01-01')->count());
        $this->assertNull(app(DataRetention::class)->policy($this->team)->activity_months);
    }

    public function test_a_limit_removes_only_this_business_s_older_lines(): void
    {
        $other = User::factory()->withPersonalTeam()->create()->currentTeam;
        app(DataRetention::class)->set($this->team, $this->owner, 12, 180, 365);
        $this->activity($this->team, '2025-11-01');   // older than a year
        $this->activity($this->team, '2026-01-01');   // within it
        $this->activity($other, '2020-01-01');        // another business, which chose nothing

        $this->assertSame(1, app(DataRetention::class)->prune($this->team)['activity']);
        $this->assertSame(0, DB::table('activities')->where('created_at', '2025-11-01')->count());
        $this->assertSame(1, DB::table('activities')->where('created_at', '2026-01-01')->count());
        $this->assertSame(1, DB::table('activities')->where('team_id', $other->id)->count());
    }

    public function test_only_read_notices_about_this_business_s_projects_go(): void
    {
        $otherOwner = User::factory()->withPersonalTeam()->create();
        $theirs = Project::create(['team_id' => $otherOwner->currentTeam->id, 'owner_id' => $otherOwner->id, 'name' => 'Theirs', 'status' => 'active']);
        app(DataRetention::class)->set($this->team, $this->owner, null, 30, 365);
        for ($i = 0; $i < 3; $i++) {
            $this->notice($this->owner, $this->project->id, '2026-10-01');     // read, old: goes
        }
        $this->notice($this->owner, $this->project->id, '2026-11-30');         // read, recent: stays
        $this->notice($this->owner, $this->project->id, null);                 // unread: stays
        $this->notice($otherOwner, $theirs->id, '2026-01-01');                 // another business: stays

        $this->assertSame(3, app(DataRetention::class)->prune($this->team)['notices']);
        $this->assertSame(3, DB::table('notifications')->count());
    }

    public function test_finished_automation_runs_go_and_waiting_ones_stay(): void
    {
        app(DataRetention::class)->set($this->team, $this->owner, null, 180, 90);
        foreach (['done', 'skipped', 'failed', 'waiting', 'awaiting'] as $status) {
            $this->automationRun($this->team, $status, '2026-01-01');
        }
        $this->automationRun($this->team, 'done', '2026-11-30');

        $this->assertSame(3, app(DataRetention::class)->prune($this->team)['automation_runs']);
        $this->assertEqualsCanonicalizing(['waiting', 'awaiting', 'done'], AutomationRun::query()->pluck('status')->all());
    }

    public function test_the_nightly_command_records_what_it_removed(): void
    {
        app(DataRetention::class)->set($this->team, $this->owner, 12, 180, 365);
        $this->activity($this->team, '2024-01-01');

        $this->artisan('data:prune')->expectsOutputToContain('1 business')->assertSuccessful();
        $policy = RetentionPolicy::query()->firstWhere('team_id', $this->team->id);
        $this->assertSame(1, $policy->last_removed['activity']);
        $this->assertTrue($policy->last_run_at->equalTo(Carbon::now()));
    }

    public function test_only_the_owner_and_admins_choose_and_only_offered_periods(): void
    {
        $member = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($member, ['role' => 'editor']);

        try {
            app(DataRetention::class)->set($this->team, $member, 12, 180, 365);
            $this->fail('a member set retention');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        app(DataRetention::class)->set($this->team, $this->owner, 13, 180, 365);
    }

    public function test_the_page_saves_the_choice_and_is_closed_to_members(): void
    {
        $this->get(route('security.index'))->assertOk()->assertSee('How long data is kept')->assertSee('Never removed by these settings');

        Livewire::test(DataPage::class)->set('activity', '60')->set('notices', '90')->set('automation', '730')->call('save')->assertHasNoErrors()->assertSee('Saved.');
        $policy = app(DataRetention::class)->policy($this->team);
        $this->assertSame([60, 90, 730], [$policy->activity_months, $policy->notice_days, $policy->automation_days]);
        $this->assertSame(1, DB::table('activities')->where('action', 'retention.changed')->count());

        $member = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($member, ['role' => 'editor']);
        $this->actingAs($member)->get(route('security.index'))->assertForbidden();
        $this->actingAs($member)->get(route('dashboard'))->assertDontSee('Data and security');
    }
}
