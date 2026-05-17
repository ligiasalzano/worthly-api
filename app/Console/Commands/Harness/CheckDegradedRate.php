<?php

namespace App\Console\Commands\Harness;

use App\Ai\Harness\Observability\DegradedRateWatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('harness:check-degraded-rate')]
#[Description('Emit a warning when the harness degraded rate over the last hour exceeds 10%.')]
class CheckDegradedRate extends Command
{
    public function handle(DegradedRateWatcher $watcher): int
    {
        $result = $watcher->check();

        $this->line(sprintf(
            'Total: %d | Degraded: %d | Rate: %.2f%% | Alerted: %s',
            $result['total'],
            $result['degraded'],
            $result['rate'] * 100,
            $result['alerted'] ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
