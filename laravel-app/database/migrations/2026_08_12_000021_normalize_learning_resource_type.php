<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! Schema::hasTable('learning_resources')
            || ! Schema::hasColumn('learning_resources', 'resource_type')) {
            return;
        }

        $type = strtolower(Schema::getColumnType('learning_resources', 'resource_type', true));
        if (! str_starts_with($type, 'enum')) {
            return;
        }

        Schema::table('learning_resources', function (Blueprint $table): void {
            $table->string('resource_type', 32)->default('file')->change();
        });
    }

    public function down(): void
    {
        // Do not restore an enum because current resource types would be lost.
    }
};
