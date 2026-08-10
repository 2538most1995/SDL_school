<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'color_scheme')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('color_scheme', 24)->default('blue')->after('theme'));
        }

        $columns = [
            'logo_path' => fn (Blueprint $table) => $table->string('logo_path')->nullable()->after('primary_color'),
            'logo_updated_at' => fn (Blueprint $table) => $table->timestamp('logo_updated_at')->nullable()->after('logo_path'),
            'dashboard_hero_path' => fn (Blueprint $table) => $table->string('dashboard_hero_path')->nullable()->after('login_hero_updated_at'),
            'dashboard_hero_updated_at' => fn (Blueprint $table) => $table->timestamp('dashboard_hero_updated_at')->nullable()->after('dashboard_hero_path'),
        ];
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('districts', $column)) {
                Schema::table('districts', $definition);
            }
        }
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
