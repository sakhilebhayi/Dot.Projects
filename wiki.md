---
title: Dot.Projects — Platform Wiki
version: 0.1.0
status: active
owners: [Projects Platform Lead]
platform-id: dot-projects
last-review: 2026-08-01
---

# Dot.Projects

Purpose: this is Dot.Projects's own knowledge home — owned and maintained by the Dot.Projects team. It describes what this platform actually is and does today, and where it's headed. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-projects.md)

---

## 1. What Dot.Projects Is

Dot.Projects is the project and programme delivery platform in the InfoDot ecosystem: plan, track, and deliver multi-phase initiatives with milestones, tasks, and team collaboration, with AI-assisted plan generation to turn a project brief into a structured milestone/task breakdown instantly.

**Status:** working scaffold. This is a real Laravel 12 application with a functioning data model, a Livewire-driven project creation and kanban board flow, and a live (mock-fallback) AI planning service — but it does not yet publish Knowledge Packs, emit ecosystem events, or consume recommendations from Dot.Brain. Sections below distinguish **shipped** behavior from **planned** integration.

## 2. Architecture

| Layer | Technology |
|---|---|
| Framework | Laravel 12 (PHP 8.4) |
| Frontend | Livewire 3 + Alpine.js 3 + Tailwind CSS |
| Auth | Laravel Sanctum, with a dedicated `EcosystemAuthController` that exchanges an InfoDot-issued personal access token (scoped `ecosystem:read`) for a logged-in session — SSO handoff from the InfoDot hub, not a local login flow |
| Teams | Laravel Jetstream (teams, invitations, memberships) — a project belongs to a `Team`, not directly to a user |
| Database | PostgreSQL 16, shared across the ecosystem (`DB_DATABASE=infodot`) |
| Realtime | Laravel Reverb (configured, not yet wired to project/task broadcasting) |
| AI | Anthropic API direct HTTP call (`ANTHROPIC_MODEL=claude-sonnet-4-6`), with a deterministic mock fallback when no API key is configured |
| Queue / Cache | Redis, Laravel Horizon, database cache/queue drivers in `.env.example` |

The app is intentionally thin right now: two Livewire components (`CreateProject`, `ProjectBoard`), one controller (`ProjectController::show`), one service (`AiProjectPlannerService`). There is no event/listener/observer layer yet — model changes are not currently dispatched anywhere.

## 3. Domain Entities

| Entity | Table | Key fields | Notes |
|---|---|---|---|
| Project | `projects` | `team_id`, `owner_id`, `name`, `status`, `start_date`, `due_date` | `status`: planning / active / on_hold / completed |
| Milestone | `milestones` | `project_id`, `title`, `due_date`, `status`, `sort_order` | `status`: pending / in_progress / completed |
| ProjectTask | `project_tasks` | `project_id`, `milestone_id` (nullable), `assignee_id` (nullable), `status`, `priority`, `due_date`, `sort_order` | `status`: backlog / todo / in_progress / review / done (kanban columns); `priority`: low / medium / high / urgent |
| ProjectComment | `project_comments` | polymorphic `commentable` (Project or ProjectTask), `user_id`, `body` | Shared comment model across both entity types |
| AiPlanLog | `ai_plan_logs` | `project_id`, `user_id`, `prompt`, `response`, `tokens_used` | Full prompt/response audit trail for every AI plan generation call |

`Project::completionPercentage()` derives progress as done-tasks / total-tasks — the only computed metric in the codebase today.

## 4. AI Milestone Generation

`AiProjectPlannerService::generatePlan()` builds a fixed prompt from the project's name and description, asking for 3–5 milestones with 3–6 tasks each as strict JSON. If `ANTHROPIC_API_KEY` is unset, it falls back to a hardcoded three-milestone mock plan (Discovery & Planning / Core Development / Testing & Launch) rather than failing — a deliberate design choice so the flow is demoable without live credentials. Every real API call is logged to `ai_plan_logs` with the prompt, raw response, and token usage, giving a full audit trail per project. This is invoked from `CreateProject::generatePlan()`, which creates the project first and then materializes the returned milestones/tasks against it.

## 5. Events Emitted

**None today.** No `Event::dispatch`, observer, or listener exists in the codebase outside Jetstream/Fortify's own internals. Dot.Brain's ingested view of this platform (`platforms/dot-projects.md`) specifies a target event contract — `delivery.milestone.reached`/`slipped`, `delivery.project.closed`, `delivery.dependency.blocked` — which is accurate as **design intent**, not current behavior. Wiring these to actual milestone/status transitions (`Milestone::status`, `Project::status`) is the next concrete step toward ecosystem participation; see Roadmap.

## 6. Connecting to Dot.Brain

Dot.Projects is registered in the ecosystem as `dot-projects`, but the Knowledge Pack publishing pipeline described on the Brain side has no implementation in this repo yet — no DKP manifest, no signing key, no pack emission code. What Dot.Brain's `platforms/dot-projects.md` describes (observation/insight/outcome/incident packs, the HR structure-to-structure staffing contract, the Dot.Tasks boundary, the verified team-milestone dopamine mechanic) is the integration target this platform is building toward, derived from the shared architecture work already done on the Brain side.

**The Dot.Tasks boundary**, as understood from that target design: Projects owns work with phases and end dates — an initiative that is eventually finished or failed. Tasks owns work that recurs and is never "finished," only healthy or unhealthy. A project may spawn recurring task templates at closure once whatever it built needs ongoing operation; Tasks escalates a recurring failure into a new project when the fix exceeds routine capacity. A given unit of work lives in exactly one platform at a time. Today's `ProjectTask` model is purely internal to a project's kanban board and has no relationship to Dot.Tasks — the spawn/escalate handoff is not implemented.

| Payload type (target) | Cadence | Status here |
|---|---|---|
| `observation` (phase-duration, slip-pattern aggregates) | monthly | not implemented |
| `insight` (delivery-pattern findings) | per finding | not implemented |
| `outcome` (recommendation verifications) | per verified recommendation | not implemented |
| `incident` (delivery failures) | per incident | not implemented |

Full manifest, entity/event mapping, the HR consumption contract, the dopamine-surface deployment terms, and a worked publish→PR round-trip example are maintained on the Brain side at [`platforms/dot-projects.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-projects.md) — treat that document as the integration blueprint; this wiki is authoritative for what's actually running.

## 7. Roadmap

- [ ] Wire `Milestone`/`Project` status transitions to domain events (`delivery.milestone.reached`, `delivery.milestone.slipped`, `delivery.project.closed`)
- [ ] Publish the first `observation` Knowledge Pack once phase/milestone data has enough volume to aggregate meaningfully
- [ ] Implement the `platform.dkp.json` manifest and signing-key provisioning
- [ ] Build the Dot.Tasks spawn/escalate handoff (template spawn at project closure; escalation into a new project on recurring failure)
- [ ] Consume Dot.HR's role/skill structures for phase staffing instead of the current free-text assignment model
- [ ] Gantt timeline view and cross-project dependency tracking (README-advertised, not yet built)
- [ ] Wire Reverb for realtime board updates (currently configured but unused)
- [ ] Time logging per task linked to Dot.Billing (README-advertised, not yet built)

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.1.0 | 2026-08-01 | Projects Platform Lead | Initial wiki: documented the actual Laravel/Livewire scaffold (entities, AI planner, SSO), reconciled against Dot.Brain's ingested `platforms/dot-projects.md` target design, marked Knowledge Pack publishing and the Dot.Tasks handoff as roadmap rather than shipped |

## Open Questions

- Should milestone/task status transitions dispatch events immediately (even before a Knowledge Pack pipeline exists), so an event log is being built up for later backfill?
- Does the free-text `assignee_id` on `ProjectTask` get replaced by role-based staffing (per Dot.HR's structure-to-structure contract) before or after multi-project dependency tracking ships?
- Cross-org programme scopes (contractor orgs on a shared project): standard scope templates or per-programme negotiation? Unresolved on both the Brain side and here.
