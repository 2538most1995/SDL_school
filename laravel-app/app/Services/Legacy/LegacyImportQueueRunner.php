<?php

namespace App\Services\Legacy;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class LegacyImportQueueRunner
{
    public function run(string $connection = 'database', bool $once = false): int
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $lockPath = storage_path('framework/legacy-import-worker.lock');
        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('ไม่สามารถสร้าง worker lock สำหรับงานนำเข้าได้');
        }

        try {
            // Both the deferred automatic kicker and a Plesk scheduled worker
            // use this lock. Only one worker may process the import queue even
            // when cache/config is cleared or the status endpoint retries.
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return 0;
            }

            $arguments = [
                'connection' => $connection,
                '--queue' => 'default',
                '--tries' => 1,
                '--timeout' => 600,
            ];
            if ($once) {
                $arguments['--once'] = true;
            } else {
                $arguments['--stop-when-empty'] = true;
                $arguments['--max-time'] = 600;
            }

            $exitCode = Artisan::call('queue:work', $arguments);
            if ($exitCode !== 0) {
                throw new RuntimeException('ไม่สามารถเริ่ม background import worker ได้');
            }

            return $exitCode;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
