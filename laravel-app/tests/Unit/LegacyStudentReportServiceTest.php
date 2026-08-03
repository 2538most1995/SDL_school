<?php

namespace Tests\Unit;

use App\Domain\Students\Services\LegacyStudentReportService;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Tests\TestCase;

final class LegacyStudentReportServiceTest extends TestCase
{
    public function test_registered_subjects_start_from_historical_grades_and_keep_teacher_group_scope(): void
    {
        $batch = 'import_1700000000_history';
        $queries = [];
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('selectOne')
            ->times(3)
            ->andReturn((object) ['batch_key' => $batch]);
        $connection->shouldReceive('select')->andReturnUsing(
            function (string $query, array $bindings = [], bool $useReadPdo = true) use ($batch, &$queries): array {
                $queries[] = compact('query', 'bindings', 'useReadPdo');

                return match (true) {
                    str_contains($query, 'INFORMATION_SCHEMA.TABLES') => array_map(
                        static fn (string $table): object => (object) ['table_name' => $table],
                        [
                            "db_{$batch}_1_student",
                            "db_{$batch}_1_grade",
                            "db_{$batch}_1_subject",
                            "db_{$batch}_1214120000_group",
                        ],
                    ),
                    str_contains($query, 'SELECT DISTINCT g._perf_semestry AS raw_term') => [
                        (object) ['raw_term' => '68/2'],
                    ],
                    str_contains($query, 'SELECT g._id AS row_id') => [
                        (object) [
                            'row_id' => 1,
                            'student_code' => '6811000001',
                            'subject_code' => 'ทช11001',
                            'raw_term' => '68/2',
                            'grade_value' => '3',
                            'typ_code' => '',
                            'subject_name' => 'เศรษฐกิจพอเพียง',
                            'subject_credit' => '2',
                            'subject_type' => '1',
                            'prename' => 'นางสาว',
                            'first_name' => 'นักศึกษาเก่า',
                            'last_name' => 'ทดสอบ',
                            'group_code' => 'G-OLD',
                            'group_name' => 'กลุ่มครู ก',
                        ],
                        (object) [
                            'row_id' => 2,
                            'student_code' => '6811000001',
                            'subject_code' => 'พท11001',
                            'raw_term' => '2/2568',
                            'grade_value' => '',
                            'typ_code' => '',
                            'subject_name' => 'ภาษาไทย',
                            'subject_credit' => '3',
                            'subject_type' => '1',
                            'prename' => 'นางสาว',
                            'first_name' => 'นักศึกษาเก่า',
                            'last_name' => 'ทดสอบ',
                            'group_code' => 'G-OLD',
                            'group_name' => 'กลุ่มครู ก',
                        ],
                        (object) [
                            'row_id' => 3,
                            'student_code' => '6911000002',
                            'subject_code' => 'พท11001',
                            'raw_term' => '68/2',
                            'grade_value' => '1',
                            'typ_code' => '',
                            'subject_name' => 'ภาษาไทย',
                            'subject_credit' => '3',
                            'subject_type' => '1',
                            'prename' => 'นาย',
                            'first_name' => 'นักศึกษาปัจจุบัน',
                            'last_name' => 'ทดสอบ',
                            'group_code' => 'G-NEW',
                            'group_name' => 'กลุ่มครู ก',
                        ],
                    ],
                    default => [],
                };
            },
        );

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->andReturn($connection);
        $service = new LegacyStudentReportService($database);
        $teacher = new User([
            'role' => 'teacher',
            'district_id' => 1,
            'assigned_groups' => ['กลุ่มครู ก'],
        ]);

        $result = $service->registeredSubjects($teacher, 1, [
            'term' => '2/2568',
            'view' => 'subject',
        ]);

        $this->assertSame(2, $result['summary']['unique_students']);
        $this->assertSame(3, $result['summary']['registered_records']);
        $this->assertSame(2, $result['summary']['subject_count']);
        $this->assertSame('2/2568', $result['selected_term']);
        $this->assertContains('2/2568', $result['terms']);
        $this->assertSame('2 คน', collect($result['rows'])->firstWhere('secondary', 'พท11001')['metric']);

        $registrationQuery = collect($queries)->first(
            static fn (array $entry): bool => str_contains($entry['query'], 'SELECT g._id AS row_id'),
        );
        $this->assertNotNull($registrationQuery);
        $this->assertStringContainsString('INNER JOIN', $registrationQuery['query']);
        $this->assertStringContainsString('st._perf_id10 = g._perf_std10', $registrationQuery['query']);
        $this->assertStringContainsString('st.grp_code IN', $registrationQuery['query']);
        $this->assertContains('กลุ่มครู ก', $registrationQuery['bindings']);
        $this->assertFalse(collect($queries)->contains(
            static fn (array $entry): bool => str_contains($entry['query'], 'active_grade'),
        ));

        $gradeReport = $service->gradesAboveTwo($teacher, 1, [
            'term' => '2/2568',
            'view' => 'subject',
        ]);
        $this->assertSame(2, $gradeReport['summary']['unique_students']);
        $this->assertSame(3, $gradeReport['summary']['registered_records']);
        $this->assertSame(1, $gradeReport['summary']['grade_two_or_above']);
        $this->assertSame(33.3, $gradeReport['summary']['success_rate']);

        $attendanceReport = $service->examAttendance($teacher, 1, [
            'term' => '2/2568',
            'view' => 'subject',
        ]);
        $this->assertSame(2, $attendanceReport['summary']['unique_students']);
        $this->assertSame(3, $attendanceReport['summary']['registered_records']);
        $this->assertSame(2, $attendanceReport['summary']['attended_records']);
        $this->assertSame(1, $attendanceReport['summary']['absent_records']);
        $this->assertSame(66.7, $attendanceReport['summary']['attendance_rate']);
    }
}
