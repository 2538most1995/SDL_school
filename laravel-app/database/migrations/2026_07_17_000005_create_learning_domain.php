<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_assignments')) {
            Schema::create('learning_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
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
                $table->index(['district_id', 'status', 'due_at']);
            });
        }

        if (! Schema::hasTable('learning_submissions')) {
            Schema::create('learning_submissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('assignment_id')->constrained('learning_assignments')->cascadeOnDelete();
                $this->addStudentForeignId($table);
                $table->text('content')->nullable();
                $table->string('attachment_disk', 40)->nullable();
                $table->string('attachment_path')->nullable();
                $table->string('original_filename')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->string('status', 24)->default('draft')->index();
                $table->decimal('score', 7, 2)->nullable();
                $table->text('feedback')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->unique(['assignment_id', 'student_id']);
            });
        }

        $this->repairStudentForeignId('learning_submissions');

        if (! Schema::hasTable('learning_resources')) {
            Schema::create('learning_resources', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title', 220);
                $table->text('description')->nullable();
                $table->string('subject_code', 32)->nullable()->index();
                $table->string('resource_type', 32)->default('file')->index();
                $table->string('storage_disk', 40)->nullable();
                $table->string('storage_path')->nullable();
                $table->string('external_url')->nullable();
                $table->string('visibility', 24)->default('district')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('learning_lesson_plans')) {
            Schema::create('learning_lesson_plans', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject_code', 32)->index();
                $table->string('academic_term', 16)->index();
                $table->string('title', 220);
                $table->text('objectives')->nullable();
                $table->text('activities')->nullable();
                $table->text('assessment')->nullable();
                $table->string('status', 24)->default('draft')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('learning_calendar_events')) {
            Schema::create('learning_calendar_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title', 220);
                $table->text('description')->nullable();
                $table->string('event_type', 32)->default('meeting')->index();
                $table->timestamp('starts_at')->index();
                $table->timestamp('ends_at')->nullable();
                $table->string('location')->nullable();
                $table->string('target_type', 24)->default('all');
                $table->string('target_value', 120)->nullable();
                $table->timestamps();
                $table->index(['district_id', 'starts_at']);
            });
        }

        if (! Schema::hasTable('learning_schedules')) {
            Schema::create('learning_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->string('academic_term', 16)->index();
                $table->string('subject_code', 32)->index();
                $table->string('subject_name', 220);
                $table->string('group_code', 64)->nullable()->index();
                $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('schedule_type', 24)->default('class')->index();
                $table->timestamp('starts_at')->index();
                $table->timestamp('ends_at')->nullable();
                $table->string('room')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('exam_rooms')) {
            Schema::create('exam_rooms', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('academic_term', 16)->index();
                $table->string('subject_code', 32)->index();
                $table->string('room_code', 64)->index();
                $table->string('student_code', 32)->index();
                $table->unsignedInteger('seat_number')->nullable();
                $table->timestamps();
                $table->unique(['district_id', 'academic_term', 'subject_code', 'student_code'], 'exam_room_student_subject');
            });
        }
    }

    private function addStudentForeignId(Blueprint $table): void
    {
        $this->defineStudentId($table);
        $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
    }

    private function repairStudentForeignId(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'student_id')) {
            return;
        }

        if ($this->hasStudentForeignKey($table)) {
            return;
        }

        $hasOrphans = DB::table($table.' as child')
            ->leftJoin('students', 'students.id', '=', 'child.student_id')
            ->whereNotNull('child.student_id')
            ->whereNull('students.id')
            ->exists();

        if ($hasOrphans) {
            return;
        }

        if (! $this->studentIdTypesMatch($table)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $this->defineStudentId($blueprint, change: true);
            });
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
        });
    }

    private function defineStudentId(Blueprint $table, bool $change = false): void
    {
        $type = strtolower(Schema::getColumnType('students', 'id', true));
        $unsigned = str_contains($type, 'unsigned');

        $column = str_contains($type, 'bigint')
            ? ($unsigned ? $table->unsignedBigInteger('student_id') : $table->bigInteger('student_id'))
            : ($unsigned ? $table->unsignedInteger('student_id') : $table->integer('student_id'));

        if ($change) {
            $column->change();
        }
    }

    private function studentIdTypesMatch(string $table): bool
    {
        return $this->normalizedIntegerType(Schema::getColumnType('students', 'id', true))
            === $this->normalizedIntegerType(Schema::getColumnType($table, 'student_id', true));
    }

    private function normalizedIntegerType(string $type): string
    {
        $type = strtolower($type);

        return sprintf(
            '%s%s',
            str_contains($type, 'unsigned') ? 'unsigned_' : '',
            str_contains($type, 'bigint') ? 'bigint' : 'integer',
        );
    }

    private function hasStudentForeignKey(string $table): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === ['student_id'] && $foreignKey['foreign_table'] === 'students') {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_rooms');
        Schema::dropIfExists('learning_schedules');
        Schema::dropIfExists('learning_calendar_events');
        Schema::dropIfExists('learning_lesson_plans');
        Schema::dropIfExists('learning_resources');
        Schema::dropIfExists('learning_submissions');
        Schema::dropIfExists('learning_assignments');
    }
};
