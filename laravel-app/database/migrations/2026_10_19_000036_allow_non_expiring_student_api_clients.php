<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_api_clients') && Schema::hasColumn('student_api_clients', 'expires_at')) {
            Schema::table('student_api_clients', function (Blueprint $table): void {
                $table->timestamp('expires_at')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Preserve issued non-expiring credentials; reverting to NOT NULL would
        // require changing their authentication contract or deleting records.
    }
};
