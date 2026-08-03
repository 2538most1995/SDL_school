# บริบทโปรเจกต์ Sena Care School

## 1. ภาพรวม

Sena Care School เป็นเว็บแอปพลิเคชัน PHP และ MySQL สำหรับดูแลข้อมูลนักศึกษาและสารสนเทศทางการศึกษา รองรับหลายอำเภอในฐานข้อมูลเดียว มีระบบนำเข้าข้อมูลจาก ZIP/DBF ของ itw51 และมีพอร์ทัลการเรียนรู้สำหรับครูและนักศึกษา

โปรเจกต์นี้เป็น PHP แบบ procedural เป็นหลัก แต่ละหน้า `.php` ทำหน้าที่เป็น entry point และเรียก helper จาก `auth.php` หรือไฟล์ใน `includes/` ไม่มี framework และไม่มี Composer dependency ในโฟลเดอร์หลัก

## 2. ผู้ใช้งานและสิทธิ์

| Role | ขอบเขตหลัก |
| --- | --- |
| `super_admin` | จัดการทุกอำเภอ เลือกอำเภอ และดูข้อมูลรวมข้ามอำเภอในหน้าที่รองรับ |
| `admin` | จัดการข้อมูลและผู้ใช้ภายในอำเภอของตน |
| `teacher` | ดูข้อมูลนักศึกษาและจัดการงานสอนตามกลุ่มที่ได้รับมอบหมาย |
| `student` | ล็อกอินจากข้อมูลนักศึกษาและดูข้อมูลของตนเอง |

จุดควบคุมสิทธิ์ส่วนกลางอยู่ใน `auth.php` เช่น:

- `requireLogin()`
- `requireAdmin()`
- `requireTeacherOrAdmin()`
- `requireCsrf()` และ `requireJsonCsrf()`
- `canAccessStudentRecord()`
- `currentDistrictId()` และ `districtScopedTables()`

## 3. โครงสร้างระบบ

### ไฟล์ศูนย์กลาง

| ไฟล์ | หน้าที่ |
| --- | --- |
| `config.php` | เชื่อมต่อ PDO MySQL, ตั้ง session cookie และ security headers |
| `auth.php` | Auth, CSRF, rate limit, dynamic SQL helpers, district scope, theme helper และ schema หลายอำเภอ |
| `index.php` | Dashboard และข้อมูลสรุปนักศึกษา |
| `includes/header.php` | Branding, theme, CSS และโครง HTML ส่วนบน |
| `includes/sidebar.php` | เมนูตามสิทธิ์ผู้ใช้งาน |
| `includes/footer.php` | footer, visitor counter และ JavaScript UI ส่วนกลาง |

### โมดูลข้อมูลนักศึกษา

| ไฟล์ | หน้าที่ |
| --- | --- |
| `students.php` | รายชื่อนักศึกษา ค้นหา กรอง และจัดการข้อมูล |
| `grades.php` | ผลการเรียน |
| `kpch.php` | กิจกรรม กพช. |
| `moral.php` | คุณธรรม |
| `graduated_students.php` | รายชื่อนักศึกษาจบหลักสูตร |
| `transferred_students.php` | ข้อมูลเทียบโอน |
| `registered_subjects.php` | วิชาที่ลงทะเบียน |
| `grades_above_2_stats.php` | สถิติเกรด 2 ขึ้นไป |
| `exam_attendance_stats.php` | สถิติการเข้าสอบ |

### โมดูลนำเข้าข้อมูล

| ไฟล์ | หน้าที่ |
| --- | --- |
| `import.php` | อัปโหลด ZIP, แตกไฟล์อย่างปลอดภัย, อ่าน DBF และสร้างตาราง dynamic |
| `includes/DbfReader.php` | อ่านโครงสร้างและ record จาก DBF พร้อมแปลง TIS-620 เป็น UTF-8 |
| `cleanup.php` | ลบตาราง import เก่าตาม batch |
| `includes/sync_exam_rooms.php` | sync ห้องสอบหลัง import |

### พอร์ทัลการเรียนรู้

| ไฟล์ | หน้าที่ |
| --- | --- |
| `includes/learning_portal.php` | Schema และ business logic ส่วนกลางของพอร์ทัล |
| `assignments.php` | มอบหมายงานและตรวจงาน |
| `resources.php` | คลังสื่อการเรียนรู้ |
| `lesson_plans.php` | แผนการสอน |
| `calendar.php` | ปฏิทินพบกลุ่ม |
| `exams.php` | ตารางสอน |
| `scores.php` | คะแนนเก็บ |
| `api/learning_scores.php` | คะแนนของนักศึกษาสำหรับหน้าที่เรียกผ่าน JSON |

### API

| ไฟล์ | หน้าที่ |
| --- | --- |
| `api/students.php` | API รายชื่อนักศึกษาและกลุ่มเรียน รองรับ API key, session, filter และ pagination |
| `api/learning_scores.php` | API คะแนนเก็บภายในระบบ |
| `api/README.md` | วิธีใช้ Student API |

## 4. ฐานข้อมูล

### ตารางหลัก

ตารางตั้งต้นอยู่ใน `krumostc_sena_care.sql` และมี schema บางส่วนที่ระบบเติมให้อัตโนมัติ:

- `districts`
- `users`
- `import_history`
- `import_batches`
- `exam_rooms`
- `user_theme_settings`
- ตารางชื่อขึ้นต้น `learning_` สำหรับพอร์ทัลการเรียนรู้

`auth.php` เรียก `ensureMultiDistrictSchema()` เมื่อเริ่ม request ภายในระบบ ส่วน `includes/learning_portal.php` มี `learningEnsureSchema()` สำหรับโมดูลการเรียนรู้

### ตารางจากการ import

ไฟล์ ZIP ของ itw51 ถูกแตกและอ่าน DBF เพื่อสร้างตารางแบบ dynamic:

```text
db_import_{timestamp}_{hash}_{folder}_{type}
```

ตัวอย่าง:

```text
db_import_1772538756_69a6cb8481c57_1_student
db_import_1772538756_69a6cb8481c57_2_grade
db_import_1772538756_69a6cb8481c57_group
```

`folder` ที่เป็น `1`, `2`, `3` แทนระดับการศึกษา และ `type` เช่น `student`, `grade`, `subject`, `group`

ตาราง import แต่ละ batch ผูกกับอำเภอผ่าน `import_batches.batch_key` จึงต้องใช้ helper district scope ทุกครั้งที่ query ข้อมูล import

### ความหลากหลายของข้อมูลเดิม

ข้อมูล DBF อาจมี:

- ชื่อคอลัมน์ตัวพิมพ์เล็กและใหญ่ต่างกัน
- ค่าเว้นวรรคจาก fixed-width field
- รหัสนักศึกษาที่ต้องเทียบ 10 หลักท้าย
- รูปแบบภาคเรียนหลายแบบ เช่น `68/2`, `2568/2`, `2/2568`
- encoding แบบ TIS-620

ให้ใช้ helper ใน `auth.php` ก่อนเขียน SQL เอง เช่น:

- `studentIdKeySql()`
- `gradeStdKeySql()`
- `expsemSql()`
- `semestrySql()`
- `academicTermVariants()`
- `latestAcademicTerm()`

## 5. เส้นทาง Request สำคัญ

### หน้าเว็บภายใน

```text
หน้า .php
  -> require_once 'auth.php'
  -> config.php เชื่อมต่อฐานข้อมูลและเริ่ม session
  -> ensureMultiDistrictSchema()
  -> refreshSessionUserFromDatabase()
  -> handleDistrictSwitch()
  -> ตรวจสิทธิ์ของหน้า
  -> query ข้อมูลตาม district scope
  -> include header/sidebar/footer
```

### ล็อกอิน

- ครูและผู้ดูแลล็อกอินจากตาราง `users` ด้วย `password_verify()`
- นักศึกษาล็อกอินด้วยเลขบัตรประชาชน 13 หลักและรหัสนักศึกษา
- ระบบมี CSRF, rate limit และ `session_regenerate_id(true)` หลังล็อกอินสำเร็จ

### Import

```text
อัปโหลด ZIP
  -> ตรวจ CSRF และสิทธิ์ admin
  -> ตรวจไฟล์และอำเภอเป้าหมาย
  -> แตก ZIP ด้วย safeExtractZip()
  -> อ่าน DBF ด้วย DbfReader
  -> สร้างตาราง db_import_*
  -> บันทึก import_history และ import_batches
  -> sync ห้องสอบ
```

## 6. Frontend

- ใช้ Tailwind CSS v4 จาก `css/input.css` และ build เป็น `css/output.css`
- ใช้ฟอนต์ Prompt
- ใช้ Font Awesome และ SweetAlert2 จาก CDN
- รองรับ theme รายผู้ใช้ผ่าน CSS variables
- มี responsive table, modal และ sidebar logic ส่วนกลางใน `includes/header.php`, `includes/footer.php` และ `css/input.css`

คำสั่ง build CSS:

```bash
./tailwindcss -i ./css/input.css -o ./css/output.css
```

## 7. Environment

ค่าฐานข้อมูลอ่านจาก environment variable และมีค่าเริ่มต้นสำหรับ MAMP:

```text
DB_HOST=127.0.0.1
DB_PORT=8889
DB_NAME=sena_school_db
DB_USER=root
DB_PASS=root
```

ค่าที่เกี่ยวข้องเพิ่มเติม:

```text
SCHEMA_CHECK_TTL
IMPORT_MEMORY_LIMIT
STUDENT_API_KEY
STUDENT_API_KEYS
STUDENT_API_CORS_ORIGINS
```

PHP extension สำคัญ:

- `PDO`
- `pdo_mysql`
- `zip`
- `iconv`
- `mbstring`

## 8. ข้อมูลและไฟล์ที่ต้องระวัง

- `uploads/zips/` มีไฟล์ต้นฉบับที่ผู้ใช้อัปโหลด
- `uploads/extracted/` มีข้อมูล DBF ที่แตกจาก ZIP
- `uploads/learning/` มีไฟล์แนบจากพอร์ทัลการเรียนรู้
- `uploads/profiles/` มีรูปโปรไฟล์
- `logs/` มี log, cache flag, rate limit และ visitor stats
- `krumostc_sena_care.sql` มีคำสั่ง `DROP TABLE IF EXISTS` สำหรับติดตั้งใหม่ ห้ามรันกับฐานข้อมูลจริงโดยไม่ยืนยัน
- `debug_*.php`, `test_*.php`, Python script และไฟล์ log เป็นเครื่องมือช่วยพัฒนา ต้องประเมินก่อนนำขึ้น production

## 9. หลักการสำคัญของโปรเจกต์

1. ความถูกต้องของ district scope สำคัญกว่าความสะดวกในการ query
2. ข้อมูลนักศึกษาและเลขบัตรประชาชนเป็นข้อมูลส่วนบุคคล ต้องเปิดเผยเท่าที่จำเป็น
3. ตาราง import เปลี่ยนแปลงตาม batch ห้าม hardcode ชื่อตาราง
4. การลบ import, cleanup และ schema migration มีผลต่อข้อมูลจริง ต้องแยกจากการทดสอบทั่วไป
5. UI ใหม่ต้องใช้งานได้ทั้ง desktop และ mobile

