<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        } else {
            $columns = [
                'name' => fn (Blueprint $table) => $table->string('name')->nullable()->after('id'),
                'email' => fn (Blueprint $table) => $table->string('email')->nullable()->unique()->after('name'),
                'email_verified_at' => fn (Blueprint $table) => $table->timestamp('email_verified_at')->nullable()->after('email'),
                'password' => fn (Blueprint $table) => $table->string('password')->nullable()->after('email_verified_at'),
                'remember_token' => fn (Blueprint $table) => $table->string('remember_token', 100)->nullable()->after('password'),
                'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
                'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('users', $column)) {
                    Schema::table('users', $definition);
                }
            }
        }

        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
