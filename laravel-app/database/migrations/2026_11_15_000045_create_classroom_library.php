<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_classroom_templates')) {
            Schema::create('learning_classroom_templates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id');
                $table->unsignedBigInteger('created_by');
                $table->string('title', 220);
                $table->string('academic_term', 16);
                $table->string('subject_code', 32);
                $table->string('subject_name', 220);
                $table->unsignedTinyInteger('education_level');
                $table->unsignedInteger('content_count');
                $table->json('snapshot');
                $table->timestamps();
                $table->index(['district_id', 'created_by', 'id'], 'classroom_template_owner');
            });
        }
    }

    public function down(): void
    {
        // Retain reusable lessons and their private files on rollback.
    }
};
