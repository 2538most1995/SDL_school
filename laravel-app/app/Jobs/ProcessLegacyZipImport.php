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
        Cache::put(self::cacheKey($this->jobId), [
            'job_id' => $this->jobId,
            'district_id' => $this->districtId,
            'status' => 'processing',
            'message' => 'กำลังตรวจสอบและนำเข้าข้อมูล',
        ], now()->addDay());

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
            );
            Cache::put(self::cacheKey($this->jobId), [
                'job_id' => $this->jobId,
                'district_id' => $this->districtId,
                'status' => 'completed',
                'message' => 'นำเข้าและเปิดใช้ชุดข้อมูลใหม่เรียบร้อยแล้ว',
                'result' => $result,
            ], now()->addDay());
        } catch (Throwable $exception) {
            report($exception);
            Cache::put(self::cacheKey($this->jobId), [
                'job_id' => $this->jobId,
                'district_id' => $this->districtId,
                'status' => 'failed',
                'message' => 'นำเข้าข้อมูลไม่สำเร็จ กรุณาตรวจรูปแบบ ZIP และไฟล์ DBF แล้วลองใหม่',
            ], now()->addDay());
        } finally {
            Storage::disk('local')->delete($this->stagingPath);
        }
    }

    public static function cacheKey(string $jobId): string
    {
        return 'legacy-import-job:'.$jobId;
    }
}
