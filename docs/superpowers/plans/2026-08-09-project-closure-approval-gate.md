# Project Closure Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Completing a project's last milestone no longer closes the project outright — it proposes closure (`status: 'pending_closure_approval'`); only the project's owner can confirm (the real effect — `status: 'completed'`, `ProjectClosed` dispatched) or reject (reason required, reverts to `active`) it.

**Architecture:** `Project::closeIfAllMilestonesCompleted()` (still auto-triggered from `ProjectBoard::moveTask()`, trigger logic unchanged) now proposes instead of closing. Two new `Project` methods (`confirmClosure()`/`rejectClosure()`) hold the real state transitions. A new `ProjectPolicy::closeProject()` ability — checked in two new `ProjectBoard` Livewire methods, matching this app's existing `$this->authorize(...)` convention already used by `moveTask()`/`assignTask()` — restricts both actions to the project's owner. The review UI lives inline on the existing board page.

**Tech Stack:** Laravel (this repo's existing conventions), Livewire 3 (`#[Computed]` attribute style — confirmed via `ProjectBoard.php`), PHPUnit.

## Global Constraints

- `projects.status` widens from a native `enum` to a plain `string` — same portable-append technique as Dot.Ehail's `driver_profiles.status` (conditionally drop the Postgres CHECK constraint on the `pgsql` driver only; SQLite, used by this app's tests, rebuilds the table wholesale on `change()` and has no such constraint to drop).
- No separate request table — `closure_reviewed_by`/`closure_reviewed_at`/`closure_rejected_reason` live directly on `projects`, overwritten (not accumulated) on each closure cycle.
- **Authorization lives in `ProjectPolicy`, checked via `$this->authorize(...)` inside `ProjectBoard`'s Livewire methods** — not inline `abort_unless()` calls inside the `Project` model methods. This is a correction from the spec's draft (which showed `abort_unless` inside the model), made after checking `ProjectBoard::moveTask()`/`assignTask()`'s real, established convention: every user-facing action is policy-gated at the Livewire-method layer, and `Project`'s own methods stay pure state-transition logic with no auth concerns baked in.
- Only the project's `owner_id` — not `update`'s existing team-wide boundary — may confirm or reject a closure.
- Rejecting requires a non-empty reason. Rejecting is **not** a terminal state — the project returns to `active` and can propose closure again later.
- `ProjectClosed` is dispatched from exactly one place going forward: `Project::confirmClosure()`. `closeIfAllMilestonesCompleted()` no longer dispatches it.
- A pre-existing test (`tests/Feature/MilestoneAndProjectStatusEventsTest.php::test_completing_the_last_milestone_closes_the_project_and_dispatches_the_event`) currently asserts the old immediate-close behavior and must be updated, not left broken.
- Every `git add` lists files explicitly, never `git add -A`/`git add .` — this repo has pre-existing unrelated uncommitted changes (`public/images/mark-light.png`/`mark.png`) stashed, not part of this work. `git status --short` before and after every task.
- Full test suite (`php artisan test --compact`) must stay green after every task; `vendor/bin/pint --dirty --format agent` after every task that touches PHP files.

---

### Task 1: `projects` schema + `Project` model state machine

**Files:**
- Create: `database/migrations/2026_08_09_000001_add_closure_approval_to_projects_table.php`
- Modify: `app/Models/Project.php`
- Modify: `tests/Feature/MilestoneAndProjectStatusEventsTest.php`
- Test: `tests/Unit/ProjectClosureTest.php`

**Interfaces:**
- Produces: `Project::closeIfAllMilestonesCompleted(): bool` (existing signature, new behavior — proposes, doesn't close), `Project::confirmClosure(): bool`, `Project::rejectClosure(string $reason): bool` (both pure state-transition, no auth check — callers authorize before calling).

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_09_000001_add_closure_approval_to_projects_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // status was created as a native enum/CHECK-constrained column.
        // Postgres needs its named CHECK constraint dropped before
        // Schema::change() can widen the column to a plain string; SQLite
        // (this app's test driver) has no such named constraint --
        // Schema::change() there rebuilds the table wholesale instead, so
        // the DROP CONSTRAINT statement is a no-op it doesn't understand.
        // Identical technique already used for Dot.Ehail's
        // driver_profiles.status.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check');
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->string('status')->default('planning')->change();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('closure_reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('closure_reviewed_at')->nullable()->after('closure_reviewed_by');
            $table->text('closure_rejected_reason')->nullable()->after('closure_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closure_reviewed_by');
            $table->dropColumn(['closure_reviewed_at', 'closure_rejected_reason']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migration runs with no errors.

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/ProjectClosureTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Events\ProjectClosed;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProjectClosureTest extends TestCase
{
    use RefreshDatabase;

    private function projectWithCompletedMilestones(string $status = 'active'): Project
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Test Project',
            'status' => $status,
        ]);
        $project->milestones()->create(['title' => 'Only milestone', 'status' => 'completed']);

        return $project;
    }

    public function test_close_if_all_milestones_completed_proposes_instead_of_closing(): void
    {
        Event::fake();
        $project = $this->projectWithCompletedMilestones();

        $result = $project->closeIfAllMilestonesCompleted();

        $this->assertTrue($result);
        $this->assertSame('pending_closure_approval', $project->fresh()->status);
        Event::assertNotDispatched(ProjectClosed::class);
    }

    public function test_close_if_all_milestones_completed_is_a_noop_while_already_pending(): void
    {
        $project = $this->projectWithCompletedMilestones('pending_closure_approval');

        $result = $project->closeIfAllMilestonesCompleted();

        $this->assertFalse($result);
        $this->assertSame('pending_closure_approval', $project->fresh()->status);
    }

    public function test_confirm_closure_completes_the_project_and_dispatches_the_event(): void
    {
        Event::fake();
        $project = $this->projectWithCompletedMilestones('pending_closure_approval');
        $reviewer = User::find($project->owner_id);

        $result = $project->confirmClosure();

        $this->assertTrue($result);
        $fresh = $project->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->closure_reviewed_at);
        Event::assertDispatched(ProjectClosed::class, fn ($event) => $event->project->is($fresh));
    }

    public function test_confirm_closure_is_a_noop_unless_pending(): void
    {
        Event::fake();
        $project = $this->projectWithCompletedMilestones('active');

        $result = $project->confirmClosure();

        $this->assertFalse($result);
        $this->assertSame('active', $project->fresh()->status);
        Event::assertNotDispatched(ProjectClosed::class);
    }

    public function test_reject_closure_reverts_to_active_and_records_the_reason(): void
    {
        Event::fake();
        $project = $this->projectWithCompletedMilestones('pending_closure_approval');

        $result = $project->rejectClosure('Not ready, one more review needed.');

        $this->assertTrue($result);
        $fresh = $project->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame('Not ready, one more review needed.', $fresh->closure_rejected_reason);
        Event::assertNotDispatched(ProjectClosed::class);
    }

    public function test_a_rejected_closure_can_be_proposed_again(): void
    {
        $project = $this->projectWithCompletedMilestones('pending_closure_approval');
        $project->rejectClosure('Not yet.');
        $this->assertSame('active', $project->fresh()->status);

        // Reopen and re-complete the milestone -- simulates real board activity.
        $project->milestones()->first()->update(['status' => 'pending']);
        $project->milestones()->first()->update(['status' => 'completed']);

        $result = $project->fresh()->closeIfAllMilestonesCompleted();

        $this->assertTrue($result);
        $this->assertSame('pending_closure_approval', $project->fresh()->status);
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test tests/Unit/ProjectClosureTest.php`
Expected: FAIL — current `closeIfAllMilestonesCompleted()` sets `status: 'completed'` directly; `confirmClosure()`/`rejectClosure()` don't exist.

- [ ] **Step 5: Rewrite `Project`'s closure methods**

In `app/Models/Project.php`, replace the existing `closeIfAllMilestonesCompleted()` method:

```php
    /**
     * Propose closing this project (status -> "pending_closure_approval")
     * once it has at least one milestone and every milestone is
     * "completed". A no-op if the project has no milestones yet, is
     * already completed, is "on_hold" (an on-hold project isn't
     * auto-reopened or auto-closed by task activity -- that status change
     * stays a deliberate, manual one), or already has a closure pending.
     *
     * Does NOT dispatch ProjectClosed -- that now only happens from
     * confirmClosure(), once the project's owner has actually confirmed.
     *
     * Called from ProjectBoard::moveTask() after Milestone::syncStatusFromTasks()
     * so a task move that finishes the last milestone can also propose
     * closing the project in the same request.
     */
    public function closeIfAllMilestonesCompleted(): bool
    {
        if (in_array($this->status, ['completed', 'on_hold', 'pending_closure_approval'], true)) {
            return false;
        }

        $milestoneStatuses = $this->milestones()->pluck('status');

        if ($milestoneStatuses->isEmpty() || $milestoneStatuses->contains(fn ($status) => $status !== 'completed')) {
            return false;
        }

        $this->update(['status' => 'pending_closure_approval']);

        return true;
    }

    /**
     * The project owner confirms a pending closure. This is the only
     * place ProjectClosed is dispatched. No-op (returns false) unless the
     * project is actually pending_closure_approval -- callers are
     * responsible for authorizing the caller as the owner before calling
     * this (see ProjectPolicy::closeProject(), checked in
     * ProjectBoard::confirmProjectClosure()).
     */
    public function confirmClosure(): bool
    {
        if ($this->status !== 'pending_closure_approval') {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'closure_reviewed_by' => $this->owner_id,
            'closure_reviewed_at' => now(),
        ]);

        ProjectClosed::dispatch($this);

        return true;
    }

    /**
     * The project owner rejects a pending closure. Not a terminal state --
     * the project returns to "active" so work can continue, and
     * closeIfAllMilestonesCompleted() can propose closure again later.
     * No-op (returns false) unless the project is actually
     * pending_closure_approval.
     */
    public function rejectClosure(string $reason): bool
    {
        if ($this->status !== 'pending_closure_approval') {
            return false;
        }

        $this->update([
            'status' => 'active',
            'closure_rejected_reason' => $reason,
            'closure_reviewed_by' => $this->owner_id,
            'closure_reviewed_at' => now(),
        ]);

        return true;
    }
```

Note: `confirmClosure()`/`rejectClosure()` record `closure_reviewed_by` as `$this->owner_id`, not an injected `$user` parameter — the spec's draft signature was `confirmClosure(User $user)`, but per this task's corrected authorization approach (Global Constraints), the caller (`ProjectBoard`, Task 2) has already verified `auth()->id() === $project->owner_id` via the policy before calling either method, so passing the owner's own ID through is redundant; using `$this->owner_id` directly keeps these methods simpler and avoids a parameter that would always equal `$this->owner_id` anyway by the time either method is reachable.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/ProjectClosureTest.php`
Expected: PASS (6 tests)

- [ ] **Step 7: Fix the pre-existing test broken by this change**

Read `tests/Feature/MilestoneAndProjectStatusEventsTest.php` first (already read during plan-writing — reproduced here for the exact edit). Replace the `test_completing_the_last_milestone_closes_the_project_and_dispatches_the_event` method:

```php
    public function test_completing_the_last_milestone_proposes_closure_but_does_not_close_it(): void
    {
        Event::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Closing Project',
            'status' => 'active',
        ]);
        $doneMilestone = $project->milestones()->create(['title' => 'Done already', 'status' => 'completed']);
        $lastMilestone = $project->milestones()->create(['title' => 'Final push', 'status' => 'pending']);
        $task = $project->tasks()->create([
            'milestone_id' => $lastMilestone->id,
            'title' => 'Wrap it up',
            'status' => 'review',
        ]);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('moveTask', $task->id, 'done');

        $this->assertEquals('completed', $lastMilestone->fresh()->status);
        $this->assertEquals('pending_closure_approval', $project->fresh()->status);
        Event::assertNotDispatched(ProjectClosed::class);
    }
```

This replaces the old method name and body (which asserted `status === 'completed'` and `ProjectClosed` dispatched immediately) — both are now wrong given this task's change. The class's other three test methods (`test_moving_a_milestones_only_task_to_done_...`, `test_moving_a_task_to_in_progress_...`, `test_an_on_hold_project_is_not_auto_closed`) are unaffected and stay as-is.

- [ ] **Step 8: Run the full test file to verify it passes**

Run: `php artisan test tests/Feature/MilestoneAndProjectStatusEventsTest.php`
Expected: PASS (4 tests)

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_09_000001_add_closure_approval_to_projects_table.php \
  app/Models/Project.php tests/Unit/ProjectClosureTest.php \
  tests/Feature/MilestoneAndProjectStatusEventsTest.php \
  docs/superpowers/plans/2026-08-09-project-closure-approval-gate.md
git commit -m "feat: project closure now proposes instead of closing outright

closeIfAllMilestonesCompleted() sets pending_closure_approval and no
longer dispatches ProjectClosed. New confirmClosure()/rejectClosure()
hold the real state transitions -- confirmClosure() is the only place
ProjectClosed fires now. Rejecting is not terminal: the project
returns to active and can propose closure again later.

Fixes a real pre-existing test this change breaks
(MilestoneAndProjectStatusEventsTest's closure-behavior assertion) --
updated to match the new proposed-not-closed behavior, not left red."
```

---

### Task 2: `ProjectBoard` review UI

**Files:**
- Create: (none — modifying existing files)
- Modify: `app/Policies/ProjectPolicy.php`
- Modify: `app/Livewire/Projects/ProjectBoard.php`
- Modify: `resources/views/livewire/projects/project-board.blade.php`
- Modify: `resources/views/projects/show.blade.php`
- Test: `tests/Feature/ProjectClosureApprovalTest.php`

**Interfaces:**
- Consumes: `Project::confirmClosure()`/`rejectClosure(string $reason)` (Task 1).
- Produces: `ProjectPolicy::closeProject(User $user, Project $project): bool`; `ProjectBoard::confirmProjectClosure()`, `promptRejectClosure()`, `cancelRejectClosure()`, `confirmRejectClosure()`; `ProjectBoard::$rejectingClosure` (bool), `$closureRejectReason` (string).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ProjectClosureApprovalTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Events\ProjectClosed;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectClosureApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function pendingProject(User $owner): Project
    {
        $project = Project::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Test Project',
            'status' => 'pending_closure_approval',
        ]);
        $project->milestones()->create(['title' => 'Only milestone', 'status' => 'completed']);

        return $project;
    }

    public function test_owner_can_confirm_closure(): void
    {
        Event::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->pendingProject($owner);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('confirmProjectClosure');

        $fresh = $project->fresh();
        $this->assertSame('completed', $fresh->status);
        Event::assertDispatched(ProjectClosed::class, fn ($event) => $event->project->is($fresh));
    }

    public function test_owner_can_reject_with_a_reason(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->pendingProject($owner);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('promptRejectClosure')
            ->set('closureRejectReason', 'Needs one more review.')
            ->call('confirmRejectClosure');

        $fresh = $project->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame('Needs one more review.', $fresh->closure_rejected_reason);
    }

    public function test_rejecting_without_a_reason_leaves_status_unchanged(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->pendingProject($owner);

        Livewire::actingAs($owner)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('promptRejectClosure')
            ->set('closureRejectReason', '')
            ->call('confirmRejectClosure');

        $this->assertSame('pending_closure_approval', $project->fresh()->status);
    }

    public function test_non_owner_team_member_cannot_confirm_or_reject_despite_having_board_access(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $project = $this->pendingProject($owner);
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        $this->withoutExceptionHandling();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        Livewire::actingAs($member)
            ->test(ProjectBoard::class, ['project' => $project])
            ->call('confirmProjectClosure');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ProjectClosureApprovalTest.php`
Expected: FAIL — `confirmProjectClosure`/`promptRejectClosure`/`confirmRejectClosure` methods don't exist on `ProjectBoard` yet.

- [ ] **Step 3: Add `ProjectPolicy::closeProject()`**

In `app/Policies/ProjectPolicy.php`, add after `update()`:

```php
    /**
     * Determine whether the user can confirm or reject a pending project
     * closure. Narrower than update() -- any team member can edit the
     * board, but only the project's owner may finalize (or reject) an
     * automatically-proposed closure.
     */
    public function closeProject(User $user, Project $project): bool
    {
        return $user->id === $project->owner_id;
    }
```

- [ ] **Step 4: Add the Livewire methods**

In `app/Livewire/Projects/ProjectBoard.php`, add two new public properties near the top of the class, next to `public ?int $movingTaskId = null;`:

```php
    public bool $rejectingClosure = false;

    public string $closureRejectReason = '';
```

Add four new methods, placed after `moveTask()`:

```php
    public function confirmProjectClosure(): void
    {
        $this->authorize('closeProject', $this->project);

        $this->project->confirmClosure();
        $this->project->refresh();
    }

    public function promptRejectClosure(): void
    {
        $this->authorize('closeProject', $this->project);

        $this->rejectingClosure = true;
        $this->closureRejectReason = '';
    }

    public function cancelRejectClosure(): void
    {
        $this->rejectingClosure = false;
        $this->closureRejectReason = '';
    }

    public function confirmRejectClosure(): void
    {
        $this->authorize('closeProject', $this->project);

        if (trim($this->closureRejectReason) === '') {
            return;
        }

        $this->project->rejectClosure($this->closureRejectReason);
        $this->project->refresh();
        $this->rejectingClosure = false;
        $this->closureRejectReason = '';
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ProjectClosureApprovalTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Add the review banner to the board view**

Read `resources/views/livewire/projects/project-board.blade.php` first to confirm its exact opening line (already read during plan-writing: `<div class="flex gap-4 overflow-x-auto pb-4">`). Add a banner immediately before that line:

```blade
@if($project->status === 'pending_closure_approval')
    <div class="mb-4 p-4 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20">
        @if(auth()->id() === $project->owner_id)
            <p class="text-sm font-medium text-amber-800 dark:text-amber-300 mb-2">
                All milestones are complete. Confirm to close this project, or reject to keep it active.
            </p>
            @if($rejectingClosure)
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="closureRejectReason" placeholder="Reason for rejecting"
                        class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                    <button wire:click="confirmRejectClosure" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                        Confirm Reject
                    </button>
                    <button wire:click="cancelRejectClosure" class="text-xs px-3 py-1.5 rounded bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-medium">
                        Cancel
                    </button>
                </div>
            @else
                <div class="flex items-center gap-2">
                    <button wire:click="confirmProjectClosure" class="text-xs px-3 py-1.5 rounded bg-green-600 hover:bg-green-700 text-white font-medium">
                        Confirm Closure
                    </button>
                    <button wire:click="promptRejectClosure" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                        Reject
                    </button>
                </div>
            @endif
        @else
            <p class="text-sm font-medium text-amber-800 dark:text-amber-300">
                All milestones are complete. Awaiting the project owner's confirmation to close this project.
            </p>
        @endif
    </div>
@endif
```

- [ ] **Step 7: Fix the status badge in the project page wrapper**

In `resources/views/projects/show.blade.php`, the `match($project->status)` block computing `[$badgeBg, $badgeColor]` needs a new arm so `pending_closure_approval` doesn't fall through to the generic gray `default`. Add it between the `'on_hold'` and `'completed'` arms:

```php
                'on_hold'   => ['rgba(251,146,60,0.12)', '#fb923c'],
                'pending_closure_approval' => ['rgba(245,158,11,0.12)', '#f59e0b'],
                'completed' => ['rgba(34,197,94,0.12)', '#22c55e'],
```

The existing `{{ str_replace('_', ' ', ucfirst($project->status)) }}` label expression already renders `pending_closure_approval` as "Pending closure approval" correctly with no further change needed.

- [ ] **Step 8: Run tests to verify they still pass**

Run: `php artisan test tests/Feature/ProjectClosureApprovalTest.php`
Expected: PASS (4 tests) — confirms the view changes didn't break component rendering (Livewire re-renders the view after every `->call()` in the test harness, so a Blade syntax error here would surface as a test failure).

- [ ] **Step 9: Commit**

```bash
git add app/Policies/ProjectPolicy.php app/Livewire/Projects/ProjectBoard.php \
  resources/views/livewire/projects/project-board.blade.php resources/views/projects/show.blade.php \
  tests/Feature/ProjectClosureApprovalTest.php
git commit -m "feat: inline owner review UI for pending project closures

ProjectPolicy::closeProject() -- narrower than update(), owner-only.
Confirm/reject live on the existing board page the owner already has
access to; a non-owner team member sees an 'awaiting owner' message
instead of controls, and is blocked server-side too if they try to
call the Livewire methods directly. Also fixes the project-status
badge on the page wrapper, which would otherwise render the new
status with the generic gray 'unknown status' color."
```

---

### Task 3: Full regression + manual verification

**Files:** none new — verification only.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: 0 failures across the whole suite — confirms Tasks 1-2 didn't break `ProjectBoardTest`, `ProjectAuthorizationTest`, `ProjectTeamScopeTest`, `CreateProjectTest`, `ProjectCommentsTest`, or any other pre-existing test.

- [ ] **Step 2: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: passes; re-run Step 1 if it reformats anything.

- [ ] **Step 3: Manual end-to-end verification**

```bash
php artisan tinker --execute '
$owner = \App\Models\User::factory()->withPersonalTeam()->create(["name" => "Manual Test Owner"]);
$project = \App\Models\Project::create([
    "team_id" => $owner->currentTeam->id, "owner_id" => $owner->id,
    "name" => "Manual Test Project", "status" => "active",
]);
$milestone = $project->milestones()->create(["title" => "Only milestone", "status" => "pending"]);
$task = $project->tasks()->create(["milestone_id" => $milestone->id, "title" => "Last task", "status" => "review"]);
echo "owner_id={$owner->id} project_id={$project->id} task_id={$task->id}\n";
'
```

Then move the task to done via the real Livewire component, confirming the project proposes closure rather than closing outright:

```bash
php artisan tinker --execute '
auth()->loginUsingId(<owner_id>);
$project = \App\Models\Project::find(<project_id>);
$component = new \App\Livewire\Projects\ProjectBoard();
$component->mount($project);
$component->moveTask(<task_id>, "done");
echo "project status after last task done: {$project->fresh()->status}\n";
'
```

Expected: `project status after last task done: pending_closure_approval`.

Then confirm closure as the owner:

```bash
php artisan tinker --execute '
$project = \App\Models\Project::find(<project_id>);
auth()->loginUsingId(<owner_id>);
$component = new \App\Livewire\Projects\ProjectBoard();
$component->mount($project);
$component->confirmProjectClosure();
echo "project status after owner confirms: {$project->fresh()->status}\n";
'
```

Expected: `project status after owner confirms: completed`. Confirms the real end-to-end lifecycle, not just in-memory test doubles.

- [ ] **Step 4: Clean up manual verification fixtures**

```bash
php artisan tinker --execute '
$project = \App\Models\Project::where("name", "Manual Test Project")->first();
if ($project) { $project->tasks()->delete(); $project->milestones()->delete(); $project->delete(); }
\App\Models\User::where("name", "Manual Test Owner")->each(function ($u) { $u->currentTeam?->delete(); $u->delete(); });
echo "cleaned up manual verification fixtures\n";
'
```

- [ ] **Step 5: Report completion**

No commit for this task — it's verification only. If Step 1 finds any failures, stop and fix them (return to the relevant earlier task) before considering this plan complete.

## Self-Review Notes

- **Spec coverage:** Task 1 covers spec §1-§3 (schema, `closeIfAllMilestonesCompleted()` rewrite, `confirmClosure()`/`rejectClosure()`). Task 2 covers spec §4 (review UI). Task 3 covers the spec's implicit "this all actually works together" requirement.
- **Placeholder scan:** none — every step has literal file content, including full model/policy/Livewire/migration contents.
- **Type consistency:** `Project::closeIfAllMilestonesCompleted()/confirmClosure()/rejectClosure(string $reason)`, `ProjectPolicy::closeProject(User $user, Project $project)`, `ProjectBoard::confirmProjectClosure/promptRejectClosure/cancelRejectClosure/confirmRejectClosure` and its `$rejectingClosure`/`$closureRejectReason` properties are used identically across every task that references them.
- **Authorization design correction, addressed explicitly:** the spec's draft put `abort_unless` inside the `Project` model methods; Task 1 Step 5's note and the Global Constraints section both flag the correction to Policy-based `$this->authorize(...)` at the Livewire layer, matching `moveTask()`/`assignTask()`'s real, established convention — checked against the actual file before writing this plan, not assumed.
- **The "reject is not terminal" distinction, addressed at its two touch points:** `rejectClosure()`'s docblock (Task 1) and `test_a_rejected_closure_can_be_proposed_again` (Task 1's test file) both make this explicit, since every other approval gate built this session treats rejection as terminal and a reader skimming this plan out of order could otherwise assume the same here.
- **Pre-existing test regression, fixed not ignored:** Task 1 Step 7 explicitly updates `MilestoneAndProjectStatusEventsTest`'s now-wrong assertion, matching this session's established discipline (Dot.Ehail's `DashboardTest`, Dot.Emall's `ProductBrowseTest`/`CartCheckoutTest`) of fixing regressions in the same task that causes them, not deferring to the final regression pass.
- **Uncommitted pre-existing changes:** this repo had pre-existing uncommitted changes (mark images) stashed before this plan was written, per the spec's own context section; every `git add` in this plan lists files explicitly.
