# SDL School Context

## Purpose and architecture

SDL School is a Laravel 13 + React 19 application for multi-district student administration and learning services. The deployment owns one database selected by `DB_*`; users, districts, settings, audit, queue, learning data, import registry and imported DBF tables all live in that database. There is no connection to the former Sena Care School database.

## User types and roles

| Role | Scope |
| --- | --- |
| `super_admin` | cross-district administration where the route supports it |
| `admin` | manage users, imports, branding and exam rooms in selected district |
| `teacher` | read assigned student groups and manage own learning content |
| `student` | access own profile, academic records and targeted learning content |

District scope is resolved by `ResolveDistrictContext`; role checks are enforced by `EnsureRole` and route middleware. Never infer district scope from an unvalidated request parameter.

## Main modules

- Authentication/profile/appearance and district branding
- Student directory and current-student academic endpoints
- Student reports: overview, new students, graduates, transfers, registrations, grade threshold and attendance
- Learning: assignments, resources, calendar activities with private images, schedules, lesson plans, scores and content writes
- Admin: users, import status/safety, ZIP/DBF imports, exam rooms and branding
- Legacy compatibility redirects for old `.php` paths

## Business rules

1. A non-super-admin user is limited to the active selected district and their role/group scope.
2. Student code is not assumed globally unique across district/level; ambiguous lookups fail closed.
3. Imported student reads use the latest successful batch belonging to the same district; no cross-district fallback.
4. Dynamic import-table identifiers must be resolved from a validated batch and identifier whitelist; bound parameters are required for values.
5. Student PII is masked by default and unmasked only for an allowed scope/resource.
6. Import creates and validates a new batch before replacing old district batches; writes are disabled by default.
7. Learning writes require teacher/admin/super-admin role and teacher ownership/district checks.
8. คะแนนเก็บใช้รายวิชาลงทะเบียนจากชุด import ล่าสุดใน district และจำกัดนักศึกษาตามกลุ่มที่ครูรับผิดชอบ; นักศึกษาอ่านได้เฉพาะคะแนนของตนเอง
9. คลังสื่อแบบไฟล์เก็บใน private storage และต้องดาวน์โหลดผ่าน endpoint ที่ตรวจ district, กลุ่ม และระดับการศึกษา
8. Student login derives district scope from one unique citizen-ID match across current-term rosters in active districts; client-supplied district values are ignored and ambiguous matches fail closed.
9. Calendar activities, per-day schedules, external links and private images are visible only within the event district and `all`/assigned-group target; only teacher/admin/super-admin roles can create or change them, teacher writes remain creator/group scoped, and only admin/super-admin may choose the single district activity featured on the student dashboard.
10. Authenticated learning routes run an idempotent additive readiness check so a Git-only shared-hosting deploy can repair missing learning/audit tables or columns before the first read/write; normal deployments still run migrations.
11. งานที่มอบหมายเลือกได้เฉพาะรายวิชาลงทะเบียนในภาคเรียนปัจจุบันและขอบเขตกลุ่มของครู; ครูแนบลิงก์และ PDF private storage เป็นเอกสารประกอบงานได้พร้อมกัน นักศึกษาในกลุ่มจึงเห็นชื่อ คำชี้แจง และเอกสารก่อนส่งงาน; การส่งงานใช้ `student_code` จากชุดข้อมูลผู้เรียนปัจจุบัน รองรับลิงก์หรือ PDF และครูเห็น/ตรวจได้เฉพาะงานที่ตนสร้างภายในอำเภอ

## Request/data flow

```text
Request → route → Sanctum/auth + active + district/role middleware
        → controller → validation → domain service/repository
        → Eloquent/query builder on the system default database
        → resource/JSON/PDF → response
```

## Authentication and authorization

- Login is handled by `Auth\LoginController`; API access uses Sanctum.
- `EnsureActiveUser`, `EnsureRole`, `ResolveDistrictContext` and `SecurityHeaders` are registered in `bootstrap/app.php`.
- Protected routes are defined in `routes/api.php`; admin writes are restricted to `admin,super_admin`, learning writes to `teacher,admin,super_admin`, and super-admin branding routes to `super_admin`.
- POST/PATCH/PUT/DELETE paths use Laravel validation/authentication and audit where applicable. Upload/import paths must retain size/type/path validation.
- Staff login checks the local `users` table. Student login verifies a citizen ID against current-term rows in the latest imported tables across active districts, derives the matching district, then provisions/refreshes an internal session account without persisting the citizen ID in `users`. Student code remains an internal link to academic data and is not a login input.
- User administration reads and writes `users` in the default database and records audit events there; it has no shadow-user or external-database sync.

## Important routes/controllers/services

- `/api/v1/portal` → `Api\PortalController`
- `/api/v1/students*` and `/api/v1/reports/*` → `Api\Students\*`
- `/api/v1/learning/*` → `Api\Learning\*`
- `/api/v1/admin/*` → `Api\Admin\*`
- Student source → `StudentRepository`, `DemoStudentRepository` or imported-DBF repository
- Learning/import → services under `App\Services\Legacy`; the namespace is historical and does not represent a separate database connection
- PDF → `ExamScheduleExportService`/mPDF

## Data and external services

- DBF-derived tables are created in the default database. `config/system_data.php` contains feature flags and import paths only; it cannot select another database connection.
- ZIP extraction uses `ZipArchive`; DBF parsing uses `VisualFoxProDbfReader`.
- Staff users, districts, student records, learning data, imports and reports are read from databases/files owned by this deployment. Browser calls under `/api/v1` are same-application endpoints, not third-party APIs.
- Thai administrative names are resolved from the bundled `resources/data/thai_administrative_areas.csv` snapshot. `ThaiAdministrativeAreaLookup` performs no network request.
- `AppServiceProvider` blocks unreviewed outbound calls made through Laravel's HTTP client. No third-party production data API is used.

## Current status

- Laravel application, routes, migrations, models/services and tests were audited.
- Performance fixes are recorded in [`PERFORMANCE.md`](PERFORMANCE.md).
- Real MySQL EXPLAIN and production response time are `Not verified`.

## Known issues and next tasks

- Student directory performs filtering/sorting/pagination after loading the active roster and aggregates; measure realistic data before redesigning its API.
- Imported DBF reporting needs additional fixture-backed integration tests.
- Review Pint findings in the existing files and run live query plans before adding indexes.

## Do not change without checking

- Import batch selection, district scope, PII masking, role middleware and API response fields
- `2026_08_07_000014_fix_import_and_exam_room_schema.php` and any dynamic import table naming
- Default database connection and import write flags
- Student academic-term normalization and grade selection rules
- Frontend response shapes and compatibility redirect routes
