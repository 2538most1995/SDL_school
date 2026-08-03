<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('district_id')->constrained()->restrictOnDelete();
            $table->string('batch_key')->unique();
            $table->string('status', 24)->default('staging')->index();
            $table->string('source_filename')->nullable();
            $table->string('source_sha256', 64)->nullable()->index();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->json('validation_summary')->nullable();
            $table->timestamps();
            $table->index(['district_id', 'status', 'created_at']);
        });

        Schema::create('raw_import_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('education_level');
            $table->string('data_type', 40);
            $table->string('physical_table', 64)->unique();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->string('schema_hash', 64);
            $table->string('status', 24)->default('ready')->index();
            $table->timestamps();
            $table->unique(['import_batch_id', 'education_level', 'data_type'], 'raw_import_table_identity');
        });

        Schema::create('active_import_batches', function (Blueprint $table): void {
            $table->foreignId('district_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('activated_at');
            $table->unsignedBigInteger('activated_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_import_batches');
        Schema::dropIfExists('raw_import_tables');
        Schema::dropIfExists('import_batches');
    }
};
