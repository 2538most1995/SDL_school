<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_relations_posts')) {
            return;
        }

        Schema::create('public_relations_posts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('district_id')->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('title', 180);
            $table->text('description');
            $table->string('image_path', 500)->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['district_id', 'is_published', 'published_at'],
                'public_relations_district_published_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_relations_posts');
    }
};
