<?php

namespace Tests\Feature\Students;

use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class StudentDataApiTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_hashed_district_scoped_token(): void
    {
        $district = $this->district('sena-test');
        User::factory()->create([
            'username' => 'api.admin',
            'role' => 'admin',
            'district_id' => $district->id,
            'disabled_at' => null,
        ]);

        $exitCode = Artisan::call('system:create-student-api-token', [
            'username' => 'api.admin',
            '--name' => 'partner-site',
            '--expires-in-days' => '30',
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertMatchesRegularExpression('/sdl_student_[A-Za-z0-9]{64}/', $output);
        preg_match('/sdl_student_[A-Za-z0-9]{64}/', $output, $matches);
        $plainToken = $matches[0];
        $client = StudentApiClient::query()->sole();

        self::assertSame($district->id, $client->district_id);
        self::assertSame(['student-data:read'], $client->abilities);
        self::assertSame(hash('sha256', $plainToken), $client->token_hash);
        self::assertStringNotContainsString($plainToken, json_encode($client->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_super_admin_must_select_an_active_district(): void
    {
        $district = $this->district('target-test');
        User::factory()->create([
            'username' => 'api.super',
            'role' => 'super_admin',
            'district_id' => null,
            'disabled_at' => null,
        ]);

        self::assertSame(1, Artisan::call('system:create-student-api-token', [
            'username' => 'api.super',
        ]));

        self::assertSame(0, Artisan::call('system:create-student-api-token', [
            'username' => 'api.super',
            '--district-code' => $district->code,
        ]));
        self::assertSame($district->id, StudentApiClient::query()->sole()->district_id);
    }

    public function test_replace_revokes_the_old_token_and_revoke_command_is_idempotent(): void
    {
        $district = $this->district('rotate-test');
        User::factory()->create([
            'username' => 'rotate.admin',
            'role' => 'admin',
            'district_id' => $district->id,
            'disabled_at' => null,
        ]);
        $arguments = ['username' => 'rotate.admin', '--name' => 'partner'];

        self::assertSame(0, Artisan::call('system:create-student-api-token', $arguments));
        self::assertSame(1, Artisan::call('system:create-student-api-token', $arguments));
        self::assertSame(0, Artisan::call('system:create-student-api-token', [...$arguments, '--replace' => true]));

        $clients = StudentApiClient::query()->orderBy('id')->get();
        self::assertCount(2, $clients);
        self::assertNotNull($clients[0]->revoked_at);
        self::assertNull($clients[1]->revoked_at);

        self::assertSame(0, Artisan::call('system:revoke-student-api-token', ['client' => $clients[1]->id]));
        self::assertSame(0, Artisan::call('system:revoke-student-api-token', ['client' => $clients[1]->id]));
        self::assertNotNull($clients[1]->fresh()->revoked_at);
    }

    public function test_another_admin_in_the_same_district_can_rotate_the_named_client(): void
    {
        $district = $this->district('shared-rotate-test');
        foreach (['first.admin', 'second.admin'] as $username) {
            User::factory()->create([
                'username' => $username,
                'role' => 'admin',
                'district_id' => $district->id,
                'disabled_at' => null,
            ]);
        }

        self::assertSame(0, Artisan::call('system:create-student-api-token', [
            'username' => 'first.admin',
            '--name' => 'shared-partner',
        ]));
        self::assertSame(1, Artisan::call('system:create-student-api-token', [
            'username' => 'second.admin',
            '--name' => 'shared-partner',
        ]));
        self::assertSame(0, Artisan::call('system:create-student-api-token', [
            'username' => 'second.admin',
            '--name' => 'shared-partner',
            '--replace' => true,
        ]));

        $clients = StudentApiClient::query()->orderBy('id')->get();
        self::assertNotNull($clients[0]->revoked_at);
        self::assertNull($clients[1]->revoked_at);
        self::assertSame(
            User::query()->where('username', 'second.admin')->value('id'),
            $clients[1]->user_id,
        );
    }

    private function district(string $code): District
    {
        return District::query()->create([
            'name' => 'อำเภอทดสอบ',
            'code' => $code,
            'is_active' => true,
        ]);
    }
}
