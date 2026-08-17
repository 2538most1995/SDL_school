# Performance Audit

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

## Query and Eloquent review

- Dynamic legacy identifiers ถูก whitelist ก่อน interpolate; values ใช้ bindings
- Student aggregates ใช้ SQL `SUM`, `GROUP BY`, `EXISTS` และ cache ต่อ immutable batch
- Student directory ยัง sort/filter/paginate ใน memory หลัง repository สร้าง active roster; ปัจจุบันเป็น contract-compatible แต่จะกิน memory ตามจำนวน active students
- API collections ส่วนใหญ่จำกัด `250` แถว; directory จำกัด `per_page` สูงสุด `1000` และยังไม่ใช่ DB pagination
- ไม่พบ `Model::all()` ใน application path ที่ตรวจ และไม่พบ controller query ที่ชัดเจนอยู่ใน `foreach`; พบ full-table reads ของ DBF/field/group ที่เป็น legacy file/domain behavior

## Index recommendations

ยัง **ไม่ได้เพิ่ม index** โดยเดาจากชื่อ column. รายการที่ควรตรวจด้วย `EXPLAIN` บนฐานจริง:

1. `users`: พิจารณา (`district_id`, `role`, `first_name`) หาก user directory ใช้ filter/sort นี้บ่อยและ cardinality เหมาะสม
2. `learning_resources`: พิจารณา (`district_id`, `created_at`) สำหรับ district filter + newest ordering
3. คะแนนเก็บใช้ index ตาม course scope (`district_id`, `academic_term`, `subject_code`, `education_level`) และ unique key ราย scorebook/component/student; roster โหลดจากชุด import ล่าสุดแบบ join เดียว ไม่ query แยกรายคน
3. `exam_rooms`: migration ล่าสุดมี (`district_id`, `term`, `subject_code`) แล้ว; ตรวจว่า wildcard-term query ใช้ prefix นี้ได้ตามข้อมูลจริง
4. import batch/history joins: ตรวจ FK/index ของ `import_history_id`, (`district_id`, `batch_key`) และ ordering latest batch

ผลของ index ต่อ INSERT/UPDATE/DELETE และ execution plan จริง: `Not verified`.

## Cache strategy

- `LegacyStudentRepository` มี request-local caches สำหรับ table sets/student list/columns
- Aggregate cache ใช้ key ที่รวม district, batch, level และ student-code hash; default TTL `900` seconds
- Batch เป็น immutable หลัง activation จึง cache ได้ แต่ต้อง clear/เปลี่ยน key เมื่อเปิด batch ใหม่หรือเปลี่ยน schema
- ไม่เพิ่ม cache ใหม่ในรอบนี้ เพราะ user directory และ room lookup เป็น request-local query reuse ที่ชัดเจนกว่า

## Benchmark

| Metric | Before | After | Evidence |
| --- | --- | --- | --- |
| User directory query count | `1 + N` | `1` | static review + `LegacyPortalReadPerformanceTest` (1 user) |
| Overview duplicate assignment query | duplicated in same request | one load/reuse | source review; Not benchmarked |
| Exam-room queries | per unique subject (+ fallback) | one per district + term | source review; Not benchmarked |
| Admin exam-room scope | ทุกภาคเรียนสูงสุด 500 รายการและไม่มีขอบเขตตำบล/ระดับ | เฉพาะภาคเรียนปัจจุบันสูงสุด 2,000 รายการ; จัดกลุ่ม candidate ของกลุ่มเรียนก่อนเทียบช่วง | feature test; live workload Not benchmarked |
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
