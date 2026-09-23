<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_course_registrations')) {
            return;
        }

        Schema::create('learning_course_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('district_id')->index();
            $table->string('academic_term', 16)->index();
            $table->string('student_code', 64)->index();
            $table->unsignedTinyInteger('education_level')->index();
            $table->string('group_code', 64)->nullable()->index();
            $table->json('compulsory_subjects')->nullable();
            $table->json('elective_subjects')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('registered_by')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['district_id', 'academic_term', 'student_code'],
                'learning_course_reg_scope_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_course_registrations');
    }
};
