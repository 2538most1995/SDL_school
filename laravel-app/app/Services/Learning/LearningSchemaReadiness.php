<?php

namespace App\Services\Learning;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use RuntimeException;

final class LearningSchemaReadiness
{
    private static bool $ready = false;

    /** @var array<string, list<string>> */
    private const REQUIREMENTS = [
        'users' => ['name'],
        'learning_assignments' => ['district_id', 'created_by', 'title', 'instructions', 'academic_term', 'subject_code', 'subject_name', 'education_level', 'target_type', 'target_value', 'max_score', 'opens_at', 'due_at', 'status', 'material_url', 'material_disk', 'material_path', 'material_filename', 'material_size', 'created_at', 'updated_at'],
        'learning_submissions' => ['assignment_id', 'student_id', 'student_code', 'content', 'submission_type', 'external_url', 'attachment_disk', 'attachment_path', 'original_filename', 'file_size', 'submitted_at', 'status', 'score', 'feedback', 'reviewed_by', 'reviewed_at', 'created_at', 'updated_at'],
        'learning_submission_attachments' => ['submission_id', 'storage_disk', 'storage_path', 'original_filename', 'mime_type', 'file_size', 'position', 'created_at', 'updated_at'],
        'learning_resources' => ['district_id', 'uploaded_by', 'title', 'description', 'subject_code', 'education_level', 'resource_type', 'storage_disk', 'storage_path', 'external_url', 'visibility', 'target_group', 'created_at', 'updated_at'],
        'learning_lesson_plans' => ['district_id', 'teacher_id', 'subject_code', 'education_level', 'academic_term', 'title', 'objectives', 'activities', 'assessment', 'status', 'created_at', 'updated_at'],
        'learning_calendar_events' => ['district_id', 'created_by', 'title', 'description', 'event_type', 'starts_at', 'ends_at', 'location', 'target_type', 'target_value', 'image_path', 'image_updated_at', 'daily_schedule', 'external_url', 'featured_on_dashboard', 'created_at', 'updated_at'],
        'learning_scorebooks' => ['district_id', 'created_by', 'academic_term', 'subject_code', 'subject_name', 'education_level', 'group_code', 'coursework_weight', 'final_exam_weight', 'created_at', 'updated_at'],
        'learning_score_components' => ['scorebook_id', 'category', 'title', 'max_score', 'position', 'created_at', 'updated_at'],
        'learning_score_entries' => ['scorebook_id', 'component_id', 'student_code', 'score', 'updated_by', 'created_at', 'updated_at'],
        'learning_score_notes' => ['scorebook_id', 'student_code', 'note', 'updated_by', 'created_at', 'updated_at'],
        'learning_score_templates' => ['district_id', 'created_by', 'name', 'score_ratio', 'applies_to_all', 'subject_codes', 'components', 'created_at', 'updated_at'],
        'audit_logs' => ['user_id', 'district_id', 'event', 'auditable_type', 'auditable_id', 'ip_address', 'request_id', 'before', 'after', 'context', 'created_at'],
    ];

    /** @var array<string, list<string>> */
    private const INDEX_REQUIREMENTS = [
        'learning_submissions' => ['learning_submissions_assignment_student_code_unique'],
        'learning_submission_attachments' => ['learning_submission_attachments_submission_position_unique'],
        'learning_scorebooks' => ['learning_scorebooks_course_scope_unique', 'learning_scorebooks_course_scope_index'],
        'learning_score_components' => ['learning_score_components_scorebook_id_position_unique'],
        'learning_score_entries' => ['learning_score_entries_unique'],
        'learning_score_notes' => ['learning_score_notes_scorebook_id_student_code_unique'],
        'learning_score_templates' => ['learning_score_templates_district_name_unique'],
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    public function ensure(): void
    {
        $connection = $this->database->connection();
        $schema = $connection->getSchemaBuilder();

        if (self::$ready && $this->isReady($schema)) {
            return;
        }

        if ($this->isReady($schema)) {
            self::$ready = true;

            return;
        }

        $lock = $this->acquireLock($connection);

        try {
            if (! $this->isReady($schema)) {
                $this->repair($schema);
            }

            $missing = $this->missingRequirements($schema);
            if ($missing !== []) {
                throw new RuntimeException('Learning schema is incomplete after repair: '.implode(', ', $missing));
            }

            self::$ready = true;
        } finally {
            $this->releaseLock($connection, $lock);
        }
    }

    private function isReady(Builder $schema): bool
    {
        return $this->missingRequirements($schema) === [];
    }

    /** @return list<string> */
    private function missingRequirements(Builder $schema): array
    {
        $missing = [];

        foreach (self::REQUIREMENTS as $table => $columns) {
            if (! $schema->hasTable($table)) {
                $missing[] = $table;

                continue;
            }

            foreach ($columns as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
            if ($table === 'learning_resources' && $this->resourceExternalUrlNeedsWidening($schema)) {
                $missing[] = 'learning_resources.external_url_width';
            }
            if ($table === 'learning_resources' && $this->resourceTypeNeedsWidening($schema)) {
                $missing[] = 'learning_resources.resource_type_type';
            }
        }

        foreach (self::INDEX_REQUIREMENTS as $table => $indexes) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            foreach ($indexes as $index) {
                if (! $schema->hasIndex($table, $index)) {
                    $missing[] = $table.'.'.$index;
                }
            }
        }

        return $missing;
    }

    private function repair(Builder $schema): void
    {
        $this->ensureUserName($schema);
        $this->ensureAssignments($schema);
        $this->ensureSubmissions($schema);
        $this->ensureSubmissionAttachments($schema);
        $this->ensureResources($schema);
        $this->ensureLessonPlans($schema);
        $this->ensureCalendar($schema);
        $this->ensureScorebooks($schema);
        $this->ensureScoreTemplates($schema);
        $this->ensureAuditLogs($schema);
    }

    private function ensureUserName(Builder $schema): void
    {
        if ($schema->hasTable('users') && ! $schema->hasColumn('users', 'name')) {
            $schema->table('users', fn (Blueprint $table) => $table->string('name')->nullable());
        }
    }

    private function ensureAssignments(Builder $schema): void
    {
        if (! $schema->hasTable('learning_assignments')) {
            $schema->create('learning_assignments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id')->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->string('title', 220);
                $table->text('instructions')->nullable();
                $table->string('academic_term', 16)->nullable()->index();
                $table->string('subject_code', 32)->nullable()->index();
                $table->string('subject_name', 220)->nullable();
                $table->unsignedTinyInteger('education_level')->nullable()->index();
                $table->string('target_type', 24)->default('all')->index();
                $table->string('target_value', 120)->nullable()->index();
                $table->decimal('max_score', 7, 2)->default(0);
                $table->timestamp('opens_at')->nullable();
                $table->timestamp('due_at')->nullable()->index();
                $table->string('status', 24)->default('draft')->index();
                $table->string('material_url', 2000)->nullable();
                $table->string('material_disk', 40)->nullable();
                $table->string('material_path')->nullable();
                $table->string('material_filename')->nullable();
                $table->unsignedBigInteger('material_size')->nullable();
                $table->timestamps();
            });

            return;
        }

        $this->addMissingColumn($schema, 'learning_assignments', 'district_id', fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'created_by', fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'title', fn (Blueprint $table) => $table->string('title', 220)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'instructions', fn (Blueprint $table) => $table->text('instructions')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'academic_term', fn (Blueprint $table) => $table->string('academic_term', 16)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'subject_code', fn (Blueprint $table) => $table->string('subject_code', 32)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'subject_name', fn (Blueprint $table) => $table->string('subject_name', 220)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'education_level', fn (Blueprint $table) => $table->unsignedTinyInteger('education_level')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'target_type', fn (Blueprint $table) => $table->string('target_type', 24)->default('all'));
        $this->addMissingColumn($schema, 'learning_assignments', 'target_value', fn (Blueprint $table) => $table->string('target_value', 120)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'max_score', fn (Blueprint $table) => $table->decimal('max_score', 7, 2)->default(0));
        $this->addMissingColumn($schema, 'learning_assignments', 'opens_at', fn (Blueprint $table) => $table->timestamp('opens_at')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'due_at', fn (Blueprint $table) => $table->timestamp('due_at')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'status', fn (Blueprint $table) => $table->string('status', 24)->default('draft'));
        $this->addMissingColumn($schema, 'learning_assignments', 'material_url', fn (Blueprint $table) => $table->string('material_url', 2000)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'material_disk', fn (Blueprint $table) => $table->string('material_disk', 40)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'material_path', fn (Blueprint $table) => $table->string('material_path')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'material_filename', fn (Blueprint $table) => $table->string('material_filename')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'material_size', fn (Blueprint $table) => $table->unsignedBigInteger('material_size')->nullable());
        $this->addTimestamps($schema, 'learning_assignments');
    }

    private function ensureSubmissions(Builder $schema): void
    {
        if (! $schema->hasTable('learning_submissions')) {
            $schema->create('learning_submissions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('assignment_id')->index();
                $table->unsignedBigInteger('student_id')->nullable()->index();
                $table->string('student_code', 64)->nullable()->index();
                $table->text('content')->nullable();
                $table->string('submission_type', 16)->nullable()->index();
                $table->string('external_url', 2000)->nullable();
                $table->string('attachment_disk', 40)->nullable();
                $table->string('attachment_path')->nullable();
                $table->string('original_filename')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->string('status', 24)->default('draft')->index();
                $table->decimal('score', 7, 2)->nullable();
                $table->text('feedback')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable()->index();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->unique(['assignment_id', 'student_id']);
                $table->unique(['assignment_id', 'student_code'], 'learning_submissions_assignment_student_code_unique');
            });

            return;
        }

        $this->addMissingColumn($schema, 'learning_submissions', 'assignment_id', fn (Blueprint $table) => $table->unsignedBigInteger('assignment_id')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'student_id', fn (Blueprint $table) => $table->unsignedBigInteger('student_id')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'student_code', fn (Blueprint $table) => $table->string('student_code', 64)->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'content', fn (Blueprint $table) => $table->text('content')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'submission_type', fn (Blueprint $table) => $table->string('submission_type', 16)->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'external_url', fn (Blueprint $table) => $table->string('external_url', 2000)->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'attachment_disk', fn (Blueprint $table) => $table->string('attachment_disk', 40)->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'attachment_path', fn (Blueprint $table) => $table->string('attachment_path')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'original_filename', fn (Blueprint $table) => $table->string('original_filename')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'file_size', fn (Blueprint $table) => $table->unsignedBigInteger('file_size')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'submitted_at', fn (Blueprint $table) => $table->timestamp('submitted_at')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'status', fn (Blueprint $table) => $table->string('status', 24)->default('draft'));
        $this->addMissingColumn($schema, 'learning_submissions', 'score', fn (Blueprint $table) => $table->decimal('score', 7, 2)->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'feedback', fn (Blueprint $table) => $table->text('feedback')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'reviewed_by', fn (Blueprint $table) => $table->unsignedBigInteger('reviewed_by')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'reviewed_at', fn (Blueprint $table) => $table->timestamp('reviewed_at')->nullable());
        $this->addTimestamps($schema, 'learning_submissions');
        if (! $schema->hasIndex('learning_submissions', 'learning_submissions_assignment_student_code_unique')) {
            $schema->table('learning_submissions', function (Blueprint $table): void {
                $table->unique(['assignment_id', 'student_code'], 'learning_submissions_assignment_student_code_unique');
            });
        }
    }

    private function ensureSubmissionAttachments(Builder $schema): void
    {
        if (! $schema->hasTable('learning_submission_attachments')) {
            $schema->create('learning_submission_attachments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('submission_id')->index();
                $table->string('storage_disk', 40)->default('local');
                $table->string('storage_path');
                $table->string('original_filename');
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('file_size');
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
                $table->unique(
                    ['submission_id', 'position'],
                    'learning_submission_attachments_submission_position_unique',
                );
            });

            return;
        }

        $this->addMissingColumn($schema, 'learning_submission_attachments', 'submission_id', fn (Blueprint $table) => $table->unsignedBigInteger('submission_id')->nullable());
        $this->addMissingColumn($schema, 'learning_submission_attachments', 'storage_disk', fn (Blueprint $table) => $table->string('storage_disk', 40)->default('local'));
        $this->addMissingColumn($schema, 'learning_submission_attachments', 'storage_path', fn (Blueprint $table) => $table->string('storage_path')->nullable());
        $this->addMissingColumn($schema, 'learning_submission_attachments', 'original_filename', fn (Blueprint $table) => $table->string('original_filename')->nullable());
        $this->addMissingColumn($schema, 'learning_submission_attachments', 'mime_type', fn (Blueprint $table) => $table->string('mime_type', 100)->nullable());
        $this->addMissingColumn($schema, 'learning_submission_attachments', 'file_size', fn (Blueprint $table) => $table->unsignedBigInteger('file_size')->nullable());
        $this->addMissingColumn($schema, 'learning_submission_attachments', 'position', fn (Blueprint $table) => $table->unsignedSmallInteger('position')->default(0));
        $this->addTimestamps($schema, 'learning_submission_attachments');
        if (! $schema->hasIndex('learning_submission_attachments', 'learning_submission_attachments_submission_position_unique')) {
            $schema->table('learning_submission_attachments', function (Blueprint $table): void {
                $table->unique(
                    ['submission_id', 'position'],
                    'learning_submission_attachments_submission_position_unique',
                );
            });
        }
    }

    private function ensureResources(Builder $schema): void
    {
        if (! $schema->hasTable('learning_resources')) {
            $schema->create('learning_resources', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id')->index();
                $table->unsignedBigInteger('uploaded_by')->nullable()->index();
                $table->string('title', 220);
                $table->text('description')->nullable();
                $table->string('subject_code', 32)->nullable()->index();
                $table->unsignedTinyInteger('education_level')->nullable()->index();
                $table->string('resource_type', 32)->default('file')->index();
                $table->string('storage_disk', 40)->nullable();
                $table->string('storage_path')->nullable();
                $table->string('external_url', 2000)->nullable();
                $table->string('visibility', 24)->default('district')->index();
                $table->string('target_group', 120)->nullable()->index();
                $table->timestamps();
            });

            return;
        }

        $this->addMissingColumn($schema, 'learning_resources', 'district_id', fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'uploaded_by', fn (Blueprint $table) => $table->unsignedBigInteger('uploaded_by')->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'title', fn (Blueprint $table) => $table->string('title', 220)->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'description', fn (Blueprint $table) => $table->text('description')->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'subject_code', fn (Blueprint $table) => $table->string('subject_code', 32)->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'education_level', fn (Blueprint $table) => $table->unsignedTinyInteger('education_level')->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'resource_type', fn (Blueprint $table) => $table->string('resource_type', 32)->default('file'));
        $this->addMissingColumn($schema, 'learning_resources', 'storage_disk', fn (Blueprint $table) => $table->string('storage_disk', 40)->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'storage_path', fn (Blueprint $table) => $table->string('storage_path')->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'external_url', fn (Blueprint $table) => $table->string('external_url', 2000)->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'visibility', fn (Blueprint $table) => $table->string('visibility', 24)->default('district'));
        $this->addMissingColumn($schema, 'learning_resources', 'target_group', fn (Blueprint $table) => $table->string('target_group', 120)->nullable());
        $this->addTimestamps($schema, 'learning_resources');
        if ($this->resourceExternalUrlNeedsWidening($schema)) {
            $schema->table('learning_resources', function (Blueprint $table): void {
                $table->string('external_url', 2000)->nullable()->change();
            });
        }
        if ($this->resourceTypeNeedsWidening($schema)) {
            $schema->table('learning_resources', function (Blueprint $table): void {
                $table->string('resource_type', 32)->default('file')->change();
            });
        }
    }

    private function resourceExternalUrlNeedsWidening(Builder $schema): bool
    {
        if ($this->database->connection()->getDriverName() !== 'mysql'
            || ! $schema->hasTable('learning_resources')
            || ! $schema->hasColumn('learning_resources', 'external_url')) {
            return false;
        }

        $type = strtolower($schema->getColumnType('learning_resources', 'external_url', true));

        return preg_match('/varchar\((\d+)\)/', $type, $matches) === 1 && (int) $matches[1] < 2000;
    }

    private function resourceTypeNeedsWidening(Builder $schema): bool
    {
        if ($this->database->connection()->getDriverName() !== 'mysql'
            || ! $schema->hasTable('learning_resources')
            || ! $schema->hasColumn('learning_resources', 'resource_type')) {
            return false;
        }

        $type = strtolower($schema->getColumnType('learning_resources', 'resource_type', true));

        return str_starts_with($type, 'enum');
    }

    private function ensureLessonPlans(Builder $schema): void
    {
        if (! $schema->hasTable('learning_lesson_plans')) {
            $schema->create('learning_lesson_plans', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id')->index();
                $table->unsignedBigInteger('teacher_id')->nullable()->index();
                $table->string('subject_code', 32)->index();
                $table->unsignedTinyInteger('education_level')->nullable()->index();
                $table->string('academic_term', 16)->index();
                $table->string('title', 220);
                $table->text('objectives')->nullable();
                $table->text('activities')->nullable();
                $table->text('assessment')->nullable();
                $table->string('status', 24)->default('draft')->index();
                $table->timestamps();
            });

            return;
        }

        $this->addMissingColumn($schema, 'learning_lesson_plans', 'district_id', fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable());
        $this->addMissingColumn($schema, 'learning_lesson_plans', 'teacher_id', fn (Blueprint $table) => $table->unsignedBigInteger('teacher_id')->nullable());
        $this->addMissingColumn($schema, 'learning_lesson_plans', 'subject_code', fn (Blueprint $table) => $table->string('subject_code', 32)->nullable());
        $this->addMissingColumn($schema, 'learning_lesson_plans', 'education_level', fn (Blueprint $table) => $table->unsignedTinyInteger('education_level')->nullable());
        $this->addMissingColumn($schema, 'learning_lesson_plans', 'academic_term', fn (Blueprint $table) => $table->string('academic_term', 16)->nullable());
        $this->addMissingColumn($schema, 'learning_lesson_plans', 'title', fn (Blueprint $table) => $table->string('title', 220)->nullable());
        $this->addMissingColumn($schema, 'learning_lesson_plans', 'objectives', fn (Blueprint $table) => $table->text('objectives')->nullable());
        $this->addMissingColumn($schema, 'learning_lesson_plans', 'activities', fn (Blueprint $table) => $table->text('activities')->nullable());
        $this->addMissingColumn($schema, 'learning_lesson_plans', 'assessment', fn (Blueprint $table) => $table->text('assessment')->nullable());
        $this->addMissingColumn($schema, 'learning_lesson_plans', 'status', fn (Blueprint $table) => $table->string('status', 24)->default('draft'));
        $this->addTimestamps($schema, 'learning_lesson_plans');
    }

    private function ensureCalendar(Builder $schema): void
    {
        if (! $schema->hasTable('learning_calendar_events')) {
            $schema->create('learning_calendar_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id')->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->string('title', 220);
                $table->text('description')->nullable();
                $table->string('event_type', 32)->default('meeting')->index();
                $table->timestamp('starts_at')->index();
                $table->timestamp('ends_at')->nullable();
                $table->string('location')->nullable();
                $table->string('target_type', 24)->default('all');
                $table->string('target_value', 120)->nullable();
                $table->string('image_path')->nullable();
                $table->timestamp('image_updated_at')->nullable();
                $table->json('daily_schedule')->nullable();
                $table->string('external_url', 2000)->nullable();
                $table->boolean('featured_on_dashboard')->default(false)->index();
                $table->timestamps();
            });

            return;
        }

        $this->addMissingColumn($schema, 'learning_calendar_events', 'district_id', fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'created_by', fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'title', fn (Blueprint $table) => $table->string('title', 220)->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'description', fn (Blueprint $table) => $table->text('description')->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'event_type', fn (Blueprint $table) => $table->string('event_type', 32)->default('meeting'));
        $this->addMissingColumn($schema, 'learning_calendar_events', 'starts_at', fn (Blueprint $table) => $table->timestamp('starts_at')->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'ends_at', fn (Blueprint $table) => $table->timestamp('ends_at')->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'location', fn (Blueprint $table) => $table->string('location')->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'target_type', fn (Blueprint $table) => $table->string('target_type', 24)->default('all'));
        $this->addMissingColumn($schema, 'learning_calendar_events', 'target_value', fn (Blueprint $table) => $table->string('target_value', 120)->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'image_path', fn (Blueprint $table) => $table->string('image_path')->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'image_updated_at', fn (Blueprint $table) => $table->timestamp('image_updated_at')->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'daily_schedule', fn (Blueprint $table) => $table->json('daily_schedule')->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'external_url', fn (Blueprint $table) => $table->string('external_url', 2000)->nullable());
        $this->addMissingColumn($schema, 'learning_calendar_events', 'featured_on_dashboard', fn (Blueprint $table) => $table->boolean('featured_on_dashboard')->default(false));
        $this->addTimestamps($schema, 'learning_calendar_events');
    }

    private function ensureScorebooks(Builder $schema): void
    {
        if (! $schema->hasTable('learning_scorebooks')) {
            $schema->create('learning_scorebooks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id')->index();
                $table->unsignedBigInteger('created_by')->index();
                $table->string('academic_term', 16)->index();
                $table->string('subject_code', 32)->index();
                $table->string('subject_name', 220);
                $table->unsignedTinyInteger('education_level')->index();
                $table->string('group_code', 120)->default('')->index();
                $table->unsignedTinyInteger('coursework_weight')->nullable();
                $table->unsignedTinyInteger('final_exam_weight')->nullable();
                $table->timestamps();
            });
        } else {
            $this->addMissingColumn($schema, 'learning_scorebooks', 'district_id', fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable());
            $this->addMissingColumn($schema, 'learning_scorebooks', 'created_by', fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable());
            $this->addMissingColumn($schema, 'learning_scorebooks', 'academic_term', fn (Blueprint $table) => $table->string('academic_term', 16)->nullable());
            $this->addMissingColumn($schema, 'learning_scorebooks', 'subject_code', fn (Blueprint $table) => $table->string('subject_code', 32)->nullable());
            $this->addMissingColumn($schema, 'learning_scorebooks', 'subject_name', fn (Blueprint $table) => $table->string('subject_name', 220)->nullable());
            $this->addMissingColumn($schema, 'learning_scorebooks', 'education_level', fn (Blueprint $table) => $table->unsignedTinyInteger('education_level')->nullable());
            $this->addMissingColumn($schema, 'learning_scorebooks', 'group_code', fn (Blueprint $table) => $table->string('group_code', 120)->default(''));
            $this->addMissingColumn($schema, 'learning_scorebooks', 'coursework_weight', fn (Blueprint $table) => $table->unsignedTinyInteger('coursework_weight')->nullable());
            $this->addMissingColumn($schema, 'learning_scorebooks', 'final_exam_weight', fn (Blueprint $table) => $table->unsignedTinyInteger('final_exam_weight')->nullable());
            $this->addTimestamps($schema, 'learning_scorebooks');
        }

        if (! $schema->hasTable('learning_score_components')) {
            $schema->create('learning_score_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('scorebook_id')->index();
                $table->string('category', 20)->default('coursework');
                $table->string('title', 120);
                $table->decimal('max_score', 7, 2);
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
            });
        } else {
            $this->addMissingColumn($schema, 'learning_score_components', 'scorebook_id', fn (Blueprint $table) => $table->unsignedBigInteger('scorebook_id')->nullable());
            $this->addMissingColumn($schema, 'learning_score_components', 'category', fn (Blueprint $table) => $table->string('category', 20)->default('coursework'));
            $this->addMissingColumn($schema, 'learning_score_components', 'title', fn (Blueprint $table) => $table->string('title', 120)->nullable());
            $this->addMissingColumn($schema, 'learning_score_components', 'max_score', fn (Blueprint $table) => $table->decimal('max_score', 7, 2)->nullable());
            $this->addMissingColumn($schema, 'learning_score_components', 'position', fn (Blueprint $table) => $table->unsignedSmallInteger('position')->default(0));
            $this->addTimestamps($schema, 'learning_score_components');
        }

        if (! $schema->hasTable('learning_score_entries')) {
            $schema->create('learning_score_entries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('scorebook_id')->index();
                $table->unsignedBigInteger('component_id')->index();
                $table->string('student_code', 64)->index();
                $table->decimal('score', 7, 2)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->timestamps();
            });
        } else {
            $this->addMissingColumn($schema, 'learning_score_entries', 'scorebook_id', fn (Blueprint $table) => $table->unsignedBigInteger('scorebook_id')->nullable());
            $this->addMissingColumn($schema, 'learning_score_entries', 'component_id', fn (Blueprint $table) => $table->unsignedBigInteger('component_id')->nullable());
            $this->addMissingColumn($schema, 'learning_score_entries', 'student_code', fn (Blueprint $table) => $table->string('student_code', 64)->nullable());
            $this->addMissingColumn($schema, 'learning_score_entries', 'score', fn (Blueprint $table) => $table->decimal('score', 7, 2)->nullable());
            $this->addMissingColumn($schema, 'learning_score_entries', 'updated_by', fn (Blueprint $table) => $table->unsignedBigInteger('updated_by')->nullable());
            $this->addTimestamps($schema, 'learning_score_entries');
        }

        if (! $schema->hasTable('learning_score_notes')) {
            $schema->create('learning_score_notes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('scorebook_id')->index();
                $table->string('student_code', 64)->index();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->timestamps();
            });
        } else {
            $this->addMissingColumn($schema, 'learning_score_notes', 'scorebook_id', fn (Blueprint $table) => $table->unsignedBigInteger('scorebook_id')->nullable());
            $this->addMissingColumn($schema, 'learning_score_notes', 'student_code', fn (Blueprint $table) => $table->string('student_code', 64)->nullable());
            $this->addMissingColumn($schema, 'learning_score_notes', 'note', fn (Blueprint $table) => $table->text('note')->nullable());
            $this->addMissingColumn($schema, 'learning_score_notes', 'updated_by', fn (Blueprint $table) => $table->unsignedBigInteger('updated_by')->nullable());
            $this->addTimestamps($schema, 'learning_score_notes');
        }

        $this->ensureScorebookIndexes($schema);
    }

    private function ensureScorebookIndexes(Builder $schema): void
    {
        $indexes = [
            'learning_scorebooks' => [
                ['learning_scorebooks_district_id_index', ['district_id'], false],
                ['learning_scorebooks_created_by_index', ['created_by'], false],
                ['learning_scorebooks_academic_term_index', ['academic_term'], false],
                ['learning_scorebooks_subject_code_index', ['subject_code'], false],
                ['learning_scorebooks_education_level_index', ['education_level'], false],
                ['learning_scorebooks_group_code_index', ['group_code'], false],
                ['learning_scorebooks_course_scope_unique', ['district_id', 'academic_term', 'subject_code', 'education_level', 'group_code'], true],
                ['learning_scorebooks_course_scope_index', ['district_id', 'academic_term', 'subject_code', 'education_level'], false],
            ],
            'learning_score_components' => [
                ['learning_score_components_scorebook_id_index', ['scorebook_id'], false],
                ['learning_score_components_scorebook_id_position_unique', ['scorebook_id', 'position'], true],
            ],
            'learning_score_entries' => [
                ['learning_score_entries_scorebook_id_index', ['scorebook_id'], false],
                ['learning_score_entries_component_id_index', ['component_id'], false],
                ['learning_score_entries_student_code_index', ['student_code'], false],
                ['learning_score_entries_updated_by_index', ['updated_by'], false],
                ['learning_score_entries_unique', ['scorebook_id', 'component_id', 'student_code'], true],
            ],
            'learning_score_notes' => [
                ['learning_score_notes_scorebook_id_index', ['scorebook_id'], false],
                ['learning_score_notes_student_code_index', ['student_code'], false],
                ['learning_score_notes_updated_by_index', ['updated_by'], false],
                ['learning_score_notes_scorebook_id_student_code_unique', ['scorebook_id', 'student_code'], true],
            ],
        ];

        foreach ($indexes as $tableName => $definitions) {
            foreach ($definitions as [$name, $columns, $unique]) {
                if ($schema->hasIndex($tableName, $name)) {
                    continue;
                }
                $schema->table($tableName, static function (Blueprint $table) use ($name, $columns, $unique): void {
                    $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
                });
            }
        }
    }

    private function ensureScoreTemplates(Builder $schema): void
    {
        if (! $schema->hasTable('learning_score_templates')) {
            $schema->create('learning_score_templates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id')->index();
                $table->unsignedBigInteger('created_by')->index();
                $table->string('name', 120);
                $table->string('score_ratio', 5);
                $table->boolean('applies_to_all')->default(true)->index();
                $table->json('subject_codes')->nullable();
                $table->json('components');
                $table->timestamps();
                $table->unique(['district_id', 'name'], 'learning_score_templates_district_name_unique');
            });

            return;
        }

        $this->addMissingColumn($schema, 'learning_score_templates', 'district_id', fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable());
        $this->addMissingColumn($schema, 'learning_score_templates', 'created_by', fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable());
        $this->addMissingColumn($schema, 'learning_score_templates', 'name', fn (Blueprint $table) => $table->string('name', 120)->nullable());
        $this->addMissingColumn($schema, 'learning_score_templates', 'score_ratio', fn (Blueprint $table) => $table->string('score_ratio', 5)->nullable());
        $this->addMissingColumn($schema, 'learning_score_templates', 'applies_to_all', fn (Blueprint $table) => $table->boolean('applies_to_all')->default(true));
        $this->addMissingColumn($schema, 'learning_score_templates', 'subject_codes', fn (Blueprint $table) => $table->json('subject_codes')->nullable());
        $this->addMissingColumn($schema, 'learning_score_templates', 'components', fn (Blueprint $table) => $table->json('components')->nullable());
        $this->addTimestamps($schema, 'learning_score_templates');
        if (! $schema->hasIndex('learning_score_templates', 'learning_score_templates_district_name_unique')) {
            $schema->table('learning_score_templates', function (Blueprint $table): void {
                $table->unique(['district_id', 'name'], 'learning_score_templates_district_name_unique');
            });
        }
    }

    private function ensureAuditLogs(Builder $schema): void
    {
        if (! $schema->hasTable('audit_logs')) {
            $schema->create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('district_id')->nullable()->index();
                $table->string('event', 120)->index();
                $table->string('auditable_type')->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('request_id', 64)->nullable()->index();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });

            return;
        }

        $this->addMissingColumn($schema, 'audit_logs', 'user_id', fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'district_id', fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'event', fn (Blueprint $table) => $table->string('event', 120)->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'auditable_type', fn (Blueprint $table) => $table->string('auditable_type')->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'auditable_id', fn (Blueprint $table) => $table->unsignedBigInteger('auditable_id')->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'ip_address', fn (Blueprint $table) => $table->string('ip_address', 45)->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'request_id', fn (Blueprint $table) => $table->string('request_id', 64)->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'before', fn (Blueprint $table) => $table->json('before')->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'after', fn (Blueprint $table) => $table->json('after')->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'context', fn (Blueprint $table) => $table->json('context')->nullable());
        $this->addMissingColumn($schema, 'audit_logs', 'created_at', fn (Blueprint $table) => $table->timestamp('created_at')->nullable());
    }

    /** @param callable(Blueprint): mixed $definition */
    private function addMissingColumn(Builder $schema, string $tableName, string $column, callable $definition): void
    {
        if ($schema->hasColumn($tableName, $column)) {
            return;
        }

        $schema->table($tableName, fn (Blueprint $table) => $definition($table));
    }

    private function addTimestamps(Builder $schema, string $tableName): void
    {
        $this->addMissingColumn($schema, $tableName, 'created_at', fn (Blueprint $table) => $table->timestamp('created_at')->nullable());
        $this->addMissingColumn($schema, $tableName, 'updated_at', fn (Blueprint $table) => $table->timestamp('updated_at')->nullable());
    }

    private function acquireLock(ConnectionInterface $connection): ?string
    {
        if ($connection->getDriverName() !== 'mysql') {
            return null;
        }

        $name = 'sdl_learning_schema_'.substr(hash('sha256', $connection->getDatabaseName()), 0, 24);
        $result = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name]);
        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new RuntimeException('Unable to acquire learning schema repair lock.');
        }

        return $name;
    }

    private function releaseLock(ConnectionInterface $connection, ?string $name): void
    {
        if ($name !== null) {
            $connection->selectOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
}
