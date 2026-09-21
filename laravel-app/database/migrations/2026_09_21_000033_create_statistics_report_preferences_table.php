<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('statistics_report_preferences')) {
            return;
        }

        Schema::create('statistics_report_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('district_id')->index();
            $table->string('report_key', 40);
            $table->json('vertical_categories');
            $table->json('horizontal_categories');
            $table->string('active_orientation', 12)->default('vertical');
            $table->timestamps();
            $table->unique(['user_id', 'district_id', 'report_key'], 'statistics_report_preferences_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistics_report_preferences');
    }
};
