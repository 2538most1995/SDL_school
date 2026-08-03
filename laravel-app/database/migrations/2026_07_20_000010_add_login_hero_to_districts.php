<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('districts', function (Blueprint $table): void {
            $table->string('login_hero_path')->nullable()->after('primary_color');
            $table->timestamp('login_hero_updated_at')->nullable()->after('login_hero_path');
        });
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table): void {
            $table->dropColumn(['login_hero_path', 'login_hero_updated_at']);
        });
    }
};
