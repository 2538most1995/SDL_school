<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_lesson_plans') && ! Schema::hasColumn('learning_lesson_plans', 'word_url')) {
            Schema::table('learning_lesson_plans', function (Blueprint $table): void {
                $table->string('word_url', 2048)->nullable()->after('external_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('learning_lesson_plans') && Schema::hasColumn('learning_lesson_plans', 'word_url')) {
            Schema::table('learning_lesson_plans', function (Blueprint $table): void {
                $table->dropColumn('word_url');
            });
        }
    }
};
