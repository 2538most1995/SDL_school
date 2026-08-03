<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique()->after('email');
            $table->string('role', 24)->default('teacher')->index()->after('password');
            $table->foreignId('district_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->json('assigned_groups')->nullable()->after('district_id');
            $table->string('profile_image')->nullable()->after('assigned_groups');
            $table->timestamp('disabled_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['district_id']);
            $table->dropColumn([
                'username', 'role', 'district_id', 'assigned_groups', 'profile_image', 'disabled_at',
            ]);
        });
    }
};
