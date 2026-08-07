<?php

namespace App\Jobs;

use App\Services\Legacy\LegacyZipImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProcessLegacyZipImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $jobId,
        public readonly string $stagingPath,
        public readonly string $originalName,
        public readonly string $academicTerm,
        public readonly int $districtId,
        public readonly int $userId,
        public readonly ?string $ipAddress,
    ) {}

    public function handle(LegacyZipImportService $importer): void
    {
        $this->updateStatus('processing', 'กำลังตรวจสอบไฟล์ ZIP', 2);

        try {
            $absolutePath = Storage::disk('local')->path($this->stagingPath);
            if (! is_file($absolutePath)) {
                throw new \RuntimeException('ไม่พบไฟล์รอนำเข้า');
            }
            $upload = new UploadedFile($absolutePath, $this->originalName, 'application/zip', null, true);
            $result = $importer->import(
                $upload,
                $this->academicTerm,
                $this->districtId,
                $this->userId,
                $this->ipAddress,
                fn (string $message, int $progress, array $metrics = []) => $this->updateStatus('processing', $message, $progress, $metrics),
            );
            $this->updateStatus('completed', 'นำเข้าและเปิดใช้ชุดข้อมูลใหม่เรียบร้อยแล้ว', 100, ['result' => $result]);
        } catch (Throwable $exception) {
            report($exception);
            $msg = 'นำเข้าข้อมูลไม่สำเร็จ: '.$exception->getMessage();
            $this->updateStatus('failed', $msg, 100);
        } finally {
            Storage::disk('local')->delete($this->stagingPath);
        }
    }

    /** @param array<string, mixed> $extra */
    private function updateStatus(string $status, string $message, int $progress, array $extra = []): void
    {
        Cache::put(self::cacheKey($this->jobId), [
            'job_id' => $this->jobId,
            'district_id' => $this->districtId,
            'status' => $status,
            'message' => $message,
            'progress' => max(0, min(100, $progress)),
            ...$extra,
        ], now()->addDay());
    }

    public static function cacheKey(string $jobId): string
    {
        return 'legacy-import-job:'.$jobId;
    }
}
