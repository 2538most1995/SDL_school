<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_calendar_events')) {
            Schema::create('learning_calendar_events', function (Blueprint $table): void {
                $table->id();
                $this->addMatchingForeignId($table, 'district_id', 'districts');
                $this->addMatchingForeignId($table, 'created_by', 'users', nullable: true);
                $table->string('title', 220);
                $table->text('description')->nullable();
                $table->string('event_type', 32)->default('meeting')->index();
                $table->timestamp('starts_at')->index();
                $table->timestamp('ends_at')->nullable();
                $table->string('location')->nullable();
                $table->string('target_type', 24)->default('all');
                $table->string('target_value', 120)->nullable();
                $table->string('image_path')->nullable();
                $table->timestamp('image_updated_at')->nullable();
                $table->timestamps();
                $table->index(['district_id', 'starts_at']);
            });

            return;
        }

        Schema::table('learning_calendar_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('learning_calendar_events', 'image_path')) {
                $table->string('image_path')->nullable();
            }
            if (! Schema::hasColumn('learning_calendar_events', 'image_updated_at')) {
                $table->timestamp('image_updated_at')->nullable();
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
                ['image_path', 'image_updated_at'],
                static fn (string $column): bool => Schema::hasColumn('learning_calendar_events', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function addMatchingForeignId(Blueprint $table, string $column, string $parentTable, bool $nullable = false): void
    {
        if (! Schema::hasColumn($parentTable, 'id')) {
            $definition = $table->unsignedBigInteger($column);
            if ($nullable) {
                $definition->nullable();
            }

            return;
        }

        $type = strtolower(Schema::getColumnType($parentTable, 'id', true));
        $definition = str_contains($type, 'bigint')
            ? (str_contains($type, 'unsigned') ? $table->unsignedBigInteger($column) : $table->bigInteger($column))
            : (str_contains($type, 'unsigned') ? $table->unsignedInteger($column) : $table->integer($column));

        if ($nullable) {
            $definition->nullable();
        }

        $foreign = $table->foreign($column)->references('id')->on($parentTable);
        $nullable ? $foreign->nullOnDelete() : $foreign->restrictOnDelete();
    }
};
