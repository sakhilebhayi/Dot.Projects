<?php

namespace App\Services\Guardian\Checks;

use App\Models\AutomationRun;
use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;
use Illuminate\Support\Carbon;

/** Businesses' automation rules (R-34 P9): a warning when any run failed in the last day, with how many. */
class AutomationCheck implements GuardianCheck
{
    public function key(): string
    {
        return 'automations';
    }

    public function run(): CheckResult
    {
        try {
            $since = Carbon::now()->subDay();
            $failed = AutomationRun::query()->withoutGlobalScopes()->where('status', 'failed')->where('updated_at', '>=', $since)->count();
            $ran = AutomationRun::query()->withoutGlobalScopes()->where('updated_at', '>=', $since)->count();
        } catch (\Throwable $e) {
            return CheckResult::unknown('Automation runs unreadable.', ['error' => $e::class]);
        }

        $metrics = ['runs_24h' => $ran, 'failed_24h' => $failed];

        return $failed > 0
            ? CheckResult::warning('Some automation runs failed in the last day.', $metrics)
            : CheckResult::healthy('Automations running cleanly.', $metrics);
    }
}
