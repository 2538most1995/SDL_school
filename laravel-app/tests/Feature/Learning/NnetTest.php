<?php

namespace Tests\Feature\Learning;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\NnetResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NnetTest extends TestCase
{
    use RefreshDatabase;

    private District $district;
    private User $teacher;
    private User $admin;
    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->district = District::create([
            'name' => 'อำเภอเสนา',
            'code' => 'sena',
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => ['1260096'],
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'district_id' => $this->district->id,
        ]);

        $this->studentUser = User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'username' => '1100400123456',
        ]);
    }

    public function test_can_bulk_import_nnet_results_and_match_student(): void
    {
        Sanctum::actingAs($this->teacher);

        // Bind mock student repository to return a student with citizenId
        $mockStudent = new Student(
            code: '6650100001',
            districtId: $this->district->id,
            districtName: 'อำเภอเสนา',
            prefix: 'นางสาว',
            firstName: 'สมศรี',
            lastName: 'เรียนดี',
            level: 2,
            levelLabel: 'มัธยมศึกษาตอนต้น',
            groupCode: '1260096',
            groupName: 'เสนา กลุ่ม 1',
            enrollmentTerm: '1/2566',
            currentTerm: '1/2569',
            status: '1',
            statusLabel: 'กำลังศึกษา',
            gpax: 3.25,
            creditsEarned: 36.0,
            creditsRequired: 56.0,
            kpchHours: 200.0,
            moralResult: 'ผ่าน',
            citizenId: '1100400123456',
        );

        $this->app->bind(StudentRepository::class, function () use ($mockStudent) {
            $repo = $this->createMock(StudentRepository::class);
            $repo->method('students')->willReturn([$mockStudent]);
            return $repo;
        });

        $payload = [
            'education_level' => 2,
            'academic_year' => '2569',
            'round' => 1,
            'subject_codes' => ['411', '412', '413', '414', '415'],
            'subject_names' => [
                '411' => 'ทักษะการเรียนรู้',
                '412' => 'ความรู้พื้นฐาน',
                '413' => 'การประกอบอาชีพ',
                '414' => 'ทักษะการดำเนินชีวิต',
                '415' => 'การพัฒนาสังคม',
            ],
            'rows' => [
                [
                    'seat_no' => '14001471',
                    'citizen_id' => '1100400123456',
                    'name' => 'นางสาวสมศรี เรียนดี',
                    'total_score' => 44.00,
                    'scores' => [46.67, 28.57, 55.00, 60.00, 46.67],
                    'levels' => ['ดี', 'พอใช้', 'ดีมาก', 'ดีมาก', 'ดี'],
                ],
                [
                    'seat_no' => '14001479',
                    'citizen_id' => '1100400999999',
                    'name' => 'นายสมชาย ขาดสอบ',
                    'total_score' => '-',
                    'scores' => ['-', '-', '-', '-', '-'],
                    'levels' => ['-', '-', '-', '-', '-'],
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/nnet/import', $payload)
            ->assertOk()
            ->assertJsonPath('data.total_processed', 2)
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.matched_students', 1);

        $this->assertDatabaseHas('nnet_results', [
            'district_id' => $this->district->id,
            'citizen_id' => '1100400123456',
            'student_code' => '6650100001',
            'has_score' => true,
            'total_score' => 44.00,
        ]);

        $this->assertDatabaseHas('nnet_results', [
            'district_id' => $this->district->id,
            'citizen_id' => '1100400999999',
            'has_score' => false,
            'total_score' => null,
        ]);
    }

    public function test_can_list_records_and_filter(): void
    {
        Sanctum::actingAs($this->teacher);

        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'education_level_label' => 'มัธยมศึกษาตอนต้น',
            'seat_no' => '14001471',
            'citizen_id' => '1100400123456',
            'student_code' => '6650100001',
            'student_name' => 'นางสาวสมศรี เรียนดี',
            'total_score' => 45.50,
            'has_score' => true,
        ]);

        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 3,
            'education_level_label' => 'มัธยมศึกษาตอนปลาย',
            'seat_no' => '14002000',
            'citizen_id' => '1100400222222',
            'student_name' => 'นายสมศักดิ์ เรียนต่อ',
            'total_score' => 60.00,
            'has_score' => true,
        ]);

        // Filter by level 2
        $response = $this->getJson('/api/v1/nnet/records?education_level=2')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.student_name', 'นางสาวสมศรี เรียนดี');

        // Search by keyword
        $this->getJson('/api/v1/nnet/records?search=' . urlencode('สมศักดิ์'))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.student_name', 'นายสมศักดิ์ เรียนต่อ');
    }

    public function test_summary_calculates_averages_and_kpis(): void
    {
        Sanctum::actingAs($this->teacher);

        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'citizen_id' => '1100400111111',
            'student_name' => 'คนที่ 1',
            'total_score' => 40.00,
            'has_score' => true,
            'subject_codes' => ['411', '412'],
            'subject_scores' => ['411' => 50.00, '412' => 30.00],
        ]);

        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'citizen_id' => '1100400222222',
            'student_name' => 'คนที่ 2',
            'total_score' => 60.00,
            'has_score' => true,
            'subject_codes' => ['411', '412'],
            'subject_scores' => ['411' => 70.00, '412' => 50.00],
        ]);

        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'citizen_id' => '1100400333333',
            'student_name' => 'คนขาดสอบ',
            'total_score' => null,
            'has_score' => false,
        ]);

        $response = $this->getJson('/api/v1/nnet/summary?education_level=2&academic_year=2569&round=1')
            ->assertOk()
            ->assertJsonPath('data.total_students', 3)
            ->assertJsonPath('data.scored_students', 2)
            ->assertJsonPath('data.absent_students', 1);

        $this->assertEquals(50.00, $response->json('data.average_total_score'));
        $this->assertEquals(60.00, $response->json('data.max_total_score'));
        $this->assertEquals(40.00, $response->json('data.min_total_score'));
        $this->assertEquals(70.00, $response->json('data.max_subject_score'));

        $subjects = $response->json('data.subjects');
        $this->assertNotEmpty($subjects);
        $this->assertSame('411', $subjects[0]['code']);
        $this->assertEquals(60.00, $subjects[0]['average']);
    }

    public function test_crud_endpoints_store_show_update_destroy(): void
    {
        Sanctum::actingAs($this->admin);

        // CREATE
        $createPayload = [
            'education_level' => 2,
            'academic_year' => '2569',
            'round' => 1,
            'citizen_id' => '1100400555555',
            'seat_no' => '14001999',
            'student_name' => 'นายทดสอบ บันทึกเดี่ยว',
            'has_score' => true,
            'total_score' => 52.50,
            'subject_scores' => ['411' => 55.00, '412' => 50.00],
        ];

        $storeRes = $this->postJson('/api/v1/nnet/records', $createPayload)
            ->assertCreated()
            ->assertJsonPath('data.student_name', 'นายทดสอบ บันทึกเดี่ยว');

        $recordId = $storeRes->json('data.id');

        // READ (SHOW)
        $this->getJson("/api/v1/nnet/records/{$recordId}")
            ->assertOk()
            ->assertJsonPath('data.citizen_id', '1100400555555');

        // UPDATE
        $updateRes = $this->putJson("/api/v1/nnet/records/{$recordId}", [
            'student_name' => 'นายทดสอบ แก้ไขแล้ว',
            'total_score' => 65.00,
        ])
            ->assertOk()
            ->assertJsonPath('data.student_name', 'นายทดสอบ แก้ไขแล้ว');

        $this->assertEquals(65.00, $updateRes->json('data.total_score'));

        // DELETE
        $this->deleteJson("/api/v1/nnet/records/{$recordId}")
            ->assertOk()
            ->assertJsonPath('message', 'ลบข้อมูลผลสอบเรียบร้อยแล้ว');

        $this->assertDatabaseMissing('nnet_results', ['id' => $recordId]);
    }

    public function test_clear_records_removes_matching_data(): void
    {
        Sanctum::actingAs($this->admin);

        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 1,
            'citizen_id' => '1100400111111',
            'student_name' => 'ประถม คนที่ 1',
        ]);

        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'citizen_id' => '1100400222222',
            'student_name' => 'ม.ต้น คนที่ 1',
        ]);

        $this->postJson('/api/v1/nnet/clear', [
            'education_level' => 1,
            'academic_year' => '2569',
            'round' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('deleted_count', 1);

        $this->assertDatabaseMissing('nnet_results', ['citizen_id' => '1100400111111']);
        $this->assertDatabaseHas('nnet_results', ['citizen_id' => '1100400222222']);
    }

    public function test_bulk_import_pads_citizen_id_with_leading_zeros_and_matches_student(): void
    {
        Sanctum::actingAs($this->teacher);

        // Student with leading zeroes in citizen ID
        $mockStudent = new Student(
            code: '6650100999',
            districtId: $this->district->id,
            districtName: 'อำเภอเสนา',
            prefix: 'นาย',
            firstName: 'พงศกร',
            lastName: 'ใฝ่รู้',
            level: 2,
            levelLabel: 'มัธยมศึกษาตอนต้น',
            groupCode: '1260096',
            groupName: 'กลุ่มเสนา 1',
            enrollmentTerm: '1/2566',
            currentTerm: '1/2569',
            status: '1',
            statusLabel: 'กำลังศึกษา',
            gpax: 3.50,
            creditsEarned: 40.0,
            creditsRequired: 56.0,
            kpchHours: 200.0,
            moralResult: 'ผ่าน',
            citizenId: '0014011023914',
        );

        $this->app->bind(StudentRepository::class, function () use ($mockStudent) {
            $repo = $this->createMock(StudentRepository::class);
            $repo->method('students')->willReturn([$mockStudent]);
            return $repo;
        });

        // Payload with 11-digit citizen ID (Excel trimmed leading 00)
        $payload = [
            'education_level' => 2,
            'academic_year' => '2569',
            'round' => 1,
            'rows' => [
                [
                    'seat_no' => '14001001',
                    'citizen_id' => '14011023914', // 11 digits
                    'name' => 'นายพงศกร ใฝ่รู้',
                    'total_score' => 50.00,
                    'scores' => [50, 50, 50, 50, 50],
                    'levels' => ['ดี', 'ดี', 'ดี', 'ดี', 'ดี'],
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/nnet/import', $payload)
            ->assertOk()
            ->assertJsonPath('data.total_processed', 1)
            ->assertJsonPath('data.matched_students', 1);

        $this->assertDatabaseHas('nnet_results', [
            'district_id' => $this->district->id,
            'citizen_id' => '0014011023914',
            'student_code' => '6650100999',
            'group_name' => 'กลุ่มเสนา 1',
            'total_score' => 50.00,
        ]);
    }

    public function test_can_filter_records_and_summary_by_group(): void
    {
        Sanctum::actingAs($this->teacher);

        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'seat_no' => '14001001',
            'citizen_id' => '0014011023914',
            'student_name' => 'นายกนก กลุ่ม ก',
            'group_code' => 'GRP-A',
            'group_name' => 'กลุ่ม ก',
            'total_score' => 60.00,
            'has_score' => true,
        ]);

        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'seat_no' => '14001002',
            'citizen_id' => '0014011023915',
            'student_name' => 'นายขจร กลุ่ม ข',
            'group_code' => 'GRP-B',
            'group_name' => 'กลุ่ม ข',
            'total_score' => 40.00,
            'has_score' => true,
        ]);

        // Filter records by group
        $this->getJson('/api/v1/nnet/records?group=' . urlencode('กลุ่ม ก'))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.student_name', 'นายกนก กลุ่ม ก');

        // Filter summary by group
        $sumRes = $this->getJson('/api/v1/nnet/summary?education_level=2&academic_year=2569&round=1&group=' . urlencode('กลุ่ม ก'))
            ->assertOk()
            ->assertJsonPath('data.total_students', 1);
        $this->assertEquals(60.0, $sumRes->json('data.average_total_score'));
    }

    public function test_create_record_pads_citizen_id(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'education_level' => 2,
            'academic_year' => '2569',
            'round' => 1,
            'citizen_id' => '14011023914', // 11 digits
            'student_name' => 'นายทดสอบ เติมศูนย์',
            'has_score' => true,
            'total_score' => 45.00,
        ];

        $this->postJson('/api/v1/nnet/records', $payload)
            ->assertCreated()
            ->assertJsonPath('data.citizen_id', '0014011023914');

        $this->assertDatabaseHas('nnet_results', [
            'district_id' => $this->district->id,
            'citizen_id' => '0014011023914',
            'student_name' => 'นายทดสอบ เติมศูนย์',
        ]);
    }

    public function test_summary_calculates_overall_average_by_averaging_subject_averages(): void
    {
        Sanctum::actingAs($this->teacher);

        // Subject averages matching user screenshot:
        // 411: 48.57, 412: 48.77, 413: 59.76, 414: 58.73, 415: 59.36
        // Sum = 275.19 / 5 = 55.038 -> 55.04
        // Individual total_score is 54.02 (old method). The new method must return 55.04!
        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'citizen_id' => '1100400100001',
            'student_name' => 'นักศึกษา คนที่ 1',
            'total_score' => 54.02,
            'has_score' => true,
            'subject_codes' => ['411', '412', '413', '414', '415'],
            'subject_scores' => [
                '411' => 48.57,
                '412' => 48.77,
                '413' => 59.76,
                '414' => 58.73,
                '415' => 59.36,
            ],
        ]);

        $response = $this->getJson('/api/v1/nnet/summary?education_level=2&academic_year=2569&round=1')
            ->assertOk()
            ->assertJsonPath('data.total_students', 1);

        $this->assertEquals(55.04, $response->json('data.average_total_score'));
    }

    public function test_summary_calculates_all_levels_by_averaging_across_levels(): void
    {
        Sanctum::actingAs($this->teacher);

        // Level 1: ประถมศึกษา (Primary)
        // Subject 0 (ทักษะการเรียนรู้) = 40.0, Overall avg = 60.0
        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 1,
            'citizen_id' => '1100400100011',
            'student_name' => 'เด็กชายประถม',
            'total_score' => 60.0,
            'has_score' => true,
            'subject_codes' => ['101', '102', '103', '104', '105'],
            'subject_scores' => [
                '101' => 40.0,
                '102' => 50.0,
                '103' => 60.0,
                '104' => 70.0,
                '105' => 80.0,
            ],
        ]);

        // Level 2: มัธยมศึกษาตอนต้น (Lower Secondary)
        // Subject 0 = 50.0, Overall avg = 70.0
        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'citizen_id' => '1100400100022',
            'student_name' => 'นายมัธยมต้น',
            'total_score' => 70.0,
            'has_score' => true,
            'subject_codes' => ['201', '202', '203', '204', '205'],
            'subject_scores' => [
                '201' => 50.0,
                '202' => 60.0,
                '203' => 70.0,
                '204' => 80.0,
                '205' => 90.0,
            ],
        ]);

        // Level 3: มัธยมศึกษาตอนปลาย (Upper Secondary)
        // Subject 0 = 60.0, Overall avg = 80.0
        NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 3,
            'citizen_id' => '1100400100033',
            'student_name' => 'นางสาวมัธยมปลาย',
            'total_score' => 80.0,
            'has_score' => true,
            'subject_codes' => ['401', '402', '403', '404', '405'],
            'subject_scores' => [
                '401' => 60.0,
                '402' => 70.0,
                '403' => 80.0,
                '404' => 90.0,
                '405' => 100.0,
            ],
        ]);

        // Query "ทุกระดับชั้น" (no education_level filter)
        $response = $this->getJson('/api/v1/nnet/summary?academic_year=2569&round=1')
            ->assertOk()
            ->assertJsonPath('data.total_students', 3)
            ->assertJsonPath('data.scored_students', 3);

        // Subject 0 (ทักษะการเรียนรู้) = (40 + 50 + 60) / 3 = 50.00
        $subjects = $response->json('data.subjects');
        $this->assertCount(5, $subjects);
        $this->assertEquals('ทักษะการเรียนรู้', $subjects[0]['name']);
        $this->assertEquals(50.00, $subjects[0]['average']);

        // Subject 1 (ความรู้พื้นฐาน) = (50 + 60 + 70) / 3 = 60.00
        $this->assertEquals('ความรู้พื้นฐาน', $subjects[1]['name']);
        $this->assertEquals(60.00, $subjects[1]['average']);

        // Overall Average = (60 + 70 + 80) / 3 = 70.00
        $this->assertEquals(70.00, $response->json('data.average_total_score'));
        $this->assertEquals(80.00, $response->json('data.max_total_score'));
        $this->assertEquals(100.00, $response->json('data.max_subject_score'));
    }

    public function test_student_can_only_view_own_nnet_records_and_not_other_students(): void
    {
        $ownRecord = NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'citizen_id' => '1100400123456',
            'student_name' => 'สมศรี เรียนดี',
            'total_score' => 162.00,
            'has_score' => true,
            'subject_codes' => ['411', '412', '413', '414', '415'],
            'subject_scores' => [
                '411' => 40.0,
                '412' => 36.0,
                '413' => 32.0,
                '414' => 24.0,
                '415' => 30.0,
            ],
            'subject_levels' => [
                '411' => 'ผ่าน',
                '412' => 'ควรพัฒนา',
                '413' => 'ควรพัฒนา',
                '414' => 'ควรพัฒนา',
                '415' => 'ควรพัฒนา',
            ],
        ]);

        $otherRecord = NnetResult::create([
            'district_id' => $this->district->id,
            'academic_year' => '2569',
            'round' => 1,
            'education_level' => 2,
            'citizen_id' => '9999999999999',
            'student_name' => 'นายอื่น คนอื่น',
            'total_score' => 250.00,
            'has_score' => true,
            'subject_codes' => ['411', '412', '413', '414', '415'],
            'subject_scores' => ['411' => 50.0, '412' => 50.0, '413' => 50.0, '414' => 50.0, '415' => 50.0],
        ]);

        Sanctum::actingAs($this->studentUser);

        // Student lists records: should only see their own record
        $response = $this->getJson('/api/v1/nnet/records')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $ownRecord->id)
            ->assertJsonPath('data.items.0.citizen_id', '1100400123456')
            ->assertJsonPath('data.items.0.student_name', 'สมศรี เรียนดี');

        $this->assertCount(1, $response->json('data.items'));

        // Student views their own record details
        $this->getJson("/api/v1/nnet/records/{$ownRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownRecord->id)
            ->assertJsonPath('data.student_name', 'สมศรี เรียนดี')
            ->assertJsonPath('data.subject_levels.411', 'ผ่าน');

        // Student attempts to view another student's record directly: 403 Forbidden
        $this->getJson("/api/v1/nnet/records/{$otherRecord->id}")
            ->assertForbidden();
    }

    public function test_student_with_no_nnet_record_gets_empty_items(): void
    {
        $newStudent = User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'username' => '1200500999999',
        ]);

        Sanctum::actingAs($newStudent);

        $response = $this->getJson('/api/v1/nnet/records')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.items', []);

        $this->assertEmpty($response->json('data.items'));
    }
}


