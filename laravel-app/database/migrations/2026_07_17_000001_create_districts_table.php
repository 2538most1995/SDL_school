<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('districts')) {
            $columns = [
                'name' => fn (Blueprint $table) => $table->string('name')->nullable()->after('id'),
                'code' => fn (Blueprint $table) => $table->string('code', 40)->nullable()->unique()->after('name'),
                'login_title' => fn (Blueprint $table) => $table->string('login_title')->nullable(),
                'login_subtitle' => fn (Blueprint $table) => $table->string('login_subtitle')->nullable(),
                'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true)->index(),
                'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
                'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
            ];
            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('districts', $column)) {
                    Schema::table('districts', $definition);
                }
            }
            DB::table('districts')
                ->whereNull('code')
                ->orWhere('code', '')
                ->orderBy('id')
                ->eachById(fn (object $district) => DB::table('districts')
                    ->where('id', $district->id)
                    ->update(['code' => 'district-'.$district->id]));

            return;
        }

        Schema::create('districts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->string('login_title')->nullable();
            $table->string('login_subtitle')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
