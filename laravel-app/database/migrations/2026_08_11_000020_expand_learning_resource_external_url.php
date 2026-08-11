<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_resources') || ! Schema::hasColumn('learning_resources', 'external_url')) {
            return;
        }

        Schema::table('learning_resources', function (Blueprint $table): void {
            $table->string('external_url', 2000)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately do not shrink existing URLs because that could truncate user data.
    }
};
