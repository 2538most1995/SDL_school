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
    public function test_exam_eligible_students_use_status_tokens_and_deduplicate_registrations(): void
    {
        $batch = 'import_1700000002_exam';
        $queries = [];
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('selectOne')
            ->once()
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
                        (object) ['raw_term' => '69/1'],
                    ],
                    str_contains($query, 'SELECT g._id AS row_id') => [
                        (object) ['row_id' => 1, 'student_code' => '6911000001', 'subject_code' => 'พท11001', 'raw_term' => '69/1', 'grade_value' => 'ม', 'typ_code' => '', 'subject_name' => 'ภาษาไทย', 'subject_credit' => '3', 'subject_type' => '1', 'prename' => 'นาย', 'first_name' => 'ถูกตัดสิทธิ์', 'last_name' => 'ทดสอบ', 'group_code' => 'G-01', 'group_name' => 'กลุ่มครู ก'],
                        (object) ['row_id' => 2, 'student_code' => '6911000001', 'subject_code' => 'พค11001', 'raw_term' => '1/2569', 'grade_value' => 'ม', 'typ_code' => '', 'subject_name' => 'คณิตศาสตร์', 'subject_credit' => '3', 'subject_type' => '1', 'prename' => 'นาย', 'first_name' => 'ถูกตัดสิทธิ์', 'last_name' => 'ทดสอบ', 'group_code' => 'G-01', 'group_name' => 'กลุ่มครู ก'],
                        (object) ['row_id' => 3, 'student_code' => '6911000002', 'subject_code' => 'พท11001', 'raw_term' => '69/1', 'grade_value' => '0', 'typ_code' => '', 'subject_name' => 'ภาษาไทย', 'subject_credit' => '3', 'subject_type' => '1', 'prename' => 'นางสาว', 'first_name' => 'คะแนนศูนย์', 'last_name' => 'ยังมีสิทธิ์', 'group_code' => 'G-01', 'group_name' => 'กลุ่มครู ก'],
                        (object) ['row_id' => 4, 'student_code' => '6911000003', 'subject_code' => 'พท11001', 'raw_term' => '69/1', 'grade_value' => '', 'typ_code' => '', 'subject_name' => 'ภาษาไทย', 'subject_credit' => '3', 'subject_type' => '1', 'prename' => 'นาย', 'first_name' => 'ไม่มีคะแนน', 'last_name' => 'ยังมีสิทธิ์', 'group_code' => 'G-01', 'group_name' => 'กลุ่มครู ก'],
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

        $result = $service->examEligibleStudents($teacher, 1, ['term' => '1/2569']);

        $this->assertSame('1/2569', $result['selected_term']);
        $this->assertSame(3, $result['summary']['total_students']);
        $this->assertSame(2, $result['summary']['eligible_students']);
        $this->assertSame(1, $result['summary']['disqualified_students']);
        $this->assertCount(2, $result['items']);
        $this->assertSame(1, $result['summary']['group_count']);
        $this->assertSame(2, $result['group_statistics'][0]['primary_students']);
        $this->assertSame(2, $result['group_statistics'][0]['total_students']);
        $this->assertSame(['6911000002', '6911000003'], collect($result['items'])->pluck('student.code')->all());

        $registrationQuery = collect($queries)->first(
            static fn (array $entry): bool => str_contains($entry['query'], 'SELECT g._id AS row_id'),
        );
        $this->assertNotNull($registrationQuery);
        $this->assertStringContainsString('st.grp_code IN', $registrationQuery['query']);
        $this->assertContains('กลุ่มครู ก', $registrationQuery['bindings']);
    }

    public function test_registration_statistics_use_itw51_target_group_and_keep_teacher_scope(): void
    {
        $batch = 'import_1700000001_statistics';
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
                    str_contains($query, 'INFORMATION_SCHEMA.COLUMNS') => [
                        (object) ['column_name' => 'occtyp'],
                        (object) ['column_name' => 'gender'],
                        (object) ['column_name' => 'occp'],
                        (object) ['column_name' => 'nation'],
                        (object) ['column_name' => 'age'],
                    ],
                    str_contains($query, 'SELECT DISTINCT g._perf_semestry AS raw_term') => [
                        (object) ['raw_term' => in_array('กลุ่มเฉพาะเก่า', $bindings, true) ? '68/2' : '69/1'],
                    ],
                    str_contains($query, 'SELECT DISTINCT st._perf_id10 AS student_code') && in_array('กลุ่มเฉพาะเก่า', $bindings, true) => [
                        (object) ['student_code' => '6811000003', 'target_group' => '17', 'group_code' => 'G-OLD', 'group_label' => 'กลุ่มเฉพาะเก่า', 'gender' => '1', 'occupation' => '06', 'nationality' => '099', 'age' => '30'],
                    ],
                    str_contains($query, 'SELECT DISTINCT st._perf_id10 AS student_code') => [
                        (object) ['student_code' => '6911000001', 'target_group' => '07', 'group_code' => 'G-01', 'group_label' => 'กลุ่มครู ก', 'gender' => '1', 'occupation' => '05', 'nationality' => '099', 'age' => '35'],
                        (object) ['student_code' => '6911000002', 'target_group' => '09', 'group_code' => 'G-01', 'group_label' => 'กลุ่มครู ก', 'gender' => '2', 'occupation' => '04', 'nationality' => '099', 'age' => '42'],
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

        $result = $service->registrationStatistics($teacher, 1, ['category' => 'target_group']);

        $this->assertSame('1/2569', $result['selected_term']);
        $this->assertSame(2, $result['summary']['registered_students']);
        $this->assertSame('ผู้ต้องขัง', collect($result['items'])->firstWhere('code', '07')['label']);
        $this->assertSame('ผู้ใช้แรงงาน', collect($result['items'])->firstWhere('code', '09')['label']);

        $statisticsQuery = collect($queries)->first(
            static fn (array $entry): bool => str_contains($entry['query'], 'SELECT DISTINCT st._perf_id10 AS student_code'),
        );
        $this->assertNotNull($statisticsQuery);
        $this->assertStringContainsString('st.`occtyp` AS target_group', $statisticsQuery['query']);
        $this->assertStringContainsString('st.grp_code AS group_code', $statisticsQuery['query']);
        $this->assertStringContainsString('AS group_label', $statisticsQuery['query']);
        $this->assertStringContainsString('st.`age` AS age', $statisticsQuery['query']);
        $this->assertStringContainsString('st.grp_code IN', $statisticsQuery['query']);
        $this->assertContains('กลุ่มครู ก', $statisticsQuery['bindings']);

        $groupResult = $service->registrationStatistics($teacher, 1, ['category' => 'group']);
        $this->assertSame('กลุ่มครู ก', $groupResult['items'][0]['code']);
        $this->assertSame('กลุ่มครู ก', $groupResult['items'][0]['label']);

        $admin = new User(['role' => 'admin', 'district_id' => 1]);
        $olderGroup = $service->registrationStatistics($admin, 1, [
            'category' => 'group',
            'group_name' => 'กลุ่มเฉพาะเก่า',
        ]);

        $this->assertSame('2/2568', $olderGroup['selected_term']);
        $this->assertSame(1, $olderGroup['summary']['registered_students']);
        $this->assertSame('กลุ่มเฉพาะเก่า', $olderGroup['items'][0]['label']);
        $olderTermQuery = collect($queries)->first(
            static fn (array $entry): bool => str_contains($entry['query'], 'SELECT DISTINCT g._perf_semestry AS raw_term')
                && in_array('กลุ่มเฉพาะเก่า', $entry['bindings'], true),
        );
        $this->assertNotNull($olderTermQuery);
        $this->assertStringContainsString('COALESCE(NULLIF(TRIM(grp.grp_name)', $olderTermQuery['query']);
        $this->assertFalse(collect($queries)->contains(
            static fn (array $entry): bool => str_contains($entry['query'], 'SELECT s._perf_id10 AS student_code'),
        ), 'Registration statistics must not run the expected-graduates N-NET scan.');
    }

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

    public function test_scorebook_registration_source_scopes_student_to_their_own_code(): void
    {
        $batch = 'import_1700000003_studentscope';
        $queries = [];
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('selectOne')->once()->andReturn((object) ['batch_key' => $batch]);
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
                        ],
                    ),
                    str_contains($query, 'SELECT DISTINCT g._perf_semestry AS raw_term') => [(object) ['raw_term' => '69/1']],
                    str_contains($query, 'SELECT g._id AS row_id') => [(object) [
                        'row_id' => 1,
                        'student_code' => '6911000099',
                        'subject_code' => 'พท11001',
                        'raw_term' => '69/1',
                        'grade_value' => '',
                        'typ_code' => '',
                        'subject_name' => 'ภาษาไทย',
                        'subject_credit' => '3',
                        'subject_type' => '1',
                        'prename' => 'นาย',
                        'first_name' => 'นักศึกษา',
                        'last_name' => 'ทดสอบ',
                        'group_code' => 'G-01',
                        'group_name' => 'กลุ่ม 1',
                    ]],
                    default => [],
                };
            },
        );

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->andReturn($connection);
        $service = new LegacyStudentReportService($database);
        $student = new User([
            'role' => 'student',
            'district_id' => 1,
            'student_code' => '6911000099',
        ]);

        $result = $service->scorebookRegistrations($student, 1, ['term' => '1/2569']);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('6911000099', $result['rows'][0]['student_code']);
        $scopedQueries = collect($queries)->filter(static fn (array $entry): bool => str_contains($entry['query'], 'g._perf_semestry'));
        $this->assertNotEmpty($scopedQueries);
        $this->assertTrue($scopedQueries->every(
            static fn (array $entry): bool => str_contains($entry['query'], 'st._perf_id10 = ?')
                && in_array('6911000099', $entry['bindings'], true),
        ));
    }

    public function test_expected_graduates_classifies_thirteen_taken_and_ninety_five_eligible_for_any_district(): void
    {
        $batch = 'import_1700000004_graduates';
        $batchDistrictBindings = [];
        $queries = [];
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('selectOne')->once()->andReturnUsing(
            function (string $query, array $bindings = []) use ($batch, &$batchDistrictBindings): object {
                $batchDistrictBindings = $bindings;

                return (object) ['batch_key' => $batch];
            },
        );
        $connection->shouldReceive('select')->andReturnUsing(
            function (string $query, array $bindings = []) use ($batch, &$queries): array {
                $queries[] = compact('query', 'bindings');

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
                    str_contains($query, 'INFORMATION_SCHEMA.COLUMNS') => [
                        (object) ['column_name' => 'expflag'],
                        (object) ['column_name' => 'expsem'],
                        (object) ['column_name' => 'nt_sara1'],
                        (object) ['column_name' => 'nt_sara2'],
                        (object) ['column_name' => 'nt_sem'],
                        (object) ['column_name' => 'nt_nosem'],
                        (object) ['column_name' => 'gender'],
                    ],
                    str_contains($query, 'SELECT DISTINCT g._perf_semestry AS raw_term') => [
                        (object) ['raw_term' => '69/1'],
                    ],
                    str_contains($query, 'SELECT s._perf_id10 AS student_code') => array_map(
                        static function (int $number): object {
                            $taken = $number <= 13;

                            $hasNtSem = $number <= 7;
                            $hasNtNosem = $number >= 8 && $number <= 13;

                            return (object) [
                                'student_code' => '6911'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                                'prename' => $number % 2 === 0 ? 'นางสาว' : 'นาย',
                                'first_name' => $taken ? 'คนสอบแล้ว' : 'คนมีสิทธิ์สอบ',
                                'last_name' => (string) $number,
                                'grp_code' => 'PHS-G01',
                                'group_name' => 'ไพศาลี กลุ่ม 1',
                                'expflag_val' => '1',
                                'expsem_val' => '69/1',
                                'nt_sara1_val' => '0',
                                'nt_sara2_val' => '0',
                                'nt_sem_val' => $hasNtSem ? ($number === 7 ? '68/1' : '68/2') : ($number === 14 ? '0/0' : '-'),
                                'nt_nosem_val' => $hasNtNosem ? ($number === 13 ? '67/2' : '68/2') : '',
                                'gender' => $number % 2 === 0 ? '2' : '1',
                                'nnet_val' => '',
                                'fin_cause_val' => '',
                                'fin_sem_val' => '',
                                'fin_sem2_val' => '',
                            ];
                        },
                        range(1, 108),
                    ),
                    str_contains($query, 'SELECT g._perf_std10 AS student_code') => array_map(
                        static fn (int $number): object => (object) [
                            'student_code' => '6911'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                            'grade' => '3',
                            'typ_code' => '1',
                            'term' => '69/1',
                            'sub_type' => '1',
                            'sub_credit' => '48',
                            'sub_code' => 'ทร11001',
                            'sub_name' => 'วิชาบังคับ',
                        ],
                        range(1, 108),
                    ),
                    default => [],
                };
            },
        );

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->andReturn($connection);
        $service = new LegacyStudentReportService($database);
        $admin = new User(['role' => 'admin', 'district_id' => 2]);

        $result = $service->expectedGraduates($admin, 2, ['term' => '1/2569', 'group' => 'PHS-G01']);

        $this->assertCount(108, $result['rows']);
        $this->assertCount(13, collect($result['rows'])->where('examStatus', 'สอบแล้ว'));
        $this->assertCount(95, collect($result['rows'])->where('examStatus', 'มีสิทธิ์สอบ'));
        $this->assertSame([2], $batchDistrictBindings);
        $studentQuery = collect($queries)->first(
            static fn (array $entry): bool => str_contains($entry['query'], 'SELECT s._perf_id10 AS student_code'),
        );
        $this->assertNotNull($studentQuery);
        $this->assertContains('PHS-G01', $studentQuery['bindings']);
    }
}
