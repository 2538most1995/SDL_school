<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_lesson_plans') && ! Schema::hasColumn('learning_lesson_plans', 'plan_scope')) {
            Schema::table('learning_lesson_plans', fn (Blueprint $table) => $table->string('plan_scope', 16)->default('subject'));
        }
    }

    public function down(): void
    {
        // Keep the scope so combined plans are not relabelled as subject plans.
    }
};
