# Sena Care School Laravel

ระบบใหม่ใช้ Laravel 13 + React 19 + TypeScript โดย TanStack Query จัดการ server state และ TanStack Table จัดการตารางข้อมูล ระบบเชื่อมฐาน Sena Care School เดิมแบบอ่านอย่างเดียว และแยกฐาน Laravel control-plane สำหรับ session, shadow identity, theme, branding และ audit log

## สถานะข้อมูลจริง

- ล็อกอินครู/ผู้ดูแลตรวจรหัสผ่านกับตาราง `users` เดิม
- ล็อกอินนักศึกษาใช้เลขบัตรประชาชน + รหัสนักศึกษา + อำเภอ โดยไม่เก็บเลขบัตรในฐาน Laravel
- นักศึกษา เกรด วิชาลงทะเบียน กพช. และคุณธรรมอ่านจาก latest successful batch ของอำเภอเดียวกัน
- Learning, ผู้ใช้, ประวัติ import และห้องสอบอ่านตารางจริงตาม role/district/group
- โปรไฟล์ที่เลือกแสดง, theme และ branding บันทึกในฐาน Laravel พร้อม audit log
- import ทำงานผ่าน database queue โดยตรวจ ZIP/DBF และสร้างชุดใหม่ให้สำเร็จก่อนแทนที่ชุดเดิมของอำเภอนั้น

## นโยบายแหล่งข้อมูลภายในระบบ

- หน้า React เรียก `/api/v1/*` ของ Laravel แอปเดียวกันเท่านั้น เส้นทางเหล่านี้ไม่ใช่ API ของเว็บไซต์ภายนอก
- ผู้ใช้งานและสิทธิ์อ่าน/เพิ่ม/แก้ไขตาราง `users` ในฐานข้อมูลของระบบโดยตรง และจำกัดข้อมูลตาม role กับอำเภอ
- ข้อมูลนักศึกษา ผลการเรียน การเรียนรู้ รายงาน และ import ใช้ฐานข้อมูลหรือไฟล์ที่ติดตั้งอยู่กับระบบ
- ชื่อจังหวัด อำเภอ และตำบลอ่านจาก `resources/data/thai_administrative_areas.csv` ซึ่งติดมากับ deployment
- ระบบปิดกั้น HTTP request ภายนอกผ่าน Laravel HTTP client โดยค่าเริ่มต้น เพื่อไม่ให้เกิด dependency ภายนอกโดยไม่ตั้งใจ

## Stack

- Laravel 13, Sanctum และ session authentication
- React 19, TypeScript, TanStack Query และ TanStack Table
- Tailwind CSS 4, Radix Themes และ Phosphor icons
- Laravel control-plane DB แยกจาก MySQL legacy
- MySQL legacy connection ตั้ง session เป็น `TRANSACTION READ ONLY`

## รันระบบปัจจุบันด้วย MAMP

ห้ามใช้ `migrate --seed` ในโหมดข้อมูลจริง เพราะ seeder มีไว้สำหรับ development demo เท่านั้น

```bash
npm install
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm run build
```

ตั้ง Apache ของ MAMP ให้ใช้ค่าต่อไปนี้ แล้ว restart MAMP:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
DocumentRoot "/Applications/MAMP/htdocs/SDL_school/laravel-app/public"

<Directory "/Applications/MAMP/htdocs/SDL_school/laravel-app/public">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

กำหนด `APP_URL=http://localhost:8888` และ `SANCTUM_STATEFUL_DOMAINS=localhost:8888,127.0.0.1:8888` จากนั้นเปิด [http://localhost:8888/index.php](http://localhost:8888/index.php) หรือ [http://localhost:8888/login](http://localhost:8888/login)

การนำเข้า ZIP/DBF บันทึกงานลง database queue ก่อน แล้วเปิด worker เบื้องหลังอัตโนมัติ จึงไม่ต้องเปิด queue worker แยกใน MAMP ค่าแนะนำคือ:

```bash
SENA_LEGACY_IMPORT_QUEUE_CONNECTION=database
SENA_LEGACY_IMPORT_AUTOSTART_CONNECTION=background
DB_QUEUE_RETRY_AFTER=900
```

ตัวปลุก background จะระบายงาน import ที่ค้างตามลำดับจนคิวว่าง ไม่หยุดหลังงานแรก ใช้ file lock เดียวกับ scheduled worker และตั้งเวลาจองงานให้นานกว่า timeout เพื่อป้องกัน worker สองตัวนำเข้า ZIP เดียวกันซ้ำ หากโฮสต์ปิดการสร้าง background process งานจะยังค้างอย่างปลอดภัยใน database queue และสามารถเปิด worker ด้วย `php artisan legacy:work-import-queue` ได้โดยข้อมูลไม่สูญหาย สำหรับ Plesk Scheduled Task ให้ใช้ `legacy:work-import-queue --once`

หากแก้ `.env` ให้รัน `php artisan config:clear` ก่อนทดสอบ การเปิด `mod_rewrite` จำเป็นต่อการรีเฟรช route เช่น `/app`, `/students` และ `/grades`

## รันโหมด demo แบบไม่แตะฐานข้อมูลจริง

ใช้ไฟล์ SQLite ใหม่และตั้ง `SENA_DEMO_MODE=true`, `SENA_DATA_SOURCE=demo`, `LEGACY_STUDENT_ENABLED=false` จากนั้นรัน:

```bash
touch database/demo.sqlite
php artisan migrate --seed
php artisan serve
```

บัญชีทดสอบใช้รหัสผ่าน `Demo1234!`: `admin.demo`, `teacher.demo`, `super.demo` และนักศึกษา `6650100001` หน้า login จะแสดง “ชื่อผู้ใช้ + รหัสผ่าน” อัตโนมัติในโหมด demo และแสดง “เลขประจำตัวประชาชน + รหัสนักศึกษา” เมื่อเชื่อม legacy จริง

## Production database configuration

Production ต้องใช้ฐานสองชุดและบัญชีคนละสิทธิ์:

1. `DB_*` เป็นฐาน Laravel control-plane ที่เขียนได้ ห้ามใช้ฐานเดียวกับ legacy
2. `LEGACY_DB_*` เป็นฐานเดิมด้วยบัญชีที่มี `SELECT` เท่านั้น

ค่าหลัก:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com/SDL_school
ASSET_URL=https://example.com/SDL_school
SENA_DEMO_MODE=false
SENA_DATA_SOURCE=legacy
LEGACY_STUDENT_ENABLED=true
SENA_LEGACY_READ_ONLY=true
SENA_LEGACY_CONFIG_FALLBACK=false
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=...
LEGACY_DB_USERNAME=... # SELECT-only
LEGACY_DB_PASSWORD=...
LEGACY_WRITE_DB_HOST=...
LEGACY_WRITE_DB_PORT=3306
LEGACY_WRITE_DB_DATABASE=...
LEGACY_WRITE_DB_USERNAME=... # INSERT/UPDATE account for admin writes
LEGACY_WRITE_DB_PASSWORD=...
```

`LEGACY_WRITE_DB_*` ใช้เฉพาะ connection `legacy_write` สำหรับการเพิ่ม/แก้ไขผู้ใช้ ข้อมูลการเรียนรู้ และ import หากไม่กำหนด ระบบจะ fallback ไปใช้ค่า `LEGACY_DB_*` เพื่อรองรับเครื่องพัฒนาเดิม แต่ production ที่เปิด `SENA_LEGACY_WRITE_ENABLED=true` ต้องกำหนดบัญชีเขียนแยกจากบัญชี `SELECT-only` และหลังแก้ `.env` ให้รัน `php artisan optimize:clear` หรือ rebuild config cache ก่อนใช้งาน

ถ้าติดตั้งใต้ subdirectory เช่น `/SDL_school` ต้องให้ทั้ง `APP_URL` และ `ASSET_URL`
มี path เดียวกัน จากนั้นรัน `php artisan optimize:clear` และ build/deploy `public/build` ใหม่
เพื่อให้ Vite, React Router และ API ใช้ base path ถูกต้อง

`SENA_LEGACY_CONFIG_FALLBACK=true` ใช้กับ MAMP เครื่องพัฒนาเครื่องนี้เท่านั้น โดย parser อ่านเฉพาะค่าเชื่อมต่อจาก `storage/app/private/legacy-database.credentials` และไม่ execute ไฟล์นั้น ห้ามเปิด fallback บน production

## คำสั่งตรวจสอบ

```bash
npm run typecheck
npm run build
vendor/bin/pint --test app routes config database/migrations database/seeders tests bootstrap/app.php
php artisan test
```

Real database smoke tests เป็น opt-in และอ่านอย่างเดียว:

```bash
SENA_LEGACY_CONFIG_FALLBACK=true LEGACY_STUDENT_INTEGRATION=true \
  php artisan test tests/Feature/Students/LegacyStudentRepositoryTest.php

SENA_LEGACY_CONFIG_FALLBACK=true LEGACY_PORTAL_INTEGRATION=true \
  php artisan test tests/Feature/LegacyProductionReadIntegrationTest.php
```

## Production safety

- Document root ต้องชี้ไปที่ `laravel-app/public` เท่านั้น
- ห้ามชี้ Laravel default connection ไปฐาน legacy และห้ามรัน `artisan migrate` กับฐาน legacy
- ห้าม include `../auth.php` จาก Laravel เพราะไฟล์นั้นมี request-time DDL และ migration side effects
- หมุน DB/API secrets เดิมก่อนเปิดผ่านอินเทอร์เน็ต และใช้ secret manager แทน hard-coded fallback
- เปิด HTTPS พร้อม `SESSION_SECURE_COOKIE=true` และกำหนด `SESSION_DOMAIN`/`SANCTUM_STATEFUL_DOMAINS` ให้ตรงโดเมนจริง
- ก่อนเปิด import ต้องมี `quarantine → validate → stage → reconcile → approve → atomic activate → rollback`
- ดูแผน cutover เพิ่มเติมใน [docs/MIGRATION_PLAN.md](docs/MIGRATION_PLAN.md)
