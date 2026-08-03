<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('display_name_override', 120)->nullable()->after('auth_source');
            $table->text('contact_email')->nullable()->after('display_name_override');
            $table->string('theme', 16)->default('system')->after('contact_email');
            $table->string('font_size', 16)->default('normal')->after('theme');
            $table->string('density', 16)->default('comfortable')->after('font_size');
        });

        Schema::table('districts', function (Blueprint $table): void {
            $table->string('portal_name', 120)->nullable()->after('login_subtitle');
            $table->string('welcome_message', 220)->nullable()->after('portal_name');
            $table->string('primary_color', 9)->nullable()->after('welcome_message');
        });
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
