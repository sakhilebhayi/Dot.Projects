# R-34 Dot.Projects: Phase 1 audit and plan (2026-11-01)

This answers §2 and Phase 1 of the owner brief in
[`requirements/dot-projects-transformation.md`](requirements/dot-projects-transformation.md).
Everything below was checked against the code on `main` (493d8a8), a local run of the
application with seeded data, the automated suite, Laravel Boost and the live site
(projects.infodot.co.za). Where something could not be checked, it says so.

## 1. The short version

Dot.Projects today is a **working scaffold**. A signed-in member can create a project, have a
plan of milestones and tasks generated for it, drag tasks across five board columns, assign them,
comment on the project and confirm or reject an automatic closure. Single sign-on from InfoDot,
team roles (including read-only viewers), security headers, health probes, backups and a manual
deploy pipeline are real and tested.

Almost everything the brief describes beyond that does not exist yet. There is no way to create,
edit or delete a task or milestone by hand, edit or archive a project, see a list, timeline,
Gantt or calendar view, record a risk, cost, hour, file or decision, or report on anything. The
interface carries the AI-dashboard look the brief calls out, and two of its most visible buttons
are dead links.

The good news is that the foundation (tenancy, sign-in, roles, deploy, tests) is sound enough to
build on without a rewrite. The plan in section 9 keeps every working flow and its tests, and
grows the product in PR-sized phases.

## 2. Baseline

| Item | Finding |
|---|---|
| Stack | Laravel 13.37, PHP 8.5 locally, Livewire 3, Jetstream teams, Sanctum, Fortify, Scout, Reverb (configured, not used) |
| Size | 9 models, 6 controllers, 4 Livewire components, 6 policies, 12 migrations, 3 notifications, 2 events, 3 commands |
| Tests | **203 tests: 196 pass, 7 skipped** (the skips are Jetstream features switched off by design: registration, email verification, invitations, two-factor) |
| Laravel Boost | Installed (`laravel/boost` ^2.5, `boost.json` with MCP on). Its MCP server answers over stdio; `application-info`, `database-schema` and `last-error` were used in this audit. Tools available: application-info, browser-logs, database-connections, database-query, database-schema, get-absolute-url, last-error, read-log-entries, record-rule, search-docs |
| Live site | `/` 200, `/login` 200, `/dashboard` 302 to login, `/up` 200, `/up/scheduler` 200, `POST /up` 405 with a 1 KB page (no debug page), `.env` and `laravel.log` 403, HSTS on, CSP **report-only** |
| Deploy | Manual: `gh workflow run deploy.yml -f confirm=deploy` (with an `adopt` mode). CI runs the suite on pull requests |
| Production data | PostgreSQL with a nightly `pg_dump` (`projects:backup-database`). The wiki says the database is `infodot`, shared across the ecosystem; this was **not verified** on the host and should be (section 6) |
| Dependencies | `composer audit`: one medium advisory, symfony/mime CVE-2026-45070 (the same one that blocks Dot.Press). `composer.lock` is out of date with `composer.json`. `npm audit`: 0 |

## 3. Feature inventory

**Works (persisted, authorised, tested):**

- Project creation (name, description, status, dates), gated on the team `create` permission.
- AI plan generation: milestones and tasks from the name and description via the Anthropic API,
  logged in `ai_plan_logs`.
- Kanban board with five fixed columns: drag a task between columns, assign it to a team member
  (notified), milestone status derived from its tasks, `MilestoneReached` fired on completion.
- Closure approval: when every milestone is complete the project proposes closure; only the
  owner confirms or rejects with a reason (`ProjectClosed` event).
- Project comments with a notification to the owner; a notification bell and page.
- Daily "milestone due soon" reminders.
- Dashboard list with name search and status filter, task counts and progress.
- Team-scoped access: `HasTeamScope` on `Project`, policies on every model, viewer role is
  read-only (tested).
- InfoDot single sign-on (POST handoff), team sync from InfoDot, closed registration.
- Operations: security headers, CSP report endpoint, `/up` with a database check, scheduler
  heartbeat and watchdog, Guardian health report for Dot.Brain, nightly Postgres backup.

**Partial:**

- AI planning **silently falls back to a canned plan** when no API key is set. The same three
  generic milestones ("Discovery & Planning", "Core Development", "Testing & Launch") are saved
  as if they were the AI's proposal, with nothing telling the user. The brief requires generated
  content to be marked as proposed; this is the opposite.
- The plan is saved straight into the project. There is no review step ("proposed, not
  confirmed").
- `project_members` exists with owner, manager and member roles, but nothing reads or writes it;
  every team member can see and edit every project.
- Events `MilestoneReached` and `ProjectClosed` are dispatched with no listener.
- Reverb and Scout are installed and configured, not used.

**Placeholders (screen exists, nothing behind it):**

- **"Add Milestone" and "Add Task" on the project page are `href="#"`.**
- The welcome page shows a stock photograph of sticky notes, not the product.

**Broken:**

- **Icons on the project and create pages render as words** ("arrow_back", "flag",
  "calendar_today", "save", "auto_awesome"). They use the `material-symbols-outlined` class, but
  only the Rounded font is loaded. Production runs the same commit (493d8a8, deployed
  2026-10-31); the signed-in pages were not viewed on the live site.
- `ProjectBoard::moveTask()` does not check the status it is sent against the five columns. A
  tampered request with any other value hits the database constraint (confirmed: `CHECK
  constraint failed: status`) and fails with a server error instead of a validation message.

**Missing** (each is a brief requirement with no code): task and milestone create, edit and
delete; subtasks, checklists, dependencies, labels, custom fields, effort and duration,
recurrence; project edit, archive and duplicate; templates and methodologies; list, table,
timeline, Gantt, calendar, workload, roadmap, portfolio and executive views; saved views; risks,
issues, changes, decisions, approvals, stage gates and meetings; budgets, costs and forecasts; time
tracking and timesheets; documents; automation; reporting and exports; activity history and audit
trail; programmes and portfolios; command palette and keyboard shortcuts; project-level permissions,
external contractors and clients; the API (only `GET /api/user` exists).

## 4. Architecture

- **Sound:** teams as the tenant boundary, policies per model, a model-level team scope, events
  on real state changes, a service for the AI call, health and backup plumbing.
- **Presentation and logic are mixed in views.** `projects/show.blade.php` and the Livewire views
  carry long inline `style=` attributes, hard-coded colours and font names. The dashboard query
  lives in a route closure.
- **The app layout loads Tailwind from `cdn.tailwindcss.com` at runtime** while the guest pages
  use the Vite build. It is slow (the browser compiles CSS on every page), and it is why the CSP
  allows `'unsafe-eval'` and the CDN host. It is also why the CSP cannot be enforced.
- **Two unrelated type systems:** the app uses Syne, Inter and JetBrains Mono; the sign-in and
  welcome pages use Archivo and Source Sans 3.
- **Task status is a database enum** (`backlog`, `todo`, `in_progress`, `review`, `done`).
  Methodologies with their own stages (Scrum, stage-gate) need statuses as data. Project status
  was already widened to a string in 2026-08, and the same safe pattern applies to tasks.
- **No activity or audit log.** Nothing records who changed what. The brief's traceability needs
  this before governance features exist.
- **Only `Project` carries `team_id`.** Tasks, milestones and comments are scoped through their
  project. That works today because every access goes through a policy, but bulk and reporting
  queries will need either `team_id` on the hot tables or disciplined joins. The plan adds
  `team_id` to new tables from the start.

## 5. Performance

- Runtime Tailwind compilation on every app page is the largest cost; moving to the Vite build
  removes it.
- The dashboard loads every project of the team without pagination; fine at today's sizes,
  needs paging for portfolios.
- `Project::completionPercentage()` runs two queries per call; the dashboard uses `withCount`
  instead, but the method remains for the show page.
- No load testing has been done, and no scale claim is made.

## 6. Security

| Finding | Severity | Note |
|---|---|---|
| `HasTeamScope` fails **open**: with no signed-in user or no current team it adds no filter | Medium | Queue jobs, commands and any future API path would see every team's projects. Dot.HR's version fails closed and also checks team membership; Dot.Projects should match it |
| CSP is report-only and allows `'unsafe-eval'` and `cdn.tailwindcss.com` | Medium | Enforcing it depends on removing the CDN (section 4) |
| `moveTask` accepts an arbitrary status | Low | Server error, not a data leak, but input must be validated |
| AI prompt includes the raw project description; no rate limit on plan generation | Low | Cost and abuse control; logs keep the prompt and response |
| symfony/mime CVE-2026-45070 | Medium (library) | Update with the lock refresh |
| Shared `infodot` database (per the wiki) | Unverified | If production shares a database with InfoDot, table names like `users` and `teams` could collide. Needs a read-only check on the host |
| No audit trail | Gap | Required by the brief for governance and finance |

Checked and fine: every Livewire action re-authorises; assignment checks team membership
server-side; viewers cannot write; registration is closed; the legacy GET sign-in URL ignores its
token; secrets files are not served.

## 7. Design

- **Impeccable detector on `resources/views`: 14 findings.** These are: Inter used as body
  type (6); a thick side border on the responsive nav link (1); `transition: width` on the
  dashboard progress bar (1); and the Laravel mail theme's side borders (6).
- By eye (local run, screenshots kept out of the repo), the app has the pattern the brief names:
  - four equal metric tiles leading the dashboard;
  - a purple gradient primary button;
  - Syne as the display face;
  - a dark navy shell with rounded cards floating on it;
  - repeated cards for every project;
  - icon names showing as text;
  - a stock photograph on the welcome page.
- **Ecosystem conventions in practice.** Each Dot platform has its own named design system with
  a concept drawn from its subject: Dot.HR "The Register", Dot.Forms "The Carbon Book", Dot.Memory
  and Dot.Central "control room", Dot.Files a ledger. They share:
  - the Dot mark and a `dot.<name>` wordmark;
  - tokens declared once with a light and dark mapping;
  - sentence-case labels, no eyebrows, no middle-dot meta strings;
  - Material Symbols Rounded for icons;
  - a header with a workspace switcher, and a "More" menu for secondary areas;
  - plain-language flash messages and empty states;
  - CSP-safe behaviour through data attributes.

  Dot.Projects should keep those relationships and get its own concept.
- **Installed design skills reviewed** (in `~/.claude/skills`):
  - for the design phase: hallmark (new surfaces), impeccable (direction, craft floor, detector, finish);
  - design-taste-frontend (`draw.mjs --app` deals researched app directions; its inspector checks the build);
  - design-md (DESIGN.md format), awesome-design-md and open-design (reference systems);
  - emil-design-eng (component polish);
  - gsap-core, gsap-timeline and gsap-scrolltrigger (timeline and view-transition motion), react-bits and canvas-ui (only if a canvas effect is warranted), terminal-dark (not a fit for this product);
  - dot-brand-experience (Dot.Brain brand engine, advisory scoring);
  - dataviz (any chart: workload, burn-down, earned value);
  - the copywriting, UX-copy and humanizer skills for §19.

  How each is applied is recorded in the PR for each phase.

## 8. What other tools do, and the opening

This comes from working knowledge of the products, not new user research. It is meant to
orient the design, not to be quoted as data.

| Tool | Does well | Where people struggle |
|---|---|---|
| Linear | Speed, keyboard flow, opinionated states, calm interface | Built for software teams; little for budgets, resources or non-software methods |
| Asana, Monday, ClickUp | Many views over one dataset, easy start, automation recipes | Feature sprawl, settings overload, views that drift into decoration, weak schedule logic |
| Jira | Configurable workflows, scale | Configuration burden, slow, hostile to non-technical users |
| Notion | Flexible documents and databases | No real scheduling, dependencies or resource logic |
| Smartsheet, Wrike | Spreadsheet familiarity, governance | Dense and dated, steep for small teams |
| MS Project, Primavera P6 | Critical path, baselines, resource levelling, earned value | Expert tools, desktop-era interfaces, poor collaboration |

**The opening for Dot.Projects:** serious schedule and control logic (dependencies, critical
path, baselines, risk, change, cost) presented with Linear-grade calm and plain language. It
should be connected to the business's own people (Dot.HR), money (Dot.Finance) and documents
(Dot.Files), and honest about what is known and what is a forecast.

## 9. Plan

Each step is one or more PRs; each leaves the app working with its suite green. Order follows
dependency and value, not the brief's phase numbers where they differ.

| Step | Scope | Why here |
|---|---|---|
| **P1 Foundations and fixes** | Fail-closed team scope (with membership check); validate task status; label the canned AI plan honestly, and only offer it when no key is set, marked "example plan"; fix icons; move the app layout to the Vite build and drop the Tailwind CDN; refresh `composer.lock` (symfony/mime); add an activity log (who changed what, never text values) | Removes the defects and the security debt before anything is built on them |
| **P2 Design system** | Deal three app directions with `draw.mjs --app`, build three boards of the same project workspace, **owner chooses**. Then DESIGN.md, tokens, layout, navigation, dashboard, workspace shell and sign-in pages in the chosen system; Impeccable clean; both themes | The owner compares systems, not recolours; every later screen is built once, in the chosen system |
| **P3 Execution core** | Task and milestone create, edit, delete and reorder; subtasks and checklists; start dates, estimates, effort; labels; dependencies (finish-to-start first) with cycle prevention; project edit, archive, restore, duplicate; workspace tabs (overview, work, timeline, people, activity); list and table view next to the board; saved filters; statuses as data per project | The brief's success criteria 3, 4 and 6; everything else hangs off tasks |
| **P4 Schedule** | Timeline and Gantt from the same data with drag to reschedule; critical path; baselines and variance; calendar view; milestone view | Needs P3's dates and dependencies |
| **P5 Governance** | Risk register with configurable matrix, issues, change requests with impact and approval, decision register, stage gates, meeting records and actions | Needs the activity log (P1) |
| **P6 People and time** | Project roles (manager, member, contributor, viewer, external), private projects; workload view and over-allocation; time entries, timers, timesheets with approval; leave-aware capacity once a Dot.HR API exists | Needs P3 assignments |
| **P7 Money** | Budget lines and categories, commitments, actuals, forecast at completion, cost-to-complete, earned value (PV, EV, AC, SV, CV, SPI, CPI, EAC, ETC) with documented formulas; financial permission | Needs P4 baselines and P6 time |
| **P8 Portfolio and reporting** | Programmes and portfolios, cross-project dependencies, executive view with drill-down; reports with CSV, Excel and PDF export; custom dashboards | Needs P4 to P7 data |
| **P9 Automation** | Rules (trigger, conditions, actions, delays, approvals) with a dry-run preview, execution history, idempotent runs and safe retries | Needs the events P3 to P7 produce |
| **P10 Intelligence** | Plan assistant producing a *proposed* plan to review before saving; project Q&A answered only from authorised records with links; summaries; forecasts with stated assumptions and data coverage. AI never changes data without a person confirming | Needs real data from P3 to P8 |
| **P11 Ecosystem** | Each integration only where a working API exists, tested end to end. **Today:** InfoDot sign-in (live); Dot.Files has a platform files API (store, fetch, list, meta); Dot.Notify forwarding is in use by Dot.HR. Dot.Analytics and Dot.docs have API routes, live status unverified. Dot.Tasks, Dot.Finance and Dot.HR expose no API Dot.Projects can call yet | Honest integration, never claimed before tested |
| **P12 Enterprise readiness** | Enforced CSP, rate limits, retention, restore drill, load test with published numbers, monitoring | Runs alongside; formal sign-off at the end |

Industry templates (§15) and methodologies (§5.2) ride on P3 to P5 as data: a template is a
saved set of phases, statuses, task lists, risk categories and gates, not a separate system.

## 10. Decisions the owner holds

1. **The design direction** (P2): choose one of three boards.
2. **AI provider and cost limits:** whether production has an Anthropic key today, and a
   monthly cap per business.
3. **Database:** confirm whether production shares the `infodot` database (a read-only check on
   the host settles it).
4. **Roles:** whether project-level roles (manager, member, external, client) replace "every team
   member sees every project" for existing businesses, or apply only to new private projects.

## Progress

- **P1 foundations** (2026-11-01, #38, live): fail-closed team scope, board status validation,
  honest example plan, activity log, built stylesheet instead of the Tailwind CDN, icons, symfony/mime.
- **P2 design system** (2026-11-01): the owner chose **A. The Programme** from three boards. Recorded
  in `DESIGN.md` (linted clean) and `PRODUCT.md`; built in `resources/css/programme.css`. New shell
  (icon rail with the original logo symbol, top bar with business switch, notifications, account),
  projects ledger, project overview (finding sentence, "When we land", in the way, milestones, work,
  people, discussion, activity), board view with working quick add for tasks and milestones (the
  old "Add Task" and "Add Milestone" links were dead), restyled create, notifications, sign-in and
  Jetstream form components; light and dark; phone layouts. Not yet redesigned: the public welcome
  page (a Persuade surface, next) and the inside of Jetstream's profile and team pages beyond their
  shared components.
- **P3a execution core** (2026-11-01): a task page (edit everything, start and due dates, estimate,
  checklist, its own comments, delete), a Work view (all tasks by milestone with search, status,
  person and late-only filters kept in the URL, status and assignee changed in place, milestones
  renamed, dated, reordered and deleted with their tasks kept), project settings (details, archive
  as read-only and restore, duplicate with milestones, tasks and checklists reset to not started).
  Next, P3b: dependencies with cycle prevention and a blocked state, subtasks, labels, statuses as
  data per project, saved views.
- **P3b** (2026-11-01): finish-to-start dependencies inside a project, with loop prevention; a
  blocked task (waiting on unfinished work) cannot move into In progress, In review or Done until
  what it waits for is done, and is marked Blocked on the board, work table and overview; one level
  of subtasks; text-only labels per project with a label filter; saved views of the work table per
  person; duplicate carries subtasks, labels and dependencies. Statuses as data per project are
  deferred to the methodology and template step, where Scrum and stage-gate need them.
- **P4 schedule** (2026-11-01): a Schedule tab with a timeline of every dated task, grouped by
  milestone, in weeks or months. Rules, in calendar days: a task runs from its start date (or its
  due date) to its due date; the target is the project's due date (or the last task's); a backward
  pass over finish-to-start dependencies gives each open task its float; float of zero or less is
  the critical path, the only thing drawn in the one blue; a task that starts before something it
  waits for finishes is a conflict, shown and named, never refused. Bars move by drag or arrow keys
  and stretch by dragging their end or Shift with arrows; every move is checked and saved on the
  server (due never before start, done work keeps its dates, viewers and archived projects
  refused) and recorded in the activity log. Numbered baselines keep the project, milestone and
  task dates as they were, with a note; the timeline draws the chosen baseline under each bar and
  milestone, and the overview shows the forecast finish and how far the due date moved since the
  latest baseline. Nothing is estimated: a task without a due date is listed as not on the
  schedule. Calendar view, resource levelling and working-day calendars are later steps.
- **P5a risks and issues** (2026-11-01): a Governance tab with a risk register and an issue log.
  Risks are numbered per project (R-01), owned, categorised and rated by probability and impact on
  the project's own matrix (3, 4 or 5 steps, named steps and band thresholds set in Settings; a
  smaller matrix is refused while a risk sits above it). Each risk keeps a rating before and after
  mitigation, mitigation and contingency plans, a review date (overdue reviews flagged) and a
  status; the register shows the matrix as a heat grid of live risks (click a cell to filter), what
  needs attention (escalations, overdue reviews, serious risks with no plan, no owner) and the
  register by rating. Issues (I-01) carry severity, priority, owner, root cause, corrective action,
  target date and a resolution, which is required before an issue is resolved or closed. Either
  can be escalated to the project owner with a reason (in-app notice); a risk that happens is
  recorded with one action that opens an issue linked to it. History is the activity log, holding
  ratings, statuses, owners and dates, and the names of edited text fields but never their text.
  Owners are notified when given a risk or issue. The overview gains a "Risks and issues" card.
  Next, P5b: change requests with impact and approval, decisions, stage gates, meetings and
  actions, status reports.
- **P5b change requests and decisions** (2026-11-01): change requests (CR-01) under Governance,
  each with what changes and why, its kind, and an impact assessment (schedule in days, cost in rand,
  scope and quality, people, risks). A draft is edited freely, then sent to a named approver (anyone
  in the business who can change projects); once sent it is fixed, and can only be approved,
  rejected (a reason is required) or withdrawn. Only the named approver decides; approving your own
  request is allowed and recorded as self-approved. The decision is written to the decision
  register, and the requester and approver are notified. An approved change can re-baseline the
  plan in one action (a numbered baseline noted "After CR-01") and be marked implemented. The change
  log states what approved changes add up to in days and rand. The decision register (D-01) asks
  for decisions (who decides and by when, overdue flagged, owner notified) or records ones already
  made. A decision on record is never edited; it is superseded by a later one. The overview's
  Governance card adds changes waiting for approval and decisions due. Next, P5c: stage gates with
  governance checklists, meetings and steering records with an action register, status reports.
- **P5c gates, meetings and actions** (2026-11-01): stage gates (G1, G2, …) under Governance, each
  with a purpose, a due date, an optional milestone, a named approver and a governance checklist
  (start from a standard list: ready to start, to execute, to hand over, to close; then edit
  freely). The team ticks the checks; only the approver decides: pass (every check met), pass with
  conditions (conditions written) or hold (reason written; a held gate is put forward again once
  dealt with). Every gate decision is written to the decision register, and a decided gate's
  record no longer changes. Meetings (M-01), steering committee meetings among them, keep when and
  where, who attended (members) and guests, the agenda and the minutes. Decisions taken there go
  into the decision register linked to the meeting; actions agreed go into the action register
  (A-01). The register lists every action, open ones by due date, with late ones and unowned ones
  called out, owner and date changed in place, and the meeting it came from. Owners and gate
  approvers are notified. The overview's Governance card adds held or due gates and late actions.
  Next, P5d: status reports.
