<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('imported_score_edits')) {
            Schema::create('imported_score_edits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id');
                $table->string('batch_key', 64);
                $table->unsignedTinyInteger('education_level');
                $table->unsignedBigInteger('grade_row_id');
                $table->unsignedInteger('record_index');
                $table->string('zip_entry');
                $table->json('identity');
                $table->json('values');
                $table->unsignedInteger('version')->default(1);
                $table->unsignedBigInteger('updated_by');
                $table->timestamps();
                $table->unique(['district_id', 'batch_key', 'education_level', 'grade_row_id'], 'imported_score_edits_row_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_score_edits');
    }
};
