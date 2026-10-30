# ห้องเรียนออนไลน์

เมนู **ห้องเรียนออนไลน์** ที่ `/learning/classroom` แสดงรายวิชาภาคเรียนปัจจุบันจากทะเบียน ITW และรายการที่เลือกลงทะเบียนผ่านเว็บ โดยไม่นับรายการที่ไม่ได้เลือกหรือเทียบโอนอย่างเดียว ใช้ระดับการศึกษาร่วมกับรหัสวิชาเสมอ และตรวจกลุ่มจากข้อมูลนักศึกษาปัจจุบัน ไม่ใช้ข้อมูลส่วนตัวใน snapshot ทะเบียนเป็นหลักฐานสิทธิ์

## การใช้งาน

- เลือก **ประถม / ม.ต้น / ม.ปลาย** ด้วยปุ่ม แล้วเลือกการ์ดรายวิชาที่ลงทะเบียน ระดับที่ไม่มีรายวิชาในขอบเขตจะแสดงจำนวนศูนย์และเลือกไม่ได้ ค้นหารายวิชาได้เมื่อมีมากกว่า 6 วิชา
- กิจกรรมแยกเป็นการ์ด **คลิปวิดีโอ / ใบความรู้ / ใบงานออนไลน์ / แบบทดสอบ** พร้อมจำนวนกิจกรรมจริงในแต่ละหมวด กดการ์ดกิจกรรมเพื่อเปิดบทเรียนด้านล่าง ครู/ผู้ดูแลกด **สร้างกิจกรรม** เพื่อเพิ่มเนื้อหา กลุ่มเป้าหมาย และสถานะ โดยตัวเลือกทั้งหมดเป็นปุ่ม ไม่มี dropdown
- คลิปวิดีโอเป็นประเภทแยก ต้องมีลิงก์อย่างน้อยหนึ่งรายการและไม่มีคำถาม YouTube ใช้ player จาก `www.youtube-nocookie.com` ส่วน Drive/OneDrive เปิดผ่านลิงก์แชร์ ใบความรู้รองรับข้อความและลิงก์เอกสาร HTTP/HTTPS สูงสุด 20 รายการ ลิงก์วิดีโอเดิมในใบความรู้ยังอยู่แต่แสดงเป็นลิงก์ ครูเปลี่ยนประเภทเป็นคลิปได้โดยแก้กิจกรรม เจ้าของไฟล์ต้องตั้งสิทธิ์แชร์ให้ผู้เรียนเปิดดูได้ ระบบไม่ได้เชื่อม OAuth หรือนำเข้าไฟล์จากบัญชีคลาวด์
- ใบงานและแบบทดสอบมีคำถามได้สูงสุด 100 ข้อ รองรับเลือกตอบหนึ่งข้อ เลือกหลายคำตอบ ถูก/ผิด เติมคำ และอัตนัย แต่ละข้อกำหนดคะแนนและแนบภาพ JPG/PNG/WebP ไม่เกิน 5 MB และ 8,000 × 8,000 พิกเซลได้
- เลือกหลายคำตอบต้องตรงทั้งชุดจึงได้คะแนน เติมคำเทียบข้อความโดยไม่สนตัวพิมพ์ใหญ่/เล็กและช่องว่างหัวท้าย ข้ออื่นตรวจตามเฉลย อัตนัยรอครูให้คะแนนรวมพร้อมความเห็น คะแนนเหล่านี้แยกจากคะแนน ITW และไม่มีการเขียนเกรดต้นทางอัตโนมัติ
- ผู้เรียนตอบทุกข้อ ตรวจทาน และยืนยันส่งได้หนึ่งครั้งต่อกิจกรรม คำตอบที่ยังไม่กดส่งอยู่ในหน้าจอเท่านั้น ระบบเตือนเมื่อเปลี่ยนกิจกรรม/วิชาหรือปิดหน้า และคงคำตอบเมื่อการส่งล้มเหลว กำหนดส่งตรวจซ้ำที่เซิร์ฟเวอร์
- หลังมีผู้ส่งแล้วจะเปลี่ยนเนื้อหา คำถาม เป้าหมาย หรือลบกิจกรรมไม่ได้ ยังเปลี่ยนสถานะเพื่อซ่อน/เผยแพร่และตรวจคำตอบได้ หากต้องการแก้คำถามให้สร้างกิจกรรมใหม่

## รวมงานและสื่อไว้ในห้องเรียนเดียว (29 ตุลาคม 2569)

เมนู **งานและการส่งงาน** และ **คลังสื่อ** ถูกนำออกจาก sidebar ตัวตั้งค่าเมนู และปุ่มหน้าแรกนักศึกษา ทุกบทบาทใช้ **ห้องเรียนออนไลน์** เป็นจุดเริ่มต้นเดียว เลือกระดับและรายวิชาครั้งเดียว แล้วเลือกกิจกรรมด้วยการ์ดและปุ่ม ไม่มี dropdown ในส่วนเรียน ส่งงาน และจัดการสื่อ

| จุดใช้งาน | สิ่งที่รวมไว้ | วิธีเพิ่มเนื้อหา |
| --- | --- | --- |
| คลิปวิดีโอ | คลิปในกิจกรรมห้องเรียนและสื่อวิดีโอ/YouTube เดิม | สร้างกิจกรรม → คลิปวิดีโอ |
| ใบความรู้ | เนื้อหา/ลิงก์บทเรียน และไฟล์เอกสารหรือลิงก์จากคลังเดิม | สร้างใบความรู้สำหรับเนื้อหา/ลิงก์ หรือแนบไฟล์เอกสารสำหรับไฟล์จริง |
| ใบงาน → ตอบออนไลน์ | คำถามในหน้าเรียน 5 ประเภท ส่งได้หนึ่งครั้ง | สร้างกิจกรรม → ใบงาน หรือนำเข้าข้อสอบ |
| ใบงาน → ส่งไฟล์ / ลิงก์ | งานเดิม ประวัติส่ง PDF/รูปภาพ/ลิงก์ การตรวจ คะแนน และความเห็นครู | มอบหมายงานส่งไฟล์ โดยรายวิชาถูกเลือกไว้แล้ว |
| แบบทดสอบ | ข้อสอบและเฉลยตามสิทธิ์ นำเข้า JSON/Excel | สร้างแบบทดสอบหรือนำเข้าข้อสอบ |
| สื่อเพิ่มเติม | สื่อเดิมทั้งหมดที่ผู้ใช้มีสิทธิ์ รวมสื่อส่วนกลางและสื่อจากวิชาที่ไม่อยู่ในทะเบียนปัจจุบัน | ใช้เปิด/ค้นหา/แก้ไขสื่อเดิม; เพิ่มไฟล์ใหม่จากรายวิชาที่เลือก |

การรวมเป็นการแสดงผลจากแหล่งข้อมูลเดิม ไม่คัดลอกงานหรือไฟล์เป็นกิจกรรมใหม่ จึงไม่สร้างรายการซ้ำและไม่เปลี่ยน ID ประวัติส่ง คะแนน หรือความเห็น นักศึกษาเปิดไฟล์ส่วนตัวผ่าน endpoint ที่ตรวจสิทธิ์ ครูเห็นและจัดการในขอบเขตเดิม ส่วนสื่อรายวิชารวมไฟล์ตรงวิชาและสื่อส่วนกลางที่ตรงระดับ/กลุ่ม การลงทะเบียนทั้งจาก ITW และที่เลือกลงทะเบียนผ่านเว็บใช้ catalog ร่วมกัน ไม่รวมวิชาเทียบโอนหรือรายการที่ยังไม่ได้เลือกลงทะเบียน

งานส่งไฟล์ยังส่งใหม่ได้ขณะสถานะเปิดรับและถึงเวลาเปิดแล้ว งานที่เลยกำหนดยังส่งเป็นงานล่าช้าได้ตามกติกาเดิม ครูปิดรับเพื่อหยุดส่ง ส่วนคำตอบออนไลน์ยังส่งได้หนึ่งครั้งและตรวจ deadline ตามกติกาของกิจกรรมนั้น แยกรูปแบบให้เห็นก่อนเริ่มทำงาน การตรวจ/คะแนนทั้งสองแบบไม่เขียนทับคะแนน ITW

ลิงก์ `/learning/assignments` และ `assignments.php` เปิดใบงานแบบส่งไฟล์ในห้องเรียน ลิงก์ที่มี `assignment_id` รักษางานที่เลือกและค้นหารายวิชาตามสิทธิ์เพียงครั้งเดียว จึงไม่เด้งกลับวิชาเก่าระหว่างแก้แบบร่าง รวมถึงงานเก่าที่อยู่เกิน 250 รายการล่าสุด ลิงก์ `/learning/resources` และ `resources.php` เปิดสื่อเพิ่มเติม ค่าตั้งเมนูเก่าที่บันทึกในอำเภอจะไม่ทำให้สองเมนูที่เลิกใช้กลับมาแสดง

ไม่เพิ่ม migration และไม่รันการย้ายข้อมูลจริง ใช้ build ล่าสุดใน Git แล้วรัน `php artisan optimize:clear` รายการสื่อและงานส่งไฟล์ยังมีเพดานเดิม 250 รายการต่อคำขอ ใช้การค้นหาสื่อ/เลือกรายวิชาเพื่อจำกัดผลลัพธ์ รายการงานส่งไฟล์ค้นหาภายในรายการที่โหลดแล้ว ไม่มีการเพิ่ม pagination ในรอบนี้

## นำเข้าข้อสอบและเทมเพลต

ครู/ผู้ดูแลดาวน์โหลด **เทมเพลต JSON** หรือ **เทมเพลต Excel** ได้จากหน้ารายวิชาและหน้าสร้างใบงาน/แบบทดสอบ แต่ละไฟล์มีตัวอย่างครบ 5 ประเภท กด **นำเข้าข้อสอบ** หรือเลือกไฟล์ในหน้าสร้างกิจกรรม ระบบตรวจทั้งไฟล์และแสดงตัวอย่างก่อนกด **ใช้ข้อสอบในแบบร่าง** ขั้นตอนนี้แทนที่คำถามเดิมในแบบร่าง ยังต้องตรวจทานและกด **บันทึกกิจกรรม** จึงบันทึกจริง ชื่อจาก JSON ใช้เมื่อยังไม่ได้กรอกชื่อกิจกรรม

- รองรับ `.json` และ `.xlsx` ขนาดไม่เกิน 5 MB และ 1–100 ข้อ ไม่รองรับ `.xls`, แมโคร สูตร หรือไฟล์เข้ารหัส
- JSON ใช้ `{ "version": 1, "title": "ชื่อแบบทดสอบ", "questions": [...] }` ตามเทมเพลต คำถามเลือกตอบใช้ข้อความตัวเลือกจริงเป็นเฉลย ส่วนเลือกหลายคำตอบใช้รายการข้อความ
- Excel มีชีต `Questions` และ `Guide` พร้อมคำแนะนำภาษาไทย คงชื่อชีตและหัวตาราง `id,type,prompt,points,option_1…option_10,answer` ไว้ เลือกตอบใช้หมายเลขเฉลยใน `answer` เช่น `1`; เลือกหลายคำตอบใช้ `1,3`; ถูก/ผิดใช้ `true`/`false`; เติมคำใช้ข้อความ; อัตนัยเว้นเฉลยว่าง
- ทุกคำถามต้องมี id ไม่ซ้ำ โจทย์ไม่ว่าง ตัวเลือกและเฉลยตรงประเภท และคะแนน 0.01–1000 ไม่เกินสองตำแหน่ง หากแถวใดผิดจะไม่รับทั้งไฟล์ พร้อมระบุข้อหรือแถวที่ต้องแก้
- อัปโหลดภาพโจทย์หลังนำเข้า ไม่อนุญาต id รูปภาพหรือลิงก์ภาพในไฟล์นำเข้า ไฟล์อ่านภายในเบราว์เซอร์และไม่ถูกเก็บบนเซิร์ฟเวอร์ การบันทึกยังผ่าน validation และสิทธิ์เดิมที่ API
- Excel ถูกตรวจโครงสร้าง ZIP และจำนวนไบต์ที่ขยายจริงระหว่างอ่าน จำกัด 200 entries / 10 MiB expanded พร้อมปฏิเสธขนาดปลอม สูตร และเส้นทางผิดรูปแบบ ส่วน parser Excel โหลดเมื่อใช้เทมเพลต/นำเข้าเท่านั้น

## สิทธิ์และข้อมูล

ครูจัดการได้เฉพาะกิจกรรมที่ตนสร้างภายในรายวิชาและกลุ่มที่รับผิดชอบ ตัวเลือกทุกกลุ่มหมายถึงกลุ่มที่ครูมีสิทธิ์ในขณะสร้าง ผู้ดูแลจัดการภายในอำเภอที่เลือก ส่วน super admin ต้องเลือกอำเภอที่เปิดใช้งานก่อน นักศึกษาเห็นเฉพาะกิจกรรมเผยแพร่ที่ตรงวิชาลงทะเบียน ระดับ และกลุ่มของตน รวมถึงคำตอบ/คะแนนของตนเท่านั้น เฉลยไม่ส่งใน API นักศึกษาแม้ส่งคำตอบแล้ว

รูปเก็บบน private local storage และดาวน์โหลดผ่าน endpoint ที่ตรวจสิทธิ์ตามกิจกรรม รูปที่อัปโหลดแต่ยังไม่ผูกกิจกรรมอ่านได้เฉพาะเจ้าของ รูปที่ถอดออกจากคำถามหรือกิจกรรมที่ลบจะเข้าถึงผ่าน API ไม่ได้ ไฟล์ยังเก็บไว้เพื่อหลีกเลี่ยงการลบไฟล์ที่ใช้งานระหว่างคำขอ ต้องวางแผนล้างรูปที่ไม่ได้ใช้ตาม retention ของ deployment

การเขียนใช้ session/CSRF เดิม เปิดได้เมื่อ `SYSTEM_WRITES_ENABLED=true` มี audit สำหรับสร้าง แก้ไข ลบ ส่ง ตรวจ และอัปโหลด โดยไม่เก็บเนื้อหาคำตอบใน audit ลิงก์ภายนอกไม่ถูกดาวน์โหลดโดยเซิร์ฟเวอร์

## ติดตั้งและตรวจสอบ

การแก้วันที่ 29 ตุลาคม 2569 ใช้ Vite `base: './'` เพื่อให้ lazy JS/CSS และฟอนต์โหลดภายใต้พาธติดตั้ง เช่น `/SDL_school/build/` แก้อาการเปิดหน้าระบบไม่สำเร็จจากการเรียก CSS ไปยัง `/build/` ผิดตำแหน่ง ใช้ build ล่าสุดที่แนบใน Git แล้วรัน `php artisan optimize:clear` และรีเฟรชหน้า ไม่ต้องเพิ่ม migration สำหรับการแก้พาธนี้ Frontend ผ่าน 75 tests รวม regression ของไฟล์ build ที่รากโดเมนและพาธซ้อน ทดสอบเบราว์เซอร์ด้วยข้อมูล demo แยกฐานและบล็อก `/build/` ที่รากโดเมนแล้วเปิดห้องเรียน ดาวน์โหลด JSON และแสดงตัวอย่างนำเข้าได้

รัน migrations บนฐาน staging ก่อน deploy ฐานเป้าหมาย สำรองฐานข้อมูลตามขั้นตอน deployment ปกติ แล้วรันภายใน `laravel-app`:

```bash
php artisan migrate --force
php artisan optimize:clear
npm run build
```

Migration `2026_10_28_000037_create_online_classroom_tables.php` เพิ่มเฉพาะ `learning_classroom_activities`, `learning_classroom_submissions`, `learning_classroom_images` ไม่แก้ข้อมูลนักศึกษา ตาราง import หรือผลการเรียนเดิม โมดูลตอบ HTTP 503 พร้อมคำแนะนำจนกว่าจะมีตารางครบ ไม่พึ่ง readiness repair ของโมดูล learning เดิมเพื่อสร้างตารางใหม่

```bash
php artisan test --filter=OnlineClassroomTest
npm run test:frontend
npm run typecheck
```

API หลักอยู่ใต้ `/api/v1/learning/classroom`: GET workspace (`subject_code`, `education_level`, `activity_type`, `activity_id`, `activity_page`, `page`), POST `activities`, POST/DELETE `activities/{id}`, POST `activities/{id}/submit`, PATCH `activities/{id}/submissions/{submission}/review`, POST `images` และ GET `images/{id}` รายการกิจกรรมและคำตอบแบ่งหน้าครั้งละ 50 รายการ กรองประเภทก่อนแบ่งหน้า และส่ง `activity_counts` ที่นับก่อนกรองประเภทภายในสิทธิ์รายวิชาเดียวกัน

การปรับ UI/ประเภทคลิป/นำเข้าในรอบนี้ไม่มี migration เพิ่ม หากติดตั้งห้องเรียนรุ่นก่อนแล้วให้ใช้ production build ล่าสุดและรัน `php artisan optimize:clear`

## ผลตรวจสอบวันที่ 28 ตุลาคม 2569

รอบปรับการ์ดและนำเข้า: PHPUnit ทั้งระบบผ่าน 490 รายการ / ข้ามรายการเดิม 1 รายการ (491 รวม) โดยห้องเรียนผ่าน 21 กรณี Frontend ผ่าน 73 รายการ รวม import 11 กรณี JSON/Excel roundtrip ครบห้าประเภท ไฟล์ผิดรูปแบบ สูตร แมโคร และ ZIP ขนาดปลอมถูกปฏิเสธ TypeScript, build และ Pint ไฟล์ที่แก้ผ่าน ตรวจหน้าจอจริงกับข้อมูลจำลองแยกฐาน: ปุ่มระดับและหมวด การแยกคลิป ดาวน์โหลดเทมเพลตและนำเข้าทั้ง JSON/Excel การแก้ชนิดคำถามเดิมโดยไม่ทำเฉลยหาย และข้อสอบยังอยู่เมื่อ background GET ล้มเหลว มีการตรวจ keyboard focus หลังเปิดการ์ดด้วย

PHPUnit ทั้งระบบผ่าน 486 รายการ และข้ามรายการเดิม 1 รายการ (487 รวม) โดยมี feature test ห้องเรียนใหม่ 17 รายการ Frontend ผ่าน 62 รายการ TypeScript, production build, Pint ของไฟล์ที่แก้ และ diff whitespace ผ่าน Browser ตรวจด้วย SQLite/demo แยกจากฐานจริง: ครูสร้างใบความรู้พร้อม Drive link และแบบทดสอบผสมภาพโจทย์/อัตนัย นักศึกษาส่งคำตอบบนมือถือ ทดสอบความล้มเหลวแล้วลองส่งใหม่โดยคำตอบไม่หาย และครูให้คะแนนอัตนัยได้ ตรวจ 390px/1440px และโหมดมืดโดยไม่มี horizontal page overflow

Repository-wide Pint ยังรายงานรูปแบบเดิมในเจ็ดไฟล์ที่ไม่เกี่ยวกับงานนี้ (`DistrictBranding`, announcement controllers สองไฟล์, `CurriculumCatalog`, `CourseRegistrationExportService`, `routes/web.php`, `CourseRegistrationPdfTest`) ไม่ได้เปลี่ยนไฟล์เหล่านี้ ฐาน MySQL จริง ปริมาณงานขนาดใหญ่ การเล่นวิดีโอของผู้ให้บริการ และสิทธิ์แชร์ไฟล์คลาวด์จริงยัง Not verified

## Course navigation and assigned tasks (2026-10-29)

The initial classroom page shows the course chooser only. Selecting a course opens its activities at a URL containing `subject_code` and `education_level`; the return button and browser history restore the chooser. Students also see their assigned work across courses below the chooser. The retired learning-home menu is removed from sidebar, navigation editor and student-home catalogs without overwriting saved preferences; `/learning` redirects to `/learning/classroom`.

Teachers/admins open a course and choose the task action, enter a title, instructions, target group and future deadline, then select all eligible activities or individual activities. Type filters support assigning only worksheets or quizzes. All mode captures the published online activities and open file worksheets accessible to every target learner at issue time, with a maximum of 200 items. Later activities are not silently added. Content, audience, publication status and deletion of issued sources are locked; grading and review remain available. Teachers can issue/view their own work in assigned groups; admins use the selected district. Students require current-term course/level/group registration and receive only their own progress, never the roster or other answers.

Students open a task to see its required items, then use the original activity or file submission flow. Existing submissions count immediately; answers and attempts are not duplicated. Knowledge/video completion uses a learner acknowledgement button and does not prove viewing duration. Task statuses are pending, in progress and completed. Staff filter status before roster pagination (50 per page). The list shows deadlines and late/overdue indicators.

Online assessments still allow one final attempt. Their effective submission deadline is the latest deadline among tasks available to that learner that reference the activity, falling back to the original activity deadline when no task applies. Each task evaluates lateness against its own deadline. File worksheets keep their original open/submission rules and accept late work while open; content acknowledgement also remains possible after the task deadline. Previously completed activities count as done; use a new activity if a new attempt is required.

Migration `2026_10_29_000042_create_classroom_tasks.php` adds tasks, normalized item references and acknowledgement evidence. It is additive and idempotent, retains data on rollback and does not modify import/student/grade data. Task endpoints return an actionable 503 until tables exist. No real database migration was run during development.

Additional API routes under `/api/v1/learning/classroom`: GET `tasks` (optional course filter, page), GET `tasks/options` (course, target_group), POST `tasks` (selection_mode, activity_ids, assignment_ids, due_at), GET `tasks/{id}` (page, status=all|pending|in_progress|completed), POST `tasks/{task}/activities/{activity}/complete` for the current learner's own knowledge/video acknowledgement. Existing session/CSRF, write_enabled and audit protections apply.

Verification for this task: full PHPUnit suite passed 527 tests with 1 existing skip (528 total, 4,555 assertions); focused task/classroom/assignment suites passed 40 tests / 470 assertions. Frontend suite passed 97 tests, TypeScript, production build and Pint for changed PHP files passed. Browser checks used a separate SQLite demo database: course/activity separation, all/selected task creation, learner read acknowledgement and quiz submission, immediate progress refresh, staff completed/pending filters, task draft unload warning, 390px and 1440px layouts with no horizontal overflow. Actual production MySQL migration, high-volume query plans and cloud media permissions remain Not verified.

## Strict teacher targeting (2026-10-30)

The teacher task/activity/file/resource/calendar forms show only assigned groups and preselect an available own group. Their APIs require an explicit nonempty group and reject blank/all/foreign targets, including forged requests. All-activity selection means all eligible activities for the chosen own group. Existing tasks and historical file worksheets are displayed/reviewed only for the current authorized course/group roster. A historical worksheet targeting all groups cannot be edited/deleted by a teacher; create a new explicitly scoped worksheet instead. Grading remains available for authorized learners. Original creator status does not retain access after losing a group; private PDFs/images, submissions and calendar images enforce current access too.

Teacher report group filters say they cover only assigned groups. Directory, academic reports, registrations, exam attendance, score/report data and N-NET use current group scope. N-NET cannot trust stored result group names: teacher access requires an unambiguous current student identity and level. Mixed imports containing an out-of-scope identity are rejected before writes. Only district administrators may update the district-wide N-NET schedule. Admin/super_admin access remains district scoped. No migration or data rewrite is included.

Verification for teacher isolation: full PHPUnit suite passed 540 tests with 1 existing skip (541 total, 4,873 assertions). Frontend suite passed 97 tests; TypeScript, changed/new PHP Pint and production build passed. Browser checks on isolated SQLite confirmed teacher forms expose only the assigned group, successful task creation lists only that group's learner, the mobile form has no horizontal overflow, and district administrators retain all-group choices. Production account assignments, production MySQL plans and high-volume performance remain Not verified.
