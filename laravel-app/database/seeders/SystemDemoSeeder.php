<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless((bool) config('sena.demo_mode'), 403, 'Demo seeder is disabled.');

        $sena = District::query()->updateOrCreate(
            ['code' => 'sena'],
            ['name' => 'อำเภอเสนา', 'login_title' => 'SDL School', 'portal_name' => 'SDL School', 'is_active' => true],
        );

        $bangSai = District::query()->updateOrCreate(
            ['code' => 'bang-sai'],
            ['name' => 'อำเภอบางซ้าย', 'login_title' => 'SDL School', 'portal_name' => 'SDL School', 'is_active' => true],
        );

        $password = Hash::make('Demo1234!');
        $accounts = [
            ['username' => '6650100001', 'name' => 'ธีรภัทร แสงทอง', 'email' => 'student.demo@example.test', 'role' => 'student', 'district_id' => $sena->id, 'assigned_groups' => null],
            ['username' => 'teacher.demo', 'name' => 'ครูพิมพ์ชนก แสงทอง', 'email' => 'teacher.demo@example.test', 'role' => 'teacher', 'district_id' => $sena->id, 'assigned_groups' => ['SENA-M3-A', 'SENA-M3-B']],
            ['username' => 'admin.demo', 'name' => 'ผู้ดูแลอำเภอเสนา', 'email' => 'admin.demo@example.test', 'role' => 'admin', 'district_id' => $sena->id, 'assigned_groups' => null],
            ['username' => 'admin.bangsai', 'name' => 'ผู้ดูแลอำเภอบางซ้าย', 'email' => 'admin.bangsai@example.test', 'role' => 'admin', 'district_id' => $bangSai->id, 'assigned_groups' => null],
            ['username' => 'super.demo', 'name' => 'ผู้ดูแลระบบส่วนกลาง', 'email' => 'super.demo@example.test', 'role' => 'super_admin', 'district_id' => null, 'assigned_groups' => null],
        ];

        foreach ($accounts as $account) {
            User::query()->updateOrCreate(
                ['username' => $account['username']],
                [...$account, 'password' => $password, 'disabled_at' => null],
            );
        }
    }
}
