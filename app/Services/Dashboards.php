<?php

namespace App\Services;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a dashboard, in one place. Anyone in the business can make their own; only the
 * maker or the business owner arranges, shares or deletes one; anyone who can see a shared one
 * can copy it to make it their own.
 */
final class Dashboards
{
    public const MAX_WIDGETS = 12;

    public function create(Team $team, User $by, string $name): Dashboard
    {
        $dashboard = new Dashboard(['name' => $this->name($name), 'shared' => false]);
        $dashboard->forceFill(['team_id' => $team->id, 'user_id' => $by->id])->save();

        return $dashboard;
    }

    public function rename(Dashboard $d, User $by, string $name, bool $shared): void
    {
        $this->assertManage($d, $by);
        $d->update(['name' => $this->name($name), 'shared' => $shared]);
    }

    public function delete(Dashboard $d, User $by): void
    {
        $this->assertManage($d, $by);
        $d->delete();
    }

    /** A copy for $by, unshared, with the same widgets. Widgets the copier cannot use are left out. */
    public function copy(Dashboard $d, User $by): Dashboard
    {
        if (! $d->readableBy($by)) {
            throw ValidationException::withMessages(['dashboard' => 'That dashboard is not shared with you.']);
        }

        return DB::transaction(function () use ($d, $by) {
            $copy = $this->create($d->team, $by, mb_substr($d->name, 0, 73).' (copy)');
            foreach ($d->widgets as $w) {
                try {
                    $config = DashboardWidgets::clean($w->kind, $this->asInput($w), $d->team, $by);
                } catch (ValidationException) {
                    continue; // a report that is not shared with the copier stays behind
                }
                $copy->widgets()->create(['kind' => $w->kind, 'title' => $w->title, 'config' => $config, 'position' => $w->position, 'wide' => $w->wide]);
            }

            return $copy;
        });
    }

    /** @param  array<string, mixed>  $raw  the form's settings for the widget */
    public function addWidget(Dashboard $d, User $by, string $kind, array $raw, ?string $title): DashboardWidget
    {
        $this->assertManage($d, $by);
        if ($d->widgets()->count() >= self::MAX_WIDGETS) {
            throw ValidationException::withMessages(['widget' => 'A dashboard holds '.self::MAX_WIDGETS.' widgets. Remove one first, or make another dashboard.']);
        }
        $config = DashboardWidgets::clean($kind, $raw, $d->team, $by);
        $title = trim((string) $title);

        return $d->widgets()->create([
            'kind' => $kind,
            'title' => $title === '' ? null : mb_substr($title, 0, 80),
            'config' => $config,
            'position' => (int) $d->widgets()->max('position') + 1,
            'wide' => in_array($kind, ['roadmap', 'report'], true),
        ]);
    }

    /** Swaps the widget with its neighbour before (-1) or after (+1). */
    public function move(DashboardWidget $w, User $by, int $step): void
    {
        $this->assertManage($w->dashboard, $by);
        $list = $w->dashboard->widgets()->get()->values();
        $i = $list->search(fn ($x) => $x->id === $w->id);
        $j = $i === false ? false : $i + ($step < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= $list->count()) {
            return;
        }
        DB::transaction(function () use ($list, $i, $j) {
            foreach ($list as $n => $x) {
                $x->update(['position' => $n === $i ? $j : ($n === $j ? $i : $n)]);
            }
        });
    }

    public function toggleWide(DashboardWidget $w, User $by): void
    {
        $this->assertManage($w->dashboard, $by);
        $w->update(['wide' => ! $w->wide]);
    }

    public function removeWidget(DashboardWidget $w, User $by): void
    {
        $this->assertManage($w->dashboard, $by);
        $w->delete();
    }

    private function assertManage(Dashboard $d, User $by): void
    {
        if (! $d->manageableBy($by)) {
            throw ValidationException::withMessages(['dashboard' => 'Only the person who made this dashboard, or the business owner, can change it. Copy it to make one of your own.']);
        }
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Give the dashboard a name of up to 80 characters.']);
        }

        return $name;
    }

    /** @return array<string, mixed> a stored widget's settings in the form clean() takes */
    private function asInput(DashboardWidget $w): array
    {
        $c = $w->config;

        return [
            'portfolio' => $c['portfolio'] ?? '',
            'source' => isset($c['saved']) ? 'saved:'.$c['saved'] : ($c['type'] ?? ''),
            'rows' => $c['rows'] ?? 5,
        ];
    }
}
