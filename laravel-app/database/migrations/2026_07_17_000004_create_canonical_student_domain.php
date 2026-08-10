<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('students')) {
            Schema::create('students', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('student_code', 32);
                $table->string('citizen_id_hash', 64)->nullable()->index();
                $table->text('citizen_id_encrypted')->nullable();
                $table->string('title', 32)->nullable();
                $table->string('first_name', 120);
                $table->string('last_name', 120);
                $table->unsignedTinyInteger('education_level')->index();
                $table->string('group_code', 64)->nullable()->index();
                $table->string('group_name', 160)->nullable();
                $table->string('enrollment_term', 16)->nullable()->index();
                $table->string('latest_term', 16)->nullable()->index();
                $table->string('status', 24)->default('active')->index();
                $table->string('finished_cause', 16)->nullable();
                $table->string('finished_term', 16)->nullable()->index();
                $table->json('source_payload')->nullable();
                $table->timestamps();
                $table->unique(['district_id', 'student_code']);
                $table->index(['district_id', 'education_level', 'group_code', 'status'], 'students_scope_index');
            });
        }

        if (! Schema::hasTable('subjects')) {
            Schema::create('subjects', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->restrictOnDelete();
                $table->string('subject_code', 32);
                $table->string('name', 220);
                $table->unsignedTinyInteger('education_level');
                $table->decimal('credits', 5, 2)->default(0);
                $table->json('source_payload')->nullable();
                $table->timestamps();
                $table->unique(['district_id', 'education_level', 'subject_code'], 'subject_identity');
            });
        }

        if (! Schema::hasTable('registrations')) {
            Schema::create('registrations', function (Blueprint $table): void {
                $table->id();
                $this->addStudentForeignId($table);
                $table->foreignId('subject_id')->constrained()->restrictOnDelete();
                $table->string('academic_term', 16)->index();
                $table->string('status', 24)->default('registered')->index();
                $table->boolean('is_transfer')->default(false)->index();
                $table->timestamps();
                $table->unique(['student_id', 'subject_id', 'academic_term']);
            });
        }

        if (! Schema::hasTable('grades')) {
            Schema::create('grades', function (Blueprint $table): void {
                $table->id();
                $this->addStudentForeignId($table);
                $table->foreignId('subject_id')->constrained()->restrictOnDelete();
                $table->string('academic_term', 16)->index();
                $table->string('raw_grade', 24)->nullable();
                $table->decimal('numeric_grade', 4, 2)->nullable()->index();
                $table->decimal('credits_attempted', 5, 2)->default(0);
                $table->string('type_code', 16)->nullable()->index();
                $table->decimal('source_total', 10, 2)->nullable();
                $table->json('source_payload')->nullable();
                $table->timestamps();
                $table->unique(['student_id', 'subject_id', 'academic_term']);
                $table->index(['student_id', 'academic_term', 'numeric_grade']);
            });
        }

        if (! Schema::hasTable('student_activities')) {
            Schema::create('student_activities', function (Blueprint $table): void {
                $table->id();
                $this->addStudentForeignId($table);
                $table->string('academic_term', 16)->nullable()->index();
                $table->string('activity_code', 64)->nullable();
                $table->string('name', 220);
                $table->decimal('hours', 7, 2)->default(0);
                $table->date('activity_date')->nullable();
                $table->json('source_payload')->nullable();
                $table->timestamps();
                $table->index(['student_id', 'academic_term']);
            });
        }

        if (! Schema::hasTable('moral_assessments')) {
            Schema::create('moral_assessments', function (Blueprint $table): void {
                $table->id();
                $this->addStudentForeignId($table);
                $table->string('academic_term', 16)->index();
                $table->json('scores');
                $table->decimal('average_score', 5, 2);
                $table->string('rating', 32);
                $table->json('source_payload')->nullable();
                $table->timestamps();
                $table->unique(['student_id', 'academic_term']);
            });
        }

        // MySQL may leave the base table behind when adding a foreign key
        // fails. Repair those partial tables so rerunning this migration is
        // safe against an adopted students table whose id is an INT rather
        // than Laravel's default BIGINT UNSIGNED.
        foreach (['registrations', 'grades', 'student_activities', 'moral_assessments'] as $table) {
            $this->repairStudentForeignId($table);
        }
    }

    private function addStudentForeignId(Blueprint $table): void
    {
        $this->defineStudentId($table);
        $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
    }

    private function repairStudentForeignId(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'student_id')) {
            return;
        }

        if ($this->hasStudentForeignKey($table)) {
            return;
        }

        $hasOrphans = DB::table($table.' as child')
            ->leftJoin('students', 'students.id', '=', 'child.student_id')
            ->whereNotNull('child.student_id')
            ->whereNull('students.id')
            ->exists();

        if ($hasOrphans) {
            return;
        }

        if (! $this->studentIdTypesMatch($table)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $this->defineStudentId($blueprint, change: true);
            });
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
        });
    }

    private function defineStudentId(Blueprint $table, bool $change = false): void
    {
        $type = strtolower(Schema::getColumnType('students', 'id', true));
        $unsigned = str_contains($type, 'unsigned');

        $column = str_contains($type, 'bigint')
            ? ($unsigned ? $table->unsignedBigInteger('student_id') : $table->bigInteger('student_id'))
            : ($unsigned ? $table->unsignedInteger('student_id') : $table->integer('student_id'));

        if ($change) {
            $column->change();
        }
    }

    private function studentIdTypesMatch(string $table): bool
    {
        return $this->normalizedIntegerType(Schema::getColumnType('students', 'id', true))
            === $this->normalizedIntegerType(Schema::getColumnType($table, 'student_id', true));
    }

    private function normalizedIntegerType(string $type): string
    {
        $type = strtolower($type);

        return sprintf(
            '%s%s',
            str_contains($type, 'unsigned') ? 'unsigned_' : '',
            str_contains($type, 'bigint') ? 'bigint' : 'integer',
        );
    }

    private function hasStudentForeignKey(string $table): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === ['student_id'] && $foreignKey['foreign_table'] === 'students') {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        Schema::dropIfExists('moral_assessments');
        Schema::dropIfExists('student_activities');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('students');
    }
};
