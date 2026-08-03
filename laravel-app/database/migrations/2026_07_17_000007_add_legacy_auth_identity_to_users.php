<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('legacy_key', 120)->nullable()->unique()->after('username');
            $table->unsignedBigInteger('legacy_user_id')->nullable()->index()->after('legacy_key');
            $table->string('student_code', 32)->nullable()->index()->after('legacy_user_id');
            $table->string('auth_source', 24)->default('local')->index()->after('student_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['legacy_key', 'legacy_user_id', 'student_code', 'auth_source']);
        });
    }
};
