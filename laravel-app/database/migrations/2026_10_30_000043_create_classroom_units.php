<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_classroom_units')) {
            Schema::create('learning_classroom_units', function (Blueprint $table): void {
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
                $table->text('description')->nullable();
                $table->string('status', 16)->default('draft');
                $table->timestamps();
                $table->index(['district_id', 'academic_term', 'subject_code', 'education_level'], 'classroom_unit_course');
            });
        }
        foreach (['learning_classroom_activities', 'learning_assignments', 'learning_resources', 'learning_classroom_tasks'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'classroom_unit_id')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->unsignedBigInteger('classroom_unit_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        // Preserve activities, parent links and submitted evidence on rollback.
    }
};
