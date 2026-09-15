<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcements')) {
            return;
        }

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('district_id')->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('title', 160);
            $table->text('message');
            $table->string('button_label', 60)->nullable();
            $table->text('button_url')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();

            $table->index(
                ['district_id', 'is_active', 'updated_at'],
                'announcements_district_active_updated_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
