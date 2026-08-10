<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'student_code' => fn (Blueprint $table) => $table->string('student_code', 32)->nullable()->index()->after('username'),
            'auth_source' => fn (Blueprint $table) => $table->string('auth_source', 24)->default('local')->index()->after('student_code'),
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
            $table->dropColumn(['student_code', 'auth_source']);
        });
    }
};
