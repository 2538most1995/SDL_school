# SDL School Context

## Purpose and architecture

SDL School is a Laravel 13 + React 19 application for multi-district student administration and learning services. Laravel owns the control-plane data (users, districts, settings, audit, queue and learning write models). When `SENA_DATA_SOURCE=legacy` and `LEGACY_STUDENT_ENABLED=true`, student and imported academic data are read from a separate legacy MySQL connection using a read-only repository.

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
- Learning: assignments, resources, calendar, schedules, lesson plans, scores and content writes
- Admin: users, import status/safety, ZIP/DBF imports, exam rooms and branding
- Legacy compatibility redirects for old `.php` paths

## Business rules

1. A non-super-admin user is limited to the active selected district and their role/group scope.
2. Student code is not assumed globally unique across district/level; ambiguous lookups fail closed.
3. Legacy reads use the latest successful batch belonging to the same district; no cross-district fallback.
4. Legacy table identifiers must be resolved from a validated batch and identifier whitelist; bound parameters are required for values.
5. Student PII is masked by default and unmasked only for an allowed scope/resource.
6. Import creates and validates a new batch before replacing old district batches; writes are disabled by default.
7. Learning writes require teacher/admin/super-admin role and teacher ownership/district checks.

## Request/data flow

```text
Request → route → Sanctum/auth + active + district/role middleware
        → controller → validation → domain service/repository
        → control-plane Eloquent/query builder OR legacy read-only connection
        → resource/JSON/PDF → response
```

## Authentication and authorization

- Login is handled by `Auth\LoginController`; API access uses Sanctum.
- `EnsureActiveUser`, `EnsureRole`, `ResolveDistrictContext` and `SecurityHeaders` are registered in `bootstrap/app.php`.
- Protected routes are defined in `routes/api.php`; admin writes are restricted to `admin,super_admin`, learning writes to `teacher,admin,super_admin`, and super-admin branding routes to `super_admin`.
- POST/PATCH/PUT/DELETE paths use Laravel validation/authentication and audit where applicable. Upload/import paths must retain size/type/path validation.
- Legacy admin user writes commit to `legacy_write` first. If a compatibility deployment has not installed the optional shadow-user columns or `audit_logs` table, shadow synchronization is skipped and the audit entry falls back to the structured Laravel log; an already successful user mutation must not be returned as HTTP 500.

## Important routes/controllers/services

- `/api/v1/portal` → `Api\PortalController`
- `/api/v1/students*` and `/api/v1/reports/*` → `Api\Students\*`
- `/api/v1/learning/*` → `Api\Learning\*`
- `/api/v1/admin/*` → `Api\Admin\*`
- Student source → `StudentRepository`, `DemoStudentRepository` or `LegacyStudentRepository`
- Legacy learning/import → `LegacyPortalReadService`, `LegacyZipImportService`, `LegacyExamScheduleService`
- PDF → `ExamScheduleExportService`/mPDF

## Data and external services

- Legacy MySQL/DBF-derived tables are configured through `config/legacy.php`; live schema/cardinality is `Not verified` in this audit.
- ZIP extraction uses `ZipArchive`; DBF parsing uses `VisualFoxProDbfReader`.
- Thai administrative lookup can call `data.go.th` through `ThaiAdministrativeAreaLookup`.
- No third-party production API contract was inferred beyond code/config.

## Current status

- Laravel application, routes, migrations, models/services and tests were audited.
- Performance fixes are recorded in [`PERFORMANCE.md`](PERFORMANCE.md).
- Real MySQL EXPLAIN, production response time and live legacy integration are `Not verified`.

## Known issues and next tasks

- Student directory performs filtering/sorting/pagination after loading the active roster and aggregates; measure realistic data before redesigning its API.
- Legacy portal/exam-room integration needs fixture-backed integration tests.
- Review Pint findings in the existing files and run live query plans before adding indexes.

## Do not change without checking

- Legacy batch selection, district scope, PII masking, role middleware and API response fields
- `2026_08_07_000014_fix_import_and_exam_room_schema.php` and any dynamic import table naming
- Legacy read-only connection and import write flags
- Student academic-term normalization and grade selection rules
- Frontend response shapes and legacy redirect routes
