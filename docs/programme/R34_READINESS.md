# R-34 Dot.Projects: readiness report (2026-11-02)

The brief's Phase 9 asks for security hardening, tenant isolation, performance, load testing,
monitoring, backup and recovery, automated tests and production readiness. This report states
what has been measured and what has not.

## 1. The short version

Dot.Projects is ready for measured growth: a business with tens of projects and thousands of
tasks. Two limits remain:

- The Portfolio and Reports pages cost about 35 ms of server time per project. A business with
  30 projects waits about a second; with 100 it would wait about four.
- Restoring a backup has been proven locally on PostgreSQL. It has not yet been proven on the
  production host; that step needs the host's shell (section 3).

Not built yet:
- AI intelligence (P10), which waits for an Anthropic key and a cost cap;
- the calendar view;
- the command palette;
- methodology templates;
- external and client roles.

The work is still useful without them, and none of them is claimed.

## 2. What runs in production today

| Area | State (verified live) |
|---|---|
| Browser protection | Enforced nonce CSP, no inline scripts, `SECURITY_CSP_REPORT_ONLY` kill-switch; HSTS 180 days; frame and sniffing protections |
| Abuse limits | Per person: 300 pages, 600 interactions, 20 report files, 60 downloads a minute; sign-in 5 a minute |
| Tenant isolation | Fail-closed team scope; private projects; a test requests every record route as another business (28 routes, three ways) before any change ships |
| Audit trail | Activity history of who changed what, kept for ever unless the business limits it |
| Retention | Per business: activity, read notices and automation history, applied nightly at 03:30 and recorded |
| Monitoring | `/up` (PHP answers), `/up/scheduler` (watched every 15 minutes from GitHub), `/ready` (database, migrations, cache, storage, scheduler, outbox), `/guardian/health` (database, cache, scheduler, error rate, outbox, automations) |
| Backups | `projects:backup-database` dumps PostgreSQL nightly at 02:45 and keeps 14 copies, each verified with `pg_restore --list` |
| Deploys | Manual workflow; the smoke check gates each deploy; the application folder is probed from outside (`.env` answers 403) |

## 3. Restore drill

**Local drill, run 2026-11-02**, on PostgreSQL 16.14:

1. A fresh database was created. All 34 migrations ran on PostgreSQL, which also proves they
   work there and not only on SQLite.
2. It was filled with the synthetic `LoadSeeder` volume (section 4) and five encrypted outbox
   rows.
3. `projects:backup-database` wrote a 269 KB dump in 0.24 s and verified it with
   `pg_restore --list`.
4. `pg_restore` loaded the dump into a second fresh database in 0.22 s.
5. `projects:verify-restore --connection=restore --against=pgsql` checked the restored copy:
   - all 34 migrations applied;
   - 49 tables compared, and every row count matches;
   - 5 of 5 encrypted values decrypt with the app's key.
6. The same check with a different `APP_KEY` **fails**, as it must. A backup without the key that
   was live when it was taken cannot be read. That is why the key must be kept offline.

**On the host (owner, about 15 minutes, needs the cPanel shell):**

```bash
cd ~/projects.infodot.co.za            # the application folder
ls -l storage/backups/                 # a dump for each night: proves pg_dump runs on the host
createdb projects_restore              # a scratch database, never the live one
pg_restore --no-owner --no-privileges -d projects_restore storage/backups/projects-YYYY-MM-DD.dump
DB_RESTORE_DATABASE=projects_restore php artisan projects:verify-restore --connection=restore --against=pgsql
dropdb projects_restore
```

The drill passes when the check prints "Restore check passed". Counts may trail the live copy
by whatever was written since the backup. Copies sit on the same host as the database: they
cover a bad migration or deletion, not a lost host. An off-host copy is a separate owner
decision.

## 4. Load test

**Method.**
- `scripts/load-test.sh` signs in a different load person for each page and requests the page
  with ApacheBench. Each page must return real HTML, not just a 200.
- It runs against a local server on the synthetic `LoadSeeder` business, stored in PostgreSQL:
  - 13 people and 30 projects;
  - 2,400 tasks and 1,590 dependencies;
  - 180 risks and 120 issues;
  - 150 budget lines and 750 costs;
  - 1,560 time entries.
- The requests per person stay inside the 300-a-minute limit, so the numbers measure the app,
  not the limiter.

**Environment.**
- Apple M4, 16 GB.
- PHP 8.5.9 built-in server with 8 workers, `APP_DEBUG=false`, no OPcache, config not cached.
- PostgreSQL 16.14 on the same machine.

The production host differs: LiteSpeed/PHP-FPM, a shared host, a database on the host. Read these
numbers as the app's own cost, not as a promise about production.

| Page | 1 at a time: median / p95 ms | 4 at a time: median / p95 ms | 4 at a time: requests/s |
|---|---|---|---|
| Dashboard | 30 / 44 | 31 / 34 | 124 |
| Portfolio (30 projects) | 1,187 / 1,215 | 1,136 / 2,319 | 3.2 |
| Reports (progress, 30 projects) | 920 / 957 | 871 / 936 | 4.3 |
| Workload | 34 / 41 | 27 / 32 | 137 |
| Time | 63 / 76 | 41 / 54 | 89 |
| Project overview | 69 / 84 | 51 / 62 | 71 |
| Project work (table) | 59 / 64 | 49 / 56 | 77 |
| Project schedule (Gantt, critical path) | 60 / 67 | 54 / 107 | 68 |
| Project board | 53 / 64 | 46 / 53 | 81 |
| Project risks | 47 / 63 | 33 / 60 | 110 |
| `/ready` | 19 / 21 | 11 / 14 | 335 |

**Found and fixed by this test.** The workload computation looked up each task by scanning the
whole task list, for each person and each week. The cost grew with the square of the open tasks:
- at 2,000 open tasks it took 17 s inside the test suite;
- the Portfolio page, which reuses it for owners and admins, took 5.2 s;
- indexed, the workload takes about 0.1 s and the Portfolio page 1.2 s.

`PerformanceBudgetTest` keeps it fixed: on the old code it fails at 17.1 s.

Earned value now reuses the cost ledger the page has just worked out, instead of loading each
project's budget twice.

**Remaining limit.** Portfolio and Reports work out each project's health, schedule and money
facts:
- about 33 queries per project, one per kind of record;
- `PerformanceBudgetTest` holds this to 36 at most, and it does not grow with the number of tasks;
- the cost is linear, about 35 ms per project.

The next step, when a business passes about 50 projects, is to share loaded tasks between the
schedule engine and the project summary and cache each project's facts for a few minutes. It is
recorded, not done.

## 5. Definition of success (brief §25)

| # | Criterion | State | Evidence |
|---|---|---|---|
| 1 | Distinctive design identity | Met | "The Programme" chosen by the owner from three boards (P2); DESIGN.md; Impeccable detector clean on every screen |
| 2 | Easier, more enjoyable | Partly | Rebuilt workspace, board drag, inline edits; no command palette or keyboard-first navigation yet |
| 3 | Intuitive project creation | Partly | Start with a name; add detail later. No templates or guided set-up by methodology yet |
| 4 | Complex projects without friction | Met | Subtasks, checklists, dependencies, baselines, critical path, governance registers |
| 5 | Multiple methodologies | Partly | Board (Kanban), Gantt and critical path (waterfall/CPM), stage gates, milestones. No sprints, and no methodology templates |
| 6 | Tasks, milestones and dependencies reliable | Met | Cycle prevention, critical-path tests, schedule conflict detection |
| 7 | Consistent data across views | Partly | Built from the same records: list and table, board, Gantt and timeline, workload, milestones, roadmap, portfolio, dashboards, executive summary. Missing from the brief's 15: calendar, a separate dependency map (dependencies show on the Gantt), resource allocation beyond workload, a WBS outline |
| 8 | Resource management and finance | Met | Capacity, workload, timesheets with approval, rates, cost ledger, commitments, earned value |
| 9 | Auditable governance | Met | Risks, issues, changes with approval, decisions, gates, meetings, status reports, all in the activity history |
| 10 | Useful, explainable AI | Not yet | P10 waits for the owner's Anthropic key and monthly cost cap. Health ratings and portfolio reasons are rule-based and explained |
| 11 | Portfolio visibility | Met | Portfolios, executive view, cross-project dependencies, major risks, roadmap, custom dashboards |
| 12 | Integrations with real capabilities | Partly | InfoDot sign-in live. Dot.Notify and Dot.Files built and tested against their contracts; live end to end needs the owner's shared secrets. Dot.Analytics needs a catalogue entry on its side. Dot.HR, Dot.Finance and Dot.Tasks have no API |
| 13 | Tenant isolation and permissions | Met | Fail-closed scope, private projects, money and time permissions, isolation sweep |
| 14 | Existing functionality intact | Met | Every phase shipped with the full suite green; nothing was removed without replacement |
| 15 | Automated tests | Met | 331 passing tests (7 skipped), including isolation, CSP, rate limits, retention, restore check and performance budgets |
| 16 | Maintainable, ready for measured growth | Met with limits | Section 4's limit on Portfolio and Reports; PHPStan holds at 8 inherited Jetstream findings |

## 6. Owner steps before calling it launched

1. **Restore drill on the host** (section 3), and keep the `APP_KEY` offline.
2. **Uptime monitor** on `https://projects.infodot.co.za/ready`.
3. **Shared secrets:**
   - Dot.Notify: `NOTIFY_INGEST_URL` and `NOTIFY_INGEST_SECRET` here, and
     `NOTIFY_INGEST_SECRET_PROJECTS` on Notify;
   - Dot.Files: `FILES_PLATFORM_SECRET` here, equal to `DOT_SERVICE_SECRET_PROJECTS` on Files.

   Then the end-to-end tests of both.
4. **PHP upload limit** of 25 MB or more on the Projects host.
5. **Domain renewal** before 2026-11-27.
6. **Open decisions** from the audit:
   - the AI key and cost cap;
   - whether production shares the `infodot` database;
   - external and client roles.

## 7. Recorded follow-ups

- P10 AI intelligence (on the key and cap).
- P11c Dot.Analytics (a `projects` catalogue entry on Analytics first) and Dot.Memory.
- The Portfolio and Reports cost per project (section 4).
- Calendar view, command palette, methodology templates, statuses as data, external and client
  roles.
- An off-host backup copy.
