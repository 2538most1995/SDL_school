<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ExistingSchemaMigrationTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'sdl-existing-schema-');
        config()->set('database.connections.existing_schema_test', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('existing_schema_test');
    }

    protected function tearDown(): void
    {
        DB::purge('existing_schema_test');
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_migrate_adopts_existing_users_table_when_migration_ledger_is_empty(): void
    {
        $schema = Schema::connection('existing_schema_test');
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        DB::connection('existing_schema_test')->table('users')->insert([
            'name' => 'บัญชีเดิม',
            'email' => 'existing@example.test',
            'password' => 'preserved-password-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('migrate', [
            '--database' => 'existing_schema_test',
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($schema->hasTable('districts'));
        $this->assertTrue($schema->hasTable('learning_assignments'));
        $this->assertTrue($schema->hasTable('password_reset_tokens'));
        $this->assertTrue($schema->hasTable('sessions'));
        $this->assertTrue($schema->hasTable('password_reset_tokens'));
        $this->assertTrue($schema->hasTable('jobs'));
        $this->assertTrue($schema->hasTable('audit_logs'));
        $this->assertTrue($schema->hasColumn('users', 'first_name'));
        $this->assertTrue($schema->hasColumn('users', 'auth_source'));
        $this->assertTrue($schema->hasColumn('users', 'disabled_at'));
        $this->assertSame(
            'preserved-password-hash',
            DB::connection('existing_schema_test')->table('users')->value('password'),
        );
        $this->assertSame(
            20,
            DB::connection('existing_schema_test')->table('migrations')->count(),
        );

        DB::connection('existing_schema_test')->table('migrations')->delete();
        $secondExitCode = Artisan::call('migrate', [
            '--database' => 'existing_schema_test',
            '--force' => true,
        ]);

        $this->assertSame(0, $secondExitCode, Artisan::output());
        $this->assertSame(
            20,
            DB::connection('existing_schema_test')->table('migrations')->count(),
        );
        $this->assertSame(
            'preserved-password-hash',
            DB::connection('existing_schema_test')->table('users')->value('password'),
        );
    }

    public function test_latest_repair_migration_restores_tables_skipped_by_an_old_migration_ledger(): void
    {
        $schema = Schema::connection('existing_schema_test');
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('password');
        });
        $schema->create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });

        $migrationNames = collect(glob(database_path('migrations/*.php')) ?: [])
            ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
            ->reject(fn (string $name): bool => str_ends_with($name, 'repair_incomplete_system_schema'))
            ->values();
        foreach ($migrationNames as $name) {
            DB::connection('existing_schema_test')->table('migrations')->insert([
                'migration' => $name,
                'batch' => 1,
            ]);
        }

        $exitCode = Artisan::call('migrate', [
            '--database' => 'existing_schema_test',
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($schema->hasTable('sessions'));
        $this->assertTrue($schema->hasTable('cache'));
        $this->assertTrue($schema->hasTable('jobs'));
        $this->assertTrue($schema->hasTable('job_batches'));
        $this->assertTrue($schema->hasTable('failed_jobs'));
        $this->assertTrue($schema->hasTable('audit_logs'));
        $this->assertTrue($schema->hasColumn('users', 'first_name'));
        $this->assertTrue($schema->hasColumn('users', 'auth_source'));
    }

    public function test_migrate_repairs_an_existing_local_user_directory_without_losing_passwords(): void
    {
        $schema = Schema::connection('existing_schema_test');
        $schema->create('districts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('role');
            $table->text('assigned_groups')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        DB::connection('existing_schema_test')->table('districts')->insert([
            'id' => 10,
            'name' => 'อำเภอเดิม',
        ]);
        DB::connection('existing_schema_test')->table('users')->insert([
            'username' => 'existing.admin',
            'password' => 'existing-bcrypt-hash',
            'first_name' => 'ผู้ดูแล',
            'last_name' => 'เดิม',
            'role' => 'admin',
            'assigned_groups' => '[]',
            'district_id' => 10,
            'created_at' => now(),
        ]);

        $exitCode = Artisan::call('migrate', [
            '--database' => 'existing_schema_test',
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $user = DB::connection('existing_schema_test')->table('users')
            ->where('username', 'existing.admin')
            ->first();
        $this->assertSame('existing-bcrypt-hash', $user->password);
        $this->assertSame('ผู้ดูแล เดิม', $user->name);
        $this->assertSame('local', $user->auth_source);
        $this->assertStringEndsWith('@system.invalid', $user->email);
        $this->assertSame(
            'district-10',
            DB::connection('existing_schema_test')->table('districts')->where('id', 10)->value('code'),
        );
    }
}
