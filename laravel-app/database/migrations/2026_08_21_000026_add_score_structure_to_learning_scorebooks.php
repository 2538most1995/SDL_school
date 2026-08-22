<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_scorebooks', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_scorebooks', 'coursework_weight')) {
                $table->unsignedTinyInteger('coursework_weight')->nullable()->after('group_code');
            }
            if (! Schema::hasColumn('learning_scorebooks', 'final_exam_weight')) {
                $table->unsignedTinyInteger('final_exam_weight')->nullable()->after('coursework_weight');
            }
        });

        Schema::table('learning_score_components', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_score_components', 'category')) {
                $table->string('category', 20)->default('coursework')->after('scorebook_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('learning_score_components', function (Blueprint $table): void {
            if (Schema::hasColumn('learning_score_components', 'category')) {
                $table->dropColumn('category');
            }
        });
        Schema::table('learning_scorebooks', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['coursework_weight', 'final_exam_weight'],
                static fn (string $column): bool => Schema::hasColumn('learning_scorebooks', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
