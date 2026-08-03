<?php

namespace App\Domain\Learning;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Canonical, non-persistent fixtures for the Laravel migration preview.
 *
 * This class intentionally does not query the legacy database. Its stable IDs and
 * response fields form the API contract that the React/TanStack Query client can
 * use while policies, migrations and legacy-data comparison are being completed.
 */
final class DemoLearningPortal
{
    /**
     * @return list<array<string, mixed>>
     */
    public function assignments(?string $status = null, ?string $search = null): array
    {
        $items = [
            [
                'id' => 'assignment-001',
                'subject_code' => 'ทร21001',
                'subject_name' => 'ทักษะการเรียนรู้',
                'title' => 'แผนที่เป้าหมายการเรียนรู้ของฉัน',
                'teacher_name' => 'ครูพิมพ์ชนก',
                'due_at' => '2026-07-21T23:59:00+07:00',
                'status' => 'pending',
                'submission_status' => 'not_submitted',
                'progress_percent' => 35,
                'points' => 20,
                'accent' => 'violet',
            ],
            [
                'id' => 'assignment-002',
                'subject_code' => 'พต21001',
                'subject_name' => 'ภาษาอังกฤษในชีวิตประจำวัน',
                'title' => 'วิดีโอแนะนำสถานที่สำคัญในชุมชน',
                'teacher_name' => 'ครูวรัญญา',
                'due_at' => '2026-07-25T18:00:00+07:00',
                'status' => 'in_progress',
                'submission_status' => 'draft',
                'progress_percent' => 70,
                'points' => 30,
                'accent' => 'sky',
            ],
            [
                'id' => 'assignment-003',
                'subject_code' => 'สค21003',
                'subject_name' => 'การพัฒนาตนเอง ชุมชน สังคม',
                'title' => 'บันทึกกิจกรรมจิตอาสาในชุมชน',
                'teacher_name' => 'ครูสุเมธ',
                'due_at' => '2026-07-29T23:59:00+07:00',
                'status' => 'pending',
                'submission_status' => 'not_submitted',
                'progress_percent' => 10,
                'points' => 20,
                'accent' => 'orange',
            ],
            [
                'id' => 'assignment-004',
                'subject_code' => 'คณ21001',
                'subject_name' => 'คณิตศาสตร์',
                'title' => 'แบบฝึกหัดร้อยละและสัดส่วน',
                'teacher_name' => 'ครูพิมพ์ชนก',
                'due_at' => '2026-07-12T23:59:00+07:00',
                'status' => 'completed',
                'submission_status' => 'graded',
                'progress_percent' => 100,
                'points' => 15,
                'score' => 13,
                'accent' => 'emerald',
            ],
        ];

        return $this->filter($items, $status, $search, ['title', 'subject_code', 'subject_name', 'teacher_name']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resources(?string $category = null, ?string $search = null): array
    {
        $items = [
            [
                'id' => 'resource-001',
                'title' => 'คู่มือวางแผนการเรียนรายสัปดาห์',
                'description' => 'เทคนิคแบ่งเวลาเรียน ทำงาน และพักผ่อนให้สมดุล',
                'category' => 'คู่มือ',
                'type' => 'pdf',
                'subject_code' => 'ทร21001',
                'duration_minutes' => 12,
                'size_label' => '2.4 MB',
                'published_at' => '2026-07-15T09:00:00+07:00',
                'is_downloadable' => true,
                'accent' => 'violet',
            ],
            [
                'id' => 'resource-002',
                'title' => 'English at the Local Market',
                'description' => 'บทสนทนาสั้นพร้อมคำศัพท์ที่ใช้ได้จริงในชีวิตประจำวัน',
                'category' => 'วิดีโอ',
                'type' => 'video',
                'subject_code' => 'พต21001',
                'duration_minutes' => 18,
                'size_label' => null,
                'published_at' => '2026-07-14T13:30:00+07:00',
                'is_downloadable' => false,
                'accent' => 'sky',
            ],
            [
                'id' => 'resource-003',
                'title' => 'แบบฝึกคิดเลขเร็ว: ร้อยละ',
                'description' => 'แบบฝึกโต้ตอบพร้อมเฉลยและคำอธิบายทีละขั้น',
                'category' => 'แบบฝึกหัด',
                'type' => 'interactive',
                'subject_code' => 'คณ21001',
                'duration_minutes' => 20,
                'size_label' => null,
                'published_at' => '2026-07-10T10:00:00+07:00',
                'is_downloadable' => false,
                'accent' => 'emerald',
            ],
            [
                'id' => 'resource-004',
                'title' => 'เสียงเล่าจากคนทำงานเพื่อชุมชน',
                'description' => 'พอดแคสต์สร้างแรงบันดาลใจจากอาสาสมัครในอำเภอเสนา',
                'category' => 'พอดแคสต์',
                'type' => 'audio',
                'subject_code' => 'สค21003',
                'duration_minutes' => 24,
                'size_label' => '18 MB',
                'published_at' => '2026-07-08T08:00:00+07:00',
                'is_downloadable' => true,
                'accent' => 'orange',
            ],
        ];

        return $this->filter($items, $category, $search, ['title', 'description', 'category', 'subject_code'], 'category');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calendar(?string $type = null): array
    {
        $items = [
            [
                'id' => 'event-001',
                'type' => 'assignment',
                'title' => 'ส่งแผนที่เป้าหมายการเรียนรู้',
                'starts_at' => '2026-07-21T23:59:00+07:00',
                'ends_at' => '2026-07-21T23:59:00+07:00',
                'location' => 'ส่งผ่านระบบ',
                'subject_code' => 'ทร21001',
                'accent' => 'violet',
            ],
            [
                'id' => 'event-002',
                'type' => 'meeting',
                'title' => 'พบกลุ่มประจำสัปดาห์',
                'starts_at' => '2026-07-24T09:00:00+07:00',
                'ends_at' => '2026-07-24T12:00:00+07:00',
                'location' => 'ห้องเรียนรู้ 2',
                'subject_code' => null,
                'accent' => 'sky',
            ],
            [
                'id' => 'event-003',
                'type' => 'exam',
                'title' => 'สอบกลางภาค กลุ่มสาระพื้นฐาน',
                'starts_at' => '2026-07-27T09:00:00+07:00',
                'ends_at' => '2026-07-27T12:00:00+07:00',
                'location' => 'อาคาร 2 ห้อง 204',
                'subject_code' => null,
                'accent' => 'rose',
            ],
            [
                'id' => 'event-004',
                'type' => 'activity',
                'title' => 'กิจกรรมปลูกต้นไม้ริมคลอง',
                'starts_at' => '2026-07-30T08:00:00+07:00',
                'ends_at' => '2026-07-30T11:30:00+07:00',
                'location' => 'ชุมชนบ้านแพน',
                'subject_code' => 'สค21003',
                'accent' => 'emerald',
            ],
        ];

        return $type === null
            ? $items
            : array_values(array_filter($items, fn (array $item): bool => $item['type'] === $type));
    }

    /**
     * @return array<string, mixed>
     */
    public function scores(): array
    {
        return [
            'term' => '2/2568',
            'summary' => [
                'grade_point_average' => 3.24,
                'earned_credits' => 46,
                'target_credits' => 76,
                'completed_courses' => 12,
            ],
            'courses' => [
                [
                    'id' => 'score-001',
                    'subject_code' => 'ทร21001',
                    'subject_name' => 'ทักษะการเรียนรู้',
                    'credits' => 5,
                    'assignment_score' => 32,
                    'exam_score' => 45,
                    'total_score' => 77,
                    'grade' => '3.5',
                    'status' => 'passed',
                ],
                [
                    'id' => 'score-002',
                    'subject_code' => 'พต21001',
                    'subject_name' => 'ภาษาอังกฤษในชีวิตประจำวัน',
                    'credits' => 4,
                    'assignment_score' => 34,
                    'exam_score' => 38,
                    'total_score' => 72,
                    'grade' => '3',
                    'status' => 'passed',
                ],
                [
                    'id' => 'score-003',
                    'subject_code' => 'คณ21001',
                    'subject_name' => 'คณิตศาสตร์',
                    'credits' => 4,
                    'assignment_score' => 35,
                    'exam_score' => 46,
                    'total_score' => 81,
                    'grade' => '4',
                    'status' => 'passed',
                ],
                [
                    'id' => 'score-004',
                    'subject_code' => 'สค21003',
                    'subject_name' => 'การพัฒนาตนเอง ชุมชน สังคม',
                    'credits' => 3,
                    'assignment_score' => 28,
                    'exam_score' => null,
                    'total_score' => null,
                    'grade' => null,
                    'status' => 'studying',
                ],
            ],
            'disclaimer' => 'ข้อมูลสาธิตสำหรับตรวจรูปแบบหน้าจอ ไม่ใช่ผลการเรียนจริง',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function users(?string $role = null, ?string $search = null): array
    {
        $items = [
            [
                'id' => 'demo-user-001',
                'display_name' => 'มะลิ ใจดี',
                'username' => 'student_demo_001',
                'role' => 'student',
                'district_id' => 'demo-district-sena',
                'district_name' => 'อำเภอเสนา',
                'group' => 'มัธยมศึกษาตอนต้น กศน.ตำบลเสนา',
                'status' => 'active',
                'last_seen_at' => '2026-07-17T08:42:00+07:00',
            ],
            [
                'id' => 'demo-user-002',
                'display_name' => 'นนท์ พร้อมเรียน',
                'username' => 'student_demo_002',
                'role' => 'student',
                'district_id' => 'demo-district-sena',
                'district_name' => 'อำเภอเสนา',
                'group' => 'มัธยมศึกษาตอนปลาย กศน.ตำบลบ้านแพน',
                'status' => 'active',
                'last_seen_at' => '2026-07-16T19:15:00+07:00',
            ],
            [
                'id' => 'demo-user-003',
                'display_name' => 'ครูพิมพ์ชนก สอนดี',
                'username' => 'teacher_demo_001',
                'role' => 'teacher',
                'district_id' => 'demo-district-sena',
                'district_name' => 'อำเภอเสนา',
                'group' => 'กลุ่มสาระพื้นฐาน',
                'status' => 'active',
                'last_seen_at' => '2026-07-17T07:55:00+07:00',
            ],
            [
                'id' => 'demo-user-004',
                'display_name' => 'ผู้ดูแลระบบตัวอย่าง',
                'username' => 'admin_demo_001',
                'role' => 'admin',
                'district_id' => 'demo-district-sena',
                'district_name' => 'อำเภอเสนา',
                'group' => null,
                'status' => 'active',
                'last_seen_at' => '2026-07-17T08:10:00+07:00',
            ],
        ];

        return $this->filter($items, $role, $search, ['display_name', 'username', 'role', 'group'], 'role');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function examRooms(?string $date = null): array
    {
        $items = [
            [
                'id' => 'exam-room-001',
                'exam_date' => '2026-07-27',
                'session' => 'morning',
                'starts_at' => '09:00',
                'ends_at' => '12:00',
                'building' => 'อาคาร 2',
                'room' => '204',
                'level' => 'มัธยมศึกษาตอนต้น',
                'seat_capacity' => 30,
                'assigned_seats' => 26,
                'proctors' => ['ครูพิมพ์ชนก', 'ครูสุเมธ'],
                'status' => 'ready',
            ],
            [
                'id' => 'exam-room-002',
                'exam_date' => '2026-07-27',
                'session' => 'morning',
                'starts_at' => '09:00',
                'ends_at' => '12:00',
                'building' => 'อาคาร 2',
                'room' => '205',
                'level' => 'มัธยมศึกษาตอนปลาย',
                'seat_capacity' => 35,
                'assigned_seats' => 31,
                'proctors' => ['ครูวรัญญา', 'ครูประทีป'],
                'status' => 'ready',
            ],
            [
                'id' => 'exam-room-003',
                'exam_date' => '2026-07-28',
                'session' => 'afternoon',
                'starts_at' => '13:00',
                'ends_at' => '16:00',
                'building' => 'อาคาร 1',
                'room' => 'ประชุมใหญ่',
                'level' => 'ประถมศึกษา',
                'seat_capacity' => 24,
                'assigned_seats' => 18,
                'proctors' => ['ครูสุเมธ'],
                'status' => 'needs_review',
            ],
        ];

        return $date === null
            ? $items
            : array_values(array_filter($items, fn (array $item): bool => $item['exam_date'] === $date));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $searchableFields
     * @return list<array<string, mixed>>
     */
    private function filter(
        array $items,
        ?string $exact,
        ?string $search,
        array $searchableFields,
        string $exactField = 'status',
    ): array {
        return array_values(array_filter($items, function (array $item) use ($exact, $search, $searchableFields, $exactField): bool {
            if ($exact !== null && Arr::get($item, $exactField) !== $exact) {
                return false;
            }

            if ($search === null || trim($search) === '') {
                return true;
            }

            $haystack = collect($searchableFields)
                ->map(fn (string $field): string => (string) Arr::get($item, $field, ''))
                ->implode(' ');

            return Str::contains(Str::lower($haystack), Str::lower(trim($search)));
        }));
    }
}
