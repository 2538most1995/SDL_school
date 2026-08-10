<?php

namespace Tests\Unit;

use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LegacyPortalReadPerformanceTest extends TestCase
{
    private string $connectionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionName = 'legacy_portal_test_'.str_replace('.', '_', uniqid('', true));
        config([
            'database.default' => $this->connectionName,
            "database.connections.{$this->connectionName}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        $connection = DB::connection($this->connectionName);
        $connection->getSchemaBuilder()->create('districts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $connection->getSchemaBuilder()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('role');
            $table->unsignedBigInteger('district_id');
            $table->text('assigned_groups')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $connection->table('districts')->insert(['id' => 1, 'name' => 'อำเภอทดสอบ']);
        $connection->table('users')->insert([
            'id' => 1,
            'username' => 'teacher01',
            'first_name' => 'ครู',
            'last_name' => 'ทดสอบ',
            'role' => 'teacher',
            'district_id' => 1,
            'assigned_groups' => json_encode(['G-01'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-08-08 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge($this->connectionName);
        parent::tearDown();
    }

    public function test_user_directory_resolves_district_name_without_a_query_per_user(): void
    {
        $queries = [];
        DB::connection($this->connectionName)->listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $items = (new LegacyPortalReadService(app('db')))->users(1);

        $this->assertCount(1, $items);
        $this->assertSame('อำเภอทดสอบ', $items[0]['district_name']);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('join "districts"', strtolower($queries[0]));
    }
}
