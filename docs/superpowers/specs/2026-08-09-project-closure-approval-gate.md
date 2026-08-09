# Dot.Projects: Project Closure Approval Gate

## Context

Dot.Projects' autonomy classification audit (`Dot.Brain/platforms/dot-projects.md`, 2026-08-08) found only one automated process (a daily milestone-due reminder, read-only) and concluded no Level 2 candidate exists — its gap summary states "the platform's only automated process ... is read-and-notify with no write side effects, so it never needed an approval gate."

That conclusion is wrong. Direct inspection of `app/Models/Project.php` found `Project::closeIfAllMilestonesCompleted()`, called automatically from `App\Livewire\Projects\ProjectBoard::moveTask()` (`app/Livewire/Projects/ProjectBoard.php`) every time a task move completes a project's last milestone. Today it immediately sets `status: 'completed'` and dispatches `ProjectClosed` — a real, unreviewed write side effect. `ProjectPolicy::update()` (the authorization gate on `moveTask()`) allows *any* member of the project's team, not just the project's owner, so any team member finishing the last task on the board can trigger full project closure as an incidental side effect of routine kanban work, with no confirmation step.

The platform's own cross-platform diagram shows `ProjectClosed` triggering Dot.Billing's project billing — but that is cross-repo/aspirational. **In this repo, `ProjectClosed` currently has zero listeners** (confirmed via `grep -rln "ProjectClosed" app/`, which only matches the event class and its one dispatch site). This spec does not claim to be gating a live billing trigger; the real, current stakes are the authoritative `status: 'completed'` flip itself — visible everywhere the codebase checks project status — plus whatever a future listener eventually does with the event.

## Goal

Completing a project's last milestone no longer closes the project outright. It proposes closure (`status: 'pending_closure_approval'`) and stops. Only the project's owner (`projects.owner_id` — not "any team member," `update`'s current, much broader boundary) can confirm the closure (the real effect — `status: 'completed'`, `ProjectClosed` dispatched — happens here) or reject it (a reason is required; the project returns to `active` so work can continue, since a rejected closure isn't a terminal outcome — the underlying milestones/tasks are still real and can be reopened). The review happens inline on the existing project board page, where the owner already has full access — no new global screen, no new role/flag.

## Changes

### 1. `projects` table: status widened, review columns added

New migration: widens `projects.status` from a native `enum(['planning', 'active', 'on_hold', 'completed'])` to a plain `string` column (same portable-append technique already used this session for Dot.Ehail's `driver_profiles.status` — conditionally drop the Postgres CHECK constraint on the `pgsql` driver only, then `Schema::table()->change()` to `string`; SQLite, used by this app's test suite, has no such named constraint and rebuilds the table wholesale on `change()`, so the conditional is a no-op there). No backfill needed — `pending_closure_approval` is a brand-new value no existing row ever had. Adds `closure_reviewed_by` (nullable FK `users.id`, `nullOnDelete`), `closure_reviewed_at` (nullable timestamp), `closure_rejected_reason` (nullable text) directly on `projects` — no separate request table, since a project has at most one *current* closure cycle at a time (unlike this session's other gates, which track unbounded collections of requests); these three columns are overwritten, not accumulated, each time the gate fires again after a rejection.

### 2. `Project::closeIfAllMilestonesCompleted()` proposes instead of closing

Same trigger conditions as today (all milestones non-empty and `completed`), plus a new no-op case: also skip if `status` is already `pending_closure_approval` (mirrors the existing `completed`/`on_hold` no-op guard, and the "don't duplicate an in-flight request" rule used throughout this session's other gates). On a real trigger: sets `status: 'pending_closure_approval'` only. **Does not dispatch `ProjectClosed`** — that moves to §3's `confirmClosure()`, the only place in the codebase `ProjectClosed` is fired going forward.

### 3. `Project::confirmClosure()` / `Project::rejectClosure()`

Two new methods on `Project`:

- `confirmClosure(User $user): bool` — `abort_unless($user->id === $this->owner_id, 403)`. No-ops (returns `false`) unless `status === 'pending_closure_approval'`. Sets `status: 'completed'`, `closure_reviewed_by`, `closure_reviewed_at`, then dispatches `ProjectClosed` — the real effect, moved here from §2.
- `rejectClosure(User $user, string $reason): bool` — same ownership check and pending-only guard. `$reason` must be non-empty (a validation failure at the call site, not silently accepted). Sets `status: 'active'` (work resumes — this is not a terminal state, unlike every other "reject" in this session's prior gates), `closure_rejected_reason`, `closure_reviewed_by`, `closure_reviewed_at`.

### 4. `ProjectBoard` review UI

`app/Livewire/Projects/ProjectBoard.php` gains `confirmProjectClosure()`, `promptRejectClosure()`, `cancelRejectClosure()`, `confirmRejectClosure()` — the confirm-then-act pattern already proven this session in Dot.Mines/Dot.docs (`$rejectingClosure` bool + `$closureRejectReason` string component state), each calling straight through to §3's model methods and letting their `abort_unless`/no-op guards do the real enforcement (the Livewire methods are a thin UI layer, not a second authorization boundary).

The board's Blade view gains a banner shown only when `$project->status === 'pending_closure_approval'`: the owner (`auth()->id() === $project->owner_id`) sees Confirm/Reject controls (reject prompts for a reason inline); every other team member sees a plain "Awaiting the project owner's confirmation to close this project" message, no controls.

## Testing

New/extended test coverage, matching this app's existing test conventions (checked against the real `tests/` directory for house style before writing the plan):

- Completing a project's last milestone via `ProjectBoard::moveTask()` lands `status: 'pending_closure_approval'`, not `'completed'`, and `ProjectClosed` is **not** dispatched yet (`Event::fake()`).
- The project owner can confirm closure: `status` flips to `'completed'`, `closure_reviewed_by`/`closure_reviewed_at` set, `ProjectClosed` **is** now dispatched.
- The project owner can reject with a reason: `status` reverts to `'active'`, `closure_rejected_reason` set, `ProjectClosed` never dispatched.
- Rejecting without a reason is blocked.
- A non-owner team member — despite having `update` access to the board itself — is refused both `confirmClosure()` and `rejectClosure()` (403).
- After a rejection, reopening and re-completing all milestones proposes closure again (`status` returns to `pending_closure_approval`, overwriting the prior `closure_rejected_reason`).

## Explicitly out of scope

- A history/audit trail of every closure proposal and decision across a project's lifetime — only the current cycle's reviewer/reason are kept, overwritten on each re-fire.
- Notifying the owner that a closure is awaiting their confirmation — they see it inline the next time they load the board; no email/in-app-notification is added (this platform already has a real notification pipeline, `MilestoneDueSoonNotification`, but wiring a new one for this is out of scope here).
- Any change to `ProjectPolicy::update()`'s existing team-wide authorization — team members keep full board-editing access; only the two new closure actions are owner-restricted.
- Wiring a `ProjectClosed` listener (e.g. real Dot.Billing integration) — this repo has none today, and adding one is a separate, cross-repo concern this spec does not take on.
- Registering this change in Dot.Brain's `platforms/dot-projects.md` or `platforms/autonomy-signals.json` — a separate, future re-audit pass, not part of building the feature. (The audit's own "no Level 2 exists" conclusion also needs correcting in that future pass, since this spec closes the gap it missed.)
