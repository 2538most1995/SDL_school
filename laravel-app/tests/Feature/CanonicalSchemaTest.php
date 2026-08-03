<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CanonicalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_student_learning_import_and_audit_tables_exist(): void
    {
        foreach ([
            'districts', 'students', 'subjects', 'registrations', 'grades',
            'student_activities', 'moral_assessments', 'import_batches',
            'raw_import_tables', 'active_import_batches', 'learning_assignments',
            'learning_submissions', 'learning_resources', 'learning_lesson_plans',
            'learning_calendar_events', 'learning_schedules', 'exam_rooms', 'audit_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing canonical table: {$table}");
        }
    }

    public function test_student_schema_keeps_pii_out_of_plain_identifier_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('students', [
            'district_id', 'student_code', 'citizen_id_hash', 'citizen_id_encrypted',
            'education_level', 'group_code', 'latest_term', 'status',
        ]));
        $this->assertFalse(Schema::hasColumn('students', 'citizen_id'));
    }
}
