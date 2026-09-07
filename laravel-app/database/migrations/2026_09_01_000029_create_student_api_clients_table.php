<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_api_clients')) {
            $this->repairExistingTable();

            return;
        }

        Schema::create('student_api_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('district_id')->index();
            $table->char('token_hash', 64);
            $table->json('abilities');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'student_api_client_token_hash_unique');
            $table->index(['district_id', 'name', 'revoked_at'], 'student_api_client_scope');
        });
    }

    public function down(): void
    {
        // Credential tables may have been adopted from an existing deployment.
        // Keep rollback non-destructive so revocation history and token hashes survive.
    }

    private function repairExistingTable(): void
    {
        $columns = [
            'name' => fn (Blueprint $table) => $table->string('name', 100)->nullable(),
            'user_id' => fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable(),
            'district_id' => fn (Blueprint $table) => $table->unsignedBigInteger('district_id')->nullable(),
            'token_hash' => fn (Blueprint $table) => $table->char('token_hash', 64)->nullable(),
            'abilities' => fn (Blueprint $table) => $table->json('abilities')->nullable(),
            'expires_at' => fn (Blueprint $table) => $table->timestamp('expires_at')->nullable(),
            'revoked_at' => fn (Blueprint $table) => $table->timestamp('revoked_at')->nullable(),
            'last_used_at' => fn (Blueprint $table) => $table->timestamp('last_used_at')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('student_api_clients', $column)) {
                Schema::table('student_api_clients', $definition);
            }
        }

        if (! Schema::hasIndex('student_api_clients', 'student_api_client_token_hash_unique')) {
            Schema::table('student_api_clients', function (Blueprint $table): void {
                $table->unique('token_hash', 'student_api_client_token_hash_unique');
            });
        }
        if (! Schema::hasIndex('student_api_clients', 'student_api_clients_user_id_index')) {
            Schema::table('student_api_clients', function (Blueprint $table): void {
                $table->index('user_id');
            });
        }
        if (! Schema::hasIndex('student_api_clients', 'student_api_client_scope')) {
            Schema::table('student_api_clients', function (Blueprint $table): void {
                $table->index(['district_id', 'name', 'revoked_at'], 'student_api_client_scope');
            });
        }
    }
};
