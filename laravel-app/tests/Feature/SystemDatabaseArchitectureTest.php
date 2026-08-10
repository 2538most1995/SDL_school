<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SystemDatabaseArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_super_admin_is_created_in_the_default_database(): void
    {
        $this->artisan('system:create-admin', [
            '--username' => 'system.owner',
            '--password' => 'safe-password-123',
            '--name' => 'ผู้ดูแล ระบบ',
            '--super-admin' => true,
        ])->assertSuccessful();

        $user = User::query()->where('username', 'system.owner')->firstOrFail();
        $this->assertSame('super_admin', $user->role);
        $this->assertSame('local', $user->auth_source);
        $this->assertNull($user->district_id);
    }

    public function test_old_database_connections_are_not_configured(): void
    {
        $this->assertArrayNotHasKey('legacy', config('database.connections'));
        $this->assertArrayNotHasKey('legacy_write', config('database.connections'));
        $this->assertArrayNotHasKey('connection', config('system_data'));
        $this->assertArrayNotHasKey('write_connection', config('system_data'));
    }
}
