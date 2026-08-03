<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyImportQueueRunner;
use Illuminate\Console\Command;

final class WorkLegacyImportQueue extends Command
{
    protected $signature = 'legacy:work-import-queue {--once : Process at most one queued import}';

    protected $description = 'Process legacy import jobs under a shared worker lock';

    public function handle(LegacyImportQueueRunner $runner): int
    {
        return $runner->run(
            (string) config('legacy.import_queue_connection', 'database'),
            (bool) $this->option('once'),
        );
    }
}
