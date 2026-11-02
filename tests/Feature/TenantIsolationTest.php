<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\ChangeControl;
use App\Services\Gates;
use App\Services\Governance;
use App\Services\Meetings;
use App\Services\StatusReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDef;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R-34 P12a: "A user from one business must never access another business's projects or data."
 * Every GET route that takes a record is filled with one business's records and requested by
 * someone from another business, by someone in the same business outside a private project, and
 * with records from two businesses mixed in one address. Each must answer 403 or 404, never the
 * page. A route added later without a matching record fails this test until it is covered.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Routes this sweep deliberately leaves out, and why. */
    private const NOT_RECORD_ROUTES = [
        'livewire.preview-file' => 'a signed Livewire temporary upload, not a business record',
        'password.reset' => 'a password reset token',
        'storage.local' => 'the local disk route, unused',
        'team-invitations.accept' => 'a signed invitation link (Jetstream checks the signature and the email)',
        'reporting.export' => 'takes only a file format: it always reports on the reader\'s own business (ReportingTest)',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    /** @return array<string, mixed> every record a route can take, for one project */
    private function records(User $owner, Project $project): array
    {
        $this->actingAs($owner);
        $task = $project->tasks()->create(['title' => 'Reline', 'status' => 'todo', 'priority' => 'high']);
        $change = app(ChangeControl::class)->addChange($project, $owner, ['title' => 'Standby crane']);

        return [
            'project' => $project, 'task' => $task, 'change' => $change,
            'risk' => app(Governance::class)->addRisk($project, $owner, ['title' => 'Bricks late', 'probability' => 3, 'impact' => 3]),
            'issue' => app(Governance::class)->addIssue($project, $owner, ['title' => 'Road flooded']),
            'decision' => app(ChangeControl::class)->addDecision($project, $owner, ['title' => 'Use the standby crane'], false),
            'gate' => app(Gates::class)->add($project, $owner, ['title' => 'Ready to start'], 'start'),
            'meeting' => app(Meetings::class)->add($project, $owner, ['title' => 'Kick-off', 'kind' => 'team', 'held_at' => '2026-11-04 09:00']),
            'report' => app(StatusReports::class)->start($project, $owner),
            'document' => ProjectDocument::query()->forceCreate(['project_id' => $project->id, 'files_uuid' => (string) str()->uuid(), 'name' => 'plan.pdf', 'version' => 1]),
            'saved' => SavedReport::query()->forceCreate(['team_id' => $project->team_id, 'user_id' => $owner->id, 'name' => 'Mine', 'type' => 'risks', 'config' => [], 'shared' => false]),
            'team' => $owner->currentTeam,
            'format' => 'csv',
        ];
    }

    /**
     * @param  array<string, mixed>  $records
     * @return array<string, string> route name => URL, for every GET route that takes a record
     */
    private function urls(array $records): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RouteDef $route */
            $name = (string) $route->getName();
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() === [] || isset(self::NOT_RECORD_ROUTES[$name])) {
                continue;
            }
            $missing = array_diff($route->parameterNames(), array_keys($records));
            $this->assertSame([], $missing, 'Route '.$name.' takes '.implode(', ', $missing).': add a record for it to this sweep.');
            $out[$name] = route($name, array_intersect_key($records, array_flip($route->parameterNames())));
        }

        return $out;
    }

    private function refused(User $as, string $url, string $why): void
    {
        $status = $this->actingAs($as)->get($url)->getStatusCode();
        $this->assertContains($status, [403, 404], $why.': '.$url.' answered '.$status);
    }

    public function test_nobody_reaches_another_business_by_any_address(): void
    {
        $a = User::factory()->withPersonalTeam()->create(['name' => 'Ayanda A']);
        $b = User::factory()->withPersonalTeam()->create(['name' => 'Bongi B']);
        $records = $this->records($a, Project::create(['team_id' => $a->currentTeam->id, 'owner_id' => $a->id, 'name' => 'Kiln reline', 'status' => 'active']));
        $urls = $this->urls($records);
        $this->assertGreaterThanOrEqual(28, count($urls), 'every record route is in the sweep');

        foreach ($urls as $name => $url) {
            $this->assertContains($this->actingAs($a)->get($url)->getStatusCode(), [200, 302], 'the owner reaches '.$name);
            $this->refused($b, $url, 'another business');
        }
    }

    public function test_a_private_project_is_closed_to_the_rest_of_its_own_business(): void
    {
        $a = User::factory()->withPersonalTeam()->create();
        $colleague = User::factory()->create(['current_team_id' => $a->currentTeam->id]);
        $a->currentTeam->users()->attach($colleague, ['role' => 'admin']);
        $records = $this->records($a, Project::create(['team_id' => $a->currentTeam->id, 'owner_id' => $a->id, 'name' => 'Retrenchments', 'status' => 'active', 'visibility' => 'private']));

        foreach ($this->urls($records) as $name => $url) {
            if ($name === 'teams.show' || str_starts_with($name, 'reporting.saved')) {
                continue; // business-level pages, not the private project's
            }
            $this->refused($colleague, $url, 'a private project');
        }
    }

    public function test_records_from_two_businesses_never_combine_in_one_address(): void
    {
        $a = User::factory()->withPersonalTeam()->create();
        $b = User::factory()->withPersonalTeam()->create();
        $recordsA = $this->records($a, Project::create(['team_id' => $a->currentTeam->id, 'owner_id' => $a->id, 'name' => 'Kiln', 'status' => 'active']));
        $own = Project::create(['team_id' => $b->currentTeam->id, 'owner_id' => $b->id, 'name' => 'Own', 'status' => 'active']);

        $mixed = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $params = $route->parameterNames();
            if (! in_array('GET', $route->methods(), true) || count($params) < 2 || $params[0] !== 'project') {
                continue;
            }
            // B's own project, A's record inside it.
            $this->refused($b, route((string) $route->getName(), ['project' => $own] + array_intersect_key($recordsA, array_flip($params))), 'a record from another business');
            $mixed++;
        }
        $this->assertGreaterThanOrEqual(9, $mixed);
    }
}
