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
}
