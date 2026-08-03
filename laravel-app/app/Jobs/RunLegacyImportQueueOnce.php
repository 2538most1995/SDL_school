<?php

namespace App\Jobs;

use App\Services\Legacy\LegacyImportQueueRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RunLegacyImportQueueOnce implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 620;

    public function __construct(public readonly string $queueConnection = 'database') {}

    public function handle(LegacyImportQueueRunner $runner): void
    {
        $runner->run($this->queueConnection, false);
    }
}
