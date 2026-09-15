<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('announcements')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table): void {
            if (! Schema::hasColumn('announcements', 'image_path')) {
                $table->string('image_path', 500)->nullable()->after('button_url');
            }

            if (! Schema::hasColumn('announcements', 'show_exam_link')) {
                $table->boolean('show_exam_link')->default(false)->after('image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn(['image_path', 'show_exam_link']);
        });
    }
};
