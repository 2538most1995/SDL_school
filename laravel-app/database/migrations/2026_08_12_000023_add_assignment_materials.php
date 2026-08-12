<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_assignments', 'material_url')) {
                $table->string('material_url', 2000)->nullable();
            }
            if (! Schema::hasColumn('learning_assignments', 'material_disk')) {
                $table->string('material_disk', 40)->nullable();
            }
            if (! Schema::hasColumn('learning_assignments', 'material_path')) {
                $table->string('material_path')->nullable();
            }
            if (! Schema::hasColumn('learning_assignments', 'material_filename')) {
                $table->string('material_filename')->nullable();
            }
            if (! Schema::hasColumn('learning_assignments', 'material_size')) {
                $table->unsignedBigInteger('material_size')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Keep teacher materials intact when rolling application code back.
    }
};
