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
            'word_path' => fn (Blueprint $table) => $table->string('word_path', 255)->nullable(),
            'word_original_name' => fn (Blueprint $table) => $table->string('word_original_name', 255)->nullable(),
            'word_size' => fn (Blueprint $table) => $table->unsignedBigInteger('word_size')->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('learning_lesson_plans', $name)) {
                Schema::table('learning_lesson_plans', $add);
            }
        }
    }

    public function down(): void
    {
    }
};
