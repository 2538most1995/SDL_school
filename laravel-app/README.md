# Sena Care School Laravel

ระบบใช้ Laravel 13 + React 19 + TypeScript และเก็บข้อมูลทั้งหมดในฐานข้อมูลของ deployment นี้ผ่านค่า `DB_*` เท่านั้น ไม่มีการเรียก API หรือเปิด connection ไปยังเว็บไซต์/ฐานข้อมูล Sena Care School เดิม

## แหล่งข้อมูล

- ครู/ผู้ดูแลล็อกอินและจัดการบัญชีผ่านตาราง `users` ภายในระบบ
- นักศึกษาล็อกอินด้วยเลขบัตรประชาชน 13 หลักและรหัสนักศึกษา โดยตรวจจาก dynamic tables ของ ZIP/DBF ในฐาน `DB_*` เดียวกัน แล้วสร้างบัญชี session ภายในโดยไม่บันทึกเลขบัตรซ้ำใน `users`
- อำเภอ สิทธิ์ โปรไฟล์ theme branding audit และ queue อยู่ในฐานเดียวกัน
- งาน สื่อ แผนการสอน ปฏิทิน ตารางสอน และคะแนนใช้ตาราง `learning_*` ภายในระบบ
- นักศึกษา ผลการเรียน วิชา กพช. และคุณธรรมมาจาก ZIP/DBF ที่ผู้ดูแลนำเข้าผ่านระบบ แล้วสร้าง dynamic tables ในฐาน `DB_*`
- ชื่อจังหวัด อำเภอ และตำบลอ่านจาก `resources/data/thai_administrative_areas.csv` ที่ติดมากับ deployment
- หน้า React เรียกเฉพาะ `/api/v1/*` ของ Laravel แอปเดียวกัน และ Laravel HTTP client ปิดกั้น outbound request ที่ไม่ได้อนุมัติ

ชื่อ class/namespace บางส่วนยังมีคำว่า `Legacy` เพื่อรองรับรูปแบบไฟล์ DBF เดิม แต่ไม่มี database connection ชื่อ `legacy` หรือ `legacy_write`

## ติดตั้ง

ห้ามตั้ง `DB_*` ไปยังฐานข้อมูลเดิม และห้ามใช้ `migrate --seed` กับฐาน production เพราะ seeder เป็นข้อมูล demo

```bash
composer install --no-dev --optimize-autoloader
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm run build
```

สร้างบัญชีผู้ดูแลแรก:

```bash
php artisan system:create-admin \
  --username=system.admin \
  --name="ผู้ดูแล ระบบ" \
  --district-code=sena \
  --district-name="อำเภอเสนา"
```

คำสั่งจะถามรหัสผ่านแบบซ่อนค่า หากต้องการผู้ดูแลทุกอำเภอให้ใช้ `--super-admin` และไม่ต้องระบุอำเภอ

สำหรับ Plesk Scheduled Task ซึ่งรับคำถามแบบโต้ตอบไม่ได้ ให้ส่ง `--no-interaction` โดยไม่ต้องส่งรหัสผ่าน ระบบจะสร้างรหัสผ่านชั่วคราวและแสดงใน output ครั้งเดียว:

```bash
php artisan system:create-admin \
  --username=system.admin \
  --name="ผู้ดูแล ระบบ" \
  --super-admin \
  --no-interaction
```

งาน `migrate` และ `system:create-admin` เป็นงานครั้งเดียว ให้ปิดหรือลบ Scheduled Task หลังทำสำเร็จ

## Environment production

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com/SDL_school
ASSET_URL=https://example.com/SDL_school

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sdl_school
DB_USERNAME=...
DB_PASSWORD=...

SENA_DATA_SOURCE=system
SENA_DEMO_MODE=false
SYSTEM_STUDENT_DATA_ENABLED=true
SYSTEM_WRITES_ENABLED=true
SYSTEM_IMPORT_QUEUE_CONNECTION=database
SYSTEM_DATABASE_SESSIONS=true
```

หลังแก้ `.env` ให้รัน:

```bash
php artisan optimize:clear
php artisan migrate --force
npm run build
```

ตัวสร้าง URL ของ Vite ตรวจ base path จาก request ด้วย จึงยังสร้าง URL ใต้ `/SDL_school/build/assets/*` ได้แม้ cache เคยเก็บ `ASSET_URL` ที่รากโดเมน แต่ production ควรกำหนด `APP_URL` และ `ASSET_URL` ให้มี `/SDL_school` ถูกต้องตามตัวอย่าง

Migration รองรับฐานที่มีตารางเดิมอยู่แล้วแต่ migration ledger ว่าง โดยจะตรวจ table/column ก่อนสร้างและไม่ลบข้อมูลหรือ password hash เดิม

หากตาราง `students` ภายในฐานของระบบมี `id` แบบ `INT` จาก schema เดิม migration จะสร้างและซ่อม `student_id` ให้ใช้ชนิดเดียวกันก่อนเพิ่ม foreign key จึงสามารถรันซ้ำหลัง MySQL ทิ้งตารางที่สร้างค้างไว้ได้ โดยไม่ลบแถวข้อมูลที่อ้างอิงไม่ครบ

ระบบยังมี repair migration สำหรับ production ที่ migration ledger เคยบันทึกสำเร็จไม่ตรงกับ schema จริง โดยจะเติมคอลัมน์ผู้ใช้และตาราง `sessions`, `cache`, `jobs`, `audit_logs` ที่ขาดแบบไม่ลบข้อมูลเดิม หลัง deploy ต้องรัน `php artisan migrate --force` และ `php artisan optimize:clear`

เพื่อรองรับ shared hosting ที่ deploy ด้วย Git แต่ยังไม่ได้รันคำสั่งข้างต้น request แรกของโมดูล learning หลังล็อกอินจะตรวจและเติมเฉพาะตาราง/คอลัมน์ learning และ audit ที่จำเป็นแบบ additive ภายใต้ database lock โดยไม่ลบข้อมูลเดิม หาก database user ไม่มีสิทธิ์ `CREATE`/`ALTER` ระบบจะตอบ HTTP 503 พร้อมแจ้งให้รัน migration แทน กลไกนี้เป็น safety net เท่านั้นและไม่แทนที่การรัน migration ตามขั้นตอน deploy

จากนั้นนำเข้า ZIP/DBF ผ่านเมนู Admin เพื่อให้ข้อมูลนักศึกษาอยู่ในฐานใหม่ของระบบ ไม่ควร copy หรือ query ข้อมูลจากฐานเก่าโดยตรง

หากต้องการตารางสอบแบบเดิม ZIP ต้องมี `SCHEDULE.DBF` ของแต่ละระดับที่ใช้งานและ `FIELD.DBF` สำหรับชื่อสนามสอบ ระบบจะนำไฟล์เหล่านี้เข้าเป็นตาราง `db_import_*_schedule` และ `db_import_*_field` ในฐาน `DB_*` ของระบบเอง ส่วนการจัดห้องสอบอ่านจาก `exam_rooms` ในฐานเดียวกัน

## MAMP

ตั้ง Apache document root ไปที่ `laravel-app/public`:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
DocumentRoot "/Applications/MAMP/htdocs/SDL_school/laravel-app/public"

<Directory "/Applications/MAMP/htdocs/SDL_school/laravel-app/public">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

กำหนด `APP_URL=http://localhost:8888` และ `SANCTUM_STATEFUL_DOMAINS=localhost:8888,127.0.0.1:8888` แล้ว restart MAMP

ระบบเริ่มประมวลผล import หลังส่ง HTTP response ด้วย `deferred` driver จึงไม่ต้องเปิด background process และใช้งานได้บน shared hosting ที่ปิด `proc_open` หากต้องการ worker สำรอง ให้ตั้ง Scheduled Task รัน `--once` ทุก 1 นาที:

```bash
php artisan system:work-import-queue --once
```

## Demo

```bash
touch database/demo.sqlite
# ตั้ง DB_CONNECTION=sqlite, DB_DATABASE เป็น path ของไฟล์
# ตั้ง SENA_DATA_SOURCE=demo และ SENA_DEMO_MODE=true
php artisan migrate --seed
php artisan serve
```

## ตรวจสอบ

```bash
php artisan test
npm run typecheck
vendor/bin/pint --test app routes config database/migrations database/seeders tests bootstrap/app.php
npm run build
```

## Production safety

- Document root ต้องชี้ `public` เท่านั้น
- ใช้ database user ที่จำกัดเฉพาะฐาน `DB_DATABASE` ของระบบ
- เปิด HTTPS และตั้ง `SESSION_SECURE_COOKIE=true`
- สำรองฐานข้อมูลและไฟล์ import ก่อน deploy/migrate
- ทดสอบ migration ในฐาน staging ก่อน production
- รักษา district scope, role middleware, validation ZIP/DBF และ audit log
