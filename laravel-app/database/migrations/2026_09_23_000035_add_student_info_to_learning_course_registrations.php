<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_course_registrations', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_course_registrations', 'student_info')) {
                $table->json('student_info')->nullable()->after('group_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('learning_course_registrations', function (Blueprint $table): void {
            if (Schema::hasColumn('learning_course_registrations', 'student_info')) {
                $table->dropColumn('student_info');
            }
        });
    }
};
