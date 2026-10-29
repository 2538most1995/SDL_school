<?php

namespace Tests\Feature\Students;

use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SenaCareTeacherIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private User $teacher;

    private StudentApiClient $client;

    private string $base = '/api/v1/integrations/student-data';

    protected function setUp(): void
    {
        parent::setUp();
        $this->district = District::query()->create(['name' => 'Test', 'code' => 'test', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id, 'disabled_at' => null]);
        $this->teacher = User::factory()->create([
            'username' => 'real.teacher', 'email' => 'teacher@example.test', 'password' => 'source-secret',
            'role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['G01'], 'disabled_at' => null,
        ]);
        $this->client = StudentApiClient::query()->create([
            'name' => 'sena-care', 'user_id' => $admin->id, 'district_id' => $this->district->id,
            'token_hash' => hash('sha256', 'test-token'), 'expires_at' => now()->addDay(),
            'abilities' => ['student-data:read', 'sena-care:teachers', 'sena-care:authenticate'],
        ]);
    }

    public function test_teacher_and_auth_endpoints_require_explicit_abilities_and_token(): void
    {
        $this->getJson($this->base.'/teachers')->assertUnauthorized();
        $this->client->update(['abilities' => ['student-data:read']]);
        $this->withToken('test-token')->getJson($this->base.'/teachers')->assertForbidden();
        $this->withToken('test-token')->getJson($this->base.'/groups')->assertForbidden();
        $this->withToken('test-token')->postJson($this->base.'/teacher-authenticate', [
            'identifier' => 'real.teacher', 'password' => 'source-secret',
        ])->assertForbidden();
    }

    public function test_teacher_list_is_paginated_district_scoped_and_excludes_secrets(): void
    {
        $other = District::query()->create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        User::factory()->create(['role' => 'teacher', 'district_id' => $other->id]);
        User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'disabled_at' => now()]);
        $response = $this->withToken('test-token')->getJson($this->base.'/teachers?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.username', 'real.teacher')->assertJsonPath('data.0.assigned_groups.0', 'G01')
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson($this->base.'/teachers?per_page=101')->assertUnprocessable();
        $this->getJson($this->base.'/teachers?page=100')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->base.'/teachers?district_id='.$other->id)->assertUnprocessable();
    }

    public function test_teacher_login_accepts_original_username_or_email_and_password_without_creating_session(): void
    {
        foreach (['real.teacher', 'teacher@example.test'] as $identifier) {
            $this->withToken('test-token')->postJson($this->base.'/teacher-authenticate', [
                'identifier' => $identifier, 'password' => 'source-secret',
            ])->assertOk()->assertJsonPath('data.id', $this->teacher->id)
                ->assertJsonPath('data.district_id', $this->district->id)->assertJsonMissingPath('data.password');
            $this->assertGuest();
        }
    }

    public function test_non_expiring_client_keeps_teacher_login_available_after_a_year(): void
    {
        $this->client->update(['expires_at' => null]);
        $this->travel(366)->days();
        $this->withToken('test-token')->postJson($this->base.'/teacher-authenticate', [
            'identifier' => 'real.teacher', 'password' => 'source-secret',
        ])->assertOk()->assertJsonPath('data.id', $this->teacher->id);
        $this->client->update(['revoked_at' => now()]);
        $this->postJson($this->base.'/teacher-authenticate', [
            'identifier' => 'real.teacher', 'password' => 'source-secret',
        ])->assertUnauthorized();
    }

    public function test_login_rejects_wrong_password_disabled_users_other_roles_and_other_districts(): void
    {
        $this->withToken('test-token')->postJson($this->base.'/teacher-authenticate', [
            'identifier' => 'real.teacher', 'password' => 'wrong',
        ])->assertUnauthorized()->assertJsonPath('message', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
        foreach (['disabled_at' => now(), 'role' => 'admin', 'district_id' => null] as $field => $value) {
            $original = $this->teacher->$field;
            $this->teacher->forceFill([$field => $value])->save();
            $this->postJson($this->base.'/teacher-authenticate', [
                'identifier' => 'real.teacher', 'password' => 'source-secret',
            ])->assertUnauthorized()->assertJsonPath('message', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
            $this->teacher->forceFill([$field => $original])->save();
        }
    }

    public function test_failed_teacher_login_is_rate_limited_per_identifier_even_if_request_ip_changes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])->withToken('test-token')
                ->postJson($this->base.'/teacher-authenticate', ['identifier' => 'unknown', 'password' => 'wrong'])
                ->assertUnauthorized();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.100'])->postJson($this->base.'/teacher-authenticate', [
            'identifier' => 'unknown', 'password' => 'wrong',
        ])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_ip_failure_limit_applies_even_to_a_new_identifier(): void
    {
        $ip = '10.30.40.50';
        $key = 'sena-care-teacher-ip:'.hash('sha256', $ip);
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->withToken('test-token')
            ->postJson($this->base.'/teacher-authenticate', [
                'identifier' => 'different.teacher', 'password' => 'wrong',
            ])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_group_catalog_includes_empty_groups_from_the_latest_district_batch(): void
    {
        $batch = 'import_1700000300_groups';
        $history = DB::table('import_history')->insertGetId([
            'file_name' => 'test.zip', 'saved_file_name' => 'test.zip', 'batch_key' => $batch,
            'file_size_kb' => 1, 'level' => '2', 'file_count' => 1, 'status' => 'success',
            'district_id' => $this->district->id, 'created_at' => now(),
        ]);
        DB::table('import_batches')->insert([
            'district_id' => $this->district->id, 'import_history_id' => $history,
            'batch_key' => $batch, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::create('db_'.$batch.'_2_group', function ($table): void {
            $table->string('grp_code');
            $table->string('grp_name');
            $table->string('grp_class');
        });
        DB::table('db_'.$batch.'_2_group')->insert(['grp_code' => 'EMPTY', 'grp_name' => 'Empty group', 'grp_class' => '2']);
        $this->withToken('test-token')->getJson($this->base.'/groups')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'EMPTY')
            ->assertJsonPath('data.0.name', 'Empty group')->assertJsonPath('data.0.level', 'มัธยมศึกษาตอนต้น');
    }
}
