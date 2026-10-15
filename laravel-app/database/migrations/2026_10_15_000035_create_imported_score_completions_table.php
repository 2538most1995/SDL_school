<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('imported_score_completions')) {
            Schema::create('imported_score_completions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('district_id');
                $table->string('batch_key', 64);
                $table->string('score_key', 64);
                $table->unsignedBigInteger('completed_by');
                $table->timestamp('completed_at');
                $table->unique(['district_id', 'batch_key', 'score_key'], 'imported_score_completions_scope');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_score_completions');
    }
};
