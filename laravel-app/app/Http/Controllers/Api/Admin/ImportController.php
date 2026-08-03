<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Imports\DemoImportRegistry;
use App\Domain\Learning\DemoQueryRules;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessLegacyZipImport;
use App\Jobs\RunLegacyImportQueueOnce;
use App\Services\Legacy\LegacyZipImportService;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class ImportController extends Controller
{
    public function __invoke(Request $request, DemoImportRegistry $registry, LegacyPortalReadService $legacy): JsonResponse
    {
        return $this->index($request, $registry, $legacy);
    }

    public function index(Request $request, DemoImportRegistry $registry, LegacyPortalReadService $legacy): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['validated', 'active', 'archived', 'failed'])],
            'search' => DemoQueryRules::search(),
        ]);

        $items = config('legacy.enabled')
            ? $legacy->imports((int) $request->attributes->get('district_id'), $filters['status'] ?? null, $filters['search'] ?? null)
            : $registry->batches($filters['status'] ?? null, $filters['search'] ?? null);
        $writeEnabled = (bool) config('legacy.enabled') && (bool) config('legacy.write_enabled');

        return response()->json([
            'data' => $items,
            'meta' => [
                ...(config('legacy.enabled')
                    ? ['mode' => 'production', 'source' => 'legacy_controlled_write', 'read_only' => ! $writeEnabled, 'pagination' => ['page' => 1, 'per_page' => count($items), 'total' => count($items), 'last_page' => 1], 'filters' => $filters]
                    : DemoResponseMeta::collection(count($items), $filters)),
                'legacy_database_connected' => (bool) config('legacy.enabled'),
                'write_operations_enabled' => $writeEnabled,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless((bool) config('legacy.enabled') && (bool) config('legacy.write_enabled'), 503, 'ระบบนำเข้าข้อมูลจริงยังไม่เปิดใช้งาน');
        $validated = $request->validate([
            'archive' => ['required', File::types(['zip'])->max(90 * 1024)],
            'academic_term' => ['required', 'string', 'max:20', 'regex:/^(?:[12]\/25\d{2}|25\d{2}\/[12])$/'],
        ], [
            'archive.required' => 'กรุณาเลือกไฟล์ ZIP',
            'archive.mimes' => 'อนุญาตเฉพาะไฟล์ ZIP',
            'academic_term.regex' => 'กรุณาระบุภาคเรียน เช่น 1/2569',
        ]);

        $districtId = (int) $request->attributes->get('district_id');
        $jobId = (string) Str::uuid();
        $archive = $validated['archive'];
        $stagingPath = $archive->storeAs("import-queue/{$districtId}", $jobId.'.zip', 'local');
        abort_if($stagingPath === false, 500, 'ไม่สามารถบันทึกไฟล์รอนำเข้าได้');
        $status = [
            'job_id' => $jobId,
            'district_id' => $districtId,
            'status' => 'queued',
            'message' => 'รับไฟล์แล้วและกำลังเริ่มนำเข้าข้อมูล',
            'progress' => 0,
        ];
        Cache::put(ProcessLegacyZipImport::cacheKey($jobId), $status, now()->addDay());

        $queueConnection = (string) config('legacy.import_queue_connection', 'database');
        ProcessLegacyZipImport::dispatch(
            $jobId,
            $stagingPath,
            basename($archive->getClientOriginalName()),
            (string) $validated['academic_term'],
            $districtId,
            (int) $request->user()->id,
            $request->ip(),
        )->onConnection($queueConnection);
        RunLegacyImportQueueOnce::dispatch($queueConnection)
            ->onConnection((string) config('legacy.import_autostart_connection', 'background'));

        return response()->json(['data' => $status, 'meta' => [
            'source' => 'legacy_controlled_write',
            'read_only' => false,
        ]], 202);
    }

    public function status(Request $request, string $job): JsonResponse
    {
        abort_unless(Str::isUuid($job), 404);
        $status = Cache::get(ProcessLegacyZipImport::cacheKey($job));
        abort_unless(is_array($status), 404, 'ไม่พบสถานะงานนำเข้า');
        abort_unless((int) ($status['district_id'] ?? 0) === (int) $request->attributes->get('district_id'), 403);

        return response()->json(['data' => $status, 'meta' => [
            'source' => 'legacy_controlled_write',
            'read_only' => false,
        ]]);
    }

    public function destroy(Request $request, string $batch, LegacyZipImportService $importer): JsonResponse
    {
        abort_unless((bool) config('legacy.enabled') && (bool) config('legacy.write_enabled'), 503, 'ระบบลบข้อมูลจริงยังไม่เปิดใช้งาน');
        $districtId = (int) $request->attributes->get('district_id');

        try {
            $result = $importer->deleteDistrictBatch($districtId, $batch);
        } catch (InvalidArgumentException $exception) {
            abort(404, $exception->getMessage());
        }

        try {
            DB::table('audit_logs')->insert([
                'user_id' => (int) $request->user()->id,
                'district_id' => $districtId,
                'event' => 'admin.import.deleted',
                'auditable_type' => 'legacy_import_batch',
                'auditable_id' => null,
                'ip_address' => $request->ip(),
                'before' => json_encode(['batch_key' => $batch], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'after' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        return response()->json(['data' => ['deleted' => true, ...$result], 'meta' => [
            'source' => 'legacy_controlled_write',
            'read_only' => false,
        ]]);
    }
}
