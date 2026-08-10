<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Imports\DemoImportRegistry;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportSafetyController extends Controller
{
    public function __invoke(Request $request, DemoImportRegistry $registry, LegacyPortalReadService $legacy): JsonResponse
    {
        if (! (bool) config('system_data.enabled')) {
            return response()->json(['data' => $registry->safetyState(), 'meta' => DemoResponseMeta::item()]);
        }

        $districtId = (int) $request->attributes->get('district_id');
        $state = $legacy->safetyState($districtId);
        $writeEnabled = (bool) config('system_data.write_enabled');
        $state['operations'] = [
            ['key' => 'import-write', 'label' => 'นำเข้าข้อมูลใหม่', 'reason' => 'ตรวจไฟล์และสร้างชุดใหม่ให้สำเร็จก่อนสลับใช้งาน', 'state' => $writeEnabled ? 'enabled' : 'disabled'],
            ['key' => 'cleanup-write', 'label' => 'แทนที่ชุดข้อมูลเดิม', 'reason' => 'ลบ batch ตาราง และไฟล์ชุดเก่าอัตโนมัติเฉพาะอำเภอเดียวกัน', 'state' => $writeEnabled ? 'enabled' : 'disabled'],
        ];
        $state['required_controls'] = [
            ['key' => 'district-scope', 'label' => "จำกัดขอบเขตอำเภอ {$districtId}", 'state' => 'ready'],
            ['key' => 'archive-validation', 'label' => 'ตรวจ ZIP, path, symlink, ขนาด และอัตราบีบอัด', 'state' => 'ready'],
            ['key' => 'dbf-schema', 'label' => 'ตรวจโครงสร้าง DBF และแปลง Windows-874 เป็น UTF-8', 'state' => 'ready'],
            ['key' => 'batch-activation', 'label' => 'เปิดชุดใหม่ก่อนลบชุดเดิม เพื่อไม่ให้หน้าเว็บว่างระหว่างนำเข้า', 'state' => 'ready'],
            ['key' => 'district-replacement', 'label' => 'คงชุดข้อมูลใช้งานเพียงชุดเดียวต่ออำเภอ', 'state' => 'ready'],
            ['key' => 'audit-log', 'label' => 'บันทึกผู้ดำเนินการ อำเภอ และผลนำเข้า', 'state' => 'ready'],
        ];

        return response()->json(['data' => $state, 'meta' => [
            'mode' => 'production',
            'source' => 'system_database',
            'read_only' => ! $writeEnabled,
        ]]);
    }
}
