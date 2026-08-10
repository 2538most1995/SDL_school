<?php

namespace Tests\Feature\Learning;

use App\Domain\Students\Models\RegisteredSubject;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Services\Legacy\LegacyExamScheduleService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LegacyExamScheduleDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_is_read_from_system_import_tables_and_supports_legacy_term_format(): void
    {
        config()->set('system_data.enabled', true);
        config()->set('system_data.student_enabled', true);

        $district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena', 'is_active' => true]);
        $batch = 'import_1700000200_abcd';
        $historyId = DB::table('import_history')->insertGetId([
            'file_name' => 'itw51.zip',
            'saved_file_name' => 'itw51.zip',
            'batch_key' => $batch,
            'file_size_kb' => 100,
            'level' => 'ทุกระดับ',
            'file_count' => 4,
            'status' => 'success',
            'district_id' => $district->id,
            'created_at' => now(),
        ]);
        DB::table('import_batches')->insert([
            'district_id' => $district->id,
            'import_history_id' => $historyId,
            'batch_key' => $batch,
            'status' => 'active',
            'source_filename' => 'itw51.zip',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scheduleTable = 'db_'.$batch.'_2_schedule';
        Schema::create($scheduleTable, function (Blueprint $table): void {
            $table->id();
            $table->string('sub_code');
            $table->string('semestry');
            $table->string('exam_day');
            $table->string('exam_start');
            $table->string('exam_end');
            $table->string('fld_code')->nullable();
            $table->string('_perf_sub')->index();
            $table->string('_perf_semestry')->index();
        });
        DB::table($scheduleTable)->insert([
            'sub_code' => 'ทช21001',
            'semestry' => '69/1',
            'exam_day' => '16/08/69',
            'exam_start' => '0900',
            'exam_end' => '1030',
            'fld_code' => 'SENA01',
            '_perf_sub' => 'ทช21001',
            '_perf_semestry' => '69/1',
        ]);

        $fieldTable = 'db_'.$batch.'_source_field';
        Schema::create($fieldTable, function (Blueprint $table): void {
            $table->id();
            $table->string('fld_code');
            $table->string('fld_name');
        });
        DB::table($fieldTable)->insert([
            'fld_code' => 'SENA01',
            'fld_name' => 'สนามสอบ กศน.อำเภอเสนา',
        ]);

        $gradeTable = 'db_'.$batch.'_2_grade';
        Schema::create($gradeTable, function (Blueprint $table): void {
            $table->id();
            $table->string('std_code');
            $table->string('sub_code');
            $table->string('roomno')->nullable();
            $table->string('_perf_std10')->index();
        });
        DB::table($gradeTable)->insert([
            'std_code' => '6650100001',
            'sub_code' => 'ทช21001',
            'roomno' => '08',
            '_perf_std10' => '6650100001',
        ]);

        DB::table('exam_rooms')->insert([
            'district_id' => $district->id,
            'term' => '69/1',
            'subject_code' => 'ทช21001',
            'assignment_type' => 'student_range',
            'start_val' => '6650100001',
            'end_val' => '6650100001',
            'room_name' => 'ห้องสอบจากระบบ 8',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = $this->student($district);
        $subject = new RegisteredSubject(
            studentCode: $student->code,
            code: 'ทช21001',
            name: 'เศรษฐกิจพอเพียง',
            credits: 1.0,
            type: 'compulsory',
            term: '1/2569',
            registrationStatus: 'registered',
            transferred: false,
            grade: null,
            examAttended: false,
        );
        $service = new LegacyExamScheduleService(
            app(DatabaseManager::class),
            $this->repositoryWithSubjects([$subject]),
        );

        $result = $service->forStudent($student);

        $this->assertTrue($result['source_ready']);
        $this->assertTrue($result['sources']['schedule']);
        $this->assertTrue($result['sources']['field']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('1/2569', $result['rows'][0]['term']);
        $this->assertSame('16 ส.ค. 2569', $result['rows'][0]['exam_date_display']);
        $this->assertSame('09:00', $result['rows'][0]['start_time']);
        $this->assertSame('สนามสอบ กศน.อำเภอเสนา', $result['rows'][0]['location']);
        $this->assertSame('ห้องสอบจากระบบ 8', $result['rows'][0]['room']);
    }

    private function student(District $district): Student
    {
        return new Student(
            code: '6650100001',
            districtId: $district->id,
            districtName: $district->name,
            prefix: 'นาย',
            firstName: 'ทดสอบ',
            lastName: 'ระบบ',
            level: 2,
            levelLabel: 'มัธยมศึกษาตอนต้น',
            groupCode: '220001',
            groupName: 'กลุ่มเสนา 1',
            enrollmentTerm: '1/2567',
            currentTerm: '1/2569',
            status: 'studying',
            statusLabel: 'กำลังศึกษา',
            gpax: 2.75,
            creditsEarned: 40,
            creditsRequired: 56,
            kpchHours: 100,
            moralResult: 'ผ่าน',
        );
    }

    /** @param list<RegisteredSubject> $subjects */
    private function repositoryWithSubjects(array $subjects): StudentRepository
    {
        return new class($subjects) implements StudentRepository
        {
            /** @param list<RegisteredSubject> $subjects */
            public function __construct(private readonly array $subjects) {}

            public function students(?array $districtIds = null): array
            {
                return [];
            }

            public function find(string $code, ?int $districtId = null, ?int $level = null): ?Student
            {
                return null;
            }

            public function gradesFor(Student $student): array
            {
                return [];
            }

            public function gradesForMany(array $students): array
            {
                return [];
            }

            public function subjectsFor(Student $student): array
            {
                return $this->subjects;
            }

            public function kpchFor(Student $student): array
            {
                return [];
            }

            public function moralFor(Student $student): array
            {
                return [];
            }
        };
    }
}
