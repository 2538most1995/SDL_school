<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_lesson_plans')) {
            return;
        }
        $columns = [
            'external_url' => fn (Blueprint $table) => $table->text('external_url')->nullable(),
            'pdf_path' => fn (Blueprint $table) => $table->string('pdf_path', 255)->nullable(),
            'pdf_original_name' => fn (Blueprint $table) => $table->string('pdf_original_name', 255)->nullable(),
            'pdf_size' => fn (Blueprint $table) => $table->unsignedBigInteger('pdf_size')->nullable(),
            'cover_path' => fn (Blueprint $table) => $table->string('cover_path', 255)->nullable(),
            'page_count' => fn (Blueprint $table) => $table->unsignedInteger('page_count')->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('learning_lesson_plans', $name)) {
                Schema::table('learning_lesson_plans', $add);
            }
        }
    }

    public function down(): void
    {
        // Documents are retained on rollback; never silently delete uploaded plans.
    }
};
