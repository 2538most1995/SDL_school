<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_assignments', 'academic_term')) {
                $table->string('academic_term', 16)->nullable()->index();
            }
            if (! Schema::hasColumn('learning_assignments', 'subject_name')) {
                $table->string('subject_name', 220)->nullable();
            }
            if (! Schema::hasColumn('learning_assignments', 'education_level')) {
                $table->unsignedTinyInteger('education_level')->nullable()->index();
            }
        });

        Schema::table('learning_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_submissions', 'student_code')) {
                $table->string('student_code', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('learning_submissions', 'submission_type')) {
                $table->string('submission_type', 16)->nullable()->index();
            }
            if (! Schema::hasColumn('learning_submissions', 'external_url')) {
                $table->string('external_url', 2000)->nullable();
            }
            if (! Schema::hasColumn('learning_submissions', 'file_size')) {
                $table->unsignedBigInteger('file_size')->nullable();
            }
        });

        $this->makeStudentIdNullable();

        if (! Schema::hasIndex('learning_submissions', 'learning_submissions_assignment_student_code_unique')) {
            Schema::table('learning_submissions', function (Blueprint $table): void {
                $table->unique(
                    ['assignment_id', 'student_code'],
                    'learning_submissions_assignment_student_code_unique',
                );
            });
        }
    }

    public function down(): void
    {
        // Additive workflow migration. Keep submitted work and assignment
        // metadata intact during rollback.
    }

    private function makeStudentIdNullable(): void
    {
        if (! Schema::hasColumn('learning_submissions', 'student_id')) {
            return;
        }

        // MySQL refuses to alter a column while a foreign key uses it. Keep the
        // original constraints so a failed, partially applied migration can be
        // rerun safely without weakening referential integrity.
        $requiresForeignKeyRelease = Schema::getConnection()->getDriverName() === 'mysql';
        $foreignKeys = $requiresForeignKeyRelease
            ? collect(Schema::getForeignKeys('learning_submissions'))
                ->filter(static fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['student_id'])
                ->values()
                ->all()
            : [];

        foreach ($foreignKeys as $foreignKey) {
            Schema::table('learning_submissions', function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign($foreignKey['name']);
            });
        }

        try {
            // Imported student rosters are dynamic DBF tables, so a submission
            // is linked canonically by student_code. student_id remains for
            // deployments that also populate the local students table.
            $this->changeStudentIdToNullable();
        } finally {
            foreach ($foreignKeys as $foreignKey) {
                Schema::table('learning_submissions', function (Blueprint $table) use ($foreignKey): void {
                    $constraint = $table->foreign('student_id', $foreignKey['name'])
                        ->references(($foreignKey['foreign_columns'] ?? ['id'])[0])
                        ->on($foreignKey['foreign_table']);

                    if (! empty($foreignKey['on_update'])) {
                        $constraint->onUpdate(strtolower($foreignKey['on_update']));
                    }
                    if (! empty($foreignKey['on_delete'])) {
                        $constraint->onDelete(strtolower($foreignKey['on_delete']));
                    }
                });
            }
        }
    }

    private function changeStudentIdToNullable(): void
    {
        $type = strtolower(Schema::getColumnType('learning_submissions', 'student_id', true));

        Schema::table('learning_submissions', function (Blueprint $table) use ($type): void {
            $unsigned = str_contains($type, 'unsigned');
            $column = str_contains($type, 'bigint')
                ? ($unsigned ? $table->unsignedBigInteger('student_id') : $table->bigInteger('student_id'))
                : ($unsigned ? $table->unsignedInteger('student_id') : $table->integer('student_id'));

            $column->nullable()->change();
        });
    }
};
