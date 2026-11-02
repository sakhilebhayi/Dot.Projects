<?php

namespace App\Console\Commands;

use App\Services\Automations;
use Illuminate\Console\Command;

/** R-34 P9b: runs dated automation rules and carries on runs whose wait is over (hourly). */
class SweepAutomations extends Command
{
    protected $signature = 'automations:sweep';

    protected $description = 'Run dated automation rules and resume waiting runs';

    public function handle(Automations $automations): int
    {
        $out = $automations->sweep();
        $this->info('Automations: '.$out['fired'].' run(s) started, '.$out['resumed'].' resumed.');

        return self::SUCCESS;
    }
}
