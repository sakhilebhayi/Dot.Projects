<?php

namespace App\Console\Commands;

use App\Services\DataRetention;
use Illuminate\Console\Command;

/** R-34 P12b: applies every business's retention choices (nightly). */
class PruneData extends Command
{
    protected $signature = 'data:prune';

    protected $description = 'Remove what each business chose not to keep: old activity, read notices, finished automation runs';

    public function handle(DataRetention $retention): int
    {
        $out = $retention->pruneAll();
        $this->info('Retention: '.$out['teams'].' business(es); removed '.$out['activity'].' activity line(s), '.$out['notices'].' read notice(s), '.$out['automation_runs'].' automation run(s).');

        return self::SUCCESS;
    }
}
