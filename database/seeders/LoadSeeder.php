<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synthetic volume for the local load test and restore drill (R-34 P12c), never real data: one business ("Load test
 * business") with 12 people and 30 projects, each with 4 milestones, 80 tasks chained by dependencies, 6 risks,
 * 4 issues, 5 budget lines and 24 costs, and 60 working days of time from every person. Every name says it is a
 * load-test record. It refuses to run in production.
 *
 *   php artisan db:seed --class=LoadSeeder
 */
class LoadSeeder extends Seeder
{
    public const PROJECTS = 30;

    public const TASKS = 80;

    public const PEOPLE = 12;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LoadSeeder writes synthetic records and never runs in production.');
        }

        $now = Carbon::now();
        $owner = User::query()->firstWhere('email', 'test@example.com') ?? User::factory()->withPersonalTeam()->create(['name' => 'Test User', 'email' => 'test@example.com']);
        $team = Team::query()->forceCreate(['user_id' => $owner->id, 'name' => 'Load test business', 'personal_team' => false]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $people = collect(range(1, self::PEOPLE))->map(fn ($i) => User::factory()->create(['name' => 'Load person '.$i, 'email' => "load{$i}@example.test", 'current_team_id' => $team->id]));
        foreach ($people as $i => $p) {
            $team->users()->attach($p, ['role' => $i < 2 ? 'admin' : 'editor']);
        }
        $ids = $people->pluck('id')->push($owner->id)->all();

        foreach (range(1, self::PROJECTS) as $n) {
            $start = $now->copy()->subDays(90 - $n);
            $project = Project::withoutEvents(fn () => Project::query()->forceCreate(['team_id' => $team->id, 'owner_id' => $ids[$n % count($ids)], 'name' => sprintf('Load test project %02d', $n),
                'status' => $n % 7 === 0 ? 'on_hold' : 'active', 'start_date' => $start, 'due_date' => $start->copy()->addDays(180), 'visibility' => 'team']));
            DB::table('project_members')->insert(collect(array_slice($ids, 0, 6))->map(fn ($u, $k) => ['project_id' => $project->id, 'user_id' => $u, 'role' => $k === 0 ? 'manager' : 'member', 'created_at' => $now, 'updated_at' => $now])->all());

            $milestones = [];
            foreach (range(1, 4) as $m) {
                $milestones[] = DB::table('milestones')->insertGetId(['project_id' => $project->id, 'title' => "Stage {$m}", 'due_date' => $start->copy()->addDays(45 * $m), 'status' => $m === 1 ? 'completed' : 'pending', 'sort_order' => $m, 'created_at' => $now, 'updated_at' => $now]);
            }

            $tasks = [];
            foreach (range(1, self::TASKS) as $t) {
                $done = $t <= 30;
                $tasks[] = ['project_id' => $project->id, 'milestone_id' => $milestones[intdiv($t - 1, 20)], 'assignee_id' => $ids[($n + $t) % count($ids)], 'title' => "Load task {$n}.{$t}",
                    'status' => $done ? 'done' : (['todo', 'in_progress', 'review', 'backlog'][$t % 4]), 'priority' => ['low', 'medium', 'high', 'urgent'][$t % 4],
                    'start_date' => $start->copy()->addDays(2 * $t), 'due_date' => $start->copy()->addDays(2 * $t + 3), 'estimate_minutes' => 240 + 30 * ($t % 8), 'sort_order' => $t,
                    'completed_at' => $done ? $start->copy()->addDays(2 * $t + 3) : null, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('project_tasks')->insert($tasks);
            $taskIds = DB::table('project_tasks')->where('project_id', $project->id)->orderBy('id')->pluck('id')->all();
            $deps = [];
            foreach (array_slice($taskIds, 1) as $k => $succ) {
                if ($k % 3 !== 2) {
                    $deps[] = ['predecessor_id' => $taskIds[$k], 'successor_id' => $succ, 'type' => 'FS', 'created_at' => $now, 'updated_at' => $now];
                }
            }
            DB::table('task_dependencies')->insert($deps);

            DB::table('project_risks')->insert(collect(range(1, 6))->map(fn ($r) => ['project_id' => $project->id, 'number' => $r, 'title' => "Load risk {$n}.{$r}", 'category' => 'schedule',
                'owner_id' => $ids[$r % count($ids)], 'probability' => 1 + $r % 5, 'impact' => 1 + ($r + $n) % 5, 'status' => $r === 6 ? 'closed' : 'open', 'created_at' => $now, 'updated_at' => $now])->all());
            DB::table('project_issues')->insert(collect(range(1, 4))->map(fn ($r) => ['project_id' => $project->id, 'number' => $r, 'title' => "Load issue {$n}.{$r}", 'category' => 'quality',
                'severity' => 'medium', 'priority' => 'medium', 'owner_id' => $ids[$r % count($ids)], 'status' => 'open', 'created_at' => $now, 'updated_at' => $now])->all());

            foreach (['labour', 'contractors', 'materials', 'equipment', 'operational'] as $k => $category) {
                $line = DB::table('budget_lines')->insertGetId(['project_id' => $project->id, 'category' => $category, 'name' => ucfirst($category), 'budget_cents' => 5_000_000 * ($k + 1), 'sort_order' => $k, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('cost_items')->insert(collect(range(1, 5))->map(fn ($c) => ['project_id' => $project->id, 'budget_line_id' => $line, 'kind' => $c === 5 ? 'commitment' : 'actual',
                    'description' => "Load cost {$n}.{$k}.{$c}", 'amount_cents' => 400_000 * $c, 'dated' => $start->copy()->addDays(10 * $c), 'created_at' => $now, 'updated_at' => $now])->all());
            }
        }

        // 60 working days of time: each person logs to two of the projects every day.
        $projectIds = Project::withoutTeamScope(fn () => Project::query()->where('team_id', $team->id)->pluck('id')->all());
        foreach ($ids as $k => $user) {
            $rows = [];
            for ($d = 0, $day = $now->copy()->subDays(84); $d < 60; $day->addDay()) {
                if ($day->isWeekend()) {
                    continue;
                }
                foreach ([0, 1] as $j) {
                    $rows[] = ['team_id' => $team->id, 'project_id' => $projectIds[($k + $d + 7 * $j) % count($projectIds)], 'user_id' => $user, 'work_date' => $day->toDateString(),
                        'minutes' => 210, 'note' => 'Load time', 'billable' => true, 'created_at' => $now, 'updated_at' => $now];
                }
                $d++;
            }
            DB::table('time_entries')->insert($rows);
        }
    }
}
