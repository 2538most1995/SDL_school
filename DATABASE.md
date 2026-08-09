# Database Knowledge Base

เอกสารนี้สร้างจาก `laravel-app/database/migrations/`, `app/Models/` และ query ใน `app/` เท่านั้น Migration และ source ปัจจุบันเป็น source of truth; schema ของฐาน legacy จริงยังไม่ได้ตรวจด้วย live connection (`Not verified`)

## Connections

| Connection | ใช้สำหรับ | สิทธิ์/สถานะ |
| --- | --- | --- |
| default (`DB_*`) | Laravel control-plane, users, districts, learning, queue, audit | เขียนได้ตาม environment |
| `legacy` | import registry, DBF-derived tables และ legacy reads | production ตั้งเป็น `SELECT`-only |
| `legacy_write` | import/replace เมื่อเปิดใช้งานโดย explicit config | ปิดโดย default |

## Control-plane tables

### `districts`

`id` BIGINT PK, `name` string NOT NULL, `code` string(40) UNIQUE NOT NULL, login/branding fields nullable, `is_active` boolean DEFAULT true indexed, NNET schedule fields nullable, timestamps. มี `District::users()` → `users.district_id`.

### `users`

เริ่มจาก Laravel users table แล้วเพิ่ม `legacy_key` UNIQUE nullable, `legacy_user_id` indexed nullable, `student_code` indexed nullable, `auth_source` indexed DEFAULT `local`, display/contact/appearance fields, avatar fields และ timestamps. `password`, `remember_token` ถูกซ่อนจาก model output; `contact_email` ถูก encrypt/cast ใน `User` model. `district_id` ถูกเพิ่มโดย migration scope ของผู้ใช้และอ้างถึง `districts.id`.

### Student canonical domain

- `students`: district/import batch, student code, hashed/encrypted citizen ID, name, education level, group, enrollment/latest term, status และ source payload; UNIQUE (`district_id`, `student_code`) และ scope index (`district_id`, `education_level`, `group_code`, `status`)
- `subjects`: district, subject code/name, education level, credits; UNIQUE (`district_id`, `education_level`, `subject_code`)
- `registrations`: student/subject, academic term, status, transfer flag; UNIQUE (`student_id`, `subject_id`, `academic_term`)
- `grades`: student/subject, academic term, raw/numeric grade, credits, type code, source totals; UNIQUE (`student_id`, `subject_id`, `academic_term`) และ index (`student_id`, `academic_term`, `numeric_grade`)
- `student_activities`: student, term, activity, name, hours/date; index (`student_id`, `academic_term`)
- `moral_assessments`: student, term, JSON scores, average/rating; UNIQUE (`student_id`, `academic_term`)

Domain classes under `app/Domain/Students/Models/` (`Student`, `Grade`, `RegisteredSubject`, `KpchActivity`, `MoralAssessment`) เป็น immutable DTOs ไม่ใช่ Eloquent models. Current production student reads use `LegacyStudentRepository` and dynamic imported tables; canonical tables are not assumed to contain current legacy data.

### Learning domain

- `learning_assignments`: district, creator, title/instructions, subject, target type/value, score, open/due timestamps, status; index (`district_id`, `status`, `due_at`)
- `learning_submissions`: assignment/student, content/attachment, submission/review status, score/feedback; UNIQUE (`assignment_id`, `student_id`)
- `learning_resources`: district, uploader, title/description, subject, resource/storage fields, visibility; indexed subject/type/visibility
- `learning_lesson_plans`: district, teacher, subject, academic term, content fields, status; indexed subject/term/status
- `learning_calendar_events`: district, creator, title/content, event type, start/end, location and targeting; index (`district_id`, `starts_at`)
- `learning_schedules`: district, term, subject, group, teacher, type, start/end and room; indexed term/subject/group/type/start
- `exam_rooms`: latest repair migration uses `term`, `subject_code`, `assignment_type`, `start_val`, `end_val`, `room_name`, district/import batch and timestamps; index (`district_id`, `term`, `subject_code`)

Column names from older migration definitions (`academic_term`, `room_code`, `student_code`, `seat_number`) are migrated by `2026_08_07_000014_fix_import_and_exam_room_schema.php`; do not write new code against the old shape.

### Import, audit and infrastructure

- `import_batches`: district, unique `batch_key`, status, source metadata, importer, activation, validation JSON and timestamps; index (`district_id`, `status`, `created_at`)
- `import_history`: file/batch metadata, district, status and indexes (`batch_key`, `district_id`, `batch_key`)
- `raw_import_tables`: import batch, education level, data type, physical table, row count/schema hash/status; UNIQUE physical table and batch/type identity
- `active_import_batches`: district PK → active import batch, activation metadata
- `audit_logs`: optional user/district, event, auditable identity, request/IP, before/after/context JSON and time indexes
- `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens`: Laravel infrastructure tables

## Legacy dynamic tables

Successful ZIP/DBF imports create physical names such as `db_import_{timestamp}_{hash}_{level}_{type}`. Supported types are `student`, `grade`, `subject`, `activity`, `virtue`, `group`, and optional `schedule`/`field`. Identifiers must come from the validated batch registry and pass the repository identifier whitelist; values use bindings. Exact live columns, cardinalities and indexes are `Not verified` without the legacy database.

## Important relationships and query patterns

- District scope is mandatory for admin/teacher reads and writes.
- Latest student data is resolved from the latest successful registered batch for the same district; cross-district fallback is not allowed.
- Student repository batches grade/activity/moral aggregates by table set and student codes; it does not query one grade/activity record per student.
- `exam_rooms` lookup uses district + term and matches exact/wildcard subject plus group/student ranges.
- `import_batches.import_history_id` must remain aligned with both district and batch key.

## Index notes

Existing indexes cover the main district/status/date filters and exam-room district/term/subject lookup. Additional index proposals are recorded in [`PERFORMANCE.md`](PERFORMANCE.md); none are added based on column names alone. Live `SHOW INDEX` and `EXPLAIN` are `Not verified`.

## Migration history

The schema was introduced in the dated migrations from `2026_07_17` onward. Branding/avatar/color/NNet changes followed, and `2026_08_07_000014_fix_import_and_exam_room_schema.php` repairs import history and exam-room compatibility. Run `php artisan migrate:status` against the intended control-plane database before deployment.
