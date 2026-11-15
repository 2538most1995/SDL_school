<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_classroom_activities') && ! Schema::hasColumn('learning_classroom_activities', 'sort_order')) {
            Schema::table('learning_classroom_activities', function (Blueprint $table): void {
                $table->unsignedInteger('sort_order')->nullable();
                $table->index(['district_id', 'classroom_unit_id', 'sort_order'], 'classroom_activity_order');
            });
        }
    }

    public function down(): void
    {
        // Retain the teacher's saved order when rolling back application code.
    }
};
