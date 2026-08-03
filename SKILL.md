---
name: maintain-sena-care-school
description: ใช้เมื่อวิเคราะห์ แก้ไข ทดสอบ ตรวจสอบความปลอดภัย หรือขยายระบบ Sena Care School ซึ่งเป็นเว็บ PHP และ MySQL สำหรับดูแลข้อมูลนักศึกษาแบบหลายอำเภอ มีการนำเข้า ZIP/DBF จาก itw51 ตารางฐานข้อมูลแบบ dynamic พอร์ทัลการเรียนรู้ และ API นักศึกษา
---

# คู่มือการทำงานของ AI สำหรับ Sena Care School

## เป้าหมาย

ทำงานกับระบบนี้อย่างรอบคอบ โดยรักษาความถูกต้องของข้อมูลนักศึกษา ขอบเขตข้อมูลรายอำเภอ สิทธิ์ผู้ใช้งาน และความเข้ากันได้กับข้อมูลเดิมจาก itw51

ก่อนเริ่มงานทุกครั้ง:

1. อ่าน [CONTEXT.md](CONTEXT.md) เพื่อเข้าใจระบบและจุดเสี่ยง
2. อ่าน [AGENTS.md](AGENTS.md) เพื่อเลือกผู้เชี่ยวชาญ AI ที่ต้องร่วมวิเคราะห์
3. สำรวจไฟล์ที่เกี่ยวข้องก่อนแก้ไข ห้ามสรุปจากชื่อไฟล์เพียงอย่างเดียว
4. แยกให้ออกว่างานกระทบข้อมูลจริง, schema, สิทธิ์ หรือเฉพาะ UI

## ลำดับการทำงานมาตรฐาน

### 1. ทำความเข้าใจคำขอ

ระบุให้ชัดว่างานอยู่ในกลุ่มใด:

- ระบบล็อกอิน สิทธิ์ session CSRF หรือ rate limit
- ข้อมูลหลายอำเภอและการจำกัดขอบเขตข้อมูล
- การ import ZIP/DBF จาก itw51 และตาราง dynamic
- ข้อมูลนักศึกษา ผลการเรียน กพช. คุณธรรม หรือรายงาน
- ห้องสอบ การลงทะเบียน และสถานะการศึกษา
- พอร์ทัลการเรียนรู้ งาน สื่อ แผนการสอน ปฏิทิน ตารางสอน หรือคะแนนเก็บ
- API และการเปิดเผยข้อมูลส่วนบุคคล
- UI responsive theme sidebar header footer หรือ Tailwind CSS

ถ้างานคร่อมหลายกลุ่ม ให้ใช้ Agent Lead ประสานเจ้าของแต่ละส่วนตาม [AGENTS.md](AGENTS.md)

### 2. สำรวจผลกระทบก่อนแก้

อ่านไฟล์ต้นทางและ helper ที่เกี่ยวข้องเสมอ โดยเริ่มจาก:

- `config.php`
- `auth.php`
- entry page ที่รับ request
- include หรือ API ที่ entry page เรียกใช้
- ตารางและชื่อคอลัมน์ที่ query จริง

ตรวจสอบเพิ่มเมื่อเกี่ยวข้อง:

- ใช้ `rg` ค้นหาการเรียก function และชื่อ table เพื่อดูผลกระทบข้ามไฟล์
- อ่าน `krumostc_sena_care.sql` เมื่อแตะ schema หลัก
- อ่าน `includes/DbfReader.php` และ `import.php` เมื่อแตะข้อมูลนำเข้า
- อ่าน `includes/learning_portal.php` เมื่อแตะโมดูลการเรียนรู้
- อ่าน `api/README.md` และ `api/students.php` เมื่อแตะ API นักศึกษา

### 3. ออกแบบการแก้ไข

เลือกการเปลี่ยนแปลงที่เล็กและตรงจุด:

- ใช้รูปแบบ procedural PHP เดิมของโปรเจกต์
- ใช้ helper ที่มีอยู่ก่อนสร้าง helper ใหม่
- หลีกเลี่ยงการ refactor ไฟล์ใหญ่ถ้าไม่จำเป็นต่อคำขอ
- รักษาพฤติกรรมเดิมของข้อมูล import ที่มีชื่อคอลัมน์และรูปแบบภาคเรียนหลากหลาย
- ระบุ migration หรือผลกระทบต่อข้อมูลทุกครั้งที่แก้ schema

### 4. แก้ไขด้วยกติกาความปลอดภัย

#### Authentication และ Authorization

- หน้าใช้งานภายในต้องเรียก `requireLogin()`
- หน้า admin ต้องเรียก `requireAdmin()` หรือ guard ที่เข้มกว่า
- งานที่ครูแก้ไขได้ให้ใช้ `requireTeacherOrAdmin()`
- การดูหรือแก้ข้อมูลนักศึกษารายคนต้องผ่าน `requireStudentRecordAccess()` หรือ `requireJsonStudentRecordAccess()`
- ทุก POST ที่แก้ข้อมูลต้องตรวจ CSRF ด้วย `requireCsrf()`, `requireJsonCsrf()` หรือ `verifyCsrfToken()`
- ห้ามเชื่อค่าจาก `$_GET`, `$_POST`, `$_FILES`, session หรือ header โดยไม่ validate

#### ขอบเขตข้อมูลรายอำเภอ

- ใช้ `currentDistrictId()`, `districtScopedTables()`, `tableBelongsToCurrentDistrict()` และ `canManageDistrictId()` ตามบริบท
- `super_admin` เลือกอำเภอและดูข้ามอำเภอได้ ส่วน `admin` และ `teacher` ต้องถูกจำกัดข้อมูลตามอำเภอ
- เมื่อเพิ่ม query ใหม่ ให้ตรวจทั้งกรณีอำเภอเดียวและกรณี `super_admin` ดูทุกอำเภอ
- ห้าม query ตาราง import ทั้งหมดแล้วกรองใน UI ภายหลัง

#### SQL และตาราง dynamic

- ใช้ prepared statement สำหรับ value ทุกครั้ง
- ชื่อตารางและชื่อคอลัมน์ bind parameter ไม่ได้ ต้อง validate แล้วครอบด้วย `dbIdent()`
- ใช้ `isValidDbIdentifier()`, `isValidStudentTableName()`, `tableExists()` และ `tableHasColumn()` ก่อนประกอบ SQL แบบ dynamic
- ใช้ helper เช่น `studentIdKeySql()`, `gradeStdKeySql()`, `expsemSql()`, `semestrySql()` และ `groupCodeSql()` เพื่อรองรับข้อมูลเดิมและ performance column
- ห้ามสมมติว่าตาราง DBF ทุกชุดมีคอลัมน์เหมือนกัน

#### Import ZIP/DBF

- ใช้แนวทางป้องกัน Zip Slip และจำกัดจำนวนไฟล์กับขนาดแตกไฟล์ตาม `safeExtractZip()`
- รักษาการแปลงข้อความ TIS-620 เป็น UTF-8 ใน `includes/DbfReader.php`
- การลบ import ต้องจำกัดเฉพาะ batch และอำเภอที่มีสิทธิ์
- ห้ามทดสอบ import, cleanup หรือ DROP TABLE กับข้อมูลจริงโดยไม่ได้รับอนุญาตชัดเจน
- เมื่อแก้ logic import ให้ตรวจผลต่อ `import_history`, `import_batches`, ตาราง `db_import_*` และ `syncExamRooms()`

#### API และข้อมูลส่วนบุคคล

- API ต้องคืน JSON พร้อม status code ที่เหมาะสม
- ใช้ rate limit สำหรับ endpoint ที่เปิดรับ request จำนวนมาก
- อย่าเปิดเผยเลขบัตรประชาชนเต็มโดยปริยาย
- แยกสิทธิ์ API key, session admin, teacher และ student ให้ชัดเจน
- ถ้าเพิ่ม CORS ให้จำกัด origin ตาม environment และไม่ฝัง secret ใน source code

#### Schema

- ตารางหลักอ้างอิงจาก `krumostc_sena_care.sql`
- schema หลายอำเภอถูกดูแลโดย `ensureMultiDistrictSchema()` ใน `auth.php`
- schema พอร์ทัลการเรียนรู้ถูกดูแลโดย `learningEnsureSchema()` ใน `includes/learning_portal.php`
- ถ้าเพิ่ม schema ใหม่ ให้ทำแบบ idempotent และพิจารณาผลของ cache flag
- ห้ามแก้ข้อมูลจริงด้วยคำสั่ง destructive ระหว่างการสำรวจ

#### UI และ CSS

- ใช้โครงหน้าเดิมผ่าน `includes/header.php`, `includes/sidebar.php` และ `includes/footer.php`
- แก้ source CSS ที่ `css/input.css` แล้ว build `css/output.css`
- ใช้ class และ theme variable เดิมก่อนเพิ่ม style เฉพาะหน้า
- ตรวจ desktop และ mobile เมื่อแก้ตาราง modal sidebar หรือ responsive layout
- รักษาภาษาไทยและฟอนต์ Prompt ของระบบ

## การตรวจสอบหลังแก้ไข

เลือกตรวจตามขอบเขตงาน และรายงานสิ่งที่ยังไม่ได้ตรวจ:

```bash
php -l path/to/changed-file.php
find . -name '*.php' -not -path './uploads/*' -print0 | xargs -0 -n1 php -l
./tailwindcss -i ./css/input.css -o ./css/output.css
```

ตรวจเพิ่มตามชนิดงาน:

- Auth: ล็อกอินผิด ล็อกอินถูก session role และ CSRF
- District: `super_admin`, `admin`, `teacher` และข้อมูลต่างอำเภอ
- Student record: นักศึกษาเห็นเฉพาะข้อมูลตัวเอง ครูเห็นเฉพาะกลุ่มที่รับผิดชอบ
- API: request ไม่มี key, key ถูกต้อง, pagination, filter และ sensitive field
- Import: ใช้ไฟล์ตัวอย่างหรือฐานข้อมูลทดสอบเท่านั้น ตรวจ log ที่ `logs/import_debug.log`
- UI: เปิดหน้าเป้าหมายจริงและตรวจ desktop กับ mobile

## รูปแบบการสรุปงาน

เมื่อทำงานเสร็จ ให้สรุป:

1. แก้ไฟล์ใดและเปลี่ยนพฤติกรรมอะไร
2. ตรวจสอบด้วยวิธีใดและผลเป็นอย่างไร
3. มีความเสี่ยง ข้อจำกัด หรือสิ่งที่ยังไม่ได้ทดสอบอะไร
4. ถ้ามี schema หรือข้อมูลจริงได้รับผลกระทบ ให้ระบุอย่างชัดเจน

