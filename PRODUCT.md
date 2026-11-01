# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary: **project managers** running one or several projects day to day, who need to know what
moves the finish date, what is blocked, what waits on a decision and who is overloaded. When needs
conflict, the project manager's workflow sets the defaults (owner decision, 2026-11-01).

Also served: sponsors and executives (portfolio health, decisions), team members and contractors
(their own work), site and field teams (mining, construction, operations), and small-business owners
running a few projects themselves.

## Product Purpose

Dot.Projects is the project execution platform of the Dot ecosystem (InfoDot). It connects a
project's work, schedule, people, money, risks and decisions in one place so a project can be planned,
run and delivered without spreadsheets on the side. Success is a project manager who can answer
"will we land on time, and what is in the way?" in seconds, and act on it from the same screen.

## Positioning

Serious schedule and control logic (dependencies, critical path, baselines, risk, change, cost)
presented with calm and plain language, connected to the business's own people (Dot.HR), money
(Dot.Finance) and documents (Dot.Files), and honest about what is known and what is only a forecast.

## Operating Context

- Businesses sign in through InfoDot (single sign-on); a business is a Jetstream team.
- Industries named by the owner: mining and natural resources, construction and engineering,
  technology, manufacturing, professional services, general business (South Africa first; ZAR).
- Long working sessions on desktop for project managers; quick checks on phones; site offices on
  tablets.
- Full brief: `docs/programme/requirements/dot-projects-transformation.md` (R-34). Audit and plan:
  `docs/programme/R34_AUDIT.md`.

## Capabilities and Constraints

- Today (after R-34 P1): projects, AI or example plan, milestones and tasks on a five-column board,
  assignment, comments, closure approval, notifications, activity log, InfoDot sign-in, viewer role.
  Most of the brief is still to build (see the plan).
- **Roles (owner decision, 2026-11-01):** existing projects stay visible to the whole team; new
  projects may be made private with their own members and roles.
- **AI (owner, 2026-11-01):** no Anthropic key in production yet. Without one, the app offers a
  clearly labelled example plan, never an AI claim.
- Undecided: whether production shares the `infodot` Postgres database; AI cost cap per business.

## Brand Commitments

- Name: Dot.Projects. Original logo: `public/images/logo.png` (gold circle with folder and star, navy
  chevron, "dot.projects" wordmark); night variant `logo-light.png`. Use the artwork, never redraw it.
- A member of the Dot ecosystem: shares its conventions (sentence case, no eyebrows, both themes,
  Material Symbols Rounded icons in the ecosystem, plain words) while keeping its own design system.
- Owner rejects generic AI-dashboard looks; owner's rejected faces: Inter, Geist, Space Grotesk,
  Plus Jakarta Sans.

## Evidence on Hand

No customers, testimonials, case studies or usage figures exist for Dot.Projects. Do not invent any.
Sample data in design boards is labelled as a sample.

## Product Principles

1. The date first: every project screen answers "when do we land, and what moves it?" before anything else.
2. Forecasts are labelled as forecasts; nothing estimated is shown as fact, and AI output is a proposal until a person accepts it.
3. One click to the job: the decision, the blocker or the person who needs attention is acted on where it is shown.
4. Calm by default: colour and weight are spent only where they change what someone does next.
5. Connected, not copied: people, money and documents come from their Dot platforms when those offer a working API; Dot.Projects never duplicates them silently.

## Accessibility & Inclusion

WCAG 2.2 AA: visible focus, labelled controls, reduced motion respected (already tested), contrast
checked in light and dark, no meaning carried by colour alone (hatching and labels for blocked work).
