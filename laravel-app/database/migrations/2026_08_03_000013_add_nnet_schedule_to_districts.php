<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('districts', function (Blueprint $table): void {
            $table->string('nnet_exam_date')->nullable();
            $table->string('nnet_exam_time')->nullable();
            $table->string('nnet_exam_location')->nullable();
            $table->text('nnet_exam_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table): void {
            $table->dropColumn(['nnet_exam_date', 'nnet_exam_time', 'nnet_exam_location', 'nnet_exam_notes']);
        });
    }
};
