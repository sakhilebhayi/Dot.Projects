<?php

namespace Tests\Feature;

use App\Livewire\Reporting\Builder;
use App\Models\Project;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\CostLedger;
use App\Services\Governance;
use App\Services\ProjectPeople;
use App\Services\ProjectWork;
use App\Services\Reporting;
use App\Services\TimeTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * R-34 P8c: reports with stated columns, worked out for each reader (projects, money, time by
 * person and other people's utilisation only where allowed, and the exclusions said), sortable,
 * kept under a name and shared as settings, and downloaded as CSV, Excel or PDF.
 */
class ReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    /** @return array{boss: User, pm: User, ed: User, kiln: Project, belt: Project, secret: Project} */
    private function business(): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        $make = function (string $name) use ($team) {
            $u = User::factory()->create(['name' => $name, 'current_team_id' => $team->id]);
            $team->users()->attach($u, ['role' => 'editor']);

            return $u;
        };
        $pm = $make('Palesa PM');
        $ed = $make('Ed Editor');
        $mk = fn (string $name, User $owner, array $extra = []) => Project::create($extra + ['team_id' => $team->id, 'owner_id' => $owner->id, 'name' => $name, 'status' => 'active']);
        $kiln = $mk('Kiln reline', $pm, ['due_date' => '2026-11-20']);
        $belt = $mk('=HYPERLINK("x") belt', $boss);
        $secret = $mk('Retrenchments', $boss, ['visibility' => 'private']);
        $mk('Old plant', $boss, ['status' => 'completed']);
        $mk('Mothballed', $boss)->forceFill(['archived_at' => now()])->save();

        $this->actingAs($pm);
        app(CostLedger::class)->addLine($kiln, $pm, 'materials', 'Bricks', 10_000_000, null);
        $kiln->tasks()->create(['title' => 'Strip', 'status' => 'done', 'priority' => 'high', 'due_date' => '2026-11-03', 'completed_at' => '2026-11-02 10:00:00']);
        $kiln->tasks()->create(['title' => 'Late one', 'status' => 'done', 'priority' => 'high', 'due_date' => '2026-11-01', 'completed_at' => '2026-11-03 10:00:00']);
        $kiln->tasks()->create(['title' => 'Reline', 'status' => 'todo', 'priority' => 'high', 'due_date' => '2026-11-02']);
        app(Governance::class)->addRisk($kiln, $pm, ['title' => 'Bricks arrive late', 'probability' => 4, 'impact' => 5]);
        app(Governance::class)->addRisk($kiln, $pm, ['title' => 'Paint smell', 'probability' => 1, 'impact' => 1]);

        return ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln->fresh(), 'belt' => $belt->fresh(), 'secret' => $secret->fresh()];
    }

    /** @return array<string, mixed> */
    private function report(string $type, User $as, array $config = []): array
    {
        $this->actingAs($as);

        return app(Reporting::class)->run($type, $as->currentTeam, $as, Reporting::normalise($type, $config));
    }

    public function test_reports_cover_only_what_the_reader_may_see_and_say_what_is_left_out(): void
    {
        ['boss' => $boss, 'ed' => $ed] = $this->business();

        $mine = $this->report('executive', $boss);
        $this->assertSame(['=HYPERLINK("x") belt', 'Kiln reline', 'Retrenchments'], array_column($mine['rows'], 'project'), 'open projects only: no completed or archived');
        $kilnRow = collect($mine['rows'])->firstWhere('project', 'Kiln reline');
        $this->assertSame([10_000_000, 1, 0], [$kilnRow['budget'], $kilnRow['serious_risks'], $kilnRow['days_late']]);
        $this->assertSame([], $mine['notes']);

        $theirs = $this->report('executive', $ed);
        $this->assertSame(['=HYPERLINK("x") belt', 'Kiln reline'], array_column($theirs['rows'], 'project'));
        $this->assertNull(collect($theirs['rows'])->firstWhere('project', 'Kiln reline')['budget']);
        $this->assertSame(['2 projects whose money you do not see show no budget, CPI or SPI.'], $theirs['notes']);

        $budget = $this->report('budget', $ed);
        $this->assertSame([], $budget['rows']);
        $this->assertStringContainsString('2 projects whose money you do not see are left out.', implode(' ', $budget['notes']));
        $this->assertSame([10_000_000, 0], [$this->report('budget', $boss)['rows'][0]['budget'], $this->report('budget', $boss)['rows'][0]['actual']]);

        $this->assertSame(['=HYPERLINK("x") belt', 'Kiln reline', 'Old plant', 'Retrenchments'], array_column($this->report('progress', $boss, ['filters' => ['scope' => 'all'], 'sort' => 'project'])['rows'], 'project'));
        $this->assertSame(['Mothballed'], array_column($this->report('progress', $boss, ['filters' => ['scope' => 'archived']])['rows'], 'project'));

        $util = $this->report('utilisation', $ed);
        $this->assertSame(['Ed Editor'], array_column($util['rows'], 'person'));
        $this->assertSame(['Bongi Boss', 'Ed Editor', 'Palesa PM'], array_column($this->report('utilisation', $boss)['rows'], 'person'));
    }

    public function test_the_figures_follow_the_stated_rules_and_sort(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'kiln' => $kiln] = $this->business();
        $this->actingAs($pm);
        $m = $kiln->milestones()->create(['title' => 'Kiln hot', 'status' => 'pending', 'due_date' => '2026-11-10']);
        $kiln->tasks()->create(['title' => 'Heat up', 'status' => 'todo', 'priority' => 'high', 'milestone_id' => $m->id, 'due_date' => '2026-11-12']);
        app(ProjectWork::class)->setBaseline($kiln->fresh(), $pm, 'Agreed');
        $m->update(['due_date' => '2026-11-11']);

        $tasks = collect($this->report('tasks', $boss, ['filters' => ['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-02']])['rows'])->firstWhere('project', 'Kiln reline');
        $this->assertSame([4, 2, 1, 1, 50, 50], [$tasks['tasks'], $tasks['done'], $tasks['done_in_period'], $tasks['overdue'], $tasks['done_pct'], $tasks['on_time_pct']], 'Late one finished after its due date');

        $ms = $this->report('milestones', $boss)['rows'][0];
        $this->assertSame(['Kiln hot', '2026-11-10', '2026-11-11', '2026-11-12', 2], [$ms['milestone'], $ms['baseline_due']->toDateString(), $ms['due']->toDateString(), $ms['forecast']->toDateString(), $ms['slip']]);

        $risks = $this->report('risks', $boss);
        $this->assertSame(['Bricks arrive late', 'Paint smell'], array_column($risks['rows'], 'title'), 'worst first');
        $this->assertSame(['Paint smell', 'Bricks arrive late'], array_column($this->report('risks', $boss, ['sort' => 'score'])['rows'], 'title'));
        $this->assertSame(['Bricks arrive late', 'Paint smell'], array_column($this->report('risks', $boss, ['sort' => 'score', 'desc' => true])['rows'], 'title'));

        $c = Reporting::normalise('risks', ['columns' => ['score', 'nonsense', 'title'], 'sort' => 'nope', 'filters' => ['scope' => 'everything', 'portfolio' => 'x']]);
        $this->assertSame([['project', 'score', 'title'], null, 'open', ''], [$c['columns'], $c['sort'], $c['filters']['scope'], $c['filters']['portfolio']], 'unknown settings are dropped; the first column always stays');
        $this->expectException(ValidationException::class);
        Reporting::normalise('salaries', []);
    }

    public function test_time_by_person_shows_only_on_projects_you_run(): void
    {
        ['boss' => $boss, 'pm' => $pm, 'ed' => $ed, 'kiln' => $kiln] = $this->business();
        $time = app(TimeTracking::class);
        foreach ([$pm, $ed] as $who) {
            $this->actingAs($who);
            $time->log($who, $kiln, ['work_date' => '2026-11-03', 'minutes' => 90]);
        }
        $this->actingAs($boss);
        app(ProjectPeople::class)->add($kiln, $boss, $ed->id, 'member');

        $run = $this->report('time', $pm, ['filters' => ['period' => 'this_week']]);
        $this->assertEqualsCanonicalizing(['Palesa PM', 'Ed Editor'], array_column($run['rows'], 'person'));
        $this->assertSame(180, $run['totals']['hours']);
        $this->assertArrayNotHasKey('entries', $run['totals'], 'totals cover the columns shown');

        $run = $this->report('time', $ed, ['filters' => ['period' => 'this_week']]);
        $this->assertSame([['Everyone', 180]], array_map(fn ($r) => [$r['person'], $r['hours']], $run['rows']));
        $this->assertStringContainsString('Time by person shows only on projects you run', $run['notes'][0]);
    }

    public function test_reports_download_as_csv_excel_and_pdf(): void
    {
        ['boss' => $boss, 'ed' => $ed] = $this->business();
        $q = ['type' => 'executive', 'columns' => ['project', 'health', 'budget', 'cpi'], 'sort' => 'project'];

        $csv = $this->actingAs($boss)->get(route('reporting.export', ['format' => 'csv'] + $q))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $lines = explode("\n", trim(substr($csv->getContent(), 3)));
        $this->assertSame('Project,Health,"Budget (R)",CPI', $lines[0]);
        $this->assertCount(4, $lines);
        $this->assertSame('"\'=HYPERLINK(""x"") belt","On track",,', $lines[1]);
        $this->assertStringStartsWith('"Kiln reline",', $lines[2]);
        $this->assertStringEndsWith(',100000,', $lines[2]);
        $this->assertSame('Retrenchments,"On track",,', $lines[3]);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent());
        $this->assertStringContainsString("'=HYPERLINK", $csv->getContent(), 'a cell that would run as a formula is written as text');
        $this->assertStringContainsString('100000', $csv->getContent(), 'money in rand, as a number');

        $xlsx = $this->actingAs($boss)->get(route('reporting.export', ['format' => 'xlsx'] + $q))->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'rep');
        file_put_contents($path, $xlsx);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'a readable zip');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('<t xml:space="preserve">=HYPERLINK(&quot;x&quot;) belt</t>', $sheet, 'text stays text, never a formula');
        $this->assertStringContainsString('<v>100000</v>', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('state="frozen"', $sheet);

        $pdf = $this->actingAs($ed)->get(route('reporting.export', ['format' => 'pdf'] + $q))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('(Page 1 of 1)', $pdf);
        $this->assertStringContainsString('(Kiln reline)', $pdf);
        $this->assertStringNotContainsString('Retrenchments', $pdf, 'the editor cannot see the private project');
        $this->assertStringContainsString('whose money you do not see', $pdf);

        $this->actingAs($boss)->get(route('reporting.export', ['format' => 'csv', 'type' => 'salaries']))->assertNotFound();
        $this->actingAs($boss)->get('/reports/export.exe?type=executive')->assertNotFound();
    }

    public function test_reports_are_saved_shared_as_settings_and_kept_by_their_maker(): void
    {
        ['boss' => $boss, 'ed' => $ed] = $this->business();

        Livewire::actingAs($boss)->test(Builder::class)->call('choose', 'risks')->set('columns', ['project', 'title', 'score'])->call('sortBy', 'score')->call('sortBy', 'score')
            ->call('startSave', true)->set('name', '')->call('save')->assertHasErrors('name')
            ->set('name', 'Board risk pack')->set('shared', true)->call('save')->assertHasNoErrors()->assertSee('Board risk pack');
        $saved = SavedReport::query()->sole();
        $this->assertSame(['risks', ['project', 'title', 'score'], 'score', true, true], [$saved->type, $saved->config['columns'], $saved->config['sort'], $saved->config['desc'], $saved->shared]);

        $page = Livewire::actingAs($ed)->test(Builder::class)->assertSee('Board risk pack')->assertSee('Shared by Bongi Boss')->call('open', $saved->id)
            ->assertSet('type', 'risks')->assertSet('columns', ['project', 'title', 'score'])->assertSee('Bricks arrive late')->assertDontSee('Save as new');
        $page->call('removeSaved')->assertForbidden();
        Livewire::actingAs($ed)->test(Builder::class)->call('open', $saved->id)->call('startSave')->set('name', 'My copy')->call('save');
        $this->assertSame(2, SavedReport::query()->count(), 'someone else\'s shared report is copied, never overwritten');
        $this->assertSame('Board risk pack', $saved->fresh()->name);

        $this->actingAs($ed)->get(route('reporting.saved.export', [$saved, 'csv']))->assertOk()->assertSee('Bricks arrive late');
        $saved->update(['shared' => false]);
        $this->actingAs($ed)->get(route('reporting.saved.export', [$saved, 'csv']))->assertNotFound();
        Livewire::actingAs($ed)->test(Builder::class)->call('open', $saved->id)->assertNotFound();

        $this->actingAs($boss)->get(route('reporting.index'))->assertOk()->assertSee('Projects compared')->assertSee('>Reports<', false);
    }
}
