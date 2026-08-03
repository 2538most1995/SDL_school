<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class PortalDemoController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'mode' => 'demo',
                'viewer' => [
                    'name' => 'นักศึกษาตัวอย่าง',
                    'role' => 'student',
                    'district' => 'อำเภอเสนา',
                ],
                'summary' => [
                    ['label' => 'งานที่ต้องส่ง', 'value' => '3', 'hint' => 'ภายใน 7 วัน'],
                    ['label' => 'หน่วยกิตสะสม', 'value' => '46', 'hint' => 'จากเป้าหมาย 76 หน่วยกิต'],
                    ['label' => 'กพช. สะสม', 'value' => '128', 'hint' => 'ชั่วโมง'],
                    ['label' => 'ผลการเรียนล่าสุด', 'value' => '3.24', 'hint' => 'ภาคเรียน 2/2568'],
                ],
                'upcoming' => [
                    ['id' => 1, 'day' => '21', 'month' => 'ก.ค.', 'title' => 'ส่งงานวิชาทักษะการเรียนรู้', 'meta' => 'ก่อน 23:59 น.'],
                    ['id' => 2, 'day' => '24', 'month' => 'ก.ค.', 'title' => 'พบกลุ่มประจำสัปดาห์', 'meta' => '09:00 - 12:00 น.'],
                    ['id' => 3, 'day' => '27', 'month' => 'ก.ค.', 'title' => 'สอบกลางภาค', 'meta' => 'ห้องสอบ 204'],
                ],
                'modules' => [
                    ['name' => 'ข้อมูลนักศึกษา', 'status' => 'foundation', 'route' => '/students'],
                    ['name' => 'ผลการเรียนและรายงาน', 'status' => 'mapped', 'route' => '/grades'],
                    ['name' => 'พอร์ทัลการเรียนรู้', 'status' => 'mapped', 'route' => '/learning'],
                    ['name' => 'นำเข้าข้อมูล DBF', 'status' => 'security-review', 'route' => '/admin/imports'],
                ],
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'contains_personal_data' => false,
            ],
        ]);
    }
}
