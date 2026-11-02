<?php

namespace Tests\Feature;

use App\Livewire\Dashboards\Board;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\Dashboards;
use App\Services\DashboardWidgets;
use App\Services\Governance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P8d: custom dashboards. Widgets are worked out for whoever looks, so a shared dashboard
 * shares its arrangement and never more than each person may see; only the maker or the business
 * owner arranges one; anyone it is shared with can copy it.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    /** @return array{boss: User, ed: User, kiln: Project, secret: Project} */
    private function business(): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        $ed = User::factory()->create(['name' => 'Ed Editor', 'current_team_id' => $team->id]);
        $team->users()->attach($ed, ['role' => 'editor']);
        $kiln = Project::create(['team_id' => $team->id, 'owner_id' => $boss->id, 'name' => 'Kiln reline', 'status' => 'active']);
        $secret = Project::create(['team_id' => $team->id, 'owner_id' => $boss->id, 'name' => 'Retrenchments', 'status' => 'active', 'visibility' => 'private']);
        $old = Project::create(['team_id' => $team->id, 'owner_id' => $boss->id, 'name' => 'Mothballed', 'status' => 'active']);
        $old->forceFill(['archived_at' => now()])->save();
        $kiln->tasks()->create(['title' => 'Order bricks', 'status' => 'todo', 'priority' => 'high', 'assignee_id' => $ed->id, 'due_date' => '2026-11-02']);
        $kiln->tasks()->create(['title' => 'Heat up', 'status' => 'todo', 'priority' => 'high', 'assignee_id' => $ed->id, 'due_date' => '2026-11-30']);
        $old->tasks()->create(['title' => 'Old chore', 'status' => 'todo', 'priority' => 'low', 'assignee_id' => $ed->id, 'due_date' => '2026-11-03']);
        $this->actingAs($boss);
        app(Governance::class)->addRisk($kiln, $boss, ['title' => 'Bricks arrive late', 'probability' => 4, 'impact' => 5]);
        app(Governance::class)->addRisk($secret, $boss, ['title' => 'Union walks out', 'probability' => 5, 'impact' => 5]);

        return ['boss' => $boss, 'ed' => $ed, 'kiln' => $kiln->fresh(), 'secret' => $secret->fresh()];
    }

    private function refuses(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail('expected a refusal on '.$key);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_a_starter_dashboard_shows_each_widget_from_the_readers_own_records(): void
    {
        ['boss' => $boss, 'ed' => $ed] = $this->business();

        Livewire::actingAs($ed)->test(Board::class)->assertSee('Start with one made for you')->call('starter')
            ->assertSee('My dashboard')->assertSee('2 open tasks given to you, 1 overdue.')->assertSee('Order bricks')->assertDontSee('Old chore')
            ->assertSee('Bricks arrive late')->assertDontSee('Union walks out')->assertSee('1 project')
            ->assertSee('logged in the week of 2 Nov, against 40 h of capacity');
        $d = Dashboard::query()->sole();
        $this->assertSame(['my_work', 'my_time', 'health', 'risks', 'roadmap'], $d->widgets()->pluck('kind')->all());
        $this->assertSame([$ed->id, false], [$d->user_id, $d->shared]);

        $this->actingAs($boss)->get(route('dashboards.index'))->assertOk()->assertDontSee('Order bricks', 'not shared, so the boss sees only their own (none)');
    }

    public function test_a_shared_dashboard_shows_each_person_only_what_they_may_see(): void
    {
        ['boss' => $boss, 'ed' => $ed] = $this->business();
        $svc = app(Dashboards::class);
        $d = $svc->create($boss->currentTeam, $boss, 'Board pack');
        $private = SavedReport::query()->forceCreate(['team_id' => $boss->currentTeam->id, 'user_id' => $boss->id, 'name' => 'Boss only', 'type' => 'risks', 'config' => ['columns' => ['project', 'title']], 'shared' => false]);
        $svc->addWidget($d, $boss, 'risks', [], null);
        $svc->addWidget($d, $boss, 'report', ['source' => 'saved:'.$private->id, 'rows' => 10], 'Risk list');
        $svc->addWidget($d, $boss, 'report', ['source' => 'executive'], null);

        Livewire::actingAs($boss)->test(Board::class)->assertSee('Union walks out')->assertSee('Risk list')->assertSee('Retrenchments');
        Livewire::actingAs($ed)->test(Board::class)->assertSee('Start with one made for you')->assertDontSee('Board pack');

        $svc->rename($d, $boss, 'Board pack', true);
        Livewire::actingAs($ed)->test(Board::class)->assertSee('Board pack')->assertSee('Shared by Bongi Boss')
            ->assertSee('Bricks arrive late')->assertDontSee('Union walks out')->assertDontSee('Retrenchments')
            ->assertSee('This report is not shared with you, or it has been deleted.')->assertSee('Copy to my dashboards')->assertDontSee('Arrange');
    }

    public function test_only_the_maker_or_the_business_owner_arranges_and_others_copy(): void
    {
        ['boss' => $boss, 'ed' => $ed] = $this->business();
        $svc = app(Dashboards::class);
        $d = $svc->create($boss->currentTeam, $boss, 'Board pack');
        $private = SavedReport::query()->forceCreate(['team_id' => $boss->currentTeam->id, 'user_id' => $boss->id, 'name' => 'Boss only', 'type' => 'risks', 'config' => [], 'shared' => false]);
        $a = $svc->addWidget($d, $boss, 'health', [], null);
        $svc->addWidget($d, $boss, 'report', ['source' => 'saved:'.$private->id], null);
        $c = $svc->addWidget($d, $boss, 'my_work', [], 'Mine');
        $svc->rename($d, $boss, 'Board pack', true);

        $this->refuses(fn () => $svc->addWidget($d, $ed, 'roadmap', [], null), 'dashboard');
        $this->refuses(fn () => $svc->removeWidget($a, $ed), 'dashboard');
        $this->refuses(fn () => $svc->delete($d, $ed), 'dashboard');
        Livewire::actingAs($ed)->test(Board::class)->call('startEdit')->assertForbidden();
        Livewire::actingAs($ed)->test(Board::class)->call('removeWidget', $a->id)->assertForbidden();

        Livewire::actingAs($ed)->test(Board::class)->call('copy')->assertSee('Board pack (copy)')->assertSee('Arrange');
        $copy = Dashboard::query()->where('user_id', $ed->id)->sole();
        $this->assertSame(['health', 'my_work'], $copy->widgets()->pluck('kind')->all(), 'the boss\'s private report stays behind');
        $this->assertSame([false, 'Mine'], [$copy->shared, $copy->widgets()->where('kind', 'my_work')->value('title')]);

        $svc->move($c->fresh(), $boss, -1);
        $this->assertSame(['health', 'my_work', 'report'], $d->widgets()->pluck('kind')->all());
        $svc->move($a->fresh(), $boss, -1);
        $this->assertSame(['health', 'my_work', 'report'], $d->widgets()->pluck('kind')->all(), 'the first stays first');
        $svc->toggleWide($a->fresh(), $boss);
        $this->assertTrue($a->fresh()->wide);
    }

    public function test_widget_settings_are_checked(): void
    {
        ['boss' => $boss, 'ed' => $ed] = $this->business();
        $team = $boss->currentTeam;
        $other = User::factory()->withPersonalTeam()->create();
        $foreign = Portfolio::query()->forceCreate(['team_id' => $other->currentTeam->id, 'name' => 'Theirs', 'kind' => 'portfolio']);
        $mine = Portfolio::query()->forceCreate(['team_id' => $team->id, 'name' => 'Plant', 'kind' => 'programme']);
        $private = SavedReport::query()->forceCreate(['team_id' => $team->id, 'user_id' => $boss->id, 'name' => 'Boss only', 'type' => 'risks', 'config' => [], 'shared' => false]);

        $this->refuses(fn () => DashboardWidgets::clean('salaries', [], $team, $ed), 'widget');
        $this->refuses(fn () => DashboardWidgets::clean('health', ['portfolio' => (string) $foreign->id], $team, $ed), 'widget');
        $this->refuses(fn () => DashboardWidgets::clean('report', ['source' => 'saved:'.$private->id], $team, $ed), 'widget');
        $this->refuses(fn () => DashboardWidgets::clean('report', ['source' => 'nonsense'], $team, $ed), 'widget');
        $this->assertSame(['portfolio' => (string) $mine->id], DashboardWidgets::clean('roadmap', ['portfolio' => (string) $mine->id, 'rows' => 99], $team, $ed));
        $this->assertSame(['type' => 'risks', 'rows' => 5], DashboardWidgets::clean('report', ['source' => 'risks', 'rows' => 99], $team, $ed));

        $svc = app(Dashboards::class);
        $d = $svc->create($team, $ed, 'Full');
        foreach (range(1, Dashboards::MAX_WIDGETS) as $i) {
            $svc->addWidget($d, $ed, 'my_time', [], null);
        }
        $this->refuses(fn () => $svc->addWidget($d, $ed, 'my_time', [], null), 'widget');
        $this->refuses(fn () => $svc->create($team, $ed, '   '), 'name');

        $w = $svc->addWidget($svc->create($team, $ed, 'Plant view'), $ed, 'health', ['portfolio' => (string) $mine->id], null);
        $mine->delete();
        $this->assertSame(['gone' => true], app(DashboardWidgets::class)->data($w->fresh(), $team, $ed));

        $theirs = Dashboard::query()->forceCreate(['team_id' => $other->currentTeam->id, 'user_id' => $other->id, 'name' => 'Elsewhere', 'shared' => true]);
        Livewire::actingAs($ed)->withQueryParams(['d' => (string) $theirs->id])->test(Board::class)->assertNotFound();
        $this->assertSame(0, DashboardWidget::query()->where('dashboard_id', $theirs->id)->count());
    }
}
