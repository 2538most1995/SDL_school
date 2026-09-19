<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_exam_attendances')) {
            return;
        }

        Schema::create('learning_exam_attendances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('district_id')->index();
            $table->string('academic_term', 16)->index();
            $table->string('subject_code', 32)->index();
            $table->unsignedTinyInteger('education_level')->index();
            $table->string('student_code', 64)->index();
            $table->boolean('attended')->default(false)->index();
            $table->unsignedBigInteger('checked_by')->nullable()->index();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['district_id', 'academic_term', 'subject_code', 'education_level', 'student_code'],
                'learning_exam_attendances_scope_unique',
            );
            $table->index(
                ['district_id', 'academic_term', 'subject_code', 'education_level', 'attended'],
                'learning_exam_attendances_summary_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_exam_attendances');
    }
};
