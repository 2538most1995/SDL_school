# ทีมผู้เชี่ยวชาญ AI สำหรับ Sena Care School

## วัตถุประสงค์

เอกสารนี้กำหนดบทบาทในการวิเคราะห์งานของ AI เพื่อให้การแก้ไขระบบ Sena Care School ครบทั้ง business logic, ความปลอดภัย, ฐานข้อมูล และประสบการณ์ใช้งาน

> สถานะปัจจุบัน: แอปหลักอยู่ใน `laravel-app/` เป็น Laravel 13 + React 19 ไม่ใช่ procedural PHP แบบเดิมทั้งหมด ให้ยึด `laravel-app/app/`, `routes/`, `database/migrations/` และ test เป็น source of truth ปัจจุบัน

เอกสารคู่มือที่ต้องอ่านร่วมกันคือ `CONTEXT.md`, `DATABASE.md`, `PERFORMANCE.md`, `SKILL.md` และ `laravel-app/README.md` หากข้อมูลในเอกสารเก่าขัดกับ migration/source ให้รายงานและยึด code + migration ปัจจุบัน

บทบาทเหล่านี้เป็นกรอบคิดของทีม AI ไม่จำเป็นต้องแยกเป็น process จริงทุกครั้ง งานเล็กใช้บทบาทหลักหนึ่งคนและให้ Reviewer ตรวจ ส่วนงานเสี่ยงหรือข้ามโมดูลให้ Agent Lead ประสานหลายบทบาท

## โครงสร้างทีม

### 1. Agent Lead: ผู้วิเคราะห์ภาพรวมและประสานงาน

**รับผิดชอบ**

- อ่านคำขอและกำหนดขอบเขต
- เลือกผู้เชี่ยวชาญที่ต้องร่วมวิเคราะห์
- ตรวจ dependency ข้ามไฟล์และลำดับการแก้ไข
- สรุปผลกระทบ การทดสอบ และข้อจำกัด

**ต้องถามก่อนส่งงานต่อ**

- กระทบ role ใดบ้าง
- กระทบอำเภอเดียวหรือหลายอำเภอ
- แตะข้อมูลจริง ตาราง import หรือ schema หรือไม่
- ต้องทดสอบ UI, API หรือ database flow ใด

### 2. Security & Access Agent: ผู้เชี่ยวชาญสิทธิ์และความปลอดภัย

**รับผิดชอบ**

- Login, session, role, CSRF, rate limit และ security headers
- สิทธิ์ `super_admin`, `admin`, `teacher`, `student`
- การเข้าถึงข้อมูลรายคนและข้อมูลส่วนบุคคล
- ตรวจ SQL injection, XSS, upload validation และ error disclosure

**ไฟล์หลัก**

- `config.php`
- `auth.php`
- `login.php`
- `users.php`
- `get_student*.php`
- `delete_student.php`
- `api/*.php`

**Checklist**

- POST ที่แก้ข้อมูลตรวจ CSRF หรือยัง
- Query จำกัด district และ student scope หรือยัง
- dynamic identifier ผ่าน validation และ `dbIdent()` หรือยัง
- output HTML ใช้ `htmlspecialchars()` หรือยัง
- API mask เลขบัตรประชาชนและคืน status code เหมาะสมหรือยัง

### 3. Data Import Agent: ผู้เชี่ยวชาญ ZIP, DBF และตาราง Dynamic

**รับผิดชอบ**

- การนำเข้าข้อมูล itw51
- ความปลอดภัยของ ZIP และไฟล์อัปโหลด
- DBF encoding และโครงสร้างคอลัมน์
- การสร้าง ลบ และเชื่อมตาราง `db_import_*`
- ประวัติ import, batch และการ sync ห้องสอบ

**ไฟล์หลัก**

- `import.php`
- `cleanup.php`
- `includes/DbfReader.php`
- `includes/sync_exam_rooms.php`
- `krumostc_sena_care.sql`

**Checklist**

- ป้องกัน Zip Slip และไฟล์ขนาดผิดปกติหรือยัง
- batch ผูกกับ `district_id` ถูกต้องหรือยัง
- รองรับชื่อคอลัมน์และ encoding เดิมหรือยัง
- การลบจำกัดเฉพาะ batch และอำเภอเป้าหมายหรือยัง
- ทดสอบด้วยข้อมูลตัวอย่างแทนข้อมูลจริงหรือยัง

### 4. Database & Performance Agent: ผู้เชี่ยวชาญ Schema และ Query

**รับผิดชอบ**

- Schema หลัก schema อัตโนมัติ index และ migration
- Query กับตาราง import ขนาดใหญ่
- performance column เช่น `_perf_id10`, `_perf_std10`, `_perf_expsem`
- รูปแบบภาคเรียนและการเลือก batch ล่าสุด

**ไฟล์หลัก**

- `auth.php`
- `includes/learning_portal.php`
- `includes/multi_district_tables.php`
- `krumostc_sena_care.sql`
- หน้าที่มี query รายงานขนาดใหญ่

**Checklist**

- ใช้ prepared statement สำหรับ value หรือยัง
- ใช้ helper สำหรับ dynamic table และ academic term หรือยัง
- migration รันซ้ำได้โดยไม่เสียข้อมูลหรือยัง
- cache flag ทำให้ schema ใหม่ไม่ถูกใช้ทันทีหรือไม่
- query ทำงานได้เมื่อมีหลาย batch และหลายอำเภอหรือยัง

### 5. Student Domain Agent: ผู้เชี่ยวชาญข้อมูลนักศึกษาและรายงาน

**รับผิดชอบ**

- ข้อมูลนักศึกษา ผลการเรียน กพช. คุณธรรม
- สถานะจบการศึกษา เทียบโอน วิชาลงทะเบียน และการเข้าสอบ
- กติกาการเลือกภาคเรียนล่าสุดและข้อมูล active student
- การจำกัดกลุ่มสำหรับครู

**ไฟล์หลัก**

- `students.php`
- `grades.php`
- `kpch.php`
- `moral.php`
- `graduated_students.php`
- `transferred_students.php`
- `registered_subjects.php`
- `grades_above_2_stats.php`
- `exam_attendance_stats.php`

**Checklist**

- ผลลัพธ์ถูกต้องทั้งระดับ `1`, `2`, `3` หรือยัง
- รองรับรูปแบบภาคเรียนหลายแบบหรือยัง
- นักศึกษา ครู admin และ super admin เห็นข้อมูลตามสิทธิ์หรือยัง
- filter, pagination และรายงานยังตรงกับข้อมูลต้นทางหรือยัง

### 6. Learning Portal Agent: ผู้เชี่ยวชาญพอร์ทัลการเรียนรู้

**รับผิดชอบ**

- งานที่มอบหมาย การส่งงาน การตรวจงาน และคะแนน
- คลังสื่อ แผนการสอน ปฏิทินพบกลุ่ม และตารางสอน
- Schema `learning_*`
- upload ไฟล์และการมองเห็นข้อมูลตามกลุ่ม

**ไฟล์หลัก**

- `includes/learning_portal.php`
- `assignments.php`
- `resources.php`
- `lesson_plans.php`
- `calendar.php`
- `exams.php`
- `scores.php`
- `api/learning_scores.php`

**Checklist**

- ครูจัดการข้อมูลได้เฉพาะขอบเขตที่รับผิดชอบหรือยัง
- นักศึกษาเห็นงานและคะแนนของตนเองหรือยัง
- upload จำกัดชนิดและขนาดไฟล์หรือยัง
- schema และ index รองรับการรันซ้ำหรือยัง

### 7. API Integration Agent: ผู้เชี่ยวชาญ API และระบบเชื่อมต่อ

**รับผิดชอบ**

- Student API และ JSON endpoint
- API key, Bearer token, CORS, rate limit และ pagination
- รูปแบบ response และ backward compatibility
- การปกป้องข้อมูลส่วนบุคคล

**ไฟล์หลัก**

- `api/students.php`
- `api/learning_scores.php`
- `api/README.md`
- helper API ใน `auth.php`

**Checklist**

- ไม่มี key หรือ session ต้องถูกปฏิเสธหรือยัง
- teacher, admin, super admin และ API key ได้ข้อมูลตามขอบเขตหรือยัง
- `include_sensitive` จำกัดสิทธิ์หรือยัง
- เอกสาร API ตรงกับพฤติกรรมจริงหรือยัง

### 8. Frontend UX Agent: ผู้เชี่ยวชาญ UI, Responsive และ Theme

**รับผิดชอบ**

- Layout, sidebar, modal, table, form และ mobile UX
- Tailwind CSS, theme variable, dark mode และ branding
- ความสม่ำเสมอของหน้าจอและภาษาไทย

**ไฟล์หลัก**

- `includes/header.php`
- `includes/sidebar.php`
- `includes/footer.php`
- `includes/modern_list_ui.php`
- `css/input.css`
- `css/output.css`
- หน้า `.php` ที่แสดงผล

**Checklist**

- ใช้โครง header/sidebar/footer เดิมหรือยัง
- build `css/output.css` หลังแก้ `css/input.css` หรือยัง
- ตรวจ desktop และ mobile หรือยัง
- ตารางและ modal อ่านง่ายโดยไม่ล้นหน้าจอหรือยัง
- dark mode และ theme รายผู้ใช้ยังใช้งานได้หรือยัง

### 9. QA & Reviewer Agent: ผู้ตรวจสอบก่อนส่งมอบ

**รับผิดชอบ**

- อ่าน diff และตรวจ regression
- เลือกชุดทดสอบตามความเสี่ยง
- ตรวจ syntax และเส้นทางใช้งานสำคัญ
- สรุปสิ่งที่ตรวจแล้วและสิ่งที่ยังไม่ได้ตรวจ

**คำสั่งพื้นฐาน**

```bash
php -l path/to/changed-file.php
find . -name '*.php' -not -path './uploads/*' -print0 | xargs -0 -n1 php -l
./tailwindcss -i ./css/input.css -o ./css/output.css
```

**Checklist**

- ไม่มี syntax error หรือ warning สำคัญ
- ไม่เผลอแก้ secret, upload, log หรือข้อมูลผู้ใช้จริง
- ไม่มีการทดสอบ destructive กับฐานข้อมูลจริง
- flow ที่แก้ถูกตรวจอย่างน้อยหนึ่งกรณีสำเร็จและหนึ่งกรณีถูกปฏิเสธ
- รายงานข้อจำกัดชัดเจนถ้ายังทดสอบครบไม่ได้

## ตารางเลือก Agent ตามงาน

| งาน | Agent หลัก | Agent ที่ต้อง Review |
| --- | --- | --- |
| แก้ล็อกอินหรือสิทธิ์ | Security & Access | QA & Reviewer |
| เพิ่มหน้า admin | Agent Lead + Security & Access | Frontend UX + QA & Reviewer |
| แก้ import DBF หรือ cleanup | Data Import | Database & Performance + Security & Access + QA & Reviewer |
| แก้รายงานนักศึกษา | Student Domain | Database & Performance + Security & Access |
| เพิ่มโมดูลการเรียนรู้ | Learning Portal | Security & Access + Frontend UX |
| แก้ Student API | API Integration | Security & Access + QA & Reviewer |
| แก้ responsive หรือ theme | Frontend UX | QA & Reviewer |
| เพิ่มหรือเปลี่ยน schema | Database & Performance | เจ้าของโมดูล + QA & Reviewer |

## ขั้นตอนส่งต่องานระหว่าง Agent

เมื่อส่งต่อการวิเคราะห์ ให้ระบุสั้น ๆ:

```text
เป้าหมาย:
ไฟล์ที่เกี่ยวข้อง:
role และ district scope ที่ได้รับผลกระทบ:
ข้อมูลหรือ schema ที่ได้รับผลกระทบ:
ความเสี่ยงที่พบ:
สิ่งที่ต้องตรวจต่อ:
```

## Definition of Done

งานถือว่าเสร็จเมื่อ:

1. Agent Lead ระบุผลกระทบครบ
2. เจ้าของโมดูลตรวจ business logic
3. Security & Access Agent ตรวจเมื่อแตะข้อมูล สิทธิ์ API หรือ upload
4. QA & Reviewer Agent ตรวจ syntax และ flow ที่เกี่ยวข้อง
5. ไม่มีคำสั่ง destructive ถูกใช้กับข้อมูลจริงโดยไม่ได้รับอนุญาต
6. สรุปผลการแก้ไข การทดสอบ และข้อจำกัดให้ผู้ใช้ทราบ
