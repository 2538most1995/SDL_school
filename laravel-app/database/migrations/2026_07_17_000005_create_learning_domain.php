<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::create('learning_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('learning_assignments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
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
