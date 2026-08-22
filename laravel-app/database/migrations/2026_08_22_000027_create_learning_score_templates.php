<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_score_templates')) {
            return;
        }

        Schema::create('learning_score_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('district_id')->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('name', 120);
            $table->string('score_ratio', 5);
            $table->boolean('applies_to_all')->default(true)->index();
            $table->json('subject_codes')->nullable();
            $table->json('components');
            $table->timestamps();
            $table->unique(['district_id', 'name'], 'learning_score_templates_district_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_score_templates');
    }
};
