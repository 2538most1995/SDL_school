<?php

namespace App\Domain\Imports;

use Illuminate\Support\Str;

/**
 * Read-only import registry used by the migration preview.
 *
 * No filename, table name or operation from this class is passed to a database,
 * filesystem or shell command. Production import work must use district-scoped
 * policies, staging storage and an independently reviewed activation service.
 */
final class DemoImportRegistry
{
    /**
     * @return list<array<string, mixed>>
     */
    public function batches(?string $status = null, ?string $search = null): array
    {
        $items = [
            [
                'id' => 'demo-import-003',
                'batch_key' => 'DEMO-SENA-2568-02-R03',
                'district_id' => 'demo-district-sena',
                'district_name' => 'อำเภอเสนา',
                'academic_term' => '2/2568',
                'source_filename' => 'itw51_demo_2568_02.zip',
                'status' => 'validated',
                'row_count' => 1842,
                'table_count' => 12,
                'warning_count' => 2,
                'created_at' => '2026-07-17T08:20:00+07:00',
                'activated_at' => null,
                'is_active' => false,
            ],
            [
                'id' => 'demo-import-002',
                'batch_key' => 'DEMO-SENA-2568-02-R02',
                'district_id' => 'demo-district-sena',
                'district_name' => 'อำเภอเสนา',
                'academic_term' => '2/2568',
                'source_filename' => 'itw51_demo_2568_02_revision2.zip',
                'status' => 'active',
                'row_count' => 1829,
                'table_count' => 12,
                'warning_count' => 0,
                'created_at' => '2026-07-10T10:15:00+07:00',
                'activated_at' => '2026-07-10T10:32:00+07:00',
                'is_active' => true,
            ],
            [
                'id' => 'demo-import-001',
                'batch_key' => 'DEMO-SENA-2568-01-R01',
                'district_id' => 'demo-district-sena',
                'district_name' => 'อำเภอเสนา',
                'academic_term' => '1/2568',
                'source_filename' => 'itw51_demo_2568_01.zip',
                'status' => 'archived',
                'row_count' => 1764,
                'table_count' => 12,
                'warning_count' => 1,
                'created_at' => '2026-05-02T09:00:00+07:00',
                'activated_at' => '2026-05-02T09:22:00+07:00',
                'is_active' => false,
            ],
        ];

        return array_values(array_filter($items, function (array $item) use ($status, $search): bool {
            if ($status !== null && $item['status'] !== $status) {
                return false;
            }

            if ($search === null || trim($search) === '') {
                return true;
            }

            $haystack = implode(' ', [
                $item['batch_key'],
                $item['district_name'],
                $item['academic_term'],
                $item['source_filename'],
            ]);

            return Str::contains(Str::lower($haystack), Str::lower(trim($search)));
        }));
    }

    /**
     * @return array<string, mixed>
     */
    public function safetyState(): array
    {
        return [
            'mode' => 'demo',
            'overall_state' => 'locked',
            'system_database' => [
                'connected' => false,
                'reads_enabled' => false,
                'writes_enabled' => false,
                'label' => 'ยังไม่เชื่อมฐานข้อมูลจริง',
            ],
            'operations' => [
                [
                    'key' => 'upload',
                    'state' => 'disabled',
                    'label' => 'อัปโหลด ZIP/DBF',
                    'reason' => 'รอพื้นที่ staging และการตรวจชนิดไฟล์ ขนาดไฟล์ และ Zip Slip',
                ],
                [
                    'key' => 'validate',
                    'state' => 'preview_only',
                    'label' => 'ตรวจสอบโครงสร้าง',
                    'reason' => 'แสดงผลข้อมูลสาธิตโดยไม่อ่านไฟล์จริง',
                ],
                [
                    'key' => 'activate',
                    'state' => 'disabled',
                    'label' => 'เปิดใช้งาน batch',
                    'reason' => 'รอ policy ระดับ role/district และ transaction สำหรับสลับ batch',
                ],
                [
                    'key' => 'delete',
                    'state' => 'disabled',
                    'label' => 'ลบ batch',
                    'reason' => 'ห้ามลบข้อมูลจริงจากหน้า preview',
                ],
                [
                    'key' => 'sync_exam_rooms',
                    'state' => 'disabled',
                    'label' => 'ซิงก์ห้องสอบ',
                    'reason' => 'รอการเทียบผลกับ batch และอำเภอเป้าหมาย',
                ],
            ],
            'required_controls' => [
                [
                    'key' => 'district_scope',
                    'label' => 'จำกัด batch ตามอำเภอ',
                    'state' => 'required',
                ],
                [
                    'key' => 'archive_validation',
                    'label' => 'ตรวจ Zip Slip, extension และขนาดไฟล์',
                    'state' => 'required',
                ],
                [
                    'key' => 'dbf_schema',
                    'label' => 'ตรวจคอลัมน์ DBF และ encoding',
                    'state' => 'required',
                ],
                [
                    'key' => 'atomic_activation',
                    'label' => 'สลับ active batch แบบ transaction',
                    'state' => 'required',
                ],
                [
                    'key' => 'audit_log',
                    'label' => 'บันทึกผู้ดำเนินการและผลตรวจ',
                    'state' => 'required',
                ],
            ],
            'upload_policy_preview' => [
                'allowed_archive_types' => ['zip'],
                'allowed_payload_types' => ['dbf'],
                'maximum_archive_megabytes' => 50,
                'maximum_uncompressed_megabytes' => 250,
                'maximum_file_count' => 100,
            ],
            'guarantees' => [
                'active_batch_will_not_change' => true,
                'real_files_will_not_be_read' => true,
                'real_tables_will_not_be_created_or_dropped' => true,
                'personal_data_is_not_included' => true,
            ],
        ];
    }
}
