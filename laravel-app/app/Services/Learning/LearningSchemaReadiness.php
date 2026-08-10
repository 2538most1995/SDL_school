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

            if (! $this->isReady($schema)) {
                throw new RuntimeException('Learning schema is incomplete after repair.');
            }

            self::$ready = true;
        } finally {
            $this->releaseLock($connection, $lock);
        }
    }

    private function isReady(Builder $schema): bool
    {
        $requirements = [
            'users' => ['name'],
            'learning_assignments' => ['district_id', 'created_by', 'title', 'target_type', 'target_value', 'status'],
            'learning_submissions' => ['assignment_id', 'student_id', 'status'],
            'learning_resources' => ['district_id', 'uploaded_by', 'title', 'education_level', 'target_group'],
            'learning_lesson_plans' => ['district_id', 'teacher_id', 'title', 'education_level'],
            'learning_calendar_events' => ['district_id', 'created_by', 'title', 'starts_at', 'image_path', 'image_updated_at'],
            'audit_logs' => ['user_id', 'district_id', 'event', 'auditable_type', 'auditable_id', 'ip_address', 'before', 'after', 'created_at'],
        ];

        foreach ($requirements as $table => $columns) {
            if (! $schema->hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
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
        }
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
                $table->string('external_url')->nullable();
                $table->string('visibility', 24)->default('district')->index();
                $table->string('target_group', 120)->nullable()->index();
                $table->timestamps();
            });

            return;
        }

        $schema->table('learning_resources', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('learning_resources', 'education_level')) {
                $table->unsignedTinyInteger('education_level')->nullable()->index();
            }
            if (! $schema->hasColumn('learning_resources', 'target_group')) {
                $table->string('target_group', 120)->nullable()->index();
            }
        });
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

        if (! $schema->hasColumn('learning_lesson_plans', 'education_level')) {
            $schema->table('learning_lesson_plans', fn (Blueprint $table) => $table->unsignedTinyInteger('education_level')->nullable()->index());
        }
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

        $schema->table('learning_calendar_events', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('learning_calendar_events', 'image_path')) {
                $table->string('image_path')->nullable();
            }
            if (! $schema->hasColumn('learning_calendar_events', 'image_updated_at')) {
                $table->timestamp('image_updated_at')->nullable();
            }
        });
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

        $schema->table('audit_logs', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('audit_logs', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }
            if (! $schema->hasColumn('audit_logs', 'district_id')) {
                $table->unsignedBigInteger('district_id')->nullable()->index();
            }
            if (! $schema->hasColumn('audit_logs', 'event')) {
                $table->string('event', 120)->nullable();
            }
            if (! $schema->hasColumn('audit_logs', 'auditable_type')) {
                $table->string('auditable_type')->nullable();
            }
            if (! $schema->hasColumn('audit_logs', 'auditable_id')) {
                $table->unsignedBigInteger('auditable_id')->nullable();
            }
            if (! $schema->hasColumn('audit_logs', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }
            if (! $schema->hasColumn('audit_logs', 'before')) {
                $table->json('before')->nullable();
            }
            if (! $schema->hasColumn('audit_logs', 'after')) {
                $table->json('after')->nullable();
            }
            if (! $schema->hasColumn('audit_logs', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
        });
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
