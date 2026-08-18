<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_social_profiles')) {
            Schema::create('student_social_profiles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('district_id')->constrained()->cascadeOnDelete();
                $table->string('student_code', 32);
                $table->string('facebook_url', 500)->nullable();
                $table->string('line_id', 255)->nullable();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['district_id', 'student_code'], 'student_social_identity');
                $table->index(['district_id', 'student_code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_social_profiles');
    }
};
