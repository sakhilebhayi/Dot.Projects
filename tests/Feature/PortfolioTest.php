<?php

namespace Tests\Feature;

use App\Livewire\Portfolio\Board;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\User;
use App\Services\CostLedger;
use App\Services\Governance;
use App\Services\PortfolioOverview;
use App\Services\Portfolios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P8a: portfolios and programmes, and the executive overview: health by the status-report
 * rule, delivery, money only where allowed, intervention and upcoming milestones, never more
 * than a person may see.
 */
class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    /** @return array{boss: User, editor: User, late: Project, risky: Project, fine: Project, secret: Project} */
    private function business(): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        $editor = User::factory()->create(['name' => 'Ed Editor', 'current_team_id' => $team->id]);
        $team->users()->attach($editor, ['role' => 'editor']);
        $mk = fn (string $name, array $extra = []) => Project::create($extra + ['team_id' => $team->id, 'owner_id' => $boss->id, 'name' => $name, 'status' => 'active']);

        $late = $mk('Kiln reline', ['due_date' => '2026-11-20']);
        $late->tasks()->create(['title' => 'Reline', 'status' => 'in_progress', 'priority' => 'high', 'due_date' => '2026-11-30']);
        $risky = $mk('Conveyor upgrade', ['due_date' => '2026-12-20']);
        $risky->milestones()->create(['title' => 'Belt delivered', 'status' => 'pending', 'due_date' => '2026-11-18']);
        $this->actingAs($boss);
        app(Governance::class)->addRisk($risky, $boss, ['title' => 'Belt supplier fails', 'probability' => 4, 'impact' => 4]);
        $fine = $mk('Canteen refit', ['due_date' => '2027-02-01']);
        $fine->tasks()->create(['title' => 'Paint', 'status' => 'done', 'priority' => 'low', 'due_date' => '2026-11-02']);
        $mk('Old plant', ['status' => 'completed']);
        $archived = $mk('Mothballed');
        $archived->forceFill(['archived_at' => now()])->save();
        $secret = $mk('Retrenchments', ['visibility' => 'private']);

        return ['boss' => $boss, 'editor' => $editor, 'late' => $late->fresh(), 'risky' => $risky->fresh(), 'fine' => $fine->fresh(), 'secret' => $secret->fresh()];
    }

    public function test_the_overview_rates_orders_and_flags_projects(): void
    {
        ['boss' => $boss, 'late' => $late, 'risky' => $risky, 'fine' => $fine] = $this->business();
        $o = app(PortfolioOverview::class)->build(collect([$fine, $risky, $late]), $boss);

        $this->assertSame(['Kiln reline', 'Conveyor upgrade', 'Canteen refit'], array_map(fn ($r) => $r['project']->name, $o['rows']), 'off track first, then at risk, then on track');
        $this->assertSame(['off_track', 'at_risk', 'on_track'], array_column($o['rows'], 'health'));
        $this->assertSame(10, $o['rows'][0]['late']);
        $this->assertSame(['on_track' => 1, 'at_risk' => 1, 'off_track' => 1, 'delayed' => 1], $o['counts']);
        $this->assertSame(['Kiln reline'], array_map(fn ($i) => $i['project']->name, $o['intervene']));
        $this->assertSame(['Belt delivered'], array_column($o['milestones'], 'title'));

        $this->actingAs($boss)->get(route('portfolio.index'))->assertOk()
            ->assertSeeText('4 active projects: 2 on track, 1 at risk, 1 off track; 1 delayed.')
            ->assertSee('Retrenchments')->assertDontSee('Old plant')->assertDontSee('Mothballed');
    }

    public function test_people_see_only_their_projects_and_money_they_may_see(): void
    {
        ['boss' => $boss, 'editor' => $editor, 'fine' => $fine] = $this->business();
        $this->actingAs($boss);
        app(CostLedger::class)->addLine($fine, $boss, 'materials', 'Paint', 5_000_000, null);

        $this->actingAs($boss)->get(route('portfolio.index'))->assertSee('R 50 000 across 1 project');
        $this->actingAs($editor)->get(route('portfolio.index'))->assertOk()
            ->assertSeeText('3 active projects')->assertDontSee('Retrenchments')
            ->assertDontSee('R 50 000')->assertSee('Not shown')->assertSee('3 projects whose money you do not see')
            ->assertDontSee('over capacity');
    }

    public function test_portfolios_group_projects_under_an_owner_and_an_objective(): void
    {
        ['boss' => $boss, 'editor' => $editor, 'late' => $late, 'risky' => $risky, 'fine' => $fine, 'secret' => $secret] = $this->business();
        $svc = app(Portfolios::class);
        try {
            $svc->create($boss->currentTeam, $editor, ['name' => 'Mine', 'kind' => 'programme']);
            $this->fail('an editor created a portfolio');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('portfolio', $e->errors());
        }

        Livewire::actingAs($boss)->test(Board::class)->call('startCreate')
            ->set('name', 'Plant renewal')->set('kind', 'programme')->set('owner', (string) $editor->id)->set('objective', 'Run the plant past 2035')
            ->set('chosen', [(string) $late->id, (string) $risky->id, (string) $secret->id])->call('save')->assertHasNoErrors();
        $p = Portfolio::query()->sole();
        $this->assertSame(['programme', $editor->id], [$p->kind, $p->owner_id]);
        $inIt = fn () => DB::table('portfolio_project')->where('portfolio_id', $p->id)->pluck('project_id')->map(fn ($id) => (int) $id)->all();
        $this->assertEqualsCanonicalizing([$late->id, $risky->id, $secret->id], $inIt());

        $board = Livewire::actingAs($editor)->test(Board::class)->set('portfolio', (string) $p->id);
        $board->assertSee('Run the plant past 2035')->assertSee('Kiln reline')->assertDontSee('Canteen refit');
        $board->call('startEdit')->set('chosen', [(string) $fine->id])->call('save')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing([$fine->id, $secret->id], $inIt(), 'the editor cannot see Retrenchments, so cannot take it out');
        $this->expectExceptionOnce(fn () => $svc->setProjects($p, $editor, [$secret->id]), 'projects');

        $this->actingAs($boss)->get(route('portfolio.index'))->assertSeeText('2 active projects in no portfolio or programme');
        Livewire::actingAs($editor)->test(Board::class)->set('portfolio', (string) $p->id)->call('startEdit')->call('remove')->assertHasErrors('portfolio');
        Livewire::actingAs($boss)->test(Board::class)->set('portfolio', (string) $p->id)->call('remove');
        $this->assertSame(0, Portfolio::query()->count());

        $other = User::factory()->withPersonalTeam()->create();
        $foreign = Portfolio::query()->forceCreate(['team_id' => $other->currentTeam->id, 'name' => 'Theirs', 'kind' => 'portfolio']);
        Livewire::actingAs($boss)->test(Board::class)->set('portfolio', (string) $foreign->id)->assertNotFound();
    }

    private function expectExceptionOnce(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail('expected a refusal on '.$key);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }
}
