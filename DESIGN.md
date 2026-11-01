---
name: The Programme
description: Dot.Projects design system of record. A project read like a well-set programme document; ink on a blue-grey ground, with one blue reserved for the critical path.
colors:
  primary: "#141414"
  on-primary: "#ffffff"
  secondary: "#e5e5e5"
  on-secondary: "#0d0f12"
  tertiary: "#1d5fbf"
  on-tertiary: "#ffffff"
  neutral: "#f2f4f7"
  surface: "#ffffff"
  on-surface: "#0d0f12"
  raised: "#eceef2"
  border: "#e3e6eb"
  rule: "#eef0f3"
  muted: "#5e636b"
  critical: "#1d5fbf"
  critical-soft: "#e8effa"
  negative: "#b3261e"
  negative-soft: "#fdecea"
  warning: "#8a4b0b"
  warning-soft: "#fbf1e3"
  positive: "#2a6b3a"
  positive-soft: "#e6f2e9"
typography:
  title:
    fontFamily: Schibsted Grotesk
    fontSize: 24px
    fontWeight: 600
    lineHeight: 1.2
  section:
    fontFamily: Schibsted Grotesk
    fontSize: 14px
    fontWeight: 600
    lineHeight: 1.4
  body:
    fontFamily: Schibsted Grotesk
    fontSize: 14px
    fontWeight: 400
    lineHeight: 1.5
  small:
    fontFamily: Schibsted Grotesk
    fontSize: 12px
    fontWeight: 400
    lineHeight: 1.45
  figure:
    fontFamily: Schibsted Grotesk
    fontSize: 34px
    fontWeight: 600
    lineHeight: 1.1
  heading:
    fontFamily: Schibsted Grotesk
    fontSize: 16px
    fontWeight: 600
    lineHeight: 1.3
  auth-title:
    fontFamily: Schibsted Grotesk
    fontSize: 22px
    fontWeight: 600
    lineHeight: 1.2
  control:
    fontFamily: Schibsted Grotesk
    fontSize: 13.5px
    fontWeight: 500
    lineHeight: 1.4
  label:
    fontFamily: Schibsted Grotesk
    fontSize: 13px
    fontWeight: 500
    lineHeight: 1.4
  meta:
    fontFamily: Schibsted Grotesk
    fontSize: 12.5px
    fontWeight: 400
    lineHeight: 1.45
  badge:
    fontFamily: Schibsted Grotesk
    fontSize: 10.5px
    fontWeight: 600
    lineHeight: 1
  icon:
    fontFamily: Material Symbols Rounded
    fontSize: 20px
    fontWeight: 400
    lineHeight: 1
  id:
    fontFamily: DM Mono
    fontSize: 12px
    fontWeight: 400
    lineHeight: 1.4
rounded:
  mark: 2px
  bar: 3px
  focus: 4px
  control: 6px
  card: 8px
  pill: 999px
spacing:
  gap: 12px
  pad: 18px
  page: 28px
  rail: 56px
  row: 44px
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.control}"
    height: 36px
    padding: 0 14px
  button-secondary:
    backgroundColor: "{colors.secondary}"
    textColor: "{colors.on-secondary}"
    rounded: "{rounded.control}"
    height: 36px
    padding: 0 14px
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    rounded: "{rounded.card}"
    padding: 16px 18px
  pill-critical:
    backgroundColor: "{colors.critical-soft}"
    textColor: "{colors.critical}"
    rounded: "{rounded.pill}"
  pill-negative:
    backgroundColor: "{colors.negative-soft}"
    textColor: "{colors.negative}"
    rounded: "{rounded.pill}"
  pill-warning:
    backgroundColor: "{colors.warning-soft}"
    textColor: "{colors.warning}"
    rounded: "{rounded.pill}"
  pill-positive:
    backgroundColor: "{colors.positive-soft}"
    textColor: "{colors.positive}"
    rounded: "{rounded.pill}"
  pill-muted:
    backgroundColor: "{colors.raised}"
    textColor: "{colors.muted}"
    rounded: "{rounded.pill}"
  page:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.on-surface}"
  card-header-note:
    textColor: "{colors.muted}"
    typography: "{typography.small}"
  row-divider:
    backgroundColor: "{colors.rule}"
    height: 1px
  card-edge:
    backgroundColor: "{colors.border}"
    height: 1px
---

# The Programme

## Overview

Chosen by the owner on 2026-11-01 from three drawn directions (R-34 P2; boards at
https://claude.ai/artifact/7oZnZiP6sQpLGrewicEeVx). A project is read like a well-set programme
document: ink on a blue-grey ground, everything ruled and aligned, no colour in the chrome. **The one
blue is reserved for the critical path**, the work that decides the date, so colour on any screen
always means "this is what moves the finish". The system lives in `resources/css/programme.css`
(prefix `pg-`); this file is the contract every screen follows.

Every project screen answers its question first, in a title row: the project's name, then one
**finding sentence** built from real data ("Kathu go-live is 3 days overdue; 2 tasks on it are
blocked."), then the one action that finding drives as the near-black primary button.

## Colors

- Ground `neutral` #f2f4f7 (blue-grey, a ledger's paper); cards `surface` white with a 1px `border`.
- Ink `on-surface` #0d0f12 for text and data; `muted` #5e636b for secondary text (4.5:1 on white and
  on the ground).
- `critical` #1d5fbf only for the critical path, the work on it, and time lost on it. Never for
  links, navigation, brand or decoration.
- `negative` only for over budget, over capacity, overdue. `warning` for at-risk. `positive` sparingly
  for confirmed good news. Each has a pale `-soft` fill for pills.
- **Dark theme** (`html.dark`, user toggle or system preference): ground #111316, surface #181b1f,
  raised #22262b, border #2a2f35, ink #eef0f3, muted #a0a6af, critical lifted to #6f9ff0 (on dark),
  negative #f08a80. Same roles; never pure black.
- The logo keeps its own gold and navy; they do not enter the interface palette.

## Typography

- **Schibsted Grotesk** 400/500/600/700 for everything. Body 14px/1.5. Title 24px/600. Section 14px/600.
- Steps in use: title 24, auth title 22, figure 34, heading 16, body 14, control 13.5, label 13,
  meta 12.5, small 12, badge 10.5. Icons are Material Symbols Rounded at 20px (the ecosystem's set).
- **DM Mono** 12px only for identifiers (`DP-0142`, `CR-04`, `R-01`). Never for prose or figures.
- Figures: tabular numerals everywhere they count or line up. 700 only for money totals; the one
  headline figure per screen (the forecast date) at 34px/600.
- Sentence case. No tracked capitals, no eyebrows, no middle-dot meta strings: write sentences
  ("Due 30 Nov, 3 days late") instead.

## Layout

- Shell: a 56px **icon rail** (the original Dot.Projects symbol at the top, labelled icons with
  tooltips, utilities at the bottom), and a top bar with the business (team) switcher, search,
  notifications, theme and account. On phones the rail becomes a bottom bar.
- Page: padding 28px; a breadcrumb (business / Projects / ID), the title row, then project views as
  text tabs with a 2px ink underline on the active one.
- A 12-column grid, one gap (12px) both ways. The first row is the question's answer; time-sensitive
  blocks (in the way, waiting on a decision) come before long lists. Lists show 4 to 6 rows, then
  "View all".
- Rows are 44px with 1px `rule` dividers; key and value pairs 30px.

## Elevation & Depth

Flat. Separation is a 1px border or one tone step; no shadows at rest. Menus and the command
palette may take one soft shadow (0 8px 24px rgba(13,15,18,.12)) because they float over content.

## Shapes

Controls 6px, cards 8px, pills fully round; 2px for legend marks, 3px for bar ends, 4px for focus rings. Bars in charts have square ends on the baseline and at
most a 3px radius. Avatars are circles with the person's initials on a pale tint of their own hue.

## Components

- `button-primary`: near-black, the screen's one main action. `button-secondary`: grey. Ghost
  buttons for row actions. No coloured buttons.
- `card`: white, 1px border, 8px radius; a 14px/600 header with a muted count or note on the right.
- Pills: `pill-critical` for critical-path work, `pill-negative` for overdue or over, warning for at
  risk, muted for neutral states. Text always says the state; colour never carries it alone.
- **When we land** (the signature): milestones on a date line, today as a 1px ink line, each
  milestone a dot (filled when done, ink when on time, `critical` when on the critical path and late),
  dates under each. Baselines appear as hollow dots once baselines exist (P4); until then the line
  shows planned dates only and says so.
- **Risk matrix** (P5a): probability rows (most likely at the top) by impact columns (worst on the
  right), each cell tinted by its band from the state colours, never the critical-path blue: low is
  `raised`, medium a 26% mix of `warning`, high a 42% mix of `negative`, critical solid `negative`.
  Counts are ink; the band is always named in text (legend, pill, cell label). On phones the
  impact labels turn vertical. Band pills follow the same steps (`pill-negative` solid for critical).
- Blocked work is hatched, never just coloured. Load bars show capacity as a 1px tick.

## Do's and Don'ts

- Do lead with the finding sentence and its action. Do label every forecast as a forecast.
- Do spend `critical` only on the critical path; if a screen has none, it has no blue.
- Don't use gradients, glass, glows, tinted KPI tiles, icons in coloured squares, sparklines on
  figures, or four equal metric tiles.
- Don't use Inter, Geist, Space Grotesk or Plus Jakarta Sans.
- Don't invent data: empty states keep their frame and say what will appear and how to add it.
