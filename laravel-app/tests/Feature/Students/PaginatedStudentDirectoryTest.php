<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Repositories\LegacyStudentRepository;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Services\CourseRegistrationExportService;
use App\Domain\Students\Services\StudentAcademicService;
use App\Domain\Students\Services\StudentDirectoryService;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PaginatedStudentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private string $batch;

    private int $districtId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('system_data.cache_seconds', 300);
        $this->districtId = District::create(['name' => 'Synthetic Directory', 'code' => 'directory-test', 'is_active' => true])->id;
        $this->admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->districtId]);
        Sanctum::actingAs($this->admin);
        $this->batch = 'import_1700000011_'.bin2hex(random_bytes(4));
        $history = DB::table('import_history')->insertGetId(['file_name' => 'synthetic.zip', 'saved_file_name' => 'synthetic.zip', 'batch_key' => $this->batch, 'district_id' => $this->districtId, 'status' => 'success', 'created_at' => now()]);
        DB::table('import_batches')->insert(['batch_key' => $this->batch, 'district_id' => $this->districtId, 'import_history_id' => $history, 'created_at' => now()]);
        $db = DB::connection();
        $db->getPdo()->sqliteCreateFunction('DATABASE', static fn () => 'main');
        $db->getPdo()->sqliteCreateFunction('regexp', static fn ($pattern, $value) => preg_match('~'.$pattern.'~u', (string) $value) === 1);
        $db->getPdo()->sqliteCreateFunction('CHAR_LENGTH', static fn ($value) => mb_strlen((string) $value));
        $db->getPdo()->sqliteCreateFunction('CONCAT', static fn (...$values) => implode('', $values));
        if (! in_array('INFORMATION_SCHEMA', array_column($db->select('PRAGMA database_list'), 'name'), true)) {
            $db->statement("ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA");
        }
        $db->statement('CREATE TABLE IF NOT EXISTS INFORMATION_SCHEMA.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
        foreach ([1, 2, 3] as $level) {
            foreach ([
                'student' => '_perf_id10 TEXT, _perf_grp TEXT, _perf_cardid TEXT, prename TEXT, name TEXT, surname TEXT, grp_code TEXT, gender TEXT, birday TEXT, fin_cause TEXT, trn_date2 TEXT, dep_sem TEXT',
                'grade' => '_id INTEGER PRIMARY KEY AUTOINCREMENT, _perf_std10 TEXT, _perf_semestry TEXT, _perf_sub TEXT, grade TEXT, typ_code TEXT',
                'subject' => '_perf_sub TEXT, sub_code TEXT, sub_name TEXT, sub_type TEXT, sub_credit TEXT',
                'activity' => '_id INTEGER PRIMARY KEY AUTOINCREMENT, _perf_std10 TEXT, _perf_semestry TEXT, hour TEXT, activity TEXT, transfer TEXT, trntype TEXT',
            ] as $type => $columns) {
                $table = $this->table($level, $type);
                $db->statement("CREATE TABLE {$table} ({$columns})");
                DB::table('INFORMATION_SCHEMA.TABLES')->insert(['TABLE_SCHEMA' => 'main', 'TABLE_NAME' => $table]);
            }
            $s = $this->table($level, 'student');
            $g = $this->table($level, 'grade');
            $sub = $this->table($level, 'subject');
            $act = $this->table($level, 'activity');
            $db->statement("CREATE INDEX {$s}_code ON {$s} (_perf_id10)");
            $db->statement("CREATE INDEX {$g}_student_term ON {$g} (_perf_std10, _perf_semestry, _perf_sub)");
            $db->statement("CREATE INDEX {$g}_term ON {$g} (_perf_semestry)");
            $db->statement("CREATE INDEX {$sub}_code ON {$sub} (_perf_sub)");
            $db->statement("CREATE INDEX {$act}_student_term ON {$act} (_perf_std10, _perf_semestry)");
            DB::table($sub)->insert([['_perf_sub' => 'C', 'sub_code' => 'C', 'sub_name' => 'Core', 'sub_type' => '1', 'sub_credit' => '3'], ['_perf_sub' => 'E', 'sub_code' => 'E', 'sub_name' => 'Elective', 'sub_type' => '2', 'sub_credit' => '2']]);
        }
        $this->bindRepository();
    }

    private function bindRepository(): LegacyStudentRepository
    {
        $repository = new LegacyStudentRepository(DB::connection());
        $this->app->instance(StudentRepository::class, $repository);

        return $repository;
    }

    private function table(int $level, string $type): string
    {
        return "db_{$this->batch}_{$level}_{$type}";
    }

    private function student(int $level, string $code, string $term = '69/1', string $group = '001'): void
    {
        DB::table($this->table($level, 'student'))->insert(['_perf_id10' => $code, '_perf_grp' => $group, '_perf_cardid' => '1111111111111', 'prename' => 'นาย', 'name' => 'Synthetic', 'surname' => $code, 'grp_code' => $group, 'gender' => $level === 2 ? '2' : '1', 'birday' => '2000-01-01', 'fin_cause' => '', 'trn_date2' => '', 'dep_sem' => '68/1']);
        $this->grade($level, $code, $term, 'C', '3');
    }

    private function grade(int $level, string $code, string $term, string $subject, string $grade, string $type = ''): void
    {
        DB::table($this->table($level, 'grade'))->insert(['_perf_std10' => $code, '_perf_semestry' => $term, '_perf_sub' => $subject, 'grade' => $grade, 'typ_code' => $type]);
    }

    public function test_term_gpa_matches_student_detail_and_only_reads_the_authorized_page(): void
    {
        $this->student(1, '1000000001', '68/2');
        $this->grade(1, '1000000001', '2/2568', 'E', '4');
        $this->grade(1, '1000000001', '69/1', 'C', '2');
        $this->grade(1, '1000000001', '69/1', 'E', '0');
        $this->student(1, '1000000002', '68/2');
        DB::table($this->table(1, 'grade'))->where('_perf_std10', '1000000002')->update(['grade' => '']);
        $this->student(1, '1000000003', '68/2', '002');
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]));

        $reads = [];
        DB::listen(static function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'AS raw_term') && str_contains($query->sql, 'AS row_id')) {
                $reads[] = $query->bindings;
            }
        });

        $this->getJson('/api/v1/students?term=2/2568&per_page=1&include_term_gpa=1')->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.code', '1000000001')
            ->assertJsonPath('data.0.academic.term_gpa', 3.4);
        self::assertSame([['1000000001', '1000000001']], $reads);
        $this->getJson('/api/v1/students?term=2/2568&per_page=1&page=2&include_term_gpa=1')->assertOk()
            ->assertJsonPath('data.0.code', '1000000002')
            ->assertJsonPath('data.0.academic.term_gpa', null);
        $this->getJson('/api/v1/students?term=1/2569&search=1000000001&include_term_gpa=1')->assertOk()
            ->assertJsonPath('data.0.academic.term_gpa', 2)
            ->assertJsonPath('data.0.academic.gpax', 2.88);
        $this->getJson('/api/v1/students/1000000001/grades?level=1')->assertOk()
            ->assertJsonPath('data.summary.term_summaries.1.term', '2/2568')
            ->assertJsonPath('data.summary.term_summaries.1.gpa', 3.4)
            ->assertJsonPath('data.summary.term_summaries.1.compulsory_credits', 3)
            ->assertJsonPath('data.summary.term_summaries.1.elective_credits', 2);
        $this->getJson('/api/v1/students?term=2/2568')->assertOk()->assertJsonMissingPath('data.0.academic.term_gpa');
        $this->getJson('/api/v1/students/1000000003/grades?level=1')->assertNotFound();
    }

    public function test_registration_types_filter_historical_counts_group_rows_and_both_drilldowns(): void
    {
        config()->set('system_data.student_enabled', true);
        $this->student(1, '1000000001', '68/2');
        $this->grade(1, '1000000001', '2/2568', 'E', '4', '1');
        $this->grade(1, '1000000001', '68/2', 'C', '1', '1'); // Lower duplicate does not change the registration type.
        $this->student(1, '1000000002', '68/2');
        DB::table($this->table(1, 'grade'))->where('_perf_std10', '1000000002')->update(['typ_code' => '1']);
        $this->grade(1, '1000000002', '68/2', 'E', '1', '7');
        $this->student(1, '1000000003', '68/2', '002');
        $this->grade(1, '1000000003', '68/2', 'E', '4', '1');
        $this->grade(1, '1000000001', '69/1', 'E', '4', '1');
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]));

        $report = '/api/v1/reports/registered-subjects?term=2/2568&level=1';
        foreach (['regular' => 2, 'transfer' => 2, 'all' => 4] as $type => $count) {
            $subjects = $this->getJson($report.'&view=subject&registration_type='.$type)->assertOk()
                ->assertJsonPath('data.summary.registered_records', $count)
                ->assertJsonPath('data.summary.unique_students', 2)
                ->assertJsonPath('data.rows.0.level_id', 1);
            self::assertSame($count, array_sum(array_column($subjects->json('data.group_rows'), 'registered_students')));
            $students = $this->getJson($report.'&view=student&registration_type='.$type)->assertOk()
                ->assertJsonCount(2, 'data.items')->assertJsonPath('data.summary.registered_records', $count);
            self::assertSame([$count / 2], array_values(array_unique(array_column($students->json('data.items'), 'registered_subjects'))));
        }

        $this->getJson($report.'&view=student&subject=C&registration_type=transfer')->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.student.code', '1000000002')
            ->assertJsonPath('data.items.0.is_transferred', true);
        $this->getJson('/api/v1/students/1000000001/subjects?term=2/2568&level=1&registration_type=transfer')->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.code', 'E');
        $this->getJson('/api/v1/students/1000000001/subjects?term=2/2568&level=1&registration_type=regular')->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.code', 'C');
        $this->getJson('/api/v1/students/1000000003/subjects?term=2/2568&level=1&registration_type=transfer')->assertNotFound();
        $this->getJson($report.'&registration_type=unknown')->assertUnprocessable()->assertJsonValidationErrors('registration_type');

        $otherDistrict = District::create(['name' => 'Other District', 'code' => 'other-registration-test']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $otherDistrict->id]));
        $this->getJson($report.'&registration_type=transfer')->assertOk()->assertJsonCount(0, 'data.rows');
        $this->getJson('/api/v1/students/1000000001/subjects?term=2/2568&level=1&registration_type=transfer')->assertNotFound();
    }

    public function test_g_identifiers_display_in_current_and_historical_lists_profiles_and_rosters(): void
    {
        $this->student(1, '1000000001');
        $this->grade(1, '1000000001', '68/2', 'C', '3');
        $this->student(2, '2000000001');
        DB::table($this->table(1, 'student'))->where('_perf_id10', '1000000001')->update(['_perf_cardid' => 'G123456789012']);

        foreach (['1/2569', '2/2568'] as $term) {
            $this->getJson('/api/v1/students?term='.$term.'&search=G123456789012')->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.code', '1000000001')
                ->assertJsonPath('data.0.demographics.citizen_id', 'G123456789012')
                ->assertJsonPath('data.0.demographics.citizen_id_masked', 'G-xxxx-xxxxx-xx-2');
            $this->getJson('/api/v1/students/1000000001?term='.$term.'&level=1')->assertOk()
                ->assertJsonPath('data.demographics.citizen_id', 'G123456789012');
        }
        $this->getJson('/api/v1/students/1000000001?level=1')->assertOk()
            ->assertJsonPath('data.demographics.citizen_id', 'G123456789012');
        $this->getJson('/api/v1/students?search=1111111111111')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.demographics.citizen_id', '1111111111111');

        // The non-paginated repository path is still used by other modules.
        $repository = $this->bindRepository();
        foreach ([$repository->students([$this->districtId]), $repository->studentsForTerm([$this->districtId], '2/2568')] as $roster) {
            $student = array_values(array_filter($roster, static fn ($row) => $row->code === '1000000001'))[0];
            self::assertSame('G123456789012', $student->citizenId);
            self::assertStringNotContainsString('G123456789012', json_encode($student->toSummaryArray(), JSON_THROW_ON_ERROR));
            self::assertSame('G-xxxx-xxxxx-xx-2', $student->demographics['citizen_id_masked']);
        }

        // Printing uses the same identifier; its 13 boxes must keep the G too.
        $print = $this->app->make(CourseRegistrationExportService::class)->build($this->admin, [
            'scope' => 'student', 'student' => '1000000001', 'term' => '1/2569',
        ]);
        self::assertSame(str_split('G123456789012'), $print['documents'][0]['student']['citizen_digits']);
        $numericPrint = $this->app->make(CourseRegistrationExportService::class)->build($this->admin, [
            'scope' => 'student', 'student' => '2000000001', 'term' => '1/2569',
        ]);
        self::assertSame(str_split('1111111111111'), $numericPrint['documents'][0]['student']['citizen_digits']);
    }

    public function test_g_identifiers_remain_scoped_to_the_teachers_group_and_district(): void
    {
        $this->student(1, '1000000001');
        DB::table($this->table(1, 'student'))->update(['_perf_cardid' => 'G123456789012']);
        $teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/v1/students?search=G123456789012')->assertOk()
            ->assertJsonPath('data.0.demographics.citizen_id', 'G123456789012');
        $teacher->assigned_groups = ['002'];
        $teacher->save();
        $this->getJson('/api/v1/students?search=G123456789012')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/students/1000000001?level=1')->assertNotFound();

        $other = District::create(['name' => 'Other', 'code' => 'g-code-other']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->getJson('/api/v1/students?search=G123456789012&district_id='.$this->districtId)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/students/1000000001?level=1')->assertNotFound();
    }

    public function test_historical_pagination_and_summary_are_sql_scoped_and_exclude_future_metrics(): void
    {
        foreach ([1, 2, 3] as $level) {
            for ($i = 1; $i <= 4; $i++) {
                $this->student($level, "{$level}00000000{$i}", '68/2');
            }
            $this->student($level, "{$level}999999998", '69/1');
            $this->student($level, "{$level}999999999", '69/1');
        }
        $this->grade(1, '1000000001', '69/1', 'E', '4');
        $this->grade(1, '1000000001', '68/2', 'C', '3'); // no repeated credits
        DB::table($this->table(1, 'activity'))->insert([
            ['_perf_std10' => '1000000001', '_perf_semestry' => '68/2', 'hour' => '40'],
            ['_perf_std10' => '1000000001', '_perf_semestry' => '69/1', 'hour' => '160'],
        ]);
        $queries = [];
        DB::listen(static function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $r = $this->getJson('/api/v1/students?term=2/2568&per_page=2&page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.total', 12)
            ->assertJsonPath('meta.summary.total', 12)->assertJsonPath('meta.summary.male', 8)->assertJsonPath('meta.summary.female', 4)
            ->assertJsonPath('meta.selected_term', '2/2568')->assertJsonPath('meta.current_term', '1/2569');
        self::assertSame('1000000003', $r->json('data.0.code'));
        $sql = implode("\n", $queries);
        self::assertStringContainsString('directory_page ORDER BY code ASC', $sql);
        self::assertStringContainsString('LIMIT ? OFFSET ?', $sql);
        foreach (['registered_address', 'current_address', 'phone', 'email', 'last_updated'] as $unneeded) {
            self::assertStringNotContainsString($unneeded, $sql);
        }
        $this->getJson('/api/v1/students?term=2/2568&search=1000000001')->assertOk()
            ->assertJsonPath('meta.summary.total', 1)->assertJsonPath('data.0.academic.credits_earned', 3)
            ->assertJsonPath('data.0.academic.kpch_hours', 40)->assertJsonPath('data.0.academic.gpax', 3);
        $this->getJson('/api/v1/students?term=1/2569&search=1000000001')->assertOk()->assertJsonPath('data.0.academic.credits_earned', 5)->assertJsonPath('data.0.academic.kpch_hours', 200)->assertJsonPath('data.0.academic.gpax', 3.25);
        $this->getJson('/api/v1/students?term=2/2567')->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(2, 'meta.filter_options.terms');
    }

    public function test_historical_detail_preserves_level_term_and_authorization(): void
    {
        $this->student(1, '1000000001', '68/2');
        $this->student(2, '1000000001', '68/2', '1');
        $this->student(3, '3999999999', '69/1');
        $this->grade(1, '1000000001', '69/1', 'E', '4');
        $this->getJson('/api/v1/students/1000000001?term=2/2568&level=1')->assertOk()->assertJsonPath('data.current_term', '2/2568')->assertJsonPath('data.academic.credits_earned', 3);
        $this->getJson('/api/v1/students/1000000001?term=2/2568')->assertNotFound();
        $this->getJson('/api/v1/students/1000000001?term=2/2567&level=1')->assertNotFound();
        $teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/v1/students?term=2/2568')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/students/1000000001?term=2/2568&level=2')->assertNotFound();
        $teacher->assigned_groups = [];
        $teacher->save();
        $this->getJson('/api/v1/students?term=2/2568')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_summary_cache_is_scoped_and_invalidated_by_score_revision(): void
    {
        $this->student(1, '1000000001');
        $this->student(2, '2000000001', '69/1', '002');
        $this->getJson('/api/v1/students')->assertOk()->assertJsonPath('meta.summary.total', 2);
        $queries = [];
        DB::listen(static function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->bindRepository();
        $this->getJson('/api/v1/students?page=2&per_page=1')->assertOk();
        self::assertFalse((bool) array_filter($queries, static fn ($q) => str_contains($q, 'directory_summary')));
        DB::table($this->table(1, 'grade'))->update(['grade' => '4']);
        DB::table('imported_score_edits')->insert(['district_id' => $this->districtId, 'batch_key' => $this->batch, 'education_level' => 1, 'grade_row_id' => 1, 'record_index' => 0, 'zip_entry' => '1/GRADE.DBF', 'identity' => '{}', 'values' => '{}', 'version' => 1, 'updated_by' => $this->admin->id]);
        $this->bindRepository();
        $this->getJson('/api/v1/students')->assertJsonPath('meta.summary.average_gpax', 3.5);
        $teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/v1/students')->assertOk()->assertJsonPath('meta.summary.total', 1);
        $teacher->assigned_groups = ['002'];
        $teacher->save();
        $this->getJson('/api/v1/students')->assertOk()->assertJsonPath('data.0.code', '2000000001');
        $other = District::create(['name' => 'Other', 'code' => 'other-directory']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->getJson('/api/v1/students?district_id='.$this->districtId)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filter_sort_and_kpch_status_apply_before_pagination(): void
    {
        $this->student(1, '1000000001');
        $this->student(1, '1000000002');
        $this->grade(1, '1000000002', '69/1', 'E', '4');
        DB::table($this->table(1, 'activity'))->insert(['_perf_std10' => '1000000002', '_perf_semestry' => '69/1', 'hour' => '200']);
        $this->getJson('/api/v1/students?sort=credits&direction=desc&per_page=1')->assertOk()->assertJsonPath('data.0.code', '1000000002');
        $this->getJson('/api/v1/students?kpch_status=complete')->assertOk()->assertJsonPath('meta.summary.total', 1)->assertJsonPath('data.0.code', '1000000002');
        $this->getJson('/api/v1/students?search=%25')->assertOk()->assertJsonPath('meta.summary.total', 0);
        $this->getJson('/api/v1/students?search=_')->assertOk()->assertJsonPath('meta.summary.total', 0);
    }

    public function test_student_identity_cannot_be_disambiguated_by_filtering_level_or_term(): void
    {
        $this->student(1, '1000000001');
        $this->student(2, '1000000001');
        $student = User::factory()->create(['role' => 'student', 'district_id' => $this->districtId, 'student_code' => '1000000001']);
        Sanctum::actingAs($student);
        $this->getJson('/api/v1/students?level=1')->assertForbidden();
        $this->getJson('/api/v1/students/1000000001?level=1&term=1/2569')->assertForbidden();
    }

    public function test_pending_credits_do_not_duplicate_passed_subjects_and_term_detail_apis_work(): void
    {
        $this->student(1, '1000000001', '68/2');
        $this->grade(1, '1000000001', '69/1', 'C', '');
        $this->grade(1, '1000000001', '69/1', 'E', '');
        $this->getJson('/api/v1/students?term=1/2569')->assertOk()->assertJsonPath('data.0.academic.credits_earned', 3)->assertJsonPath('data.0.academic.credits_current', 5);
        $this->getJson('/api/v1/students/1000000001/grades?level=1&term=2/2568')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.term', '2/2568');
        DB::table($this->table(1, 'activity'))->insert(['_perf_std10' => '1000000001', '_perf_semestry' => '68/2', 'hour' => '40', 'activity' => 'Synthetic activity']);
        $this->getJson('/api/v1/students/1000000001/kpch?level=1&term=2/2568')->assertOk()->assertJsonPath('data.summary.total_hours', 40);
    }

    public function test_integration_academic_service_keeps_its_existing_lookup_and_empty_term_contract(): void
    {
        $this->student(1, '1000000001');
        $result = $this->app->make(StudentAcademicService::class)->grades($this->admin, '1000000001', '2/2567', 1);
        self::assertNotNull($result);
        self::assertSame([], $result['items']);
        self::assertSame('1/2569', $result['student']->currentTerm);
        $this->getJson('/api/v1/students/1000000001?term=2/2567&level=1')->assertNotFound();
    }

    public function test_synthetic_benchmark_when_explicitly_enabled(): void
    {
        if (! getenv('SDL_DIRECTORY_BENCHMARK')) {
            $this->markTestSkipped('Opt-in synthetic directory benchmark.');
        }
        foreach ([1, 2, 3] as $level) {
            $students = [];
            $grades = [];
            for ($subject = 1; $subject <= 20; $subject++) {
                DB::table($this->table($level, 'subject'))->insert(['_perf_sub' => 'S'.$subject, 'sub_code' => 'S'.$subject, 'sub_name' => 'Synthetic '.$subject, 'sub_type' => '1', 'sub_credit' => '2']);
            }
            for ($i = 1; $i <= 500; $i++) {
                $code = $level.str_pad((string) $i, 9, '0', STR_PAD_LEFT);
                $students[] = ['_perf_id10' => $code, 'prename' => 'Mr', 'name' => 'Synthetic', 'surname' => (string) $i, 'grp_code' => 'G'.($i % 20), 'gender' => '1', 'fin_cause' => '', 'trn_date2' => '', 'dep_sem' => '68/1'];
                foreach (['68/1', '68/2', '69/1', '69/2'] as $term) {
                    for ($subject = 1; $subject <= 20; $subject++) {
                        $grades[] = ['_perf_std10' => $code, '_perf_semestry' => $term, '_perf_sub' => 'S'.$subject, 'grade' => (string) (($i % 4) + 1), 'typ_code' => ''];
                    }
                }
            }
            foreach (array_chunk($students, 200) as $chunk) {
                DB::table($this->table($level, 'student'))->insert($chunk);
            }
            foreach (array_chunk($grades, 500) as $chunk) {
                DB::table($this->table($level, 'grade'))->insert($chunk);
            }
        }
        $samples = [];
        $pageQuery = null;
        DB::listen(static function ($query) use (&$pageQuery) {
            if (str_contains($query->sql, 'directory_page ORDER BY')) {
                $pageQuery = [$query->sql, $query->bindings];
            }
        });
        foreach (['old_roster' => 'old', 'sql_page' => 'new'] as $name => $mode) {
            Cache::flush();
            for ($i = 0; $i < 6; $i++) {
                $repo = new LegacyStudentRepository(DB::connection());
                $start = hrtime(true);
                if ($mode === 'old') {
                    $result = array_slice($repo->studentsForTerm([$this->districtId], '1/2569'), 0, 25);
                } else {
                    $result = $repo->directoryPage($this->admin, ['term' => '1/2569', 'per_page' => 25])['items'];
                }
                $samples[$name][] = round((hrtime(true) - $start) / 1e6, 2);
                self::assertCount(25, $result);
            }
        }
        $evidence = ['engine' => 'isolated SQLite; synthetic data; not production MySQL', 'students' => 1500, 'grade_rows' => 120000, 'page_size' => 25, 'milliseconds_cold_then_five_warm' => $samples];
        $evidence['page_query_plan'] = array_map(fn ($row) => str_replace($this->batch, 'import_SYNTHETIC', $row->detail), DB::select('EXPLAIN QUERY PLAN '.$pageQuery[0], $pageQuery[1]));
        Cache::flush();
        for ($i = 0; $i < 6; $i++) {
            $repo = $this->bindRepository();
            $this->app->instance(StudentDirectoryService::class, new StudentDirectoryService($repo));
            foreach ($this->app['router']->getRoutes() as $route) {
                $route->flushController();
            }
            $start = hrtime(true);
            $response = $this->getJson('/api/v1/students?term=1/2569&per_page=25');
            $evidence['api_milliseconds_cold_then_five_warm'][] = round((hrtime(true) - $start) / 1e6, 2);
            $response->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.pagination.total', 1500);
        }
        file_put_contents(sys_get_temp_dir().'/sdl-directory-benchmark.json', json_encode($evidence, JSON_PRETTY_PRINT));
    }
}
