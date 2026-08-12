# Database Knowledge Base

เอกสารนี้สร้างจาก `laravel-app/database/migrations/`, `app/Models/` และ query ใน `app/` เท่านั้น Migration และ source ปัจจุบันเป็น source of truth

## Connections

| Connection | ใช้สำหรับ | สิทธิ์/สถานะ |
| --- | --- | --- |
| default (`DB_*`) | users, districts, students, learning, import/DBF tables, queue และ audit | เป็น connection เดียวของระบบ |

ไม่มี connection ชื่อ `legacy` หรือ `legacy_write` ใน `config/database.php` และห้ามเพิ่มค่าดังกล่าวกลับมา

## Control-plane tables

### `districts`

`id` BIGINT PK, `name` string NOT NULL, `code` string(40) UNIQUE NOT NULL, login/branding fields nullable, `is_active` boolean DEFAULT true indexed, NNET schedule fields nullable, timestamps. มี `District::users()` → `users.district_id`.

### `users`

Laravel users table มี `username`, `first_name`, `last_name`, `student_code`, `auth_source` (`local` สำหรับบัญชีที่ผู้ดูแลสร้าง และ `system_import` สำหรับ session account นักศึกษา), role/district/group, display/contact/appearance fields, avatar fields และ timestamps. การเข้าสู่ระบบนักศึกษาตรวจเลขบัตรประชาชนเพียงอย่างเดียวจากตาราง import ในฐานเดียวกัน โดยต้องตรงกับนักศึกษาปัจจุบันเพียงหนึ่งรายการ และไม่เก็บเลขบัตรซ้ำใน `users`; `student_code` ยังคงใช้ภายในเพื่อเชื่อมข้อมูลการเรียน. คอลัมน์ compatibility จาก migration เก่าอาจยังอยู่แต่ไม่ได้ใช้เชื่อมฐานภายนอก. `password`, `remember_token` ถูกซ่อนจาก model output; `contact_email` ถูก encrypt/cast ใน `User` model.

### Student canonical domain

- `students`: district/import batch, student code, hashed/encrypted citizen ID, name, education level, group, enrollment/latest term, status และ source payload; UNIQUE (`district_id`, `student_code`) และ scope index (`district_id`, `education_level`, `group_code`, `status`)
- `subjects`: district, subject code/name, education level, credits; UNIQUE (`district_id`, `education_level`, `subject_code`)
- `registrations`: student/subject, academic term, status, transfer flag; UNIQUE (`student_id`, `subject_id`, `academic_term`)
- `grades`: student/subject, academic term, raw/numeric grade, credits, type code, source totals; UNIQUE (`student_id`, `subject_id`, `academic_term`) และ index (`student_id`, `academic_term`, `numeric_grade`)
- `student_activities`: student, term, activity, name, hours/date; index (`student_id`, `academic_term`)
- `moral_assessments`: student, term, JSON scores, average/rating; UNIQUE (`student_id`, `academic_term`)

Domain classes under `app/Domain/Students/Models/` (`Student`, `Grade`, `RegisteredSubject`, `KpchActivity`, `MoralAssessment`) เป็น immutable DTOs ไม่ใช่ Eloquent models. Production student reads use dynamic tables produced by ZIP/DBF import in the default database; canonical tables รองรับข้อมูลที่สร้าง/แปลงภายในระบบ.

### Learning domain

- `learning_assignments`: district, creator, title/instructions, subject, target type/value, score, open/due timestamps, status; index (`district_id`, `status`, `due_at`)
- `learning_submissions`: assignment/student, content/attachment, submission/review status, score/feedback; UNIQUE (`assignment_id`, `student_id`)
- `learning_resources`: district, uploader, title/description, subject, education level, target group, resource/storage fields, visibility; external URL รองรับได้สูงสุด 2,000 ตัวอักษร ส่วนไฟล์ PDF/เอกสาร/สเปรดชีต/ZIP เก็บใน local private storage และดาวน์โหลดผ่าน authenticated endpoint ที่ตรวจ district, กลุ่ม และระดับการศึกษาของนักศึกษา
- `learning_lesson_plans`: district, teacher, subject, education level, academic term, content fields และ status
- `learning_calendar_events`: district, creator, title/content, event type, outer start/end, JSON `daily_schedule` สำหรับเวลาเริ่ม/สิ้นสุดรายวัน, location, `external_url`, targeting, `featured_on_dashboard`, private `image_path` and `image_updated_at`; index (`district_id`, `starts_at`). รูปกิจกรรมเก็บใน local private storage และส่งผ่าน authenticated endpoint ที่ตรวจ district/group scope โดยผู้ดูแลเลือกกิจกรรมหน้าแรกได้หนึ่งรายการต่ออำเภอ
- `learning_schedules`: district, term, subject, group, teacher, type, start/end and room; indexed term/subject/group/type/start
- `learning_scorebooks`: สมุดคะแนนหนึ่งชุดต่อ district/term/subject/level/group พร้อมผู้สร้าง; UNIQUE (`district_id`, `academic_term`, `subject_code`, `education_level`, `group_code`)
- `learning_score_components`: ช่องคะแนนและคะแนนเต็มเรียงตามลำดับ; UNIQUE (`scorebook_id`, `position`)
- `learning_score_entries`: คะแนนรายนักศึกษาและช่องคะแนน โดยใช้ `student_code` snapshot แทน foreign key ไปตาราง DBF แบบ dynamic; UNIQUE (`scorebook_id`, `component_id`, `student_code`)
- `learning_score_notes`: หมายเหตุรายนักศึกษาในสมุดคะแนน; UNIQUE (`scorebook_id`, `student_code`)
- `exam_rooms`: latest repair migration uses `term`, `subject_code`, `assignment_type`, `start_val`, `end_val`, `room_name`, district/import batch and timestamps; index (`district_id`, `term`, `subject_code`)

Column names from older migration definitions (`academic_term`, `room_code`, `student_code`, `seat_number`) are migrated by `2026_08_07_000014_fix_import_and_exam_room_schema.php`; do not write new code against the old shape.

### Import, audit and infrastructure

- `import_batches`: district, unique `batch_key`, status, source metadata, importer, activation, validation JSON and timestamps; index (`district_id`, `status`, `created_at`)
- `import_history`: file/batch metadata, district, status and indexes (`batch_key`, `district_id`, `batch_key`)
- `raw_import_tables`: import batch, education level, data type, physical table, row count/schema hash/status; UNIQUE physical table and batch/type identity
- `active_import_batches`: district PK → active import batch, activation metadata
- `audit_logs`: optional user/district, event, auditable identity, request/IP, before/after/context JSON and time indexes
- `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens`: Laravel infrastructure tables

## Dynamic import tables

Successful ZIP/DBF imports create physical names such as `db_import_{timestamp}_{hash}_{level}_{type}` inside the default database. Supported types are `student`, `grade`, `subject`, `activity`, `virtue`, `group`, and optional `schedule`/`field`. Identifiers must come from the validated batch registry and pass the repository identifier whitelist; values use bindings.

## Important relationships and query patterns

- District scope is mandatory for admin/teacher reads and writes.
- Latest student data is resolved from the latest successful registered batch for the same district; cross-district fallback is not allowed.
- Student repository batches grade/activity/moral aggregates by table set and student codes; it does not query one grade/activity record per student.
- `exam_rooms` lookup uses district + term and matches exact/wildcard subject plus group/student ranges.
- `import_batches.import_history_id` must remain aligned with both district and batch key.

## Index notes

Existing indexes cover the main district/status/date filters and exam-room district/term/subject lookup. Additional index proposals are recorded in [`PERFORMANCE.md`](PERFORMANCE.md); none are added based on column names alone. Live `SHOW INDEX` and `EXPLAIN` are `Not verified`.

## Migration history

The schema was introduced in the dated migrations from `2026_07_17` onward. `2026_08_07_000014_fix_import_and_exam_room_schema.php` repairs import history/exam rooms, `2026_08_10_000015_prepare_system_owned_data.php` completes local user and learning fields without overwriting an existing `auth_source`, `2026_08_10_000016_repair_incomplete_system_schema.php` safely restores user/runtime tables, `2026_08_10_000017_repair_calendar_events_and_add_media.php` restores a missing calendar table when necessary and adds private activity-image fields, `2026_08_11_000018_add_calendar_presentation_fields.php` adds per-day schedules, activity links and dashboard selection, `2026_08_11_000019_create_learning_scorebooks.php` adds registered-subject scorebooks and score entries, `2026_08_11_000020_expand_learning_resource_external_url.php` widens resource URLs without shrinking or deleting existing values, and `2026_08_12_000021_normalize_learning_resource_type.php` converts an adopted legacy `resource_type` enum to the current string contract without deleting rows. These repair migrations do not delete tables or user data. Authenticated learning routes also use `LearningSchemaReadiness` as an additive, database-locked safety net for Git-only shared-hosting deployments; migrations remain the source of truth. Run `php artisan migrate:status` against the intended system database before deployment.

The canonical student and learning migrations inspect the actual parent-key types before creating foreign-key columns, including student, assignment, district and user references. This supports adopted local tables whose primary keys are `INT`, repairs child tables left behind by a failed MySQL foreign-key statement, and never deletes orphaned rows to force a constraint. A reference is left unconstrained when an adopted parent table has no `id` column.
