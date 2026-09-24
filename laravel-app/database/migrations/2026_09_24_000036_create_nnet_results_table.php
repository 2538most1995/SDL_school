<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nnet_results')) {
            return;
        }

        Schema::create('nnet_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('district_id')->index();
            $table->string('academic_year', 8)->index();
            $table->unsignedTinyInteger('round')->index(); // 1 or 2
            $table->unsignedTinyInteger('education_level')->index(); // 1 = ประถม, 2 = ม.ต้น, 3 = ม.ปลาย
            $table->string('education_level_label', 64)->nullable();
            $table->string('seat_no', 32)->nullable()->index();
            $table->string('citizen_id', 32)->index();
            $table->string('student_code', 64)->nullable()->index();
            $table->string('student_name', 191);
            $table->string('group_code', 64)->nullable()->index();
            $table->string('group_name', 191)->nullable();
            $table->decimal('total_score', 6, 2)->nullable();
            $table->boolean('has_score')->default(true)->index();
            $table->json('subject_codes')->nullable();
            $table->json('subject_names')->nullable();
            $table->json('subject_scores')->nullable();
            $table->json('subject_levels')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['district_id', 'academic_year', 'round', 'education_level', 'citizen_id'],
                'nnet_results_scope_citizen_unique'
            );

            $table->index(
                ['district_id', 'academic_year', 'round', 'education_level'],
                'nnet_results_filter_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nnet_results');
    }
};
