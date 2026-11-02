<?php

namespace Tests\Feature;

use App\Livewire\Integrations\Status;
use App\Livewire\Projects\ProjectDocuments;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\ProjectDocuments as Documents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-34 P11b: project documents in Dot.Files. Filed through the signed platform file API, one
 * folder per project; listed here by reference; downloaded through Dot.Projects after its own
 * permission check; new versions refused when someone saved a newer one; removal goes to the
 * business's Dot.Files trash; private projects ask before filing.
 */
class ProjectDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'files-test-secret';

    private const UUID = '7f3c2a10-1111-4222-8333-944455556666';

    private const DOC = '0b6f8a52-2222-4333-8444-955566667777';

    /** @var list<Request> */
    private array $calls = [];

    /** @var array<string, array{0: int, 1: array<string, mixed>}> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
        Cache::flush();
        config(['services.files.url' => 'https://files.test', 'services.files.secret' => self::SECRET, 'services.files.platform' => 'projects']);
        $this->answers = [
            'store' => [201, ['uuid' => self::DOC, 'name' => 'plan.pdf', 'size' => 2048, 'mime' => 'application/pdf', 'reference' => null, 'open_url' => 'https://files.test/o/'.self::DOC]],
            'update' => [200, ['uuid' => self::DOC, 'name' => 'plan.pdf', 'size' => 4096, 'mime' => 'application/pdf', 'version' => 2]],
            'fetch' => [200, ['uuid' => self::DOC, 'name' => 'plan.pdf', 'size' => 9, 'mime' => 'application/pdf', 'content_b64' => base64_encode('%PDF-1.4!')]],
            'trash' => [200, ['uuid' => self::DOC]],
        ];
        Http::fake(function (Request $request) {
            $this->calls[] = $request;
            [$status, $body] = $this->answers[basename($request->url())] ?? [404, ['error' => 'not_found']];

            return Http::response($body, $status);
        });
    }

    /** A real file on disk: Livewire's test helper stores fake uploads empty. */
    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return array{boss: User, ed: User, vi: User, kiln: Project} */
    private function business(): array
    {
        $boss = User::factory()->withPersonalTeam()->create(['name' => 'Bongi Boss']);
        $team = $boss->currentTeam;
        $team->forceFill(['infodot_team_uuid' => self::UUID])->save();
        $make = function (string $name, string $role) use ($team) {
            $u = User::factory()->create(['name' => $name, 'current_team_id' => $team->id]);
            $team->users()->attach($u, ['role' => $role]);

            return $u;
        };
        $ed = $make('Ed Editor', 'editor');
        $vi = $make('Vi Viewer', 'viewer');
        $kiln = Project::create(['team_id' => $team->id, 'owner_id' => $boss->id, 'name' => 'Kiln reline', 'status' => 'active']);

        return ['boss' => $boss, 'ed' => $ed, 'vi' => $vi, 'kiln' => $kiln];
    }

    public function test_a_document_is_filed_in_dot_files_signed_and_listed_here(): void
    {
        ['ed' => $ed, 'kiln' => $kiln] = $this->business();
        $task = $kiln->tasks()->create(['title' => 'Reline', 'status' => 'todo', 'priority' => 'high']);

        $this->actingAs($ed);
        app(Documents::class)->add($kiln, $ed, $this->file('plan.pdf', '%PDF-1.4 kiln plan'), $task, false);
        Livewire::actingAs($ed)->test(ProjectDocuments::class, ['project' => $kiln])->assertSee('plan.pdf')->assertSee('version 1, for “Reline”')->assertSee('Open in Dot.Files');

        $call = $this->calls[0];
        $this->assertSame('https://files.test/api/platform/files/store', $call->url());
        // Dot.Files' own check (App\Services\Ecosystem\PlatformRequest), rebuilt here.
        $ts = (int) $call->header('X-Dot-Timestamp')[0];
        $this->assertSame(['projects', 'sha256='.hash_hmac('sha256', $ts.'.'.$call->body(), self::SECRET)], [$call->header('X-Dot-Platform')[0], $call->header('X-Dot-Signature')[0]]);
        $body = json_decode($call->body(), true);
        $this->assertSame([self::UUID, 'plan.pdf', 'Dot.Projects - Kiln reline', '%PDF-1.4 kiln plan'], [$body['team_uuid'], $body['name'], $body['folder'], base64_decode($body['content_b64'])]);

        $doc = ProjectDocument::query()->sole();
        $this->assertSame([self::DOC, $task->id, 2048, 1, $ed->id], [$doc->files_uuid, $doc->task_id, $doc->size, $doc->version, $doc->uploaded_by]);
        $this->assertContains('added the document “plan.pdf”', array_column(ActivityFeed::forProject($kiln, 10), 'text'));
        Livewire::actingAs($ed)->test(ProjectDocuments::class, ['project' => $kiln, 'task' => $task])->assertSee('plan.pdf')->assertDontSee('For a task');
        $this->actingAs($ed)->get(route('projects.documents', $kiln))->assertOk()->assertSee('>Documents<', false);
    }

    public function test_downloads_go_through_the_project_check_and_name_what_went_wrong(): void
    {
        ['boss' => $boss, 'ed' => $ed, 'vi' => $vi, 'kiln' => $kiln] = $this->business();
        $this->actingAs($ed);
        $doc = app(Documents::class)->add($kiln, $ed, $this->file('plan.pdf', '%PDF'), null, false);

        $this->actingAs($vi)->get(route('projects.documents.download', [$kiln, $doc]))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'attachment; filename="plan.pdf"')->assertSeeText('%PDF-1.4!', false);
        $this->assertEquals(['uuid' => self::DOC, 'team_uuid' => self::UUID], array_intersect_key(json_decode(end($this->calls)->body(), true), ['uuid' => 1, 'team_uuid' => 1]));

        $secret = Project::create(['team_id' => $kiln->team_id, 'owner_id' => $boss->id, 'name' => 'Retrenchments', 'status' => 'active', 'visibility' => 'private']);
        $hidden = ProjectDocument::query()->forceCreate(['project_id' => $secret->id, 'files_uuid' => '1b6f8a52-2222-4333-8444-955566667777', 'name' => 'list.xlsx', 'version' => 1]);
        $this->actingAs($ed)->get(route('projects.documents.download', [$secret, $hidden]))->assertNotFound(); // a private project is not even confirmed to exist
        $this->actingAs($ed)->get('/projects/'.$kiln->id.'/documents/'.$hidden->id)->assertNotFound();

        $this->answers['fetch'] = [401, ['error' => 'invalid_request']];
        $this->actingAs($ed)->get(route('projects.documents.download', [$kiln, $doc]))->assertRedirect(route('projects.documents', $kiln));
        $this->actingAs($ed)->get(route('projects.documents', $kiln))->assertSee('DOT_SERVICE_SECRET_PROJECTS');
    }

    public function test_new_versions_respect_dot_files_and_removal_uses_its_trash(): void
    {
        ['ed' => $ed, 'vi' => $vi, 'kiln' => $kiln] = $this->business();
        $this->actingAs($ed);
        $svc = app(Documents::class);
        $doc = $svc->add($kiln, $ed, $this->file('plan.pdf', '%PDF'), null, false);

        $svc->replace($doc->fresh(), $ed, $this->file('plan.pdf', '%PDF v2'));
        $this->assertSame([1, '%PDF v2'], [json_decode(end($this->calls)->body(), true)['base_version'], base64_decode(json_decode(end($this->calls)->body(), true)['content_b64'])]);
        $this->assertSame([2, 4096], [$doc->fresh()->version, $doc->fresh()->size]);
        Livewire::actingAs($ed)->test(ProjectDocuments::class, ['project' => $kiln])->assertSee('version 2');

        $this->answers['update'] = [409, ['error' => 'version_conflict', 'current_version' => 3]];
        try {
            $svc->replace($doc->fresh(), $ed, $this->file('plan.pdf', '%PDF v3'));
            $this->fail('a conflicting version was saved');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Someone saved a newer version in Dot.Files', $e->errors()['document'][0]);
        }
        $this->assertSame(2, $doc->fresh()->version, 'nothing is overwritten');

        try {
            $svc->remove($doc->fresh(), $vi);
            $this->fail('a viewer removed a document');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only people who can change this project', $e->errors()['document'][0]);
        }
        $this->answers['trash'] = [503, []];
        Livewire::actingAs($ed)->test(ProjectDocuments::class, ['project' => $kiln])->call('remove', $doc->id)->assertSee('Dot.Files had an error (503)');
        $this->assertNotNull($doc->fresh(), 'kept while Dot.Files cannot trash it');
        $this->answers['trash'] = [404, ['error' => 'not_found']];
        Livewire::actingAs($ed)->test(ProjectDocuments::class, ['project' => $kiln])->call('remove', $doc->id)->assertHasNoErrors();
        $this->assertNull($doc->fresh(), 'already gone from Dot.Files, so it goes here too');
        $this->assertContains('removed a document', array_column(ActivityFeed::forProject($kiln, 10), 'text'));
    }

    public function test_the_page_hands_uploads_to_the_service_and_shows_its_refusals(): void
    {
        ['ed' => $ed, 'vi' => $vi, 'kiln' => $kiln] = $this->business();
        // Livewire's upload helper stores fake files without content, which the service refuses.
        Livewire::actingAs($ed)->test(ProjectDocuments::class, ['project' => $kiln])->assertSee('File it in Dot.Files')
            ->set('upload', UploadedFile::fake()->create('plan.pdf', 10))->call('add')->assertSee('That file is empty.')
            ->set('upload', UploadedFile::fake()->create('run.exe', 10))->call('add')->assertSee('Dot.Files accepts pdf');
        $this->assertSame([], $this->calls);
        Livewire::actingAs($vi)->test(ProjectDocuments::class, ['project' => $kiln])->assertDontSee('File it in Dot.Files');
    }

    public function test_private_projects_ask_first_and_bad_files_never_leave(): void
    {
        ['boss' => $boss, 'kiln' => $kiln] = $this->business();
        $kiln->forceFill(['visibility' => 'private'])->save();
        $this->actingAs($boss);
        $svc = app(Documents::class);
        foreach ([[$this->file('plan.pdf', '%PDF'), false, 'This project is private'], [$this->file('run.exe', 'MZ'), true, 'Dot.Files accepts']] as [$file, $ack, $why]) {
            try {
                $svc->add($kiln->fresh(), $boss, $file, null, $ack);
                $this->fail('expected a refusal');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($why, $e->errors()['document'][0]);
            }
        }
        $this->assertSame([], $this->calls, 'nothing was sent to Dot.Files');
        $svc->add($kiln->fresh(), $boss, $this->file('plan.pdf', '%PDF'), null, true);
        $this->assertSame(1, ProjectDocument::query()->count());
    }

    public function test_without_dot_files_set_up_documents_say_so_and_owners_see_the_state(): void
    {
        ['boss' => $boss, 'kiln' => $kiln] = $this->business();
        Livewire::actingAs($boss)->test(Status::class)->assertSee('Dot.Files')->assertSee('0 filed by this business')->assertSee('None yet');

        config(['services.files.secret' => '']);
        Livewire::actingAs($boss)->test(ProjectDocuments::class, ['project' => $kiln])->assertSee('not connected on this server yet')->assertSee('See Integrations')->assertDontSee('File it in Dot.Files');
        Livewire::actingAs($boss)->test(Status::class)->assertSee('DOT_SERVICE_SECRET_PROJECTS');
        $this->assertSame([], $this->calls);
    }
}
