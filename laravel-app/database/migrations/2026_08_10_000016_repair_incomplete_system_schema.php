<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairUserColumns();
        $this->repairPasswordResetTokens();
        $this->repairSessions();
        $this->repairCache();
        $this->repairQueue();
        $this->repairAuditLog();
    }

    private function repairUserColumns(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $columns = [
            'name' => fn (Blueprint $table) => $table->string('name')->nullable(),
            'email' => fn (Blueprint $table) => $table->string('email')->nullable(),
            'email_verified_at' => fn (Blueprint $table) => $table->timestamp('email_verified_at')->nullable(),
            'password' => fn (Blueprint $table) => $table->string('password')->nullable(),
            'remember_token' => fn (Blueprint $table) => $table->string('remember_token', 100)->nullable(),
            'username' => fn (Blueprint $table) => $table->string('username', 100)->nullable()->index(),
            'first_name' => fn (Blueprint $table) => $table->string('first_name', 120)->nullable(),
            'last_name' => fn (Blueprint $table) => $table->string('last_name', 120)->nullable(),
            'role' => fn (Blueprint $table) => $table->string('role', 32)->default('teacher')->index(),
            'district_id' => fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable()->index(),
            'assigned_groups' => fn (Blueprint $table) => $table->json('assigned_groups')->nullable(),
            'profile_image' => fn (Blueprint $table) => $table->string('profile_image')->nullable(),
            'disabled_at' => fn (Blueprint $table) => $table->timestamp('disabled_at')->nullable()->index(),
            'student_code' => fn (Blueprint $table) => $table->string('student_code', 32)->nullable()->index(),
            'auth_source' => fn (Blueprint $table) => $table->string('auth_source', 24)->default('local')->index(),
            'display_name_override' => fn (Blueprint $table) => $table->string('display_name_override', 120)->nullable(),
            'contact_email' => fn (Blueprint $table) => $table->text('contact_email')->nullable(),
            'theme' => fn (Blueprint $table) => $table->string('theme', 16)->default('system'),
            'color_scheme' => fn (Blueprint $table) => $table->string('color_scheme', 24)->default('blue'),
            'font_size' => fn (Blueprint $table) => $table->string('font_size', 16)->default('normal'),
            'density' => fn (Blueprint $table) => $table->string('density', 16)->default('comfortable'),
            'avatar_path' => fn (Blueprint $table) => $table->string('avatar_path')->nullable(),
            'avatar_updated_at' => fn (Blueprint $table) => $table->timestamp('avatar_updated_at')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', $definition);
            }
        }
    }

    private function repairPasswordResetTokens(): void
    {
        if (Schema::hasTable('password_reset_tokens')) {
            return;
        }

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    private function repairSessions(): void
    {
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    private function repairCache(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->bigInteger('expiration')->index();
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->bigInteger('expiration')->index();
            });
        }
    }

    private function repairQueue(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedSmallInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('connection');
                $table->string('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
                $table->index(['connection', 'queue', 'failed_at']);
            });
        }
    }

    private function repairAuditLog(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('district_id')->nullable()->index();
            $table->string('event', 120)->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        // This is a non-destructive production repair migration. Rolling it
        // back must never remove adopted tables or user data.
    }
};
