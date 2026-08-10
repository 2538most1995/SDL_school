<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $this->addMatchingForeignId($table, 'user_id', 'users');
                $this->addMatchingForeignId($table, 'district_id', 'districts');
                $table->string('event', 120)->index();
                $table->string('auditable_type')->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('request_id', 64)->nullable()->index();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->index(['auditable_type', 'auditable_id']);
            });
        }

        $this->repairMatchingForeignId('user_id', 'users');
        $this->repairMatchingForeignId('district_id', 'districts');
    }

    private function addMatchingForeignId(Blueprint $table, string $column, string $parentTable): void
    {
        $this->defineMatchingId($table, $column, $parentTable);
        $table->foreign($column)->references('id')->on($parentTable)->nullOnDelete();
    }

    private function repairMatchingForeignId(string $column, string $parentTable): void
    {
        if (! Schema::hasColumn('audit_logs', $column) || $this->hasForeignKey($column, $parentTable)) {
            return;
        }

        $hasOrphans = DB::table('audit_logs as audit')
            ->leftJoin($parentTable.' as parent', 'parent.id', '=', 'audit.'.$column)
            ->whereNotNull('audit.'.$column)
            ->whereNull('parent.id')
            ->exists();

        if ($hasOrphans) {
            return;
        }

        if (! $this->idTypesMatch($column, $parentTable)) {
            Schema::table('audit_logs', function (Blueprint $table) use ($column, $parentTable): void {
                $this->defineMatchingId($table, $column, $parentTable, change: true);
            });
        }

        Schema::table('audit_logs', function (Blueprint $table) use ($column, $parentTable): void {
            $table->foreign($column)->references('id')->on($parentTable)->nullOnDelete();
        });
    }

    private function defineMatchingId(
        Blueprint $table,
        string $columnName,
        string $parentTable,
        bool $change = false,
    ): void {
        $type = strtolower(Schema::getColumnType($parentTable, 'id', true));
        $unsigned = str_contains($type, 'unsigned');

        $column = str_contains($type, 'bigint')
            ? ($unsigned ? $table->unsignedBigInteger($columnName) : $table->bigInteger($columnName))
            : ($unsigned ? $table->unsignedInteger($columnName) : $table->integer($columnName));

        $column->nullable();

        if ($change) {
            $column->change();
        }
    }

    private function idTypesMatch(string $column, string $parentTable): bool
    {
        return $this->normalizedIntegerType(Schema::getColumnType($parentTable, 'id', true))
            === $this->normalizedIntegerType(Schema::getColumnType('audit_logs', $column, true));
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

    private function hasForeignKey(string $column, string $parentTable): bool
    {
        foreach (Schema::getForeignKeys('audit_logs') as $foreignKey) {
            if ($foreignKey['columns'] === [$column] && $foreignKey['foreign_table'] === $parentTable) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
