<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('color_scheme', 24)->default('blue')->after('theme');
        });

        Schema::table('districts', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('primary_color');
            $table->timestamp('logo_updated_at')->nullable()->after('logo_path');
            $table->string('dashboard_hero_path')->nullable()->after('login_hero_updated_at');
            $table->timestamp('dashboard_hero_updated_at')->nullable()->after('dashboard_hero_path');
        });
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'logo_updated_at', 'dashboard_hero_path', 'dashboard_hero_updated_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('color_scheme');
        });
    }
};
