# SDL School

ระบบจัดการข้อมูลนักศึกษาและพอร์ทัลการเรียนรู้สำหรับหลายอำเภอ ปัจจุบันแอปหลักอยู่ใน [`laravel-app/`](laravel-app/) และอ่านข้อมูลนักศึกษา/ผลการเรียนจากฐาน legacy แบบ read-only เมื่อเปิดใช้งานโหมด production data

## Features

- Authentication ด้วย Laravel Sanctum และ session
- สิทธิ์ `super_admin`, `admin`, `teacher`, `student` พร้อม district scope
- Student directory, grades, subjects, กพช., คุณธรรม และรายงาน
- Learning assignments, resources, calendar, lesson plans, schedules และ scores
- ZIP/DBF import แบบ staging และ queue พร้อม batch replacement
- Branding, user appearance, audit log และ PDF exam schedule

## Tech stack

- Laravel 13.23.0, PHP `^8.3`
- React 19, TypeScript, Vite 8, Tailwind CSS 4
- MySQL สำหรับ production และ SQLite สำหรับ automated tests
- Sanctum, mPDF และ database queue

## Requirements

- PHP 8.3+ พร้อม PDO, `pdo_mysql`, `mbstring`, `zip`, `iconv`
- Composer, Node.js และ npm
- Laravel control-plane database ที่เขียนได้
- Legacy MySQL database แยกบัญชี SELECT-only เมื่อเปิด `SENA_DATA_SOURCE=legacy`

## Installation and development

```bash
cd laravel-app
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

ห้ามใช้ `migrate --seed` กับฐานข้อมูลจริง เพราะ seeder เป็นข้อมูล demo สำหรับ development เท่านั้น ดูรายละเอียด environment และ MAMP ได้ที่ [`laravel-app/README.md`](laravel-app/README.md)

## Database setup

Migration ของ Laravel control-plane อยู่ที่ [`laravel-app/database/migrations/`](laravel-app/database/migrations/) รายละเอียดตาราง, foreign key, index และ legacy dynamic tables อยู่ใน [`DATABASE.md`](DATABASE.md)

## Testing and quality

```bash
cd laravel-app
php artisan test
npm run typecheck
./vendor/bin/pint --test app routes config database/migrations database/seeders tests bootstrap/app.php
npm run build
```

Real legacy integration tests ต้องเปิด opt-in และใช้ connection แบบอ่านอย่างเดียวตามคำสั่งใน [`laravel-app/README.md`](laravel-app/README.md)

## Deployment

ให้ web server ชี้ document root ไปที่ `laravel-app/public` เท่านั้น ใช้ฐาน control-plane และ legacy คนละ connection/credential, ปิด `APP_DEBUG`, เปิด HTTPS และตรวจ `SENA_LEGACY_READ_ONLY=true` ก่อน deploy

## Project structure

```text
laravel-app/app/Domain/       business domains และ repositories
laravel-app/app/Http/         controllers, middleware, requests, resources
laravel-app/app/Services/     legacy integration, import และ PDF services
laravel-app/database/         migrations, factories, seeders
laravel-app/routes/           API, web และ console routes
laravel-app/resources/        React, TypeScript, Blade และ CSS
laravel-app/tests/            feature/unit tests
```

## Documentation

- [`CONTEXT.md`](CONTEXT.md) — architecture, roles, business rules และ data flow
- [`DATABASE.md`](DATABASE.md) — schema knowledge base จาก migrations/source
- [`PERFORMANCE.md`](PERFORMANCE.md) — performance audit, fixes, index และ benchmark status
- [`AGENTS.md`](AGENTS.md) — กฎสำหรับ AI/developer ที่ทำงานต่อ
- [`SKILL.md`](SKILL.md) — workflow และ checklist สำหรับงาน Laravel/MySQL
