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

        // Imported student rosters are dynamic DBF tables, so a submission is
        // linked canonically by student_code. student_id remains available for
        // deployments that also populate the local students table.
        Schema::table('learning_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('student_id')->nullable()->change();
        });

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
};
