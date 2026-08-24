<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('districts', 'school_code')) {
            Schema::table('districts', function (Blueprint $table): void {
                $table->string('school_code', 20)->nullable()->after('code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('districts', 'school_code')) {
            Schema::table('districts', function (Blueprint $table): void {
                $table->dropColumn('school_code');
            });
        }
    }
};
