<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_calendar_events')) {
            return;
        }

        Schema::table('learning_calendar_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_calendar_events', 'daily_schedule')) {
                $table->json('daily_schedule')->nullable();
            }
            if (! Schema::hasColumn('learning_calendar_events', 'external_url')) {
                $table->string('external_url', 2000)->nullable();
            }
            if (! Schema::hasColumn('learning_calendar_events', 'featured_on_dashboard')) {
                $table->boolean('featured_on_dashboard')->default(false)->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('learning_calendar_events')) {
            return;
        }

        Schema::table('learning_calendar_events', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['daily_schedule', 'external_url', 'featured_on_dashboard'],
                static fn (string $column): bool => Schema::hasColumn('learning_calendar_events', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
