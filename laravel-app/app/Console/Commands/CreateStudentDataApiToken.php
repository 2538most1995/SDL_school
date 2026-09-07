<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateStudentDataApiToken extends Command
{
    protected $signature = 'system:create-student-api-token
        {username : บัญชี admin หรือ super_admin ผู้ออก token}
        {--name=student-data-integration : ชื่อ client สำหรับตรวจสอบย้อนหลัง}
        {--district-code= : รหัสอำเภอ (จำเป็นสำหรับ super_admin)}
        {--expires-in-days=90 : อายุ token เป็นวัน (1-365)}
        {--replace : เพิกถอน client ชื่อเดิมในอำเภอนี้ก่อนสร้างใหม่}';

    protected $description = 'Create a district-scoped, read-only Bearer token for the Student Data API';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $name = trim((string) $this->option('name'));
        $expiresInDays = filter_var($this->option('expires-in-days'), FILTER_VALIDATE_INT);
        $user = User::query()->where('username', $username)->first();

        if ($user === null || ! in_array($user->role, ['admin', 'super_admin'], true) || $user->disabled_at !== null) {
            $this->error('บัญชีผู้ออก token ไม่ถูกต้องหรือไม่มีสิทธิ์');

            return self::FAILURE;
        }
        if ($name === '' || mb_strlen($name) > 100) {
            $this->error('ชื่อ client ต้องยาว 1-100 ตัวอักษร');

            return self::FAILURE;
        }
        if ($expiresInDays === false || $expiresInDays < 1 || $expiresInDays > 365) {
            $this->error('อายุ token ต้องอยู่ระหว่าง 1-365 วัน');

            return self::FAILURE;
        }

        $district = $this->resolveDistrict($user);
        if ($district === null) {
            return self::FAILURE;
        }

        $plainToken = 'sdl_student_'.Str::random(64);
        $client = DB::transaction(function () use ($district, $expiresInDays, $name, $plainToken, $user): ?StudentApiClient {
            $existing = StudentApiClient::query()
                ->where('district_id', $district->id)
                ->where('name', $name)
                ->whereNull('revoked_at')
                ->lockForUpdate();

            if ($existing->exists() && ! $this->option('replace')) {
                return null;
            }
            if ($this->option('replace')) {
                $existing->update(['revoked_at' => now()]);
            }

            return StudentApiClient::query()->create([
                'name' => $name,
                'user_id' => $user->id,
                'district_id' => $district->id,
                'token_hash' => hash('sha256', $plainToken),
                'abilities' => ['student-data:read'],
                'expires_at' => now()->addDays($expiresInDays),
            ]);
        });

        if ($client === null) {
            $this->error('มี client ชื่อนี้ในอำเภอแล้ว ใช้ --replace เมื่อต้องการหมุน token');

            return self::FAILURE;
        }

        $this->info("สร้าง Student Data API client #{$client->id} สำหรับ {$district->name} เรียบร้อยแล้ว");
        $this->warn('Token จะแสดงครั้งเดียว กรุณาเก็บใน secret manager ของเว็บปลายทาง');
        $this->newLine();
        $this->line($plainToken);

        return self::SUCCESS;
    }

    private function resolveDistrict(User $user): ?District
    {
        if ($user->role === 'admin') {
            $district = District::query()->whereKey($user->district_id)->where('is_active', true)->first();
            if ($district === null) {
                $this->error('บัญชี admin ไม่มีอำเภอที่เปิดใช้งาน');
            }

            return $district;
        }

        $districtCode = trim((string) $this->option('district-code'));
        $district = District::query()->where('code', $districtCode)->where('is_active', true)->first();
        if ($district === null) {
            $this->error('super_admin ต้องระบุ --district-code ของอำเภอที่เปิดใช้งาน');
        }

        return $district;
    }
}
