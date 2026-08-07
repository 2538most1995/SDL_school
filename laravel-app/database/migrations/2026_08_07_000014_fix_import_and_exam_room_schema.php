<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix import_history table (missing) and exam_rooms schema mismatch.
 *
 * Problem 1: LegacyZipImportService::registerBatch() writes to `import_history`
 *            and links it via `import_history_id` in `import_batches`, but no
 *            migration ever created the `import_history` table or the FK column.
 *
 * Problem 2: exam_rooms migration defined columns (academic_term, room_code,
 *            student_code, seat_number) that don't match the columns actually
 *            used by ExamRoomController, LegacyExamScheduleService, and
 *            LegacyPortalReadService (term, assignment_type, start_val,
 *            end_val, room_name).
 *
 * This migration is idempotent: every step checks existence before acting.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Create import_history table ─────────────────────────
        if (! Schema::hasTable('import_history')) {
            Schema::create('import_history', function (Blueprint $table): void {
                $table->id();
                $table->string('file_name')->comment('Original upload filename');
                $table->string('saved_file_name')->comment('Filename on disk');
                $table->string('batch_key')->index()->comment('Matches import_batches.batch_key');
                $table->unsignedInteger('file_size_kb')->default(0);
                $table->string('level', 20)->default('ทุกระดับ');
                $table->unsignedInteger('file_count')->default(0);
                $table->string('status', 24)->default('pending')->index();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->index(['district_id', 'batch_key']);
            });
        }

        // ─── 2. Add import_history_id FK to import_batches ──────────
        if (Schema::hasTable('import_batches') && ! Schema::hasColumn('import_batches', 'import_history_id')) {
            Schema::table('import_batches', function (Blueprint $table): void {
                $table->unsignedBigInteger('import_history_id')->nullable()->after('district_id');

                $table->foreign('import_history_id')
                    ->references('id')
                    ->on('import_history')
                    ->nullOnDelete();
            });
        }

        // ─── 3. Fix exam_rooms schema ───────────────────────────────
        $this->fixExamRooms();
    }

    private function fixExamRooms(): void
    {
        if (! Schema::hasTable('exam_rooms')) {
            // Table doesn't exist at all — create it with the correct schema.
            Schema::create('exam_rooms', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('term', 50)->index()->comment('Academic term e.g. 1/2569');
                $table->string('subject_code', 100)->index();
                $table->string('assignment_type', 24)->comment('group_range or student_range');
                $table->string('start_val', 100)->comment('Range start (student code or group code)');
                $table->string('end_val', 100)->comment('Range end');
                $table->string('room_name', 100)->index()->comment('Exam room name');
                $table->timestamps();

                $table->index(['district_id', 'term', 'subject_code']);
            });

            return;
        }

        // Table exists — rename/add/drop columns to match code expectations.

        // 3a. Rename academic_term → term (if old column exists)
        if (Schema::hasColumn('exam_rooms', 'academic_term') && ! Schema::hasColumn('exam_rooms', 'term')) {
            Schema::table('exam_rooms', function (Blueprint $table): void {
                $table->renameColumn('academic_term', 'term');
            });
        }

        // 3b. Rename room_code → room_name (if old column exists)
        if (Schema::hasColumn('exam_rooms', 'room_code') && ! Schema::hasColumn('exam_rooms', 'room_name')) {
            Schema::table('exam_rooms', function (Blueprint $table): void {
                $table->renameColumn('room_code', 'room_name');
            });
        }

        // 3c. Replace student_code with assignment_type + start_val + end_val
        if (Schema::hasColumn('exam_rooms', 'student_code') && ! Schema::hasColumn('exam_rooms', 'assignment_type')) {
            // Step 1: Add new columns first so we can copy data into them.
            Schema::table('exam_rooms', function (Blueprint $table): void {
                $table->string('assignment_type', 24)->default('student_range');
                $table->string('start_val', 100)->nullable();
                $table->string('end_val', 100)->nullable();
            });

            // Step 2: Copy existing student_code data into start_val.
            \Illuminate\Support\Facades\DB::statement(
                'UPDATE exam_rooms SET start_val = student_code WHERE student_code IS NOT NULL'
            );

            // Step 3: Drop ALL indexes that reference student_code (SQLite
            // refuses to drop a column if any index still references it).
            $this->dropIndexByName('exam_room_student_subject');
            $this->dropIndexByName('exam_rooms_student_code_index');

            // Step 4: Drop the old column.
            Schema::table('exam_rooms', function (Blueprint $table): void {
                $table->dropColumn('student_code');
            });
        } elseif (Schema::hasColumn('exam_rooms', 'student_code') && Schema::hasColumn('exam_rooms', 'assignment_type')) {
            // Partial migration recovery: new columns exist but student_code
            // was never dropped (previous run failed mid-way). Clean it up.
            $this->dropIndexByName('exam_room_student_subject');
            $this->dropIndexByName('exam_rooms_student_code_index');

            Schema::table('exam_rooms', function (Blueprint $table): void {
                $table->dropColumn('student_code');
            });
        } elseif (! Schema::hasColumn('exam_rooms', 'assignment_type')) {
            Schema::table('exam_rooms', function (Blueprint $table): void {
                $table->string('assignment_type', 24)->default('student_range');
                $table->string('start_val', 100)->nullable();
                $table->string('end_val', 100)->nullable();
            });
        }

        // 3d. Drop seat_number if it still exists (code doesn't use it)
        if (Schema::hasColumn('exam_rooms', 'seat_number')) {
            Schema::table('exam_rooms', function (Blueprint $table): void {
                $table->dropColumn('seat_number');
            });
        }

        // 3e. Ensure term column size is large enough (rename may have kept 16 chars)
        if (Schema::hasColumn('exam_rooms', 'term')) {
            Schema::table('exam_rooms', function (Blueprint $table): void {
                $table->string('term', 50)->change();
            });
        }

        // 3f. Ensure room_name column size matches code validation (max:100)
        if (Schema::hasColumn('exam_rooms', 'room_name')) {
            Schema::table('exam_rooms', function (Blueprint $table): void {
                $table->string('room_name', 100)->change();
            });
        }

        // 3g. Add composite index for query performance
        $this->addIndexSafely('exam_rooms', ['district_id', 'term', 'subject_code'], 'exam_rooms_district_term_subject');
    }

    /**
     * Safely drop an index by name using raw SQL.
     *
     * Blueprint's dropUnique/dropIndex don't support "IF EXISTS" and SQLite
     * throws if the index doesn't exist, so we use raw DDL which both
     * MySQL and SQLite handle correctly.
     */
    private function dropIndexByName(string $indexName): void
    {
        try {
            \Illuminate\Support\Facades\DB::statement("DROP INDEX IF EXISTS \"{$indexName}\"");
        } catch (\Throwable) {
            // Silently ignore — the index may have already been removed.
        }
    }

    private function addIndexSafely(string $tableName, array $columns, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->index($columns, $indexName);
            });
        } catch (\Throwable) {
            // Index already exists — safe to ignore.
        }
    }

    public function down(): void
    {
        // Reverse exam_rooms changes
        if (Schema::hasTable('exam_rooms')) {
            if (Schema::hasColumn('exam_rooms', 'assignment_type') && ! Schema::hasColumn('exam_rooms', 'student_code')) {
                Schema::table('exam_rooms', function (Blueprint $table): void {
                    $table->string('student_code', 32)->nullable()->index();
                });

                \Illuminate\Support\Facades\DB::statement(
                    'UPDATE exam_rooms SET student_code = start_val WHERE start_val IS NOT NULL'
                );

                Schema::table('exam_rooms', function (Blueprint $table): void {
                    $table->dropColumn(['assignment_type', 'start_val', 'end_val']);
                });
            }

            if (Schema::hasColumn('exam_rooms', 'room_name') && ! Schema::hasColumn('exam_rooms', 'room_code')) {
                Schema::table('exam_rooms', function (Blueprint $table): void {
                    $table->renameColumn('room_name', 'room_code');
                });
            }

            if (Schema::hasColumn('exam_rooms', 'term') && ! Schema::hasColumn('exam_rooms', 'academic_term')) {
                Schema::table('exam_rooms', function (Blueprint $table): void {
                    $table->renameColumn('term', 'academic_term');
                });
            }

            if (! Schema::hasColumn('exam_rooms', 'seat_number')) {
                Schema::table('exam_rooms', function (Blueprint $table): void {
                    $table->unsignedInteger('seat_number')->nullable();
                });
            }
        }

        // Remove import_history_id from import_batches
        if (Schema::hasTable('import_batches') && Schema::hasColumn('import_batches', 'import_history_id')) {
            Schema::table('import_batches', function (Blueprint $table): void {
                try {
                    $table->dropForeign(['import_history_id']);
                } catch (\Throwable) {
                    // FK may not exist on SQLite — safe to ignore.
                }
                $table->dropColumn('import_history_id');
            });
        }

        Schema::dropIfExists('import_history');
    }
};
