<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Repositories\LegacyStudentRepository;
use App\Models\District;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StudentAuthenticationLookupTest extends TestCase
{
    use RefreshDatabase;

    private int $district;

    private string $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->district = District::create(['name' => 'Synthetic', 'code' => 'auth-test', 'is_active' => true])->id;
        $db = DB::connection();
        $db->getPdo()->sqliteCreateFunction('DATABASE', static fn () => 'main');
        $db->getPdo()->sqliteCreateFunction('regexp', static fn ($pattern, $value) => preg_match('~'.$pattern.'~', (string) $value) === 1);
        if (! in_array('INFORMATION_SCHEMA', array_column($db->select('PRAGMA database_list'), 'name'), true)) {
            $db->statement("ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA");
        }
        $db->statement('CREATE TABLE IF NOT EXISTS INFORMATION_SCHEMA.TABLES (TABLE_SCHEMA TEXT,TABLE_NAME TEXT)');
        $this->batch = 'import_1700000000_'.bin2hex(random_bytes(4));
        $this->batch($this->batch, now()->subDay());
        foreach ([1, 2, 3] as $level) {
            $s = $this->table($level);
            $g = "db_{$this->batch}_{$level}_grade";
            $sub = "db_{$this->batch}_{$level}_subject";
            $db->statement("CREATE TABLE {$s} (_perf_id10 TEXT,_perf_cardid TEXT,_perf_grp TEXT,prename TEXT,name TEXT,surname TEXT,grp_code TEXT,fin_cause TEXT,trn_date2 TEXT)");
            $db->statement("CREATE INDEX {$s}_card ON {$s} (_perf_cardid)");
            $db->statement("CREATE TABLE {$g} (_perf_std10 TEXT,_perf_semestry TEXT)");
            $db->statement("CREATE TABLE {$sub} (_perf_sub TEXT)");
            foreach ([$s, $g, $sub] as $t) {
                DB::table('INFORMATION_SCHEMA.TABLES')->insert(['TABLE_SCHEMA' => 'main', 'TABLE_NAME' => $t]);
            }
            $this->student($level, $level.'999999998', '8888888888888', false);
            $this->student($level, $level.'999999999', '9999999999999', false);
        }
        $this->student(2, '1234567890', '1111111111111');
    }

    public function test_minimal_lookup_uses_no_academic_aggregates_or_sensitive_projection(): void
    {
        $queries = [];
        DB::listen(static function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $r = $this->repo()->findStudentForAuthentication('1111111111111', $this->district);
        self::assertSame('1234567890', $r['student_id']);
        self::assertSame('นายSynthetic Student', $r['full_name']);
        self::assertSame(2, $r['level']);
        self::assertSame(['student_id', 'full_name', 'group_code', 'class_name', 'district_id', 'district_name', 'level', 'level_name'], array_keys($r));
        $sql = implode("\n", $queries);
        foreach (['SUM(', 'AVG(', 'activity', 'virtue', 'registered_address', 'birth_date', 'phone', 'gpasem'] as $v) {
            self::assertStringNotContainsString($v, $sql);
        }
        self::assertStringContainsString('LIMIT 2', $sql);
        self::assertStringNotContainsString('1111111111111', $sql);
    }

    public function test_formatted_ids_work_and_duplicate_citizen_ids_fail_closed(): void
    {
        DB::table($this->table(2))->where('_perf_id10', '1234567890')->update(['_perf_cardid' => '1-1111-11111-11-1']);
        self::assertNotNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district));
        $this->student(3, '2234567890', '1111111111111');
        self::assertNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district));
    }

    public function test_historical_code_sharing_citizen_id_keeps_current_identity_and_rejects_current_ambiguity(): void
    {
        $this->student(2, '1111111111', '1111111111111', false);
        DB::table("db_{$this->batch}_2_grade")->insert([
            '_perf_std10' => '1111111111', '_perf_semestry' => '2/2568',
        ]);
        self::assertSame('1234567890', $this->repo()->findStudentForAuthentication('1111111111111', $this->district)['student_id']);

        DB::table("db_{$this->batch}_2_grade")->insert([
            '_perf_std10' => '1111111111', '_perf_semestry' => '1/2569',
        ]);
        self::assertNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district));
    }

    public function test_duplicate_code_across_levels_and_duplicate_physical_rows_fail_closed(): void
    {
        $this->student(3, '1234567890', '2222222222222');
        self::assertNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district));
        DB::table($this->table(3))->where('_perf_id10', '1234567890')->delete();
        $this->student(2, '1234567890', '1111111111111');
        self::assertNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district));
    }

    public function test_scope_status_and_current_cohort_rules(): void
    {
        self::assertNull($this->repo()->findStudentForAuthentication('invalid', $this->district));
        self::assertNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district + 100));
        foreach ([['fin_cause' => '1'], ['fin_cause' => '2'], ['fin_cause' => '', 'trn_date2' => '2026-11-01']] as $change) {
            DB::table($this->table(2))->where('_perf_id10', '1234567890')->update($change);
            self::assertNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district));
        }
        DB::table($this->table(2))->where('_perf_id10', '1234567890')->update(['fin_cause' => '', 'trn_date2' => '']);
        DB::table("db_{$this->batch}_2_grade")->where('_perf_std10', '1234567890')->update(['_perf_semestry' => '2/2568']);
        DB::table("db_{$this->batch}_2_grade")->insert(['_perf_std10' => '9999999999', '_perf_semestry' => '1/2569']);
        self::assertNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district));
        DB::table($this->table(1))->where('_perf_id10', '1999999999')->update(['_perf_cardid' => '4444444444444']);
        self::assertSame('1999999999', $this->repo()->findStudentForAuthentication('4444444444444', $this->district)['student_id']);
        District::whereKey($this->district)->update(['is_active' => false]);
        self::assertNull($this->repo()->findStudentForAuthentication('4444444444444', $this->district));
    }

    public function test_latest_successful_batch_does_not_fall_back(): void
    {
        $this->batch('import_1800000000_failed', now(), 'failed');
        self::assertNotNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district));
        $this->batch('import_1900000000_latest', now()->addDay());
        self::assertNull($this->repo()->findStudentForAuthentication('1111111111111', $this->district));
    }

    private function repo(): LegacyStudentRepository
    {
        return new LegacyStudentRepository(DB::connection());
    }

    private function table(int $level): string
    {
        return "db_{$this->batch}_{$level}_student";
    }

    private function student(int $level, string $code, string $id, bool $grade = true): void
    {
        DB::table($this->table($level))->insert(['_perf_id10' => $code, '_perf_cardid' => $id, '_perf_grp' => 'G1', 'prename' => 'นาย', 'name' => 'Synthetic', 'surname' => 'Student', 'grp_code' => 'G1', 'fin_cause' => '', 'trn_date2' => '']);
        if ($grade) {
            DB::table("db_{$this->batch}_{$level}_grade")->insert(['_perf_std10' => $code, '_perf_semestry' => '1/2569']);
        }
    }

    private function batch(string $key, mixed $at, string $status = 'success'): void
    {
        $h = DB::table('import_history')->insertGetId(['file_name' => 'synthetic.zip', 'saved_file_name' => 'synthetic.zip', 'batch_key' => $key, 'district_id' => $this->district, 'status' => $status, 'created_at' => $at]);
        DB::table('import_batches')->insert(['batch_key' => $key, 'district_id' => $this->district, 'import_history_id' => $h, 'created_at' => $at]);
    }
}
