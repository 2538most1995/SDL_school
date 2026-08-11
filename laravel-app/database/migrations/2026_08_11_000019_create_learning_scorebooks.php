<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_scorebooks')) {
            Schema::create('learning_scorebooks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id')->index();
                $table->unsignedBigInteger('created_by')->index();
                $table->string('academic_term', 16)->index();
                $table->string('subject_code', 32)->index();
                $table->string('subject_name', 220);
                $table->unsignedTinyInteger('education_level')->index();
                $table->string('group_code', 120)->default('')->index();
                $table->timestamps();
                $table->unique(
                    ['district_id', 'academic_term', 'subject_code', 'education_level', 'group_code'],
                    'learning_scorebooks_course_scope_unique',
                );
                $table->index(
                    ['district_id', 'academic_term', 'subject_code', 'education_level'],
                    'learning_scorebooks_course_scope_index',
                );
            });
        }

        if (! Schema::hasTable('learning_score_components')) {
            Schema::create('learning_score_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('scorebook_id')->index();
                $table->string('title', 120);
                $table->decimal('max_score', 7, 2);
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['scorebook_id', 'position']);
            });
        }

        if (! Schema::hasTable('learning_score_entries')) {
            Schema::create('learning_score_entries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('scorebook_id')->index();
                $table->unsignedBigInteger('component_id')->index();
                $table->string('student_code', 64)->index();
                $table->decimal('score', 7, 2)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->timestamps();
                $table->unique(['scorebook_id', 'component_id', 'student_code'], 'learning_score_entries_unique');
            });
        }

        if (! Schema::hasTable('learning_score_notes')) {
            Schema::create('learning_score_notes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('scorebook_id')->index();
                $table->string('student_code', 64)->index();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->timestamps();
                $table->unique(['scorebook_id', 'student_code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_score_notes');
        Schema::dropIfExists('learning_score_entries');
        Schema::dropIfExists('learning_score_components');
        Schema::dropIfExists('learning_scorebooks');
    }
};
