<?php

namespace Tests\Unit;

use App\Jobs\RunLegacyImportQueueOnce;
use App\Services\Legacy\LegacyImportQueueRunner;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class RunLegacyImportQueueOnceTest extends TestCase
{
    public function test_it_drains_the_database_queue_until_empty(): void
    {
        Artisan::shouldReceive('call')->once()->with('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--max-time' => 600,
            '--queue' => 'default',
            '--tries' => 1,
            '--timeout' => 600,
        ])->andReturn(0);

        (new RunLegacyImportQueueOnce('database'))->handle(app(LegacyImportQueueRunner::class));
        $this->addToAssertionCount(1);
    }

    public function test_it_reports_when_worker_cannot_start(): void
    {
        Artisan::shouldReceive('call')->once()->andReturn(1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('background import worker');

        (new RunLegacyImportQueueOnce('database'))->handle(app(LegacyImportQueueRunner::class));
    }

    public function test_it_does_not_start_a_second_worker_while_the_lock_is_held(): void
    {
        $handle = fopen(storage_path('framework/legacy-import-worker.lock'), 'c+');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        Artisan::shouldReceive('call')->never();

        try {
            (new RunLegacyImportQueueOnce('database'))->handle(app(LegacyImportQueueRunner::class));
            $this->addToAssertionCount(1);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
