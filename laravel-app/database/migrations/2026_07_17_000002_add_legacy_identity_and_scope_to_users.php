<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'username' => fn (Blueprint $table) => $table->string('username')->nullable()->unique()->after('email'),
            'role' => fn (Blueprint $table) => $table->string('role', 24)->default('teacher')->index()->after('password'),
            'district_id' => fn (Blueprint $table) => $table->foreignId('district_id')->nullable()->after('role')->constrained()->nullOnDelete(),
            'assigned_groups' => fn (Blueprint $table) => $table->json('assigned_groups')->nullable()->after('district_id'),
            'profile_image' => fn (Blueprint $table) => $table->string('profile_image')->nullable()->after('assigned_groups'),
            'disabled_at' => fn (Blueprint $table) => $table->timestamp('disabled_at')->nullable()->index(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', $definition);
            }
        }
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
