<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_submission_attachments')) {
            return;
        }

        Schema::create('learning_submission_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('submission_id')->index();
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(
                ['submission_id', 'position'],
                'learning_submission_attachments_submission_position_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_submission_attachments');
    }
};
