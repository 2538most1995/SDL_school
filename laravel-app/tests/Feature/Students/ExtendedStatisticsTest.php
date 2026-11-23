<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Support\ExtendedStudentStatistics;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ExtendedStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private string $batch;

    private int $districtId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('system_data.student_enabled', true);
        $this->districtId = District::create(['name' => 'Synthetic Statistics', 'code' => 'extended-stats', 'is_active' => true])->id;
        $this->admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->districtId]);
        Sanctum::actingAs($this->admin);
        $this->batch = 'import_1700000099_'.bin2hex(random_bytes(4));
        $history = DB::table('import_history')->insertGetId(['file_name' => 'synthetic.zip', 'saved_file_name' => 'synthetic.zip', 'batch_key' => $this->batch, 'district_id' => $this->districtId, 'status' => 'success', 'created_at' => now()]);
        DB::table('import_batches')->insert(['batch_key' => $this->batch, 'district_id' => $this->districtId, 'import_history_id' => $history, 'created_at' => now()]);
        $db = DB::connection();
        $db->getPdo()->sqliteCreateFunction('DATABASE', static fn () => 'main');
        if (! in_array('INFORMATION_SCHEMA', array_column($db->select('PRAGMA database_list'), 'name'), true)) {
            $db->statement("ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA");
        }
        $db->statement('CREATE TABLE IF NOT EXISTS INFORMATION_SCHEMA.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
        foreach ([1, 2, 3] as $level) {
            $tables = [
                'student' => '_perf_id10 TEXT, _perf_grp TEXT, prename TEXT, name TEXT, surname TEXT, grp_code TEXT, gender TEXT, occtyp TEXT, age TEXT, nt_sem TEXT, nt_nosem TEXT, nt_sara1 TEXT, nt_sara2 TEXT, nnet TEXT, fin_cause TEXT, fin_sem TEXT',
                'grade' => '_id INTEGER PRIMARY KEY AUTOINCREMENT, _perf_std10 TEXT, _perf_semestry TEXT, _perf_sub TEXT, grade TEXT, typ_code TEXT, method TEXT, learning TEXT',
                'subject' => '_perf_sub TEXT, sub_code TEXT, sub_name TEXT, sub_type TEXT, sub_credit TEXT',
                'activity' => '_perf_std10 TEXT, _perf_semestry TEXT, hour TEXT',
                'virtue' => '_id INTEGER PRIMARY KEY AUTOINCREMENT, _perf_std10 TEXT, _perf_semester TEXT, sumlevel TEXT',
            ];
            foreach ($tables as $type => $columns) {
                $table = $this->table($level, $type);
                $db->statement("CREATE TABLE {$table} ({$columns})");
                DB::table('INFORMATION_SCHEMA.TABLES')->insert(['TABLE_SCHEMA' => 'main', 'TABLE_NAME' => $table]);
            }
            [$comp, $elec] = match ($level) {
                1 => [36, 12], 2 => [40, 16], 3 => [44, 32]
            };
            foreach ([['C', '1', $comp], ['E', '2', $elec]] as [$code, $type, $credit]) {
                DB::table($this->table($level, 'subject'))->insert(['_perf_sub' => $code, 'sub_name' => $code, 'sub_type' => $type, 'sub_credit' => $credit]);
            }
        }
    }

    public function test_registered_population_age_boundaries_and_gender_are_preserved_across_levels(): void
    {
        foreach ([1, 2, 3] as $level) {
            foreach ([0, 15, 16, 24, 25, null] as $i => $age) {
                $this->student($level, "{$level}00000000{$i}", ['age' => $age, 'gender' => $i % 2 ? '2' : '1']);
            }
        }
        $age = $this->report('age-statistics', '&age_ranges=0-15,16-24')->assertOk()->assertJsonPath('data.total', 18);
        $counts = array_count_values(array_column($age->json('data.rows'), 'age_band'));
        self::assertSame(['0-15 ปี' => 6, '16-24 ปี' => 6, 'อยู่นอกช่วงที่กำหนด' => 3, 'ไม่ระบุอายุ' => 3], $counts);
        $this->report('gender-statistics')->assertOk()->assertJsonPath('data.total', 18);
        $this->report('gender-statistics', '&term=2/2568')->assertJsonPath('data.total', 0);
    }

    public function test_moral_report_counts_recorded_results_once_per_level_and_term_with_staff_scope(): void
    {
        foreach ([
            [1, '1000000001', '001', '69/1', '1'],
            [1, '1000000001', '001', '1-2569', '3'], // Latest valid result for the same student and term.
            [1, '1000000001', '001', '1/2569', ''],
            [1, '1000000001', '001', '68/2', '2'],
            [1, '1000000002', '1', '69/1', '2'],
            [2, '1000000001', '001', '69/1', '1'], // Same code in another level.
            [3, '3000000001', '001', '69/1', '0'],
            [3, '3999999999', null, '69/2', '3'], // No matching student.
        ] as [$level, $code, $group, $term, $result]) {
            $students = DB::table($this->table($level, 'student'));
            if ($group !== null && ! $students->where('_perf_id10', $code)->exists()) {
                $students->insert(['_perf_id10' => $code, 'grp_code' => $group]);
            }
            DB::table($this->table($level, 'virtue'))->insert(['_perf_std10' => $code, '_perf_semester' => $term, 'sumlevel' => $result]);
        }

        $url = '/api/v1/reports/moral';
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.terms', ['1/2569', '2/2568'])
            ->assertJsonPath('data.selected_term', '1/2569')
            ->assertJsonPath('data.rows.0.counts', [0, 0, 1, 1])
            ->assertJsonPath('data.rows.1.counts', [0, 1, 0, 0])
            ->assertJsonPath('data.rows.2.counts', [1, 0, 0, 0])
            ->assertJsonPath('data.rows.3.counts', null)
            ->assertJsonMissing(['student_code' => '1000000001']);
        $this->getJson($url.'?term=2/2568')->assertOk()->assertJsonPath('data.rows.0.counts', [0, 0, 1, 0]);
        $this->getJson($url.'?term=bad')->assertUnprocessable();

        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]));
        $this->getJson($url)->assertOk()->assertJsonPath('data.rows.0.counts', [0, 0, 0, 1]);
        $this->getJson($url.'?term=1/2569&group=1')->assertOk()->assertJsonPath('data.rows.0.counts', [0, 0, 0, 0]);

        $other = District::create(['name' => 'Other moral district', 'code' => 'other-moral']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->getJson($url)->assertOk()->assertJsonPath('data.rows.0.counts', null);
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->districtId]));
        $this->getJson($url)->assertForbidden();
    }

    public function test_moral_report_uses_only_assessments_at_or_before_graduation(): void
    {
        DB::statement('ALTER TABLE '.$this->table(2, 'student').' RENAME COLUMN fin_cause TO fincause');
        DB::statement('ALTER TABLE '.$this->table(2, 'student').' RENAME COLUMN fin_sem TO fin_sem2');
        DB::statement('ALTER TABLE '.$this->table(3, 'student').' DROP COLUMN fin_cause');

        foreach ([
            [1, '1000000001', '001', ['fin_cause' => ' 1 ', 'fin_sem' => '69/1'], [['69/1', '1'], ['1-2569', '2'], ['69/2', '0']]],
            [1, '1000000002', '001', ['fin_cause' => '1', 'fin_sem' => '1-2569'], [['69/2', '3'], ['68/2', '1'], ['68/1', '2'], ['69/2', ''], ['bad', '2']]],
            [1, '1000000003', '001', ['fin_cause' => '0', 'fin_sem' => '69/1'], [['69/1', '0']]],
            [1, '1000000004', '001', ['fin_cause' => '1', 'fin_sem' => '68/2'], [['69/1', '3']]],
            [1, '1000000005', '1', ['fin_cause' => '1', 'fin_sem' => '69/1'], [['68/2', '1']]],
            [1, '1000000006', '001', ['fin_cause' => '1', 'fin_sem' => '69/1'], [['69/1', '9'], ['69/2', '2']]],
            [2, '1000000001', '001', ['fincause' => '1', 'fin_sem2' => '69/1'], [['68/2', '0']]],
            [3, '3000000001', '001', ['fin_sem' => '69/1'], [['69/1', '3']]],
        ] as [$level, $code, $group, $graduation, $assessments]) {
            DB::table($this->table($level, 'student'))->insert(['_perf_id10' => $code, 'grp_code' => $group, ...$graduation]);
            foreach ($assessments as [$term, $result]) {
                DB::table($this->table($level, 'virtue'))->insert(['_perf_std10' => $code, '_perf_semester' => $term, 'sumlevel' => $result]);
            }
        }

        $url = '/api/v1/reports/moral';
        $this->getJson($url.'?graduated_only=0&term=1/2569')->assertOk()
            ->assertJsonPath('data.rows.0.counts', [1, 0, 1, 1])
            ->assertJsonPath('data.rows.2.counts', [0, 0, 0, 1]);
        $this->getJson($url.'?graduated_only=0&term=2/2569')->assertOk()
            ->assertJsonPath('data.rows.0.counts', [1, 0, 1, 1]);
        $this->getJson($url.'?graduated_only=1')->assertOk()
            ->assertJsonPath('data.terms', ['1/2569', '2/2568'])
            ->assertJsonPath('data.selected_term', '1/2569')
            ->assertJsonPath('data.rows.0.counts', [0, 2, 1, 0])
            ->assertJsonPath('data.rows.1.counts', [1, 0, 0, 0])
            ->assertJsonPath('data.rows.2.counts', null)
            ->assertJsonMissing(['student_code' => '1000000001']);
        $this->getJson($url.'?graduated_only=1&term=2/2568')->assertOk()
            ->assertJsonPath('data.rows.0.counts', [0, 0, 0, 0])
            ->assertJsonPath('data.rows.1.counts', [0, 0, 0, 0]);
        $this->getJson($url.'?graduated_only=all')->assertUnprocessable()->assertJsonValidationErrors('graduated_only');

        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]));
        $this->getJson($url.'?graduated_only=1&term=1/2569&group=001')->assertOk()
            ->assertJsonPath('data.rows.0.counts', [0, 1, 1, 0]);
        $this->getJson($url.'?graduated_only=1&term=1/2569&group=1')->assertOk()
            ->assertJsonPath('data.rows.0.counts', [0, 0, 0, 0]);
    }

    public function test_target_group_uses_occtyp_alongside_expected_graduation_and_registration_dimensions(): void
    {
        $this->student(1, '6910000001', ['occtyp' => ' 1 ', 'grp_code' => '001']);
        $this->student(1, '6910000002', ['occtyp' => '03', 'grp_code' => '1']);
        $this->grade(1, '6910000001', 'E', '4');
        $this->grade(1, '6910000002', 'E', '4');
        $this->report('gender-statistics')->assertOk()->assertJsonPath('data.rows.1.target_group', 'ผู้สูงอายุ');

        $this->putJson('/api/v1/settings/statistics-report', [
            'report' => 'expected-graduates', 'vertical' => ['target_group'], 'horizontal' => ['nnet', 'subject'], 'orientation' => 'vertical',
        ])->assertOk()->assertJsonPath('data.vertical', ['target_group']);
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]));
        $this->getJson('/api/v1/reports/expected-graduates?term=1/2569&include_registration_dimensions=1')
            ->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.rows.0.target_group', 'เด็กด้อยโอกาส')
            ->assertJsonPath('data.rows.0.nnet', 'มีสิทธิ์สอบ')
            ->assertJsonCount(2, 'data.rows.0.registration_dimensions')
            ->assertJsonMissingPath('data.rows.0.target_group_code');
    }

    public function test_invalid_age_ranges_and_unknown_reports_are_rejected(): void
    {
        foreach (['0-15,15-24', '24-16', '0-121', 'hello'] as $ranges) {
            $this->report('age-statistics', '&age_ranges='.rawurlencode($ranges))->assertUnprocessable()->assertJsonValidationErrors('age_ranges');
        }
        $this->report('unrecognized')->assertNotFound();
    }

    public function test_exam_disqualification_and_nnet_evidence_use_selected_term(): void
    {
        $this->student(1, '1000000001', ['nt_sem' => '68/2']);
        $this->student(1, '1000000002', ['nt_sem' => '69/2']);
        $this->student(1, '1000000003', ['nt_sem' => '0/0']);
        $this->grade(1, '1000000001', 'E', 'มส');
        $this->grade(1, '1000000001', 'C', 'ม');
        $this->grade(1, '1000000002', 'E', 'มส', '68/2');
        $this->grade(1, '1000000003', 'E', '0');
        $this->report('exam-disqualified')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.student_id', '1000000001');
        $rows = $this->report('nnet-statistics')->assertOk()->json('data.rows');
        self::assertSame(['สอบแล้ว', 'ยังไม่ได้สอบ', 'ยังไม่ได้สอบ'], array_column($rows, 'nnet'));
    }

    public function test_nnet_recognizes_scores_flags_and_exam_subjects_but_not_zero_or_future_results(): void
    {
        foreach ([1, 2, 3, 4, 5] as $i) {
            $this->student(2, "200000000{$i}", match ($i) {
                1 => ['nt_sara1' => '15'], 2 => ['nnet' => 'PASS'], 5 => ['nt_sem' => '69/2', 'nt_sara1' => '20'], default => [],
            });
        }
        DB::table($this->table(2, 'subject'))->insert(['_perf_sub' => 'N-NET', 'sub_name' => 'N-NET', 'sub_credit' => '0', 'sub_type' => '2']);
        $this->grade(2, '2000000003', 'N-NET', '20');
        $this->grade(2, '2000000004', 'N-NET', '0');
        $this->grade(2, '2000000005', 'N-NET', '20', '69/2');
        $rows = $this->report('nnet-statistics')->assertOk()->json('data.rows');
        self::assertSame(['สอบแล้ว', 'สอบแล้ว', 'สอบแล้ว', 'ยังไม่ได้สอบ', 'ยังไม่ได้สอบ'], array_column($rows, 'nnet'));
    }

    public function test_new_report_preferences_default_to_group_rows_and_level_columns_and_save_independently(): void
    {
        foreach (ExtendedStudentStatistics::KINDS as $kind) {
            $this->getJson('/api/v1/settings/statistics-report?report='.$kind)->assertOk()
                ->assertJsonPath('data.vertical', ['group'])->assertJsonPath('data.horizontal', ['level']);
        }
        $this->putJson('/api/v1/settings/statistics-report', [
            'report' => 'age-statistics', 'vertical' => ['group'], 'horizontal' => ['level', 'age_band'], 'orientation' => 'horizontal',
        ])->assertOk()->assertJsonPath('data.horizontal', ['level', 'age_band']);
        $this->getJson('/api/v1/settings/statistics-report?report=gender-statistics')->assertJsonPath('data.saved', false);
    }

    public function test_missing_activity_source_is_reported_instead_of_assuming_zero_hours(): void
    {
        $this->student(1, '1000000001');
        DB::table('INFORMATION_SCHEMA.TABLES')->where('TABLE_NAME', $this->table(1, 'activity'))->delete();
        $this->report('credit-pending')->assertUnprocessable()->assertJsonValidationErrors('report');
    }

    public function test_completed_earned_credits_require_each_component_and_split_all_missing_conditions(): void
    {
        foreach ([1, 2, 3] as $level) {
            foreach ([1, 2, 3, 4, 5, 6] as $i) {
                $code = "{$level}00000000{$i}";
                $this->student($level, $code, ['nt_sem' => in_array($i, [1, 4], true) ? '68/2' : '']);
                $this->grade($level, $code, 'C', '4', '68/2');
                $this->grade($level, $code, 'C', '4', '69/1'); // repeat must not add credits
                if ($i !== 5) {
                    $this->grade($level, $code, 'E', $i === 6 ? '' : '4', '69/1');
                }
                if (in_array($i, [2, 4], true)) {
                    DB::table($this->table($level, 'activity'))->insert(['_perf_std10' => $code, '_perf_semestry' => '69/1', 'hour' => '200']);
                } else {
                    DB::table($this->table($level, 'activity'))->insert(['_perf_std10' => $code, '_perf_semestry' => '69/2', 'hour' => '200']); // future hours ignored
                }
            }
        }
        $rows = $this->report('credit-pending')->assertOk()->assertJsonPath('data.total', 9)->json('data.rows');
        self::assertSame(['ขาด กพช.' => 3, 'ขาด N-NET' => 3, 'ขาด กพช. และ N-NET' => 3], array_count_values(array_column($rows, 'completion_status')));
        self::assertArrayNotHasKey('nt_sem', $rows[0]);
        self::assertArrayNotHasKey('nnet_flag', $rows[0]);
    }

    public function test_teacher_group_district_and_student_boundaries_apply_to_every_report(): void
    {
        $this->student(1, '1000000001', ['grp_code' => '001']);
        $this->student(1, '1000000002', ['grp_code' => '1']);
        $teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]);
        Sanctum::actingAs($teacher);
        $this->report('gender-statistics')->assertOk()->assertJsonPath('data.total', 1);
        $this->report('gender-statistics', '&group=1')->assertJsonPath('data.total', 0);
        $teacher->assigned_groups = [];
        $teacher->save();
        $this->report('nnet-statistics')->assertOk()->assertJsonPath('data.total', 0);
        $other = District::create(['name' => 'Other', 'code' => 'other-stats']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->report('gender-statistics')->assertOk()->assertJsonPath('data.total', 0);
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->districtId]));
        foreach (ExtendedStudentStatistics::KINDS as $kind) {
            $this->report($kind)->assertForbidden();
        }
    }

    public function test_subjects_and_learning_methods_are_correlated_scoped_and_term_specific(): void
    {
        $this->student(1, '1000000001', ['grp_code' => '001']);
        $this->grade(1, '1000000001', 'E', '4');
        $this->grade(1, '1000000001', 'E', '4'); // duplicate source registration
        DB::table($this->table(1, 'grade'))->where('_perf_sub', 'C')->update(['method' => '1']);
        DB::table($this->table(1, 'grade'))->where('_perf_sub', 'E')->update(['method' => '', 'learning' => '2']);
        $this->grade(1, '1000000001', 'FUTURE', '4', '69/2');
        $this->student(2, '1000000001', ['grp_code' => '1']); // same code, another level
        DB::table($this->table(2, 'grade'))->update(['method' => '3']);

        $this->report('age-statistics')->assertOk()->assertJsonMissingPath('data.rows.0.registration_dimensions');
        $rows = $this->report('age-statistics', '&include_registration_dimensions=1')->assertOk()->json('data.rows');
        self::assertSame([
            ['subject_code' => 'C', 'subject_name' => 'C', 'learning_method' => 'พบกลุ่ม'],
            ['subject_code' => 'E', 'subject_name' => 'E', 'learning_method' => 'ทางไกล'],
        ], $rows[0]['registration_dimensions']);
        self::assertSame('ทางไกลพิเศษ', $rows[1]['registration_dimensions'][0]['learning_method']);
        $this->getJson('/api/v1/reports/students/registration-statistics?term=1/2569&view=student&include_registration_dimensions=1')
            ->assertOk()->assertJsonPath('data.rows.0.registration_dimensions.0.learning_method', 'พบกลุ่ม');
        DB::table($this->table(1, 'grade'))->where('_perf_sub', 'C')->update(['typ_code' => '1']);
        $this->getJson('/api/v1/reports/transfers?term=1/2569&include_registration_dimensions=1')
            ->assertOk()->assertJsonCount(1, 'data.rows.0.registration_dimensions')
            ->assertJsonPath('data.rows.0.registration_dimensions.0.subject_code', 'C');

        $teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->districtId, 'assigned_groups' => ['001']]);
        Sanctum::actingAs($teacher);
        $this->report('age-statistics', '&include_registration_dimensions=1')
            ->assertJsonCount(1, 'data.rows')->assertJsonCount(2, 'data.rows.0.registration_dimensions');
        $this->report('age-statistics', '&include_registration_dimensions=1&group=1')->assertJsonPath('data.total', 0);
    }

    public function test_dimensions_can_be_saved_for_every_statistics_report(): void
    {
        foreach (['new-students', 'registration-statistics', 'graduates', 'expected-graduates', 'transfers', ...ExtendedStudentStatistics::KINDS] as $report) {
            $this->putJson('/api/v1/settings/statistics-report', [
                'report' => $report, 'vertical' => ['group', 'learning_method'], 'horizontal' => ['level', 'subject'], 'orientation' => 'vertical',
            ])->assertOk()->assertJsonPath('data.horizontal', ['level', 'subject']);
        }
    }

    public function test_missing_method_columns_remain_unknown_without_using_a_different_students_method(): void
    {
        $this->student(1, '1000000001');
        DB::statement('ALTER TABLE '.$this->table(1, 'grade').' DROP COLUMN method');
        DB::statement('ALTER TABLE '.$this->table(1, 'grade').' DROP COLUMN learning');
        $this->report('gender-statistics', '&include_registration_dimensions=1')->assertOk()
            ->assertJsonPath('data.rows.0.registration_dimensions.0.learning_method', 'ไม่ระบุวิธีเรียน');
    }

    private function table(int $level, string $type): string
    {
        return "db_{$this->batch}_{$level}_{$type}";
    }

    private function student(int $level, string $code, array $extra = []): void
    {
        DB::table($this->table($level, 'student'))->insert([...['_perf_id10' => $code, 'prename' => 'นาย', 'name' => 'Synthetic', 'surname' => 'Student', 'grp_code' => 'G1', 'gender' => '1', 'age' => '20'], ...$extra]);
        $this->grade($level, $code, 'C', '', '69/1');
    }

    private function grade(int $level, string $code, string $subject, string $grade, string $term = '69/1'): void
    {
        DB::table($this->table($level, 'grade'))->insert(['_perf_std10' => $code, '_perf_semestry' => $term, '_perf_sub' => $subject, 'grade' => $grade, 'typ_code' => '']);
    }

    private function report(string $kind, string $query = ''): TestResponse
    {
        return $this->getJson('/api/v1/reports/statistics/'.$kind.'?term=1/2569'.$query);
    }
}
