<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('districts')->update([
            'portal_name' => 'SDL School',
            'updated_at' => now(),
        ]);

        DB::table('districts')
            ->whereIn('login_title', ['Sena Care School', 'SENA CARE SCHOOL', 'Sena Care School Demo'])
            ->update(['login_title' => 'SDL School', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Brand data is intentionally not reverted to avoid overwriting later administrator changes.
    }
};
