<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\ProjectChange;
use App\Models\ProjectDecision;
use App\Models\ProjectIssue;
use App\Models\ProjectReport;
use App\Models\ProjectRisk;
use App\Models\User;
use App\Notifications\GovernanceNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Status reports. The facts come from the project's own records (nothing estimated beyond the
 * schedule forecast, which is labelled as one); the overall rating is the manager's, with a
 * suggestion and its reasons shown beside it. Publishing freezes the facts into the report.
 */
final class StatusReports
{
    /** The draft in progress, or a new one covering the days since the last report. */
    public function start(Project $project, User $by): ProjectReport
    {
        $draft = $project->reports()->where('status', 'draft')->first();
        if ($draft !== null) {
            return $draft;
        }
        $last = $project->reports()->where('status', 'published')->orderByDesc('period_to')->first();
        $to = Carbon::today();
        $from = $last ? $last->period_to->copy()->addDay() : $to->copy()->subDays(6);
        if ($from->gt($to)) {
            $from = $to->copy();
        }

        return DB::transaction(function () use ($project, $by, $from, $to) {
            $r = new ProjectReport(['period_from' => $from, 'period_to' => $to]);
            $r->forceFill(['project_id' => $project->id, 'number' => (int) ProjectReport::query()->where('project_id', $project->id)->lockForUpdate()->max('number') + 1, 'status' => 'draft', 'created_by' => $by->id])->save();
            Activity::record('report.started', $r, [], $project);

            return $r;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ProjectReport $r, array $data): void
    {
        $this->assertDraft($r);
        $v = Validator::make($data, [
            'period_from' => ['sometimes', 'required', 'date'],
            'period_to' => ['sometimes', 'required', 'date', 'after_or_equal:period_from'],
            'overall' => ['sometimes', 'nullable', Rule::in(array_keys(ProjectReport::RATINGS))],
            'summary' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'achievements' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'next_steps' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'help_needed' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ], ['period_to.after_or_equal' => 'The period ends on or after the day it starts.'])->validate();
        $r->fill($v);
        if (! $r->isDirty()) {
            return;
        }
        $changes = [];
        foreach (['overall'] as $f) {
            if ($r->isDirty($f)) {
                $changes[$f] = ['from' => $r->getOriginal($f), 'to' => $r->getAttribute($f)];
            }
        }
        $edited = array_values(array_filter(['period_from', 'period_to', 'summary', 'achievements', 'next_steps', 'help_needed'], fn ($f) => $r->isDirty($f)));
        if ($edited !== []) {
            $changes['fields'] = $edited;
        }
        $r->save();
        Activity::record('report.updated', $r, $changes, $r->project);
    }

    /** Freezes the facts into the report and tells the team it is out. */
    public function publish(ProjectReport $r, User $by): void
    {
        $this->assertDraft($r);
        if ($r->overall === null || trim((string) $r->summary) === '') {
            throw ValidationException::withMessages(['publish' => 'Give the overall rating and a summary before publishing.']);
        }
        $facts = $this->facts($r->project, $r->period_from, $r->period_to);
        $r->forceFill(['status' => 'published', 'snapshot' => $facts + ['suggestion' => $this->suggest($facts)], 'published_at' => Carbon::now(), 'published_by' => $by->id])->save();
        Activity::record('report.published', $r, ['overall' => $r->overall], $r->project);

        $label = ProjectReport::RATINGS[$r->overall];
        foreach ($r->project->team->allUsers() as $member) {
            if ($member->id !== $by->id) {
                $member->notify(new GovernanceNotification('report_published', 'Status report: '.$label, $by->name.' published '.$r->ref().' for "'.$r->project->name.'" ('.$r->period_from->format('j M').' to '.$r->period_to->format('j M').'): '.$label.'.', $r->project_id, route('projects.reports.show', [$r->project_id, $r])));
            }
        }
    }

    public function discard(ProjectReport $r): void
    {
        $this->assertDraft($r);
        Activity::record('report.discarded', $r, [], $r->project);
        $r->delete();
    }

    /**
     * The project's facts for a period, as plain values: what is true now, and what happened
     * between $from and $to.
     *
     * @return array<string, mixed>
     */
    public function facts(Project $project, Carbon $from, Carbon $to): array
    {
        $today = Carbon::today();
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $in = fn ($q, string $col) => $q->whereBetween($col, [$start, $end]);
        $schedule = new Schedule($project);
        $forecast = $schedule->forecastFinish();
        $baseline = $project->baselines()->first();
        $baseDue = $baseline?->items()->where('item_type', 'project')->value('due_date');
        $matrix = RiskMatrix::forProject($project);
        $summary = new ProjectSummary($project);
        $next = $summary->nextMilestone();

        $tasks = $project->tasks()->get(['id', 'status', 'due_date', 'completed_at']);
        $risks = $project->risks()->get();
        $live = $risks->filter(fn (ProjectRisk $r) => $r->isLive());
        $issues = $project->issues()->get();
        $open = $issues->filter(fn (ProjectIssue $i) => $i->isOpen());
        $changes = $project->changes()->get();
        $decisions = $project->decisions()->get();
        $gates = $project->gates()->withCount(['checks', 'checks as checks_done_count' => fn ($q) => $q->whereNotNull('done_at')])->get();
        $actions = $project->actions()->get();
        $approved = $changes->filter(fn (ProjectChange $c) => $c->accepted() && $c->decided_at && $c->decided_at->betweenIncluded($start, $end));
        $nextGate = $gates->first(fn ($g) => $g->open());

        return [
            'taken_on' => $today->toDateString(),
            'schedule' => [
                'due' => $project->due_date?->toDateString(),
                'forecast' => $forecast?->toDateString(),
                'days_late' => $project->due_date && $forecast && $forecast->gt($project->due_date) ? (int) $project->due_date->diffInDays($forecast) : 0,
                'critical' => $schedule->critical()->count(),
                'conflicts' => $schedule->conflictCount(),
                'baseline' => $baseline?->number,
                'baseline_due' => $baseDue ? Carbon::parse($baseDue)->toDateString() : null,
            ],
            'work' => [
                'total' => $tasks->count(),
                'done' => $tasks->where('status', 'done')->count(),
                'done_in_period' => $tasks->filter(fn ($t) => $t->completed_at && $t->completed_at->betweenIncluded($start, $end))->count(),
                'overdue' => $summary->overdue()->count(),
                'blocked' => $summary->blocked()->count(),
            ],
            'milestone' => $next ? ['title' => $next['milestone']->title, 'due' => $next['milestone']->due_date?->toDateString(), 'late' => (bool) $next['late'], 'done' => $next['done'], 'total' => $next['total']] : null,
            'risks' => [
                'live' => $live->count(),
                'serious' => $live->filter(fn ($r) => in_array($r->band($matrix), ['high', 'critical'], true))->count(),
                'escalated' => $live->whereNotNull('escalated_at')->count(),
                'new' => $risks->filter(fn ($r) => $r->created_at->betweenIncluded($start, $end))->count(),
                'top' => $live->sortByDesc(fn ($r) => $r->currentScore())->take(5)->map(fn ($r) => ['ref' => $r->ref(), 'title' => $r->title, 'band' => $r->band($matrix), 'score' => $r->currentScore()])->values()->all(),
            ],
            'issues' => [
                'open' => $open->count(),
                'critical' => $open->where('severity', 'critical')->count(),
                'serious' => $open->whereIn('severity', ['high', 'critical'])->count(),
                'late' => $open->filter(fn ($i) => $i->late($today))->count(),
                'resolved_in_period' => $issues->filter(fn ($i) => $i->resolved_at && $i->resolved_at->betweenIncluded($start, $end))->count(),
                'top' => $open->sortByDesc(fn ($i) => $i->weight())->take(5)->map(fn ($i) => ['ref' => $i->ref(), 'title' => $i->title, 'severity' => $i->severity])->values()->all(),
            ],
            'changes' => [
                'waiting' => $changes->where('status', 'submitted')->count(),
                'approved_in_period' => $approved->map(fn ($c) => ['ref' => $c->ref(), 'title' => $c->title, 'days' => $c->schedule_days, 'cents' => $c->cost_cents])->values()->all(),
            ],
            'decisions' => [
                'waiting' => $decisions->where('status', 'proposed')->count(),
                'overdue' => $decisions->filter(fn (ProjectDecision $d) => $d->overdue($today))->count(),
                'made_in_period' => $decisions->filter(fn ($d) => $d->decided_on && $d->decided_on->betweenIncluded($from, $to))->map(fn ($d) => ['ref' => $d->ref(), 'title' => $d->title])->values()->all(),
            ],
            'gates' => [
                'next' => $nextGate ? ['ref' => $nextGate->ref(), 'title' => $nextGate->title, 'status' => $nextGate->status, 'met' => $nextGate->checks_done_count, 'checks' => $nextGate->checks_count, 'due' => $nextGate->due_date?->toDateString(), 'overdue' => $nextGate->overdue($today)] : null,
                'held' => $gates->where('status', 'held')->count(),
                'decided_in_period' => $gates->filter(fn ($g) => $g->decided_at && $g->decided_at->betweenIncluded($start, $end))->map(fn ($g) => ['ref' => $g->ref(), 'title' => $g->title, 'status' => $g->status])->values()->all(),
            ],
            'actions' => [
                'open' => $actions->where('status', 'open')->count(),
                'late' => $actions->filter(fn (ProjectAction $a) => $a->overdue($today))->count(),
                'done_in_period' => $actions->filter(fn ($a) => $a->done_at && $a->done_at->betweenIncluded($start, $end))->count(),
            ],
        ];
    }

    /**
     * A suggested rating with the facts behind it. Off track: the forecast misses the due date, a
     * milestone is late, a critical issue is open or a gate is held. At risk: anything serious
     * still open or overdue. The manager's own rating is what the report carries.
     *
     * @param  array<string, mixed>  $f
     * @return array{rating: string, reasons: list<string>}
     */
    public function suggest(array $f): array
    {
        $plural = fn (int $n, string $word) => $n.' '.str($word)->plural($n);
        $off = array_values(array_filter([
            $f['schedule']['days_late'] > 0 ? 'The tasks as dated finish '.$plural($f['schedule']['days_late'], 'day').' after the due date' : null,
            ($f['milestone']['late'] ?? false) ? 'The milestone '.$f['milestone']['title'].' is past its date' : null,
            $f['issues']['critical'] > 0 ? $plural($f['issues']['critical'], 'critical issue').' open' : null,
            $f['gates']['held'] > 0 ? $plural($f['gates']['held'], 'gate').' held' : null,
        ]));
        if ($off !== []) {
            return ['rating' => 'off_track', 'reasons' => $off];
        }
        $risk = array_values(array_filter([
            $f['risks']['serious'] > 0 ? $plural($f['risks']['serious'], 'risk').' rated high or critical' : null,
            $f['issues']['serious'] > 0 ? $plural($f['issues']['serious'], 'high-severity issue').' open' : null,
            $f['work']['overdue'] > 0 ? $plural($f['work']['overdue'], 'task').' overdue' : null,
            $f['actions']['late'] > 0 ? $plural($f['actions']['late'], 'action').' late' : null,
            $f['decisions']['overdue'] > 0 ? $plural($f['decisions']['overdue'], 'decision').' past due' : null,
            ($f['gates']['next']['overdue'] ?? false) ? 'Gate '.$f['gates']['next']['ref'].' is past due' : null,
            $f['schedule']['conflicts'] > 0 ? $plural($f['schedule']['conflicts'], 'broken dependency') : null,
        ]));
        if ($risk !== []) {
            return ['rating' => 'at_risk', 'reasons' => $risk];
        }

        return ['rating' => 'on_track', 'reasons' => ['Nothing is late, held or seriously at risk']];
    }

    private function assertDraft(ProjectReport $r): void
    {
        if ($r->published()) {
            throw ValidationException::withMessages(['report' => 'A published report is a record; start a new one for the next period.']);
        }
    }
}
