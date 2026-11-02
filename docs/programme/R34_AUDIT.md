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
- **P5d status reports** (2026-11-01): status reports (SR-01) under Governance. One draft at a time
  covers the days since the last report (or the last week). Beside the manager's words (summary,
  done in this period, next, help needed) the draft shows the project's facts live: the schedule
  forecast against the due date and the latest baseline, work done in the period, overdue and
  blocked work, the next milestone, risks and issues (with the most serious listed), changes and
  decisions waiting and made in the period, the next gate and gates decided, and actions open, late
  and done. A suggested rating (on track, at risk, off track) is shown with the facts behind it; the
  rating the report carries is the manager's. Publishing needs a rating and a summary, freezes the
  facts into the report, and tells the team in the app. A published report is a record that reads
  as a document and prints cleanly; the overview shows the latest rating. **P5 governance is
  complete** (risks, issues, matrix, changes, decisions, gates, meetings, actions, reports).
- **P6a project roles and private projects** (2026-11-01): the owner's decision on roles, built.
  Existing projects stay open to the whole business, exactly as before. A project can be made
  private (at creation or in Settings, by its owner or the business owner); then only its owner,
  the people added to it and the business owner can see it, in every list and by every link, and
  so can see its tasks, risks, issues, changes, decisions, gates, meetings, actions and reports.
  People are added from the business with a role: manager (changes its work and manages its
  people), member (changes its work) or viewer (reads). Someone who can only read in the business
  can only be a project viewer. Work, approvals and notices only go to people who can see the
  project, and making a project private is refused while someone with open work on it would lose
  sight of it. The rules live in one place (Project::canBeSeenBy, canBeChangedBy, canBeManagedBy,
  people()) used by every policy, plus a fail-closed query scope. The "external" and "client"
  roles need sign-in for people outside the business and come with the client portal (P11).
  Next, P6b: time entries, timers and timesheets.
- **P6b time** (2026-11-02): time people log themselves, never tracked. A "Time" page in the rail
  shows the person's week in their business: seven days with totals, entries (project, optional
  task, day, hours typed as 1:30, 1.5, 90m or 1h 30m, a note, billable or not) to add, change or
  remove, and a timer: one per person, started on a project or task and stopped by hand, rounded
  up to the minute. Rules: only on projects the person can change (so not private ones they are
  not on), not for days ahead, at most 24 hours a day. The week goes as a timesheet to an approver
  (someone who runs one of its projects, or the business owner; never the person themselves) and
  is approved or returned with a reason; a submitted or approved week is fixed. Approvers review
  the week's entries on the same page. The task page shows time logged against its estimate (over
  the estimate flagged), the person's own share, a quick log for today and the timer; the split
  by person shows only to those who run the project and the business owner. Next, P6c: project
  time reports and workload, capacity and utilisation.
- **P6c project time reports** (2026-11-02): a Time tab on each project for a chosen period (this
  week, last week, this month, last month, since the start, or dates), optionally approved weeks
  only. Everyone who can see the project sees the totals, the billable share, time by task and by
  week, and each task's time against its estimate to date (over the estimate flagged). Time by
  person and a CSV of the entries (date, person, task, note, hours, billable, timesheet status;
  spreadsheet formulas neutralised) are for those who run the project and the business owner, the
  same line as P6b. Running timers count once stopped. Next, P6d: workload, capacity and
  utilisation across projects.
- **P6d workload and capacity** (2026-11-02): a Workload page in the rail. For the next six weeks,
  each person's planned load against their capacity: open tasks given to them with an estimate
  contribute what is left of it (estimate less time logged), spread evenly over the working days
  from today (or the task's start) to its due date; work past its due date counts in this week and
  is flagged. Tasks with no estimate and estimated work with no date are counted as unknowns, never
  placed by guesswork, so the page says when its picture is partial. Capacity is project hours a
  week per person (40 unless set; set with a reason by the business owner or an admin). Only
  over-capacity weeks take colour, and each says so in words; choosing a week lists the tasks
  behind it. The business owner and admins see everyone; others see their own row. Private work
  counts toward load but its names show only to people who can see the project. Nothing is moved
  automatically. Deferred: leave-aware capacity and skills shortages, which need Dot.HR to share
  leave and skills. **P6 is complete** (roles and private projects, time, reports, workload).
- **P7a cost ledger** (2026-11-02): a Money tab, seen only by the project's owner and managers and
  the business owner and admins (everyone else gets no tab, no overview line and a 403; amounts
  never enter the shared activity log). Budget lines by category (labour, contractors, materials,
  equipment, operational, contingency, other); every change to a line's amount is a revision with a
  reason, optionally linked to the approved change request it comes from, and kept as the line's
  history. Costs are commitments (orders, contracts) and actuals (invoices, expenses; a credit note
  is negative), each with supplier, reference and date, never ahead of today; an actual can settle
  a commitment. The cost report shows per line and in total: budget, actual, open commitments, cost
  to complete (what is left unless set), forecast at completion, variance and percentage used, by
  the formulas printed on the page. Budget risk indicators: forecast over budget, lines over,
  spending ahead of progress, contingency drawn, approved changes the budget does not show. Costs
  are entered here until Dot.Finance can share actuals. Next, P7b: earned value and labour cost.
- **P7b earned value and labour cost** (2026-11-02): hourly rates per person, dated (time is costed
  at the rate in force on the day worked), seen and set only by the business owner and admins on
  the Workload page. Labour cost is approved time at those rates; hours from someone without a rate
  are shown as not costed. A project chooses the budget line its labour is charged to, and that
  line's actual includes it. The Money tab adds earned value as of any date, by a stated method:
  work measured over leaf tasks weighted by estimate (unestimated tasks weigh the average); PV from
  the latest baseline's dates (current dates if none), planned evenly from start to due; EV by the
  0/50/100 rule; AC from the ledger; then SV, SPI, CV, CPI, EAC (BAC divided by CPI), ETC, VAC and
  TCPI, each with how it is worked out, a plain-language reading, and the share of the work that
  had dates to plan against. Derived amounts are whole rand; an index with nothing to divide by
  shows as a dash. **P7 money is complete.**
- **P8a portfolios and the executive view** (2026-11-02): a Portfolio page in the rail. Portfolios
  group a business's projects under an owner and a strategic objective, as a portfolio, programme,
  department, business unit or strategic initiative; a project can sit in several. The business
  owner or an admin creates them; they or the portfolio's owner manage them, and choosing projects
  only ever touches projects that person can see. The executive view (all active projects, or one
  portfolio) shows a proportional health bar and, per project: health by the status-report rule
  (with the last published report's rating when it differs), share of tasks done, forecast finish
  against due, budget forecast and CPI (only where the viewer may see money), serious risks and
  the next milestone; and across them, what needs intervention (off track, escalations, critical
  issues), milestones overdue or due within 30 days, portfolio budget and forecast, people over
  capacity (owner and admins), and active projects in no portfolio. No task-level detail. Next,
  P8b: cross-project dependencies and portfolio risks and milestones.
- **P8b work between projects** (2026-11-02): a task may now wait for a task in another project of
  the same business. On the task page, "Waits for" picks a project (this one by default) and then
  a task in it. The predecessor's project must be one the person can see and not archived; another
  business's work is never reachable. Loops are refused across every project in the business,
  including projects the person cannot see. The other project's owner and managers get an in-app
  notice, and it names the waiting project only to those who may see it; both activity logs record
  the link without free text. Work in a project someone cannot see is never named to them: the task
  page, the board, the work list, the overview, the "cannot start yet" message and schedule
  conflicts all say "a task in a project you cannot see" and give no dates. The schedule flags a
  start before external work is due as a conflict. A task that another project waits on must finish
  the day before that work starts, and its float counts this. The schedule summary says how many
  tasks wait on, or hold up, other projects. The portfolio adds three sections:
  - a six-month roadmap of every project's milestones on one axis, with milestones about four days
    apart sharing a mark that names each one;
  - the major risks across the set (live, rated high or critical on each project's own matrix);
  - which projects wait on which, with linked, still-held-up and conflicting counts. Links to
    projects outside the view are counted, never named.

  The duplicate of a project keeps only its internal dependencies. Next, P8c: reports with exports,
  and custom dashboards.
- **P8c reports and exports** (2026-11-02): a Reports page in the rail with ten reports, each with
  stated columns:
  - projects compared (the executive view);
  - progress and health;
  - schedule performance (finish against due and the latest baseline, critical tasks, broken
    dependencies, SPI);
  - budget and earned value (budget, actual, committed, forecast, variance, PV, EV, AC, SV, CV,
    SPI, CPI, EAC, ETC, VAC, TCPI, by the formulas on the Money tab);
  - risks;
  - issues;
  - task completion over a period (done, added, overdue, blocked, on time);
  - milestone performance (baseline, due, lands or finished, slip);
  - time over a period (by person on projects you run, totals elsewhere, approved hours apart);
  - utilisation (capacity, planned load, logged hours; everyone only for the business owner and
    admins).

  Every figure comes from the service the project pages use, so a report and its page agree.
  Filters: open, all or archived projects, a portfolio, chosen projects, and the period for
  period reports. You can choose and order the columns and sort by any of them. Each report is
  worked out for the person reading it: other projects, money, time by person and other people's
  utilisation are left out where not allowed, and the notes say what was left out. Reports can be
  saved under a name and shared with the business. Sharing shares the settings, never the rows. A
  shared report is changed only by its maker or the business owner; anyone else's changes are
  saved as their own copy.

  Downloads are CSV, Excel and PDF, each carrying the same rows, totals and notes plus who it was
  made for and when:
  - CSV has a UTF-8 BOM, and cells that would run as formulas are written as text;
  - Excel is a real .xlsx written without a library or the zip extension. Text is never a
    formula, money is in rand, hours are decimal, dates are real dates, and the header is frozen;
  - PDF is landscape A4 in the standard Helvetica fonts, written without a library. Only text
    columns are ever shortened, never numbers; a table too wide for the page continues in parts
    with the first column repeated, and pages are numbered "n of N".

  Next, P8d: custom dashboards built from reports.
- **P8d custom dashboards** (2026-11-02): a Dashboards page in the rail. Anyone can make dashboards
  of their own, start from a suggested one, or begin empty. A dashboard holds up to 12 widgets:
  - a report (a saved one or a standard one, its first 5, 10 or 20 rows, linked to Reports);
  - health (counts, the health bar, what needs intervention);
  - the roadmap;
  - major risks;
  - work between projects;
  - your work (your open tasks overdue or due in the next two weeks);
  - your time this week (hours against capacity, your timesheet).

  The portfolio-wide widgets can be narrowed to one portfolio. Arranging is explicit and works by
  keyboard: move earlier or later, half or full width, remove. A dashboard can be shared with the
  business. Sharing shares the arrangement, never the rows: every widget is worked out for the
  person looking. A saved report that is not shared with them says so instead of showing its
  rows, and a removed portfolio says so too. Only the maker or the business owner arranges,
  shares or deletes a dashboard. Anyone it is shared with can copy it to make it theirs; a copy
  leaves behind widgets the copier cannot use. The roadmap, major-risks and project-links
  markup is now one partial each, shared by the Portfolio page and the dashboards. **P8 portfolio
  and reporting is complete.** Dot.Analytics integration waits for P11 (ecosystem).
- **P9a automation engine** (2026-11-02): an Automations page in the rail. P9 is split: P9a is
  the engine and event rules; P9b adds rules that act on dates (overdue work, an approaching
  deadline, review dates) and delays; P9c adds approval steps and conditional branches.

  A rule reads "When … only if … then …" and covers one project (made by the people who manage
  it) or every project in the business (made by the business owner or an admin).

  Triggers, from the activity log:
  - a task is added, moves to a status, is given to someone, or starts waiting on unfinished work;
  - a milestone is completed;
  - a risk is rated high or critical;
  - an issue is logged;
  - someone is added to the project;
  - a stage gate is passed;
  - the project's status changes;
  - a change request is submitted;
  - spending and commitments reach a share of the budget.

  Conditions narrow by priority, severity, category or role. Up to six ordered actions: notify
  (the project owner, its managers, the person it concerns, the business owner, with an optional
  note), escalate, add tasks (who for, due how many days later, priority), set the task's
  priority, label it, give it to the project owner. Seven templates come from the brief's
  examples.

  Before switching a rule on:
  - it reads as a sentence;
  - a dry run shows what it would have done over the last 30 days, without doing it;
  - rules are saved switched off.

  How it runs:
  - Rules run after the change commits, as the person who made or last changed the rule.
  - They are idempotent: a run's key is unique per rule, by event, or once per risk, milestone,
    person or share of the budget.
  - What a rule does is logged with no person and names the rule, and never sets off another rule.
  - Notices go only to people who can see the project, never to the person whose action set it
    off, and name the rule.
  - Each run records its steps. A failing step records why and the others still run. A retry
    repeats only the unfinished steps.
- **P9b dated rules and waits** (2026-11-02): rules can now act on dates. An hourly sweep
  (`automations:sweep`, scheduled at five past each hour) acts only 07:00 to 19:00 South African
  time. Date triggers:
  - a task becomes overdue, or is due within 1, 2, 3 or 7 days;
  - a milestone passes its date unfinished, or is due soon;
  - the project's due date is a week, two weeks or a month away;
  - a risk is due for review;
  - every week on a chosen weekday.

  Each acts once per record and date. Moving a date re-arms it, and weekly rules act once per
  project per week.

  Two new steps:
  - "wait N days", after which the rest of the rule carries on, in working hours. It can stop if
    by then it no longer applies (the task is done, the risk closed, the milestone complete), if
    the rule was switched off, or if the record was deleted.
  - "start a progress review", which opens a status report draft or leaves an open one as it is.

  Dated rules preview what they would act on at their next check. Two new templates cover the
  brief's dated examples: chase overdue work and then escalate it, and start a progress review
  two weeks before the due date. Actions run outside the scopes, because the sweep has nobody
  signed in; the engine checks who may change the project and who may see it itself. Next,
  P9c: approval steps and conditional branches.
- **P9c approvals and branches** (2026-11-02): a rule can have one approval step. It asks the
  project owner, the project managers or the business owner, with a question and 1 to 30 days to
  answer. The run then waits for the answer.
  - Any one of the people asked answers for all. Not approving needs a reason.
  - An unanswered request expires as not approved, through the hourly sweep.
  - Each answer is recorded in the project's activity log under the person who gave it.
  - Their "Waiting for you" list says what happens if they approve and what happens if they do
    not.

  Every step after the approval runs only if approved (the default), only if not approved, or
  either way. Any step can also carry its own condition (for example "only if its priority is
  Urgent"), read from the record when the step runs. A new template covers the brief's budget
  example: when spending reaches 90%, ask the business owner to approve further commitments,
  then tell the project; if not approved, also add a budget review task. The engine now catches
  anything unexpected around every rule, so a broken rule can never undo or break the change
  that set it off; the run stays failed, to be retried. **P9 automation is complete.**
  Visual flow editing stays the sentence builder, which works by keyboard and on a phone.
- **P11a integration foundation and Dot.Notify** (2026-11-02): P11 is split. P11a is the
  foundation and Dot.Notify; P11b is Dot.Files project documents; P11c is Dot.Analytics (which
  first needs Dot.Projects in its metrics catalogue) and Dot.Memory.

  Survey of the other apps' `main` branches, 2026-11-02:
  - **Dot.Notify:** signed event ingest. Live at notify.infodot.co.za; an unsigned call answers
    401.
  - **Dot.Files:** a signed platform files API (store, update, fetch, meta, list, trash).
  - **Dot.Analytics:** signed snapshot ingest, but only for platforms in its catalogue, and
    Dot.Projects is not in it.
  - **Dot.Memory:** token-authenticated intelligence events.
  - **Dot.HR, Dot.Finance and Dot.Tasks:** no API.

  The host runs only `schedule:run`, so there is no queue worker. Calls to other platforms
  therefore go through an outbox table (`integration_deliveries`), sent by
  `integrations:deliver` every minute:
  - Keyed per integration, so nothing is sent twice.
  - Unreachable, 429 and 5xx answers are retried after 1, 5, 15, 60 and 240 minutes, then
    given up.
  - Refusals that a retry cannot fix (401/403/404/422) are given up at once, with a reason a
    person can act on.
  - A circuit breaker pauses after 5 failures in a row.
  - Payloads are encrypted at rest and pruned 30 days after sending.

  Every in-app notice is offered to Dot.Notify as an email event `projects.<type>`. It carries
  only the business, the project, the notice's title and a link; the notice's text stays here.
  It is signed exactly as Notify verifies (HMAC-SHA256 over "{timestamp}.{body}",
  X-InfoDot-Signature and X-InfoDot-Timestamp, made at send time). Nothing is queued without
  NOTIFY_INGEST_URL and NOTIFY_INGEST_SECRET, without an InfoDot company on the business, or
  without an email address.

  An Integrations page (business owner and admins) shows, per integration, whether it is set up
  on the server and for the business, what was sent, what is waiting and what was not sent with
  the reason, with "Send again". Integrations not yet connected are listed with the honest
  reason.

  Owner steps to go live:
  - one shared secret: NOTIFY_INGEST_URL and NOTIFY_INGEST_SECRET here, and
    NOTIFY_INGEST_SECRET_PROJECTS on Notify;
  - each business switches the `projects.*` events on in Notify.

  Live end-to-end delivery is verified only after those steps.
- **P11b project documents in Dot.Files** (2026-11-02): every project has a Documents tab, and
  every task page a Documents section. Documents are filed in the business's Dot.Files workspace
  through its signed platform file API (v1). Dot.Files already lists the `projects` platform.
  - **Filing:** one folder per project, "Dot.Projects - <project>"; Dot.Files v1 has one folder
    level. Up to 25 MB, in the formats Dot.Files accepts.
  - **What is kept here:** a reference only (Dot.Files id, name, size, version, task). Nothing is
    stored twice.
  - **Downloads:** always through Dot.Projects, which checks the project first. A private project
    the person cannot see is a 404.
  - **New versions:** sent with `base_version`. A 409 from Dot.Files ("someone saved a newer
    version") is refused without overwriting anything.
  - **Removing:** moves the file to the business's Dot.Files trash (30 days, owners restore it
    there); nothing is deleted for good from here.
  - **Private projects:** uploading needs an explicit acknowledgement, because Dot.Files shows
    documents to whoever may open the project's folder there.
  - **Who can do what:** people who can change the project add, replace and remove; everyone
    who can see it downloads.
  - **Activity:** the log records each change by document id only.
  - **Integrations page:** shows whether Dot.Files is set up, how many documents are filed, and
    the last call's outcome.

  Owner steps to go live:
  - one shared secret: FILES_PLATFORM_SECRET here, and DOT_SERVICE_SECRET_PROJECTS on Dot.Files;
  - the business linked to InfoDot;
  - the Projects host's PHP upload limits at 25 MB or more.

  Live end-to-end filing is verified only after those steps.
- **P12a enforced CSP, rate limits and a tenant-isolation sweep** (2026-11-02). P12 is split:
  P12a is the browser and request protections; P12b is data-retention controls and monitoring
  (`/ready`, outbox and automation health); P12c is the restore drill, a load test with
  published numbers, and the final readiness report.

  **Enforced, nonce-based CSP**, the shape InfoDot (R-19) and Dot.HR run:
  - a fresh nonce per request, registered with Vite, so @vite and Livewire's assets carry it;
  - the three inline scripts declare it;
  - no 'unsafe-inline' for scripts, and `script-src-attr 'none'`;
  - 'unsafe-eval' stays, because Livewire 3's bundled Alpine needs it;
  - style-src keeps 'unsafe-inline' for the style attributes the views use;
  - Reporting-Endpoints plus report-to;
  - `SECURITY_CSP_REPORT_ONLY=true` is the kill-switch back to report-only without a deploy.

  A test scans 11 real pages: every `<script>` must carry the header's nonce or load from this
  origin, and no on*= handlers or javascript: links may appear.

  **Rate limits** per signed-in person (per address for guests), answered with 429 and
  Retry-After:
  - the app, 300 a minute;
  - Livewire round trips, 600 a minute;
  - report files, 20 a minute;
  - document downloads, 60 a minute;
  - uploads, 30 a minute, as before;
  - sign-in and two-factor, as before.

  **Tenant-isolation sweep:** every GET route that takes a record is filled with one business's
  records and requested three ways:
  - by someone from another business;
  - by a colleague outside a private project;
  - with two businesses' records mixed in one address.

  Each must answer 403 or 404: 28 record routes, 151 assertions. A route added later without a
  record in the sweep fails the test until it is covered.
- **P12b data retention, a Data and security page, `/ready` and two health checks** (2026-11-02).

  **Retention** is chosen per business by its owner or an admin (`/data-and-security`), and
  applied every night at 03:30 by `data:prune`, after the 02:45 backup:
  - activity history (the audit trail): kept for ever by default, or 1, 2, 3, 5 or 7 years;
  - read notices: 30, 90, 180 (default) or 365 days; unread notices are never removed;
  - finished automation runs: 90, 180, 365 (default) or 730 days; waiting runs stay.

  Every change is recorded in the activity history. Each night records when it ran and what it
  removed, and the page shows that. Projects, tasks, time, money, governance records, reports and
  documents are never removed by retention, and the page says so in full.

  **The page** also states what protects the data, read from the server's real settings: the
  enforced CSP and its kill-switch, HSTS days, rate limits, the nightly backup and `/ready`.

  **`/ready`** is public and never cached. It returns 200 or 503 and names checks and ages, never
  data:
  - database (pending migrations);
  - cache;
  - storage;
  - scheduler heartbeat;
  - outbox: due calls unsent for more than 10 minutes mean the sender stopped. Calls waiting to be
    retried because a neighbour is down are not a failure.

  **Guardian checks** added to `/guardian/health`:
  - `outbox`: critical when the sender has stopped; a warning when calls were given up in the
    last day;
  - `automations`: a warning when any business's rule failed in the last day.

  Owner step: point an uptime monitor at `https://projects.infodot.co.za/ready`.
- **P12c restore drill, load test and readiness report** (2026-11-02). The full report is
  [R34_READINESS.md](R34_READINESS.md).
  - `projects:verify-restore` checks a restored copy: every migration applied, row counts per
    table (optionally compared with the live tables), and a decrypt test of encrypted values with
    this `APP_KEY`.
  - A local drill on PostgreSQL 16 passed: 49 tables matched and the encrypted values decrypt.
    A wrong key fails the check.
  - `LoadSeeder` creates synthetic volume and refuses to run in production. `scripts/load-test.sh`
    runs locally only.
  - The load test found the workload computation scanning the whole task list for every task:
    17 s at 2,000 open tasks, and 5.2 s on the Portfolio page. Fixed, the Portfolio page takes
    1.2 s at 30 projects.
  - `PerformanceBudgetTest` keeps it fixed and holds the portfolio to 36 queries per project,
    independent of task count.
  - The owner runs the host drill; the report has the commands.
- **P13a project templates and methodologies** (2026-11-02; brief §5.1, §5.2, §15).

  **New project page.** Choose how to start:
  - a blank project;
  - one of the business's own templates;
  - one of nine starters across the six industries the brief names.

  A chosen template is previewed before anything is created: its phases drawn to scale in days, the
  first tasks of each, and its gates, risks, budget lines and labels.

  **What a template brings.** The project is dated from the start you choose and keeps:
  - milestones, tasks, subtasks, checklists, dependencies and labels;
  - gates with their checks, starting risks, and budget lines by name.

  It never brings people, progress, time or amounts.

  **Saving a project as a template.** Any project can be saved as a template from its settings, by
  people who can change it and create projects. The Templates page lists them; whoever saved one,
  and the owner and admins, rename or remove it. Projects made from a template are never touched.

  **Methodology.** Each project has one of ten methodologies:
  - it is shown as a pill in the project header;
  - it can be chosen at creation or in settings;
  - it says how the project is run and where to follow it (board, schedule, gates or overview);
  - every view still works whatever is chosen.

  **Safety.** Structures are cleaned before use: bounded, references checked, and subtask and
  dependency loops broken. Starters live in code and are checked by a test.

  **Not built:** sprints as their own object (a starter models them as milestones), import of
  plans from other tools, and creating a project from a business objective or workflow.
- **P13b calendar view and command palette** (2026-11-02; brief §6 and §17).

  **Calendar tab** (`projects.calendar`). A month of one project's dated events, all read from the
  existing records, so it always agrees with the other views:
  - the project's start and due date;
  - milestones;
  - task due dates, with critical-path work in the system's one blue and late work in red;
  - meetings with their times;
  - gates, actions, and decisions waiting on a date.

  Filter by kind, or show only your own items. To move an open task, drag it to another day, or
  focus it and use the arrow keys (a day left or right, a week up or down). The move keeps the
  task's length, goes through the schedule's reschedule path and is recorded. Done work does not
  move, and viewers cannot move anything. On phones the month shows marks only, with the chosen day
  listed below.

  **Command palette** (Ctrl or ⌘ and K, or the search button in the top bar). It finds:
  - projects, through the same team and private-project scopes as everywhere;
  - tasks and documents in those projects;
  - the person's own and shared saved reports, and the standard reports;
  - the pages they may open.

  It searches case-insensitively, with wildcards matched as text. It can start a new project, and
  add what was typed as a task in the project on screen, for those who may change that project.
  Enter opens the best match: the write action sits after the results, so it is never chosen by
  default after a search. The palette finds nothing until it is opened, so pages carry no results
  or queries for it.

  **Not in this step:** a business-wide calendar across projects, assigning work from the palette,
  and recently viewed items.
