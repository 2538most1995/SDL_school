# Performance Audit

## Lesson-plan reader and missing covers (2026-10-29)

PDF companions take priority on the first reader click, avoiding a provider placeholder and second action. Stored covers retain their lightweight authenticated image request. Only visible cards without a usable stored cover download the private PDF to render page one locally; these rebuilds are serialized to avoid loading multiple 20 MB PDFs concurrently, and a failed rebuild releases the queue. No PDF or cloud request is added to list queries or the application server. Browser-generated fallback covers are temporary and regenerated on remount; uploading a PDF through the editor stores a durable first-page cover.

## Lesson-plan compatibility checks (2026-10-29)

Lesson-plan writes reuse one `Schema::getColumns` result for document readiness, title capacity and legacy alias detection, replacing the former column-list readiness query. No query is added per alias or per row; list/read queries and indexes are unchanged. Regression fixtures reproduce the inspected adopted schema in isolated SQLite. MySQL latency benchmarking is Not verified.

## Combined lesson-plan scope (2026-10-29)

Optional `plan_scope` and `education_level` filters apply to the existing district/teacher SQL query before the 250-plan cap; no per-card query or copied plan per course is introduced. A regression fixture with 251 newer subject plans confirms an older combined plan remains discoverable. Scope-filtered requests check column availability once for pre-migration compatibility. Existing bounded cover rendering and file limits are unchanged; no new index is added without MySQL plan evidence.

Verification: PHP 512 passed / 1 existing skip, frontend 87 passed, typecheck/build and changed-file Pint pass. Synthetic demo-browser checks created PDF combined plans for all three levels without a subject input, verified selected scope/level defaults, filtered out other levels, retained the PDF after metadata edits, and checked 1440px/390px without horizontal overflow. Real deployment data and MySQL timing remain Not verified.

## Lesson-plan document shelf (2026-10-29)

The existing bounded 250-plan read adds one teacher LEFT JOIN, without per-card SQL. Covers fetch only as cards approach the viewport and use revoked blob URLs; PDF.js loads only when selecting a PDF. Its worker, CMaps, fonts and image decoders ship in the build for root/subdirectory deployments without CDN requests. Rendering is limited to page one, a 900×1400 bounding box, 20 MB PDF input and a 30-second deadline; stored covers are at most 2 MB. External document links are never downloaded by the server. Legacy list search/level filtering remains client-side within the latest 250; live MySQL plans/large-library latency remain Not verified.

Verification: full PHP suite 508 passed / 1 existing skip; frontend 84 passed; TypeScript/build and changed-file Pint pass. Synthetic SQLite browser checks under `/SDL_school/` cover Thai two-page PDF first-page generation, four actual cover cards, PDF upload/read, cloud-link companions, retained original share URL, malformed PDF rejection, source-button stability, existing cover retention, 1440px/390px and dark mode without horizontal overflow. Private cloud-provider permissions/real cloud-file access remain Not verified. The dependency audit still reports the pre-existing locked Vite advisory; no unrelated dependency upgrade was applied.

## Unified classroom (2026-10-29)

The single classroom entry reuses existing resource/file-assignment APIs and private storage; no copy, schema change or per-item query is introduced. Course-pair validation and file-assignment catalogs reuse the classroom's current-term merged registrations. Resource type, subject and level filtering happens in SQL before the retained 250-row limit; explicit selected assignment IDs are prioritized with a bound CASE within the existing scope. A regression fixture with 255 newer unrelated rows verifies scoped records and old deep links remain reachable. Supplemental search filters server-side. Live MySQL plans/large-roster latency remain Not verified.

Verification: full PHP suite 498 passed / 1 existing skip (499 total); frontend 77 passed, TypeScript/build and changed-file Pint pass. Isolated demo-browser checks under `/SDL_school/` cover retired-menu absence, old assignment/resource redirects, teacher file-work creation, student image submission, teacher score/feedback, PDF upload/private preview and mobile 390px without horizontal overflow. No real student records or deployment database were modified.

## Subdirectory asset fix (2026-10-29)

Relative Vite asset resolution fixes lazy classroom stylesheet/font requests under `/SDL_school/` without additional API calls or database work. Frontend tests pass 75/75, including executing the emitted preload helper and checking CSS assets for root, `/SDL_school` and nested deployments. Typecheck/build and four PHP application smoke tests pass. A browser reproduction with domain-root `/build/` blocked now opens the classroom and previews the downloaded JSON template using isolated demo data; production authenticated behavior remains unverified until deployment.

## Online classroom (2026-10-28)

Classroom category counts add one SQL GROUP BY over the same district/term/course/level and audience/owner/publish scope. `activity_type` filtering happens before 50-row pagination. JSON/XLSX imports run client-side with a 5 MB input / 100-question limit; Excel parsing loads as a separate lazy chunk only on import/template actions. ZIP extraction checks actual emitted bytes against per-entry metadata and a 10 MiB total, in bounded compressed chunks, with 200-entry/expansion-ratio limits before SheetJS receives a sanitized archive. Templates retain typed scores and text-only cells, with wrapped prompts and Thai guide rows. Live MySQL plans and large-roster performance remain Not verified.

Card/import verification: full PHP suite 490 passed / 1 existing skip; frontend 73 passed; typecheck/build and changed-file Pint pass. Browser checks on synthetic demo data cover three levels, category filtering and video player, JSON/XLSX downloads/import previews, invalid files, five-type authoring and keeping draft values after simulated failed background reloads. Independent review verified a forged 12 MiB ZIP expansion is rejected before SheetJS (~4.5 ms locally); this is a malformed-fixture check, not a production performance benchmark.

The classroom reuses current-term registration reads and merges web registrations with one district+term+accessible-student query, without per-student database queries. Activities and review submissions use SQL pagination (50 rows), with group eligibility applied before activity pagination. Counts and own attempts are batched by visible activity IDs. A course composite index and unique activity+student attempt index support list/submission operations; writes add parent-row locking to serialize submission, question changes and deletion. Image blobs are fetched through authorization endpoints and object URLs are revoked on unmount. No external links are fetched server-side. Live MySQL plans, large-roster latency and concurrency throughput are Not verified; validation uses synthetic SQLite fixtures.

Verification: 17 new classroom feature tests pass, including filtering before pagination, separate student attempts, deadline timezone normalization and repeatable migration. Full PHP suite: 486 passed / 1 existing skip; frontend: 62 passed. Typecheck, production build and changed-file Pint pass. Browser synthetic demo checks cover creation/upload, student submit failure/retry, teacher review, 390px/1440px and dark mode. A local CSS override keeps MUI's hidden textarea measurement free of the portal's fixed minimum, preventing oversized essay inputs without changing other modules.

## Student homepage menu settings (2026-10-15)

- Custom internal/external links reuse the same district preference JSON and catalog response. Validation and resolution run in memory (maximum 50 custom links), with no server-side URL fetches, extra database queries or schema changes. Removing student grade-detail buttons changes presentation only; grade/GPA queries and calculations are unchanged.
- Custom-link verification: PHPUnit 405 passed / 1 skipped, frontend 50 passed, typecheck/build/Pint pass. On isolated SQLite demo data, an admin saved internal/external links; the learner dashboard rendered the internal `/grades` link and an external HTTP(S) anchor with `_blank`/`noopener noreferrer`. Clicking external opened a separate Example Domain tab. Learner grades have five columns and no detail action; staff grade detail buttons remain. Custom editor at 390px has no horizontal page overflow. Real production data was not used or modified.
- Student system catalog adds a column-availability check and one scoped district read for menu preferences; settings resolve in memory against the allowed module catalog. The homepage shares the existing React Query catalog key with the portal layout and uses its five-minute stale time. No query per menu item or student; saving invalidates the system catalog cache.
- No indexes are added: district preference lookup uses its primary key. Saving updates one district JSON field and inserts one audit record in a transaction. Live MySQL timing/DDL locking: `Not verified`.
- Verification uses an isolated temporary SQLite demo database: admin renamed/reordered/hid a button, reloaded persisted settings, and the student homepage showed the matching seven buttons. Desktop 1440px and mobile 390px were inspected with no page overflow at 390px. Super-admin district switching discarded an unsaved draft and loaded the other district's settings with saving disabled until edited. Initial person view was verified for registration/exam-attendance reports. PHPUnit: 399 passed, 1 skipped; frontend: 50 passed; typecheck and production build pass. No real student data was changed.

## Registration workspace ITW status (2026-10-15)

- The `/ นว. ครบ` projection merges scoped selected-term ITW and saved courses in memory, deduplicates by code and reuses the existing registration credit policy. This introduces no additional database reads or writes. Regression fixtures cover pending grades, insufficient electives despite potential graduation, historical passed-course repeats, duplicates across ITW/web, and a term with no registrations.
- ITW registration status reuses the existing scoped `gradesForMany()` result, normalizes terms and deduplicates nonempty subject codes per student in memory. No additional queries, indexes, schema changes or imported-table writes. Existing earned-credit policy supplies the independent completion badge. Live MySQL performance and real-data browser behavior: `Not verified`.

ตรวจจาก source, migrations, static query scan และ automated tests วันที่ 2026-08-08. ไม่มี live legacy dataset ใน test run จึงไม่มีการสร้างตัวเลข benchmark สมมติ

## Summary

| Priority | Issue | Status |
| --- | --- | --- |
| P1 | `LegacyPortalReadService::users()` query district name ใน loop (N+1) | Fixed |
| P1 | `LegacyPortalReadService::overview()` โหลด assignments ซ้ำผ่าน calendar | Fixed |
| P1/P2 | ห้องสอบถูก query ซ้ำต่อ subject ภายใน request เดียว | Fixed: cache ต่อ district + term |
| P2 | Student directory โหลด active roster และ aggregate ทั้งชุดก่อน filter/paginate ใน PHP | Known limitation; not refactored |
| P2 | Live MySQL execution plan/cardinality | Not verified |
| P3 | Pint findings ในไฟล์เดิม 8 ไฟล์ | Not changed; style-only scope |

## Student login district resolution (2026-08-10)

- **Problem:** requiring students to choose a district made valid credentials fail when the selected district did not match the imported record, while broadening the shared roster made the student menu show rows outside the current-term cohort.
- **Solution:** the directory roster is again limited to the latest academic term. Student login does not accept district scope from the client; it matches citizen ID across active-district current-term rosters and derives the district from the single matching record. Student code is no longer a login input.
- **Security:** zero or multiple exact matches return the same generic credential error. The citizen ID is not persisted to `users`, and a client-supplied `district_id` cannot redirect the session to another district.
- **Impact:** a login may load current-term rosters for all active districts. Request-local repository caches still apply, but this is broader than a district-scoped directory request.
- **Risk:** live MySQL row counts and execution plans for Sena/Phaisali are `Not verified`; run `EXPLAIN` with sanitized production-shaped data after deployment.

## Functional issues found by browser smoke test

การทดสอบด้วย browser จริงบน SQLite demo แยกจากฐานใช้งานพบและแก้ regression เพิ่มเติม 3 จุด:

1. `GET /api/v1/learning` เคยตอบ 404 ใน demo mode เพราะ overview บังคับ legacy อย่างเดียว ปัจจุบันมี canonical demo fallback และ contract test
2. ห้องสอบ demo ส่ง field คนละชุดกับหน้า admin ทำให้ตารางแสดง `undefined` ปัจจุบันใช้ contract เดียวกับ production (`term`, `subject_code`, `assignment_type`, `start_val`, `end_val`, `room_name`, `capacity`)
3. หน้า login นักศึกษาใช้เลขบัตรประชาชนช่องเดียวเมื่อเปิดข้อมูลนักศึกษาจากระบบ ส่วน local/demo auth ใช้ username + password โดย public branding API ระบุ `loginMode` ให้ UI เลือก field ตามโหมด

หน้า Learning Overview แสดงวันที่ย่อภาษาไทยแทน ISO timestamp เพื่อให้อ่านได้ใน card ขนาดเล็ก

## Changes

### 1. Legacy user directory N+1

- **Problem:** หลังดึงผู้ใช้สูงสุด 250 แถว มี `districts` query ต่อผู้ใช้หนึ่งราย
- **Root cause:** mapper เรียก `table('districts')->where(...)->value()` ใน callback
- **Solution:** join `districts` ครั้งเดียวและเลือก `district.name AS district_name`; เลือกเฉพาะ columns ที่ response ใช้
- **Impact:** จาก `1 + N` queries เป็น `1` query ต่อ request
- **Risk:** ต้องมี `districts` table ใน legacy/control-plane connection เดียวกับ users ตาม architecture ปัจจุบัน; contract test ยืนยันผลลัพธ์และ query count แล้ว

### 2. Duplicate assignment query

- **Problem:** `overview()` เรียก assignments ก่อน แล้วเรียก `calendar()` ซึ่งโหลด assignments อีกครั้ง
- **Root cause:** calendar ไม่มีทางรับ collection ที่โหลดแล้ว
- **Solution:** แยก `calendarItems()` และส่ง assignments ที่มีอยู่แล้วจาก overview; public calendar contract ไม่เปลี่ยน
- **Impact:** ตัด query assignments ที่ซ้ำใน overview request
- **Risk:** ต่ำ; filtering/type mapping เดิมยังทำงานที่ helper เดิม

### 3. Exam-room query reuse

- **Problem:** `queryExamRoomDb()` ยิง query ต่อ subject และอาจยิง fallback query เพิ่มอีกครั้ง
- **Root cause:** cache key ผูกกับ district + term + subject
- **Solution:** load เฉพาะ columns ที่ใช้จริงครั้งเดียวต่อ district + term แล้วกรอง exact/wildcard subject ใน memory โดยคง fallback behavior เดิม
- **Impact:** ใน service instance ลดจากต่อ unique subject เป็น 1 query ต่อ district/term
- **Risk:** live row volume และ case-sensitivity ตาม collation ยังต้องตรวจด้วย MySQL จริง

### 4. Test compatibility

แก้ driver detection ใน `LegacyStudentRepository` ให้ใช้ MySQL-specific `BINARY` เมื่อ connection มี `getDriverName()` และใช้ plain equality ใน mocked/SQLite contexts; ไม่เปลี่ยน production MySQL path.

### 5. Student statistics and expected-graduate queries

- ตัดการเรียก `expectedGraduateRows()` ออกจากรายงานลงทะเบียน เพราะรายงานนี้ไม่ใช้สถานะ N-Net และการเรียกเดิมทำให้สแกน student/grade ซ้ำทั้งอำเภอ
- รายงานคาดว่าจะจบโหลด grade แบบกลุ่มรหัสนักศึกษาครั้งละ 500 คน แทนการสร้าง `IN (...)` ขนาดไม่จำกัด ลดความเสี่ยงชน parameter/query-size limit เมื่อทะเบียนอำเภอมีข้อมูลมากขึ้น
- batch และ dynamic table set ยังคง resolve จาก batch สำเร็จล่าสุดที่ผูกกับ `district_id`; ไม่มี query หรือ table name ที่ hard-code เสนา/ไพศาลี จึงรองรับทะเบียนอำเภอใหม่ด้วยเส้นทางเดียวกัน
- import pipeline มี index สำหรับ grade ตาม `_perf_std10`, `_perf_semestry` และ `_perf_sub`; execution plan และเวลาจริงบน MySQL production ยัง `Not verified`

## Query and Eloquent review

- Dynamic legacy identifiers ถูก whitelist ก่อน interpolate; values ใช้ bindings
- Student aggregates ใช้ SQL `SUM`, `GROUP BY`, `EXISTS` และ cache ต่อ immutable batch
- Student directory ยัง sort/filter/paginate ใน memory หลัง repository สร้าง active roster; ปัจจุบันเป็น contract-compatible แต่จะกิน memory ตามจำนวน active students
- API collections ส่วนใหญ่จำกัด `250` แถว; directory จำกัด `per_page` สูงสุด `1000` และยังไม่ใช่ DB pagination
- Student Data Integration API จำกัด `per_page` สูงสุด `100`, คืนหน้าว่างเมื่อขอเกินหน้าสุดท้าย และ rate limit แยกต่อ API client ค่าเริ่มต้น `60` request/นาที แต่ยัง reuse directory ที่โหลด roster และ aggregates ทั้งอำเภอก่อน paginate; live memory, response time และ MySQL plan ยัง `Not verified`
- ไม่พบ `Model::all()` ใน application path ที่ตรวจ และไม่พบ controller query ที่ชัดเจนอยู่ใน `foreach`; พบ full-table reads ของ DBF/field/group ที่เป็น legacy file/domain behavior

## Index recommendations

ยัง **ไม่ได้เพิ่ม index** โดยเดาจากชื่อ column. รายการที่ควรตรวจด้วย `EXPLAIN` บนฐานจริง:

1. `users`: พิจารณา (`district_id`, `role`, `first_name`) หาก user directory ใช้ filter/sort นี้บ่อยและ cardinality เหมาะสม
2. `learning_resources`: พิจารณา (`district_id`, `created_at`) สำหรับ district filter + newest ordering
3. คะแนนใช้ index ตาม course scope (`district_id`, `academic_term`, `subject_code`, `education_level`) และ unique key ราย scorebook/component/student; การแยกคะแนนเก็บกับปลายภาคอ่าน component ของสมุดคะแนนเป็นชุดเดิม และ roster โหลดจากชุด import ล่าสุดแบบ join เดียว ไม่เพิ่ม query รายคน
   ตัวตรวจการคำนวณคะแนน ITW ใช้แถวคะแนนที่ `importedScores()` โหลดเป็นชุดอยู่แล้ว แล้วคำนวณ/กรองในหน่วยความจำก่อนแบ่งหน้า จึงไม่เพิ่ม query รายคนหรือเขียนกลับตารางนำเข้า; เวลาและ memory บนชุดข้อมูล production ยัง `Not verified`
4. ต้นแบบโครงสร้างคะแนนอ่านด้วย `district_id` index และจำกัดสูงสุด 200 รายการต่อ request; การกรองต้นแบบทุกรายวิชา/เฉพาะบางวิชาทำใน memory จาก JSON ขนาดเล็กเพื่อให้ทำงานเหมือนกันทั้ง MySQL และ SQLite โดยไม่เกิด query รายต้นแบบ คำสั่งใช้กับทุกรายวิชาอ่าน roster ที่ถูก scope ครั้งเดียว แล้วตรวจ/สร้างสมุดคะแนนต่อ subject; ปริมาณวิชาจริงบน production และเวลารวมยัง `Not verified`
5. `exam_rooms`: migration ล่าสุดมี (`district_id`, `term`, `subject_code`) แล้ว; ตรวจว่า wildcard-term query ใช้ prefix นี้ได้ตามข้อมูลจริง
6. `learning_course_registrations`: unique (`district_id`, `academic_term`, `student_code`) รองรับการอ่านรายการรายภาคเรียน; workspace โหลดผลการเรียนของนักศึกษาที่ผ่านตัวกรองด้วย `gradesForMany()` หนึ่งชุดแทน query รายคน และค้นหา term ที่เคยบันทึกภายใน district เดียวกันเพื่อไม่สแกนข้ามอำเภอ; live MySQL plan ยัง `Not verified`
7. import batch/history joins: ตรวจ FK/index ของ `import_history_id`, (`district_id`, `batch_key`) และ ordering latest batch
8. `announcements`: migration มี (`district_id`, `is_active`, `updated_at`) เพื่ออ่านประกาศที่เปิดล่าสุดโดยไม่สแกนข้ามอำเภอ และหน้า admin จำกัดผลลัพธ์ล่าสุดไว้ 100 รายการ; live cardinality และ MySQL plan ยัง `Not verified`
9. `statistics_report_preferences`: unique (`user_id`, `district_id`, `report_key`) รองรับการโหลด/บันทึกรูปแบบรายงานหนึ่งแถวต่อ scope โดยตรง; live MySQL plan ยัง `Not verified`
10. `public_relations_posts`: composite index (`district_id`, `is_published`, `published_at`) รองรับการอ่านข่าวที่เผยแพร่ล่าสุดของนักศึกษา หน้า admin จำกัด 100 รายการและหน้า student จำกัด 50 รายการ; รูปตอบด้วย private cache 1 ชั่วโมง แต่ live cardinality, response time และ MySQL plan ยัง `Not verified`

ผลของ index ต่อ INSERT/UPDATE/DELETE และ execution plan จริง: `Not verified`.

## Cache strategy

- `LegacyStudentRepository` มี request-local caches สำหรับ table sets/student list/columns
- Aggregate cache ใช้ key ที่รวม district, batch, level และ student-code hash; default TTL `900` seconds
- Batch เป็น immutable หลัง activation จึง cache ได้ แต่ต้อง clear/เปลี่ยน key เมื่อเปิด batch ใหม่หรือเปลี่ยน schema
- ไม่เพิ่ม cache ใหม่ในรอบนี้ เพราะ user directory และ room lookup เป็น request-local query reuse ที่ชัดเจนกว่า

## Benchmark

### ZIP transfer including registrations and scores (2026-10-15)

- ITW mode reuses the already scoped/validated JSONL snapshot, so patching DBFs adds no SQL queries. It streams selected DBF entries into private temporary files, indexes their natural keys in memory per level and performs fixed-width record updates/appends. It preserves source files and original record positions; live updates are not applied. Memory scales with one level's source identity/catalog/grade maps and edited score entries. VFP null flags, field widths and decimal precision are checked, and the target must rebuild retained CDX indexes. Maximum-dataset memory/latency and native Windows ITW execution remain Not verified.
- Score saving now stores one nullable note marker per saved learner/scorebook, including blank notes. This uses the existing unique index/schema and lets the exporter distinguish an explicit full clear from untouched original marks. It adds a note insert for saves that formerly deleted an empty note; API note values remain null. Old fully cleared rows without a marker require re-saving.

- Export verifies batch/history ownership and copies the retained ZIP to a private temporary directory without rebuilding DBFs. Five scoped learning datasets are read in ID chunks of 500 within a transaction, then encoded as JSONL. Child datasets join the district's scorebooks. Validation streams files and keeps source-ID maps, with limits of 1 MB per row and 1,000,000 rows per dataset. JSONL is stored without compression to stay within the importer's ZIP-ratio guard. Final ZIP limits remain 90 MB/2,000 entries. Temporary files are deleted after streaming, with shutdown cleanup as fallback. Browser download buffers one ZIP as a Blob.
- Restore validates before any DBF tables are created, then inserts registrations/entries/notes in batches of 500. Books/components require insertGetId per record to remap relationships. The restore and batch-registration transaction rolls back completely on unique-scope conflicts before prior-batch cleanup. There are no per-student source queries or scans of imported tables for export; no schema/index changes. Live MySQL throughput, snapshot isolation behavior under concurrent edits, maximum-size browser memory and large-district restore time remain Not verified.
- Synthetic in-memory SQLite tests verify unchanged original DBF/FPT content, all-term registration/score restoration, zero/NULL values, midterm component titles, weights, ID/actor remapping, district isolation, checksums, forged fields, relationship validation, re-export freshness, conflict rollback and ordinary ITW compatibility. Real student data was not used or modified.
- Final verification: 24 transfer feature tests (including seven ITW cases), five binary DBF-editor tests and the existing scorebook tests pass. Full PHPUnit: 434 passed, 1 skipped; frontend: 50 passed; TypeScript, production build, changed-file Pint, PHP syntax and diff whitespace checks pass. An independent dbfread 2.0.7 parser read synthetic exported DBFs: four grade records, two subjects, coursework slots 0/18, MIDTERM 18, FINAL 30, TOTAL 48, blank recalculation GRADE, Thai subject name and transferred registration all matched expectations. Playwright with an isolated demo database and mocked API responses verified ITW/SDL download choices, the fractional-score error and 1440px/390px layout without page overflow; screenshots are under `output/playwright/itw-dbf-*.png`. Popup feedback uses plain title text so contextual validation errors cannot insert HTML. Maximum-volume, real MySQL timing and native Windows ITW execution remain Not verified.

### Visual FoxPro NULL import correction (2026-10-09)

- The reader now resolves `_NullFlags` in each streamed record before decoding nullable columns. Field-to-bit positions and the bitmap offset are calculated once from the header; import still uses the existing batch INSERTs and indexes, without queries per record.
- End-to-end import of the user-supplied ZIP into a separate temporary SQLite database succeeded: 20 tables, 204,323 imported rows, zero warnings, 4.161 seconds on the local machine. The source ZIP remained unchanged. This is a local verification measurement, not a production MySQL benchmark.
- The investigated row retained coursework/total 50 and the source special grade, while FINAL/FINAL1/FINAL2 were SQL NULL despite stale numeric bytes in the source. The calculation audit no longer raised the spurious total-82 warning.
- Existing batches require reimport from their original ZIP after deployment; no in-place real-data repair or schema migration was run. Live MySQL import time and memory remain `Not verified`.

| Metric | Before | After | Evidence |
| --- | --- | --- | --- |
| User directory query count | `1 + N` | `1` | static review + `LegacyPortalReadPerformanceTest` (1 user) |
| Overview duplicate assignment query | duplicated in same request | one load/reuse | source review; Not benchmarked |
| Exam-room queries | per unique subject (+ fallback) | one per district + term | source review; Not benchmarked |
| Registration-statistics N-Net scan | full expected-graduate student/grade scan in addition to registration query | removed | unit assertion verifies no expected-graduate query |
| Expected-graduate grade lookup | one unbounded student-code `IN (...)` | chunks of at most 500 student codes | unit test with 108 expected graduates; live workload Not benchmarked |
| Admin exam-room scope | ทุกภาคเรียนสูงสุด 500 รายการและไม่มีขอบเขตตำบล/ระดับ | เฉพาะภาคเรียนปัจจุบันสูงสุด 5,000 รายการ; exact student/group assignment ใช้ index ในหน่วยความจำ; การ sync อ่าน grade/schedule ด้วย term indexes ของ active batch แล้ว insert ทีละ 500 แถว | feature test; live workload Not benchmarked |
| Database time | Not benchmarked | Not benchmarked | live MySQL unavailable |
| Response time | Not benchmarked | Not benchmarked | live workload unavailable |
| Memory usage | Not benchmarked | Not benchmarked | live workload unavailable |
| Payload size | Not benchmarked | Not benchmarked | API contract unchanged |

## Remaining issues / next steps

1. Run `EXPLAIN`/`EXPLAIN ANALYZE` on live MySQL using sanitized representative data.
2. Measure student directory at realistic row counts; consider a query-backed/cursor API only with an explicit API contract plan.
3. Verify legacy table indexes for `_perf_id10`, `_perf_std10`, `_perf_sub`, `_perf_semestry` and joins in the imported batches.
4. Add integration coverage for legacy portal and exam-room paths using a non-production fixture.

## Verification run

- Browser: admin dashboard, student directory/detail, registered-subject report, learning overview, users, exam rooms และ imports
- Browser role scope: teacher เห็น 3 นักศึกษาใน 2 กลุ่มและเข้า admin route ไม่ได้; student เห็นข้อมูลตนเอง 1 คน ผลการเรียน 6 รายการ และเข้า admin route ไม่ได้
- Login: staff และ student local/demo login รวมถึง logout
- ไม่มีการ upload, import, delete หรือเขียนข้อมูลจริงระหว่าง smoke test

## Imported-score layout and navigation (2026-10-10)

- Group/subject typeahead filters the API-provided options in the browser and sends existing values; typing in these selectors adds no per-option database query. Student search/filter/pagination API behavior is unchanged.
- Score-table scrolling uses native overflow and CSS sticky headings/name cells rather than scroll event handlers; it renders only the current API page as before. Verification used 40 synthetic rows at 390/820/1440px, not a production benchmark.
- District navigation reads one nullable JSON preference from the selected district after a schema-column availability check; no query per menu item. Categories are limited to 30 and all existing menu keys must occur exactly once on save. Role filtering remains server-side. Live MySQL timings remain Not verified.

## Registration elective reuse (2026-10-12)

- Staff registration detail adds one district+education-level query streaming only elective JSON from saved registrations, ordered by updated_at/id. It deduplicates by course code and merges the fixed curriculum suggestions without per-student queries. Learner reads skip this district-wide catalog query. No index/schema changes were introduced.
- This reads saved registration history in the selected district/level on each staff detail load; production volume, sort execution plan and latency are Not verified. The existing district/term/student unique index supplies district filtering, but not update-time ordering.
- API verification uses in-memory SQLite fixtures; browser verification uses a separate temporary SQLite demo database, not real student data.

- Browser smoke checks passed at 1440px and 390px for student/staff: two desktop columns, one mobile column and no page horizontal overflow. On isolated demo data, staff saved a new elective, another learner's editor offered it without auto-selecting it, and the owning student saw the saved course after reload. No browser errors in the staff session. Typecheck, production build, 40 frontend tests and registration feature/PDF tests passed.

- Final full PHPUnit run: 370 passed, 1 skipped; changed PHP files pass Pint. Repository-wide Pint still reports pre-existing formatting violations in seven untouched files (DistrictBranding, announcement controllers, CurriculumCatalog, registration export service, web routes and PDF tests). Real MySQL data was not used or modified.

- Layout correction: compulsory subjects restored to their original table above electives; only elective cards use a responsive two-column grid. This is a presentation-only change with no API/database updates.
- Corrected layout verified in the isolated demo browser: compulsory input belongs to the original table; the elective grid starts below that table and has two 522px columns at 1440px, one 324px column at 390px. Page width matches viewport at both sizes. Typecheck and production build pass.

- Registration pending-status correction reuses the existing selected-term saved-registration query, moving it before status resolution; it adds no database queries, schema, indexes or source-data writes. Regression fixtures cover false exam flags on blank results, historical absence followed by a new pending registration, and preservation of explicit absence/zero outcomes. Live production data for the screenshot remains Not verified.
- Pending-status verification: 29 registration feature/PDF tests pass; full suite has 372 passed and 1 skipped. PHP syntax and Pint checks pass for changed files. Tests use isolated in-memory SQLite and synthetic grade fixtures.

- Elective metadata display correction: known/saved course metadata is shown as text by default, with explicit per-course editing. Browser checks on isolated demo data confirm no metadata inputs for saved/quick-added courses, inputs when editing or adding a new course, and no mobile overflow at 390px. No API/schema/query changes; typecheck and production build pass.

- Unified elective table merges existing API data in the browser without new queries. Verification: 43 frontend tests, typecheck and production build pass; isolated demo browser checks registration/transfer exclusivity and saving only the two selected courses rather than all six displayed suggestions. Real student data was not modified.


## Imported scores are editable (2026-10-15)

The former immutable-batch academic-cache assumption no longer holds for grade values. `LegacyStudentRepository` includes scoped `SUM(imported_score_edits.version)` in academic aggregate cache keys (v3); the revision is memoized per repository/request. Saving a mark makes subsequent report requests use a new key without flushing other districts. Existing batch replacement invalidation still applies. Edits use indexed district/batch/level/row scope; export captures edit rows in 500-row chunks in the portable export transaction and validates source DBF identity before patching output. Browser edits one row at a time, suspending refetch/filter/pagination until save/cancel. Native MySQL EXPLAIN/large production benchmark: Not verified; automated tests use isolated SQLite and synthetic DBFs.


## Score-entry autosave requests (2026-10-15)

A 650 ms debounce coalesces typing, blur flushes the current row, and one queue serializes writes to avoid district-lock contention. Edits during an in-flight save retain the latest draft and chain the returned token. Errors stop retries until correction/user action; stale tokens require explicit row reload. Finishing drains saves before one completion request. GET loads completion flags in one scoped visible-page query; finishing reads grade candidates once per level (at most three queries) and bulk-upserts metadata. Entry suspends refetch/filter/pagination; compact column views reduce rendering and horizontal scrolling. Reports are invalidated after completion. Synthetic concurrency/queue tests pass. Production throughput/EXPLAIN and physical mobile-device entry: Not verified.

### Student directory term selection (2026-10-19)

Term discovery reads DISTINCT `_perf_semestry` from grade tables of the active successful district batch, with the existing `_perf_expsem` fallback only when no valid grade term exists. Normalized terms are memoized per table set during the repository lifetime; student caches include district scope and selected term. Historical roster membership uses the existing indexed `_perf_std10`/`_perf_id10` EXISTS join with bound academic-term variants; aggregate queries remain restricted to the selected cohort. No migration/index added. Live MySQL EXPLAIN and production-scale latency: Not verified.

### Student profile tabs (2026-10-19)

The detail view adds one bounded single-student SELECT (LIMIT 2 to reject ambiguous identities) against its already-resolved district/level active table set. Only allowlisted existing columns are projected; missing columns use NULL. It reuses cached column metadata and bound identity predicates. Roster/API integration paths make no added profile query, and switching tabs causes no network request. No persistent profile cache, index or migration added. SQLite fixture verified the partial-schema query and unchanged rows; live MySQL EXPLAIN and production-scale latency: Not verified.

### Profile dictionary resolution (2026-10-19)

No added SQL queries. One scoped scan resolves the source school/level directory and reference dictionaries are memoized for the repository request lifetime. Only allowlisted ID/NAME character dictionaries are read (maximum 8 MiB/10,000 rows each); missing, corrupt and conflicting labels fail closed. Qualification dictionaries never cross levels or batches. No persistent cache or import replay is introduced. Representative local dictionary reads succeeded; production request latency remains Not verified.

Province labels (2026-10-19): single-student details reuse the existing cached local administrative snapshot to resolve two-digit prior-school province codes. No HTTP or added SQL query; directory queries and pagination are unchanged. Production latency: Not verified.

## SENA Care teacher integration (2026-10-19)

Teacher export selects an explicit public identity field list and uses SQL pagination with at most 100 teachers per page. Authentication queries one active teacher by the token district plus username/email and verifies locally with Laravel Hash; per-identifier/district and per-IP failure limits bound password work. Group export reuses `DistrictLearningGroupCatalog` and its latest-successful-batch scope/cache, without per-teacher group queries. No indexes or imported-data writes added. SQLite in-memory contract and authorization tests pass; live MySQL latency/plans remain Not verified.
