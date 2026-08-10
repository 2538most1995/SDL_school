<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'avatar_path' => fn (Blueprint $table) => $table->string('avatar_path')->nullable()->after('contact_email'),
            'avatar_updated_at' => fn (Blueprint $table) => $table->timestamp('avatar_updated_at')->nullable()->after('avatar_path'),
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
            $table->dropColumn(['avatar_path', 'avatar_updated_at']);
        });
    }
};
