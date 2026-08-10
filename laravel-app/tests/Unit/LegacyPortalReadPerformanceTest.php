<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Learning\DistrictLearningGroupCatalog;
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
            $table->string('name')->nullable();
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

        $items = (new LegacyPortalReadService(
            app('db'),
            $this->app->make(DistrictLearningGroupCatalog::class),
        ))->users(1);

        $this->assertCount(1, $items);
        $this->assertSame('อำเภอทดสอบ', $items[0]['district_name']);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('join "districts"', strtolower($queries[0]));
    }

    public function test_student_calendar_supports_legacy_students_table_without_district_id(): void
    {
        $schema = DB::connection($this->connectionName)->getSchemaBuilder();
        $schema->create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('student_code');
        });
        $schema->create('learning_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('district_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->string('subject_code')->nullable();
            $table->string('target_type');
            $table->string('target_value')->nullable();
            $table->decimal('max_score')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->string('status');
        });
        $schema->create('learning_calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('district_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('event_type');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->string('target_type');
            $table->string('target_value')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('image_updated_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::connection($this->connectionName)->table('learning_assignments')->insert([
            'id' => 1,
            'district_id' => 1,
            'title' => 'งานทดสอบ',
            'target_type' => 'all',
            'max_score' => 10,
            'due_at' => '2026-08-12 16:00:00',
            'status' => 'open',
        ]);
        DB::connection($this->connectionName)->table('learning_calendar_events')->insert([
            'id' => 1,
            'district_id' => 1,
            'title' => 'กิจกรรมทดสอบ',
            'event_type' => 'activity',
            'starts_at' => '2026-08-10 09:00:00',
            'ends_at' => '2026-08-11 16:00:00',
            'target_type' => 'all',
        ]);

        $viewer = new User;
        $viewer->forceFill([
            'id' => 2,
            'username' => '6722000227',
            'student_code' => '6722000227',
            'role' => 'student',
            'district_id' => 1,
            'assigned_groups' => ['G-01'],
        ]);

        $items = (new LegacyPortalReadService(
            app('db'),
            $this->app->make(DistrictLearningGroupCatalog::class),
        ))->calendar($viewer, 1);

        $this->assertCount(2, $items);
        $this->assertSame(['event-1', 'assignment-1'], array_column($items, 'id'));
    }
}
