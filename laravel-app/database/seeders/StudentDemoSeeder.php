<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Opt-in demo identities for an empty local/testing database only.
 *
 * Run explicitly with: php artisan db:seed --class=StudentDemoSeeder
 */
final class StudentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('StudentDemoSeeder is disabled in production.');
        }

        if (District::query()->exists() || User::query()->exists()) {
            throw new LogicException('StudentDemoSeeder requires an empty database to avoid changing existing data.');
        }

        DB::transaction(function (): void {
            $district = District::query()->create([
                'name' => 'อำเภอเสนา',
                'code' => 'demo-sena',
                'login_title' => 'SDL School Demo',
                'portal_name' => 'SDL School',
                'login_subtitle' => 'พื้นที่สาธิตสำหรับทดสอบระบบ Laravel',
                'is_active' => true,
            ]);

            $password = Hash::make('Demo@2569');

            User::query()->create([
                'name' => 'ผู้ดูแลระบบสาธิต',
                'email' => 'admin.demo@example.test',
                'username' => 'admin.demo',
                'password' => $password,
                'role' => 'admin',
                'district_id' => $district->id,
                'assigned_groups' => [],
            ]);

            User::query()->create([
                'name' => 'ครูประจำกลุ่มสาธิต',
                'email' => 'teacher.demo@example.test',
                'username' => 'teacher.demo',
                'password' => $password,
                'role' => 'teacher',
                'district_id' => $district->id,
                'assigned_groups' => ['SENA-M3-A', 'SENA-M3-B'],
            ]);

            User::query()->create([
                'name' => 'ธีรภัทร แสงทอง',
                'email' => 'student.demo@example.test',
                'username' => '6650100001',
                'password' => $password,
                'role' => 'student',
                'district_id' => $district->id,
                'assigned_groups' => [],
            ]);
        });
    }
}
