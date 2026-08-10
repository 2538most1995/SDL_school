<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'first_name') || ! Schema::hasColumn('users', 'last_name')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'first_name')) {
                    $table->string('first_name', 120)->nullable()->after('name');
                }
                if (! Schema::hasColumn('users', 'last_name')) {
                    $table->string('last_name', 120)->nullable()->after('first_name');
                }
            });
        }

        DB::table('users')
            ->where(function ($query): void {
                $query->whereNull('first_name')->orWhere('first_name', '');
            })
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    [$firstName, $lastName] = array_pad(
                        preg_split('/\s+/u', trim((string) $user->name), 2) ?: [],
                        2,
                        '',
                    );
                    DB::table('users')->where('id', $user->id)->update([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ]);
                }
            });

        Schema::table('learning_resources', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_resources', 'education_level')) {
                $table->unsignedTinyInteger('education_level')->nullable()->index()->after('subject_code');
            }
            if (! Schema::hasColumn('learning_resources', 'target_group')) {
                $table->string('target_group', 120)->nullable()->index()->after('visibility');
            }
        });

        Schema::table('learning_lesson_plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_lesson_plans', 'education_level')) {
                $table->unsignedTinyInteger('education_level')->nullable()->index()->after('subject_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('learning_lesson_plans', function (Blueprint $table): void {
            if (Schema::hasColumn('learning_lesson_plans', 'education_level')) {
                $table->dropColumn('education_level');
            }
        });

        Schema::table('learning_resources', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['education_level', 'target_group'],
                static fn (string $column): bool => Schema::hasColumn('learning_resources', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['first_name', 'last_name'],
                static fn (string $column): bool => Schema::hasColumn('users', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
