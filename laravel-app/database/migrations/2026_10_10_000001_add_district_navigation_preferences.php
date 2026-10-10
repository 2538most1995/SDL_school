<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('districts', 'navigation_preferences')) {
            Schema::table('districts', fn (Blueprint $table) => $table->json('navigation_preferences')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('districts', 'navigation_preferences')) {
            Schema::table('districts', fn (Blueprint $table) => $table->dropColumn('navigation_preferences'));
        }
    }
};
