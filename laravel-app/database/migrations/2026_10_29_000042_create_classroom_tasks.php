<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_classroom_tasks')) {
            Schema::create('learning_classroom_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id');
                $table->unsignedBigInteger('created_by');
                $table->string('academic_term', 16);
                $table->string('subject_code', 32);
                $table->string('subject_name', 220);
                $table->unsignedTinyInteger('education_level');
                $table->string('target_group', 120)->default('');
                $table->json('audience_groups');
                $table->string('title', 220);
                $table->text('instructions')->nullable();
                $table->string('selection_mode', 16);
                // Immutable references and display snapshots; answers stay in their original tables.
                $table->json('items');
                $table->timestamp('due_at');
                $table->timestamps();
                $table->index(['district_id', 'academic_term', 'subject_code', 'education_level'], 'classroom_task_course');
            });
        }
        if (! Schema::hasTable('learning_classroom_task_items')) {
            Schema::create('learning_classroom_task_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('task_id');
                $table->string('kind', 16);
                $table->unsignedBigInteger('content_id');
                $table->primary(['task_id', 'kind', 'content_id'], 'classroom_task_item_unique');
                $table->index(['kind', 'content_id', 'task_id'], 'classroom_task_item_content');
            });
        }
        if (! Schema::hasTable('learning_classroom_completions')) {
            Schema::create('learning_classroom_completions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_id');
                $table->unsignedBigInteger('activity_id');
                $table->string('student_code', 64);
                $table->timestamp('completed_at');
                $table->unique(['task_id', 'activity_id', 'student_code'], 'classroom_content_completion');
            });
        }
    }

    public function down(): void
    {
        // Retain issued tasks and evidence of completion on rollback.
    }
};
