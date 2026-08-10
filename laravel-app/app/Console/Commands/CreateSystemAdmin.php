<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class CreateSystemAdmin extends Command
{
    protected $signature = 'system:create-admin
        {--username= : ชื่อผู้ใช้สำหรับเข้าสู่ระบบ}
        {--password= : รหัสผ่านอย่างน้อย 8 ตัวอักษร}
        {--name= : ชื่อที่แสดง}
        {--district-code= : รหัสอำเภอสำหรับบัญชี admin}
        {--district-name= : ชื่ออำเภอเมื่อยังไม่มีในระบบ}
        {--super-admin : สร้างบัญชีดูแลทุกอำเภอ}';

    protected $description = 'Create the first local administrator in the system database';

    public function handle(): int
    {
        $username = trim((string) ($this->option('username') ?: $this->ask('ชื่อผู้ใช้')));
        $password = (string) ($this->option('password') ?: $this->secret('รหัสผ่าน'));
        $name = trim((string) ($this->option('name') ?: $this->ask('ชื่อที่แสดง')));
        $superAdmin = (bool) $this->option('super-admin');

        if (preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $username) !== 1) {
            $this->error('ชื่อผู้ใช้ต้องยาว 3-50 ตัว และใช้เฉพาะ A-Z, 0-9, จุด, ขีด, @ หรือ _');

            return self::FAILURE;
        }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 72) {
            $this->error('รหัสผ่านต้องยาว 8-72 ตัวอักษร');

            return self::FAILURE;
        }
        if ($name === '') {
            $this->error('กรุณาระบุชื่อที่แสดง');

            return self::FAILURE;
        }
        if (User::query()->where('username', $username)->exists()) {
            $this->error('ชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว');

            return self::FAILURE;
        }

        $district = null;
        if (! $superAdmin) {
            $districtCode = trim((string) ($this->option('district-code') ?: $this->ask('รหัสอำเภอ')));
            $districtName = trim((string) ($this->option('district-name') ?: $this->ask('ชื่ออำเภอ')));
            if (preg_match('/^[A-Za-z0-9_-]{2,40}$/', $districtCode) !== 1 || $districtName === '') {
                $this->error('กรุณาระบุรหัสและชื่ออำเภอให้ถูกต้อง');

                return self::FAILURE;
            }
            $district = District::query()->firstOrCreate(
                ['code' => $districtCode],
                ['name' => $districtName, 'is_active' => true],
            );
        }

        [$firstName, $lastName] = array_pad(preg_split('/\s+/u', $name, 2) ?: [], 2, '');
        DB::transaction(function () use ($district, $firstName, $lastName, $name, $password, $superAdmin, $username): void {
            User::query()->create([
                'name' => $name,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => 'admin+'.hash('sha256', mb_strtolower($username)).'@system.invalid',
                'username' => $username,
                'password' => Hash::make($password),
                'role' => $superAdmin ? 'super_admin' : 'admin',
                'district_id' => $district?->id,
                'assigned_groups' => [],
                'auth_source' => 'local',
            ]);
        });

        $this->info('สร้างบัญชีผู้ดูแลในฐานข้อมูลระบบเรียบร้อยแล้ว');

        return self::SUCCESS;
    }
}
