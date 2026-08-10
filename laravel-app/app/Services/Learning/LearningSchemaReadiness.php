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
        'learning_assignments' => ['district_id', 'created_by', 'title', 'instructions', 'subject_code', 'target_type', 'target_value', 'max_score', 'opens_at', 'due_at', 'status', 'created_at', 'updated_at'],
        'learning_submissions' => ['assignment_id', 'student_id', 'content', 'attachment_disk', 'attachment_path', 'original_filename', 'submitted_at', 'status', 'score', 'feedback', 'reviewed_by', 'reviewed_at', 'created_at', 'updated_at'],
        'learning_resources' => ['district_id', 'uploaded_by', 'title', 'description', 'subject_code', 'education_level', 'resource_type', 'storage_disk', 'storage_path', 'external_url', 'visibility', 'target_group', 'created_at', 'updated_at'],
        'learning_lesson_plans' => ['district_id', 'teacher_id', 'subject_code', 'education_level', 'academic_term', 'title', 'objectives', 'activities', 'assessment', 'status', 'created_at', 'updated_at'],
        'learning_calendar_events' => ['district_id', 'created_by', 'title', 'description', 'event_type', 'starts_at', 'ends_at', 'location', 'target_type', 'target_value', 'image_path', 'image_updated_at', 'created_at', 'updated_at'],
        'audit_logs' => ['user_id', 'district_id', 'event', 'auditable_type', 'auditable_id', 'ip_address', 'request_id', 'before', 'after', 'context', 'created_at'],
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
        }

        return $missing;
    }

    private function repair(Builder $schema): void
    {
        $this->ensureUserName($schema);
        $this->ensureAssignments($schema);
        $this->ensureSubmissions($schema);
        $this->ensureResources($schema);
        $this->ensureLessonPlans($schema);
        $this->ensureCalendar($schema);
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
                $table->string('subject_code', 32)->nullable()->index();
                $table->string('target_type', 24)->default('all')->index();
                $table->string('target_value', 120)->nullable()->index();
                $table->decimal('max_score', 7, 2)->default(0);
                $table->timestamp('opens_at')->nullable();
                $table->timestamp('due_at')->nullable()->index();
                $table->string('status', 24)->default('draft')->index();
                $table->timestamps();
            });

            return;
        }

        $this->addMissingColumn($schema, 'learning_assignments', 'district_id', fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'created_by', fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'title', fn (Blueprint $table) => $table->string('title', 220)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'instructions', fn (Blueprint $table) => $table->text('instructions')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'subject_code', fn (Blueprint $table) => $table->string('subject_code', 32)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'target_type', fn (Blueprint $table) => $table->string('target_type', 24)->default('all'));
        $this->addMissingColumn($schema, 'learning_assignments', 'target_value', fn (Blueprint $table) => $table->string('target_value', 120)->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'max_score', fn (Blueprint $table) => $table->decimal('max_score', 7, 2)->default(0));
        $this->addMissingColumn($schema, 'learning_assignments', 'opens_at', fn (Blueprint $table) => $table->timestamp('opens_at')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'due_at', fn (Blueprint $table) => $table->timestamp('due_at')->nullable());
        $this->addMissingColumn($schema, 'learning_assignments', 'status', fn (Blueprint $table) => $table->string('status', 24)->default('draft'));
        $this->addTimestamps($schema, 'learning_assignments');
    }

    private function ensureSubmissions(Builder $schema): void
    {
        if (! $schema->hasTable('learning_submissions')) {
            $schema->create('learning_submissions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('assignment_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->text('content')->nullable();
                $table->string('attachment_disk', 40)->nullable();
                $table->string('attachment_path')->nullable();
                $table->string('original_filename')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->string('status', 24)->default('draft')->index();
                $table->decimal('score', 7, 2)->nullable();
                $table->text('feedback')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable()->index();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->unique(['assignment_id', 'student_id']);
            });

            return;
        }

        $this->addMissingColumn($schema, 'learning_submissions', 'assignment_id', fn (Blueprint $table) => $table->unsignedBigInteger('assignment_id')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'student_id', fn (Blueprint $table) => $table->unsignedBigInteger('student_id')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'content', fn (Blueprint $table) => $table->text('content')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'attachment_disk', fn (Blueprint $table) => $table->string('attachment_disk', 40)->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'attachment_path', fn (Blueprint $table) => $table->string('attachment_path')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'original_filename', fn (Blueprint $table) => $table->string('original_filename')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'submitted_at', fn (Blueprint $table) => $table->timestamp('submitted_at')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'status', fn (Blueprint $table) => $table->string('status', 24)->default('draft'));
        $this->addMissingColumn($schema, 'learning_submissions', 'score', fn (Blueprint $table) => $table->decimal('score', 7, 2)->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'feedback', fn (Blueprint $table) => $table->text('feedback')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'reviewed_by', fn (Blueprint $table) => $table->unsignedBigInteger('reviewed_by')->nullable());
        $this->addMissingColumn($schema, 'learning_submissions', 'reviewed_at', fn (Blueprint $table) => $table->timestamp('reviewed_at')->nullable());
        $this->addTimestamps($schema, 'learning_submissions');
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
                $table->string('external_url')->nullable();
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
        $this->addMissingColumn($schema, 'learning_resources', 'external_url', fn (Blueprint $table) => $table->string('external_url')->nullable());
        $this->addMissingColumn($schema, 'learning_resources', 'visibility', fn (Blueprint $table) => $table->string('visibility', 24)->default('district'));
        $this->addMissingColumn($schema, 'learning_resources', 'target_group', fn (Blueprint $table) => $table->string('target_group', 120)->nullable());
        $this->addTimestamps($schema, 'learning_resources');
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
        $this->addTimestamps($schema, 'learning_calendar_events');
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
