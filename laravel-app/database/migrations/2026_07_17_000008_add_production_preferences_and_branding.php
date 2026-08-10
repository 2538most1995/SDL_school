<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userColumns = [
            'display_name_override' => fn (Blueprint $table) => $table->string('display_name_override', 120)->nullable()->after('auth_source'),
            'contact_email' => fn (Blueprint $table) => $table->text('contact_email')->nullable()->after('display_name_override'),
            'theme' => fn (Blueprint $table) => $table->string('theme', 16)->default('system')->after('contact_email'),
            'font_size' => fn (Blueprint $table) => $table->string('font_size', 16)->default('normal')->after('theme'),
            'density' => fn (Blueprint $table) => $table->string('density', 16)->default('comfortable')->after('font_size'),
        ];
        foreach ($userColumns as $column => $definition) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', $definition);
            }
        }

        $districtColumns = [
            'portal_name' => fn (Blueprint $table) => $table->string('portal_name', 120)->nullable()->after('login_subtitle'),
            'welcome_message' => fn (Blueprint $table) => $table->string('welcome_message', 220)->nullable()->after('portal_name'),
            'primary_color' => fn (Blueprint $table) => $table->string('primary_color', 9)->nullable()->after('welcome_message'),
        ];
        foreach ($districtColumns as $column => $definition) {
            if (! Schema::hasColumn('districts', $column)) {
                Schema::table('districts', $definition);
            }
        }
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table): void {
            $table->dropColumn(['portal_name', 'welcome_message', 'primary_color']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['display_name_override', 'contact_email', 'theme', 'font_size', 'density']);
        });
    }
};
