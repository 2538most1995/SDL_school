<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_classroom_activities')) {
            Schema::create('learning_classroom_activities', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id');
                $table->unsignedBigInteger('created_by');
                $table->string('academic_term', 16);
                $table->string('subject_code', 32);
                $table->string('subject_name', 220);
                $table->unsignedTinyInteger('education_level');
                $table->string('target_group', 120)->default('');
                $table->json('audience_groups')->nullable();
                $table->string('type', 16);
                $table->string('title', 220);
                $table->text('content')->nullable();
                $table->json('links');
                $table->json('questions');
                $table->string('status', 16)->default('draft');
                $table->timestamp('due_at')->nullable();
                $table->timestamps();
                $table->index(['district_id', 'academic_term', 'subject_code', 'education_level'], 'classroom_course_index');
            });
        }
        if (! Schema::hasTable('learning_classroom_submissions')) {
            Schema::create('learning_classroom_submissions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('activity_id');
                $table->string('student_code', 64);
                $table->string('student_name', 220);
                $table->json('answers');
                $table->decimal('auto_score', 8, 2)->default(0);
                $table->decimal('score', 8, 2)->nullable();
                $table->decimal('max_score', 8, 2);
                $table->string('status', 16)->default('submitted');
                $table->text('feedback')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('submitted_at');
                $table->timestamps();
                $table->unique(['activity_id', 'student_code'], 'classroom_single_attempt');
            });
        }
        if (! Schema::hasTable('learning_classroom_images')) {
            Schema::create('learning_classroom_images', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id');
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('activity_id')->nullable()->index();
                $table->string('path');
                $table->string('mime', 32);
                $table->timestamps();
                $table->index(['district_id', 'created_by']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_classroom_images');
        Schema::dropIfExists('learning_classroom_submissions');
        Schema::dropIfExists('learning_classroom_activities');
    }
};
