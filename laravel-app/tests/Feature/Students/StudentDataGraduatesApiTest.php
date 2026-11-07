<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\DemoStudentRepository;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class StudentDataGraduatesApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/integrations/student-data/graduates';

    private District $district;

    private District $otherDistrict;

    private User $admin;

    private string $batch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.student_enabled' => true]);
        $this->district = District::query()->create(['name' => 'อำเภอทดสอบ', 'code' => 'graduates', 'is_active' => true]);
        $this->otherDistrict = District::query()->create(['name' => 'อำเภออื่น', 'code' => 'graduates-other', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id, 'disabled_at' => null]);
        StudentApiClient::query()->create([
            'name' => 'graduate-test', 'user_id' => $this->admin->id, 'district_id' => $this->district->id,
            'token_hash' => hash('sha256', 'graduates-token'), 'abilities' => ['student-data:read'], 'expires_at' => now()->addHour(),
        ]);

        // Emulate only MySQL table discovery; all graduate queries execute on SQLite.
        $connection = DB::connection();
        $connection->getPdo()->sqliteCreateFunction('DATABASE', static fn (): string => 'main');
        if (! in_array('INFORMATION_SCHEMA', array_column($connection->select('PRAGMA database_list'), 'name'), true)) {
            $connection->statement("ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA");
        }
        $connection->statement('CREATE TABLE IF NOT EXISTS INFORMATION_SCHEMA.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
        $connection->statement('CREATE TABLE IF NOT EXISTS INFORMATION_SCHEMA.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');

        // Unique names avoid the service's process-local column cache across fixtures.
        $this->batch = 'import_1700000000_'.bin2hex(random_bytes(4));
        $this->createBatch($this->batch, $this->district->id, now()->subDay());
        foreach ([1 => 'fin_sem', 2 => 'fin_sem2', 3 => 'finsem'] as $level => $termColumn) {
            $student = $this->studentTable($level);
            Schema::create($student, function (Blueprint $table) use ($termColumn): void {
                foreach (['_perf_id10', 'prename', 'name', 'surname', 'grp_code', '_perf_grp', 'fin_cause', $termColumn, 'cardid', 'phone'] as $column) {
                    $table->string($column)->nullable();
                }
            });
            foreach ([$student, "db_{$this->batch}_{$level}_grade", "db_{$this->batch}_{$level}_subject"] as $name) {
                DB::table('INFORMATION_SCHEMA.TABLES')->insert(['TABLE_SCHEMA' => 'main', 'TABLE_NAME' => $name]);
            }
            foreach (Schema::getColumnListing($student) as $column) {
                DB::table('INFORMATION_SCHEMA.COLUMNS')->insert(['TABLE_SCHEMA' => 'main', 'TABLE_NAME' => $student, 'COLUMN_NAME' => $column]);
            }
            $this->insertStudent($level, "GRAD{$level}", '1', $level === 2 ? '69/1' : '1/2569', "Graduate{$level}");
            $this->insertStudent($level, "OLD{$level}", '1', '68/2', "Previous{$level}");
            $this->insertStudent($level, "ACTIVE{$level}", '', '1/2569', "Active{$level}");
            $this->insertStudent($level, "TRANSFER{$level}", '2', '1/2569', "Transfer{$level}");
        }
        $group = "db_{$this->batch}_1_group";
        Schema::create($group, function (Blueprint $table): void {
            $table->string('_perf_grp');
            $table->string('grp_name');
        });
        DB::table($group)->insert(['_perf_grp' => 'G1', 'grp_name' => 'กลุ่มหนึ่ง']);
        DB::table('INFORMATION_SCHEMA.TABLES')->insert(['TABLE_SCHEMA' => 'main', 'TABLE_NAME' => $group]);
    }

    public function test_latest_graduates_are_safe_and_include_all_three_levels(): void
    {
        $response = $this->withToken('graduates-token')->getJson(self::ENDPOINT)
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.code', 'GRAD1')
            ->assertJsonPath('data.0.name.full_name', 'นายGraduate1 Test')
            ->assertJsonPath('data.0.group.name', 'กลุ่มหนึ่ง')
            ->assertJsonPath('data.0.graduation_term', '1/2569')
            ->assertJsonPath('meta.terms', ['1/2569', '2/2568'])
            ->assertJsonPath('meta.selected_term', '1/2569')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.sensitive_identifiers_included', false)
            ->assertJsonPath('meta.api_contract', 'student-data-v1')
            ->assertHeader('Vary', 'Authorization');

        self::assertSame([1, 2, 3], array_column(array_column($response->json('data'), 'level'), 'id'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        foreach (['cardid', 'citizen_id', '1111111111111', 'phone', '0899999999', 'ACTIVE', 'TRANSFER', 'raw_term', 'fin_cause'] as $value) {
            self::assertStringNotContainsString($value, $response->getContent());
        }
    }

    public function test_historical_term_level_group_and_search_filters_use_graduation_records(): void
    {
        foreach (['G1', 'กลุ่มหนึ่ง'] as $group) {
            $this->withToken('graduates-token')->getJson(self::ENDPOINT.'?'.http_build_query([
                'term' => '2/2568', 'level' => 1, 'group' => $group, 'search' => 'Previous',
            ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'OLD1')
                ->assertJsonPath('data.0.graduation_term', '2/2568');
        }
        foreach (['1111111111111', "' OR 1=1 --"] as $search) {
            $this->withToken('graduates-token')->getJson(self::ENDPOINT.'?'.http_build_query(['search' => $search]))
                ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.pagination.total', 0);
        }
        $this->withToken('graduates-token')->getJson(self::ENDPOINT.'?term=2/2567')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.selected_term', '2/2567')
            ->assertJsonPath('meta.terms', ['1/2569', '2/2568']);
    }

    public function test_pagination_is_global_bounded_and_does_not_clamp_empty_pages(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach ([1, 2, 3] as $page) {
            $this->withToken('graduates-token')->getJson(self::ENDPOINT."?per_page=1&page={$page}")
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', "GRAD{$page}")
                ->assertJsonPath('meta.pagination.total', 3)->assertJsonPath('meta.pagination.last_page', 3)
                ->assertJsonPath('meta.pagination.from', $page)->assertJsonPath('meta.pagination.to', $page);
        }
        self::assertCount(3, array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'LIMIT ? OFFSET ?')));
        $this->withToken('graduates-token')->getJson(self::ENDPOINT.'?per_page=1&page=999')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.pagination.current_page', 999)
            ->assertJsonPath('meta.pagination.from', null)->assertJsonPath('meta.pagination.to', null);
        foreach (['per_page=101', 'per_page=0', 'page=0', 'level=4', 'term=3/2569', 'term=69/1'] as $query) {
            $this->withToken('graduates-token')->getJson(self::ENDPOINT.'?'.$query)->assertUnprocessable();
        }
    }

    public function test_token_authentication_district_origin_and_revocation_boundaries_apply(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->actingAs($this->admin)->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->withToken('incorrect')->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->withToken('graduates-token')->getJson(self::ENDPOINT.'?district_id='.$this->otherDistrict->id)->assertUnprocessable();
        $this->withToken('graduates-token')->withHeader('X-District-Id', (string) $this->otherDistrict->id)
            ->getJson(self::ENDPOINT)->assertUnprocessable();
        $this->flushHeaders();
        $this->withToken('graduates-token')->withHeader('Origin', 'https://partner.example.test')->getJson(self::ENDPOINT)->assertForbidden();
        $this->flushHeaders();
        StudentApiClient::query()->update(['revoked_at' => now()]);
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_latest_successful_batch_and_super_admin_token_district_are_authoritative(): void
    {
        $this->admin->update(['role' => 'super_admin', 'district_id' => null]);
        $this->createBatch('import_1800000000_other', $this->otherDistrict->id, now());
        $this->createBatch('import_1800000001_failed', $this->district->id, now(), 'failed');
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('meta.pagination.total', 3);
        $this->createBatch('import_1900000000_latest', $this->district->id, now()->addDay());
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.pagination.total', 0)->assertJsonPath('meta.terms', [])->assertJsonPath('meta.selected_term', null);
    }

    public function test_expired_wrong_ability_disabled_owner_and_inactive_district_are_rejected(): void
    {
        $client = StudentApiClient::query()->firstOrFail();
        $client->update(['expires_at' => now()->subMinute()]);
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertUnauthorized();
        $client->update(['expires_at' => null, 'abilities' => ['other:read']]);
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertUnauthorized();
        $client->update(['abilities' => ['student-data:read']]);
        $this->admin->forceFill(['disabled_at' => now()])->save();
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->admin->forceFill(['disabled_at' => null])->save();
        $this->district->update(['is_active' => false]);
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_proxy_token_header_and_client_rate_limit_are_supported(): void
    {
        config(['system_data.student_api_rate_limit_per_minute' => 1]);
        $this->withHeader('X-Student-Data-Token', 'graduates-token')->getJson(self::ENDPOINT)->assertOk();
        $this->getJson(self::ENDPOINT)->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_fincause_alias_is_supported_and_missing_student_codes_are_excluded(): void
    {
        Schema::table($this->studentTable(3), static fn (Blueprint $table) => $table->renameColumn('fin_cause', 'fincause'));
        DB::table('INFORMATION_SCHEMA.COLUMNS')->where('TABLE_NAME', $this->studentTable(3))->where('COLUMN_NAME', 'fin_cause')
            ->update(['COLUMN_NAME' => 'fincause']);
        $this->insertStudent(1, '', '1', '1/2569', 'No identity');
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.code', 'GRAD3');
    }

    public function test_unknown_graduation_status_is_not_treated_as_graduated_and_api_remains_read_only(): void
    {
        Schema::table($this->studentTable(3), static fn (Blueprint $table) => $table->dropColumn('fin_cause'));
        DB::table('INFORMATION_SCHEMA.COLUMNS')->where('TABLE_NAME', $this->studentTable(3))->where('COLUMN_NAME', 'fin_cause')->delete();
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(2, 'data');
        foreach (['postJson', 'patchJson', 'deleteJson'] as $method) {
            $this->withToken('graduates-token')->{$method}(self::ENDPOINT)->assertMethodNotAllowed();
        }
    }

    public function test_demo_source_keeps_safe_search_and_the_same_payload(): void
    {
        config(['system_data.student_enabled' => false]);
        $base = (new DemoStudentRepository)->students()[0];
        $student = new Student(...[
            ...get_object_vars($base), 'districtId' => $this->district->id, 'status' => 'graduated',
            'currentTerm' => '1/2569', 'citizenId' => '1111111111111',
        ]);
        $repository = Mockery::mock(StudentRepository::class);
        $repository->shouldReceive('students')->andReturn([$student]);
        $this->app->instance(StudentRepository::class, $repository);
        $this->withToken('graduates-token')->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.level.id', $student->level)->assertJsonPath('data.0.graduation_term', '1/2569');
        $this->withToken('graduates-token')->getJson(self::ENDPOINT.'?search=1111111111111')->assertOk()->assertJsonCount(0, 'data');
    }

    private function studentTable(int $level): string
    {
        return "db_{$this->batch}_{$level}_student";
    }

    private function insertStudent(int $level, string $code, string $cause, string $term, string $name): void
    {
        $termColumn = [1 => 'fin_sem', 2 => 'fin_sem2', 3 => 'finsem'][$level];
        DB::table($this->studentTable($level))->insert([
            '_perf_id10' => $code, 'prename' => 'นาย', 'name' => $name, 'surname' => 'Test',
            'grp_code' => "G{$level}", '_perf_grp' => "G{$level}", 'fin_cause' => $cause, $termColumn => $term,
            'cardid' => '1111111111111', 'phone' => '0899999999',
        ]);
    }

    private function createBatch(string $key, int $districtId, mixed $createdAt, string $status = 'success'): void
    {
        $history = DB::table('import_history')->insertGetId([
            'file_name' => 'synthetic.zip', 'saved_file_name' => 'synthetic.zip', 'batch_key' => $key,
            'district_id' => $districtId, 'status' => $status, 'created_at' => $createdAt,
        ]);
        DB::table('import_batches')->insert([
            'batch_key' => $key, 'district_id' => $districtId, 'import_history_id' => $history, 'created_at' => $createdAt,
        ]);
    }
}
