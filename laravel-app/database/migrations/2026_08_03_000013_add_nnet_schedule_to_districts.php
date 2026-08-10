<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'nnet_exam_date' => fn (Blueprint $table) => $table->string('nnet_exam_date')->nullable(),
            'nnet_exam_time' => fn (Blueprint $table) => $table->string('nnet_exam_time')->nullable(),
            'nnet_exam_location' => fn (Blueprint $table) => $table->string('nnet_exam_location')->nullable(),
            'nnet_exam_notes' => fn (Blueprint $table) => $table->text('nnet_exam_notes')->nullable(),
        ];
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('districts', $column)) {
                Schema::table('districts', $definition);
            }
        }
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table): void {
            $table->dropColumn(['nnet_exam_date', 'nnet_exam_time', 'nnet_exam_location', 'nnet_exam_notes']);
        });
    }
};
