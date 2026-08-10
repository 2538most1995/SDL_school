<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('districts')
            || ! Schema::hasColumn('districts', 'portal_name')
            || ! Schema::hasColumn('districts', 'updated_at')) {
            return;
        }

        DB::table('districts')->update([
            'portal_name' => 'SDL School',
            'updated_at' => now(),
        ]);

        if (Schema::hasColumn('districts', 'login_title')) {
            DB::table('districts')
                ->whereIn('login_title', ['Sena Care School', 'SENA CARE SCHOOL', 'Sena Care School Demo'])
                ->update(['login_title' => 'SDL School', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Brand data is intentionally not reverted to avoid overwriting later administrator changes.
    }
};
