<?php

namespace Tests\Feature\Learning;

use App\Services\Learning\LearningSchemaReadiness;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LearningSchemaReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_schema_inspects_each_column_list_once(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(LearningSchemaReadiness::class)->ensure();
            $queries = array_column(DB::getQueryLog(), 'query');
            $columnReads = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'pragma_table_xinfo')));
            $this->assertNotEmpty($columnReads);
            foreach (array_count_values($columnReads) as $count) {
                $this->assertSame(1, $count);
            }
            $this->assertLessThan(60, count($queries));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_batched_checks_still_detect_and_repair_missing_columns_and_indexes(): void
    {
        app(LearningSchemaReadiness::class)->ensure();
        Schema::table('learning_assignments', fn (Blueprint $table) => $table->dropColumn('instructions'));
        Schema::table('learning_scorebooks', fn (Blueprint $table) => $table->dropIndex('learning_scorebooks_course_scope_index'));

        app(LearningSchemaReadiness::class)->ensure();

        $this->assertTrue(Schema::hasColumn('learning_assignments', 'instructions'));
        $this->assertTrue(Schema::hasIndex('learning_scorebooks', 'learning_scorebooks_course_scope_index'));
    }
}
