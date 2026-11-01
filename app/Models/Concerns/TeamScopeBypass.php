<?php

namespace App\Models\Concerns;

/**
 * The one explicit opt-out from HasTeamScope. Work that has no signed-in user and
 * must see rows from more than one team (the milestone reminders, a queued job)
 * runs inside `Project::withoutTeamScope(fn () => ...)`. The counter is released in
 * a finally block, so an exception can never leave the scope switched off.
 */
final class TeamScopeBypass
{
    private static int $depth = 0;

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
