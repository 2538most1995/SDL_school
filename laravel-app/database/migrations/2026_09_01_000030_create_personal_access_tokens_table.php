<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            $this->repairExistingTable();

            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64);
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique('token', 'personal_access_tokens_token_unique');
        });
    }

    public function down(): void
    {
        // Sanctum tokens may predate this migration. Never delete adopted tokens on rollback.
    }

    private function repairExistingTable(): void
    {
        $columns = [
            'tokenable_type' => fn (Blueprint $table) => $table->string('tokenable_type')->nullable(),
            'tokenable_id' => fn (Blueprint $table) => $table->unsignedBigInteger('tokenable_id')->nullable(),
            'name' => fn (Blueprint $table) => $table->text('name')->nullable(),
            'token' => fn (Blueprint $table) => $table->string('token', 64)->nullable(),
            'abilities' => fn (Blueprint $table) => $table->text('abilities')->nullable(),
            'last_used_at' => fn (Blueprint $table) => $table->timestamp('last_used_at')->nullable(),
            'expires_at' => fn (Blueprint $table) => $table->timestamp('expires_at')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('personal_access_tokens', $column)) {
                Schema::table('personal_access_tokens', $definition);
            }
        }

        if (! Schema::hasIndex('personal_access_tokens', 'personal_access_tokens_tokenable_type_tokenable_id_index')) {
            Schema::table('personal_access_tokens', function (Blueprint $table): void {
                $table->index(['tokenable_type', 'tokenable_id'], 'personal_access_tokens_tokenable_type_tokenable_id_index');
            });
        }
        if (! Schema::hasIndex('personal_access_tokens', 'personal_access_tokens_token_unique')) {
            Schema::table('personal_access_tokens', function (Blueprint $table): void {
                $table->unique('token', 'personal_access_tokens_token_unique');
            });
        }
    }
};
