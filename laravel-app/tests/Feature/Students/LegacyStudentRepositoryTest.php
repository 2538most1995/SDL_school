<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Repositories\LegacyStudentRepository;
use App\Domain\Students\Support\LegacyTableSet;
use App\Http\Resources\Students\StudentDetailResource;
use App\Http\Resources\Students\StudentSummaryResource;
use App\Models\User;
use App\Support\LegacyFptMemoReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class LegacyStudentRepositoryTest extends TestCase
{
    public function test_phone_uses_valid_database_text_when_memo_files_are_unavailable(): void
    {
        $repository = new LegacyStudentRepository(
            Mockery::mock(ConnectionInterface::class),
            memoReader: new LegacyFptMemoReader('/directory-that-does-not-exist'),
        );
        $set = new LegacyTableSet(
            districtId: 1,
            districtName: 'อำเภอทดสอบ',
            batchKey: 'import_123_abcdef',
            level: 1,
            student: 'db_import_123_abcdef_1_student',
            grade: 'db_import_123_abcdef_1_grade',
            subject: 'db_import_123_abcdef_1_subject',
            activity: null,
            virtue: null,
            group: null,
        );
        $method = new \ReflectionMethod($repository, 'phoneMemoValue');

        $this->assertSame('0812345678', $method->invoke($repository, $set, 'STUDENT01', 'phone', '081-234-5678'));
        $this->assertNull($method->invoke($repository, $set, 'STUDENT01', 'phone', "\x21\x00\x00\x00"));
    }

    public function test_it_fails_closed_when_a_district_has_no_successful_registered_batch(): void
    {
        $queries = [];
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->andReturnUsing(
            function (string $query, array $bindings, bool $readPdo) use (&$queries): array {
                $queries[] = compact('query', 'bindings', 'readPdo');

                return str_contains($query, 'FROM districts')
                    ? [(object) ['id' => 1, 'name' => 'อำเภอทดสอบ']]
                    : [];
            },
        );
        $connection->shouldNotReceive('statement');
        $connection->shouldNotReceive('unprepared');
        $connection->shouldNotReceive('insert');
        $connection->shouldNotReceive('update');
        $connection->shouldNotReceive('delete');

        $repository = new LegacyStudentRepository($connection);

        $this->assertSame([], $repository->students());
        $this->assertCount(2, $queries);
        $this->assertStringContainsString("ih.status = 'success'", $queries[1]['query']);
        $this->assertTrue($queries[0]['readPdo']);
        $this->assertTrue($queries[1]['readPdo']);
    }

    public function test_it_keeps_one_batch_context_uses_indexed_joins_and_masks_pii(): void
    {
        $batch = 'import_1700000000_A';
        $otherBatch = 'import_1800000000_B';
        $queries = [];
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->andReturnUsing(
            function (string $query, array $bindings, bool $readPdo) use ($batch, $otherBatch, &$queries): array {
                $queries[] = $query;

                return match (true) {
                    str_contains($query, 'FROM districts') => [(object) ['id' => 1, 'name' => 'อำเภอทดสอบ']],
                    str_contains($query, 'FROM import_batches') => [(object) ['batch_key' => $batch]],
                    str_contains($query, 'INFORMATION_SCHEMA.TABLES') => array_map(
                        static fn (string $table): object => (object) ['table_name' => $table],
                        [
                            "db_{$batch}_1_student",
                            "db_{$batch}_1_grade",
                            "db_{$batch}_1_subject",
                            "db_{$batch}_1_activity",
                            "db_{$batch}_1_virtue",
                            "db_{$batch}_1260090000_group",
                            "db_{$otherBatch}_1_grade",
                        ],
                    ),
                    str_contains($query, 'INFORMATION_SCHEMA.COLUMNS') => [
                        (object) ['column_name' => 'cardid'],
                    ],
                    str_contains($query, 'SELECT DISTINCT _perf_semestry') => [
                        (object) ['term' => '68/2'],
                        (object) ['term' => '1/2568'],
                    ],
                    str_contains($query, 'AS credits_earned') => [
                        (object) ['code' => '6650100001', 'credits_earned' => '12', 'credits_current' => '15', 'compulsory_earned' => '9', 'elective_earned' => '3', 'gpax' => '3.25'],
                    ],
                    str_contains($query, 'AS hours') => [
                        (object) ['code' => '6650100001', 'hours' => '128'],
                    ],
                    str_contains($query, 'latest_students') => [
                        (object) [
                            'code' => '6650100001',
                            'prename' => 'นาย',
                            'first_name' => 'ผู้เรียน',
                            'last_name' => 'ตัวอย่าง',
                            'group_code' => 'G-01',
                            'group_name' => 'กลุ่มทดสอบ',
                            'enrollment_term' => '67/1',
                            'fin_cause' => '',
                            'transfer_date' => '',
                            'gpasem' => '0',
                            'citizen_id_masked' => '1-xxxx-xxxxx-xx-1',
                            'citizen_id' => '1234567890121',
                            'gender' => '1',
                            'birth_date' => '20000131',
                            'age' => '26',
                            'application_date' => '20200102',
                            'last_updated' => '2026-07-17 10:00:00',
                            'phone' => '0812345678',
                            'curphone' => '',
                            'email' => 'learner@example.test',
                            'registered_address' => '99/1',
                            'registered_area_code' => '140405',
                            'registered_postcode' => '13110',
                            'current_address' => '100/2',
                            'current_area_code' => '140406',
                            'current_postcode' => '13110',
                        ],
                    ],
                    str_contains($query, 'g._id AS row_id') => [
                        (object) ['student_code' => '6650100001', 'row_id' => 1, 'subject_code' => 'SUB01', 'grade_value' => '0', 'raw_term' => '68/2', 'typ_code' => '', 'subject_name' => 'วิชาทดสอบ', 'subject_credit' => '3', 'subject_type' => '1'],
                        (object) ['student_code' => '6650100001', 'row_id' => 2, 'subject_code' => 'SUB01', 'grade_value' => '1', 'raw_term' => '68/2', 'typ_code' => '', 'subject_name' => 'วิชาทดสอบ', 'subject_credit' => '3', 'subject_type' => '1'],
                    ],
                    str_contains($query, 'SELECT _id AS row_id, activity') => [
                        (object) ['row_id' => 7, 'activity' => 'จิตอาสา', 'hour' => '12', 'raw_term' => '68/2', 'transfer' => '', 'trntype' => ''],
                    ],
                    str_contains($query, '_perf_semester AS raw_term') => [
                        (object) [
                            'raw_term' => '68/2',
                            'score1_1' => '95', 'score1_2' => '95', 'score1_3' => '95',
                            'score2_1' => '95', 'score2_2' => '95', 'score2_3' => '95',
                            'score3_1' => '95', 'score3_2' => '95', 'score3_3' => '95',
                            'score4_1' => '95', 'score4_2' => '95',
                        ],
                    ],
                    default => [],
                };
            },
        );

        $repository = new LegacyStudentRepository($connection);
        $students = $repository->students([1]);

        $this->assertCount(1, $students);
        $this->assertStringContainsString('id IN (?)', $queries[0]);
        $student = $students[0];
        $this->assertSame('2/2568', $student->currentTerm);
        $this->assertSame('08x-xxx-5678', $student->contact['phone_masked']);
        $this->assertSame('l***@example.test', $student->contact['email_masked']);
        $this->assertSame('1-xxxx-xxxxx-xx-1', $student->demographics['citizen_id_masked']);
        $this->assertSame('31/01/2543', $student->demographics['birth_date']);
        $this->assertSame('ชาย', $student->demographics['gender']);
        $this->assertSame(15.0, $student->creditsCurrent);
        $this->assertSame(9.0, $student->compulsoryCreditsEarned);
        $this->assertSame('personal_data_sensitive', $student->toDetailArray()['data_classification']);
        $this->assertSame('1234567890121', $student->citizenId);
        $request = Request::create('/api/v1/students', 'GET');
        $viewer = new User(['role' => 'admin', 'district_id' => 1, 'assigned_groups' => []]);
        $request->setUserResolver(static fn (): User => $viewer);
        $this->assertSame('1234567890121', (new StudentSummaryResource($student))->resolve($request)['demographics']['citizen_id']);
        $detail = (new StudentDetailResource($student))->resolve($request);
        $this->assertSame('0812345678', $detail['contact']['phone']);
        $this->assertSame('บ้านเลขที่ 99/1 รหัสตำบล 140405 รหัสไปรษณีย์ 13110', $detail['contact']['registered_address']);
        $this->assertSame('บ้านเลขที่ 100/2 รหัสตำบล 140406 รหัสไปรษณีย์ 13110', $detail['contact']['current_address']);
        $restrictedRequest = Request::create('/api/v1/students', 'GET');
        $restrictedViewer = new User(['role' => 'teacher', 'district_id' => 1, 'assigned_groups' => ['กลุ่มอื่น']]);
        $restrictedRequest->setUserResolver(static fn (): User => $restrictedViewer);
        $this->assertArrayNotHasKey(
            'citizen_id',
            (new StudentSummaryResource($student))->resolve($restrictedRequest)['demographics'],
        );
        $restrictedDetail = (new StudentDetailResource($student))->resolve($restrictedRequest);
        $this->assertArrayNotHasKey('phone', $restrictedDetail['contact']);
        $this->assertArrayNotHasKey('registered_address', $restrictedDetail['contact']);
        $this->assertArrayNotHasKey('current_address', $restrictedDetail['contact']);
        $this->assertStringNotContainsString('0812345678', json_encode($student->toDetailArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('learner@example.test', json_encode($student->toDetailArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('1234567890121', json_encode($student->toDetailArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('99/1', json_encode($student->toDetailArray(), JSON_UNESCAPED_UNICODE));

        $grades = $repository->gradesFor($student);
        $this->assertCount(1, $grades);
        $this->assertSame('1', $grades[0]->grade);
        $this->assertSame('2/2568', $grades[0]->term);
        $this->assertCount(1, $repository->kpchFor($student));
        $this->assertNull($repository->kpchFor($student)[0]->completedOn);
        $this->assertSame('ดีมาก', $repository->moralFor($student)[0]->result);

        $dynamicQueries = array_values(array_filter($queries, static fn (string $query): bool => str_contains($query, 'db_import_')));
        $this->assertNotEmpty($dynamicQueries);
        foreach ($dynamicQueries as $query) {
            $this->assertStringContainsString($batch, $query);
            $this->assertStringNotContainsString($otherBatch, $query);
        }
        $this->assertTrue((bool) array_filter($queries, static fn (string $query): bool => str_contains($query, 'EXISTS')));
        $this->assertTrue((bool) array_filter($queries, static fn (string $query): bool => str_contains($query, '_perf_std10')));
        $this->assertTrue((bool) array_filter($queries, static fn (string $query): bool => str_contains($query, 's.`cardid`')));
    }

    public function test_real_legacy_connection_is_opt_in_and_read_only(): void
    {
        if (! filter_var(env('LEGACY_STUDENT_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set LEGACY_STUDENT_INTEGRATION=true to run the read-only legacy integration test.');
        }
        if (config('database.connections.legacy') === null) {
            $this->markTestSkipped('The legacy connection is not configured.');
        }

        $repository = new LegacyStudentRepository(DB::connection('legacy'));
        $students = $repository->students();

        $this->assertIsArray($students);
        $this->assertNotEmpty($students);
        $this->assertTrue((bool) array_filter($students, static fn ($student): bool => $student->phone !== null));
        $this->assertTrue((bool) array_filter($students, static fn ($student): bool => $student->registeredAddress !== null));
        $this->assertTrue((bool) array_filter($students, static fn ($student): bool => $student->currentAddress !== null));
    }
}
