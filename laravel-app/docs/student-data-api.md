# Student Data Integration API

API นี้เป็น read-only สำหรับให้ backend ของเว็บไซต์อื่นอ่านข้อมูลนักศึกษาจากชุดนำเข้า ZIP/DBF ล่าสุดที่สำเร็จของอำเภอหนึ่งแห่ง โดยใช้ Bearer token เฉพาะระบบปลายทาง

เลขบัตรประชาชนของนักศึกษา วันเกิด ที่อยู่ เบอร์โทร อีเมล ครอบครัวและ social profile ส่งเฉพาะ endpoint รายบุคคล `/students/{student_code}/profile` ตามคำขอผู้ใช้ endpoint อื่นยังคงข้อมูลขั้นต่ำแบบเดิมและไม่ส่งเลขบัตรประชาชน ทุก endpoint ไม่ส่ง raw/source payload

ชื่อ รหัสนักศึกษา และผลการเรียนยังเป็นข้อมูลส่วนบุคคลทางการศึกษา ผู้ใช้งาน API ต้องเก็บ token ใน secret manager และเรียกจาก backend เท่านั้น ห้ามฝัง token ใน JavaScript, mobile app หรือ repository สาธารณะ Request ที่มี browser `Origin` header จะถูกปฏิเสธด้วย `403`

## ติดตั้งและออก token

หลัง deploy ให้สร้างตารางที่จำเป็นก่อน:

```bash
php artisan migrate --force
php artisan optimize:clear
```

ออก token สำหรับบัญชี `admin` ประจำอำเภอ:

```bash
php artisan system:create-student-api-token api.admin \
  --name=partner-website \
  --expires-in-days=90
```

หากผู้ออกเป็น `super_admin` ต้องเลือกอำเภอที่ token นี้จะเข้าถึงแบบถาวร:

```bash
php artisan system:create-student-api-token system.admin \
  --name=partner-website \
  --district-code=sena \
  --expires-in-days=90
```

คำสั่งจะแสดง token ครั้งเดียวและแสดงหมายเลข client สำหรับใช้เพิกถอน หากต้องการหมุน token ชื่อเดิม ให้รันคำสั่งเดิมพร้อม `--replace` ซึ่งจะเพิกถอน token เดิมก่อนสร้างค่าใหม่

เพิกถอน token โดยใช้หมายเลข client:

```bash
php artisan system:revoke-student-api-token 12
```

Token เก็บในฐานข้อมูลเป็น SHA-256 hash เท่านั้น มีอายุสูงสุด 365 วัน ผูกกับอำเภอตั้งแต่สร้าง และใช้ยืนยันตัวตนกับ route ภายใน/admin อื่นไม่ได้

## การเรียก API

Base path:

```text
/api/v1/integrations/student-data
```

ส่ง header ทุก request:

```http
Accept: application/json
Authorization: Bearer sdl_student_...
```

ตัวอย่าง:

```bash
curl --fail-with-body \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer sdl_student_...' \
  'https://example.com/SDL_school/api/v1/integrations/student-data/students?per_page=50&page=1'
```

ห้ามส่ง `X-District-Id` หรือ `district_id` เพราะอำเภอถูกกำหนดจาก token ระบบจะตอบ `422` หากพยายามเปลี่ยน scope

## Endpoints

| Method | Endpoint | ข้อมูล |
| --- | --- | --- |
| `GET` | `/students` | รายชื่อนักศึกษาปัจจุบันแบบแบ่งหน้า |
| `GET` | `/graduates` | ผู้สำเร็จการศึกษาแยกตามภาคเรียน แบบแบ่งหน้า |
| `GET` | `/students/{student_code}` | ข้อมูลนักศึกษาหนึ่งคน |
| `GET` | `/students/{student_code}/profile` | ข้อมูลส่วนบุคคล ติดต่อ ครอบครัว และการศึกษาเดิม พร้อมเลขบัตรประชาชนของนักศึกษา |
| `GET` | `/students/{student_code}/grades` | เกรดและสรุปหน่วยกิต |
| `GET` | `/students/{student_code}/kpch` | กิจกรรม กพช. และชั่วโมงสะสม |
| `GET` | `/students/{student_code}/moral` | ผลประเมินคุณธรรม |
| `GET` | `/students/{student_code}/subjects` | รายวิชาที่ลงทะเบียน |

ทุก endpoint รองรับเฉพาะ `GET`/`HEAD` และคืน `Cache-Control: private, no-store` กับ `Vary: Authorization`

### ข้อมูลส่วนบุคคล พร้อมเลขบัตรประชาชนของนักศึกษา

```bash
curl --fail-with-body \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer sdl_student_...' \
  'https://example.com/SDL_school/api/v1/integrations/student-data/students/6650100001/profile?level=3'
```

ใช้ token เดิมที่มี `student-data:read` ได้ ไม่ต้องเพิ่ม migration หรือออก token ใหม่ `level` ไม่บังคับ มีค่า `1`, `2`, `3` ใช้เมื่อรหัสนักศึกษาซ้ำข้ามระดับ หากไม่ระบุแล้วพบมากกว่าหนึ่งคนจะตอบ `404` ข้อมูลผู้จบย้อนหลังเรียกได้ตามที่ยังมีอยู่ในชุดนำเข้าล่าสุด ข้อมูลทั้งหมดเป็นค่าปัจจุบันจากชุดนำเข้านั้น ไม่ใช่ snapshot ย้อนหลัง

`data` ประกอบด้วยข้อมูลเดิมของ `/students/{student_code}` และฟิลด์ต่อไปนี้ โดยระบุรายการฟิลด์ที่ส่งได้ทุกระดับ ไม่คัดลอก raw object หรือ internal detail response:

| กลุ่ม | ฟิลด์ |
| --- | --- |
| `demographics` | `citizen_id`, `birth_date`, `gender`, `age`, `application_date`, `last_updated` |
| `contact` | `phone`, `phone_masked`, `email`, `email_masked`, `registered_address`, `current_address` |
| `social` | `facebook_url`, `line_id`, `line_url` |
| `guardian` | `name`, `phone`, `phone_masked`, `relationship` ตามข้อมูลสรุปที่มี |
| `profile.personal` | `nationality_code/label`, `religion_code/label`, `occupation_code/label`, `target_group_code/label`, `height_cm`, `weight_kg` |
| `profile.family` | `father`, `mother`, `guardian` แต่ละคนมี `name`, `occupation_code/label`, `income`, `status_code/label`; รวม `siblings`, `studying_siblings`, `marital_status_label` (สถานะสมรสบิดามารดา) |
| `profile.prior_education` | `qualification_code/label`, `graduation_year`, `school`, `province`, `province_label` |

ฟิลด์ที่ไม่มีข้อมูลคืน `null` ค่า `0` ยังคงเป็นศูนย์ ไม่แทนที่ที่อยู่ปัจจุบันด้วยที่อยู่ทะเบียน และคำนวณอายุจากวันเกิดตามกติกาเดิม ระบบอ่านอีเมลจริงจากแหล่งข้อมูลเดิมเมื่อมี ส่วน `email_masked` เป็นค่าแสดงแบบปิดบางส่วน ฟิลด์วันใช้รูปแบบเดียวกับข้อมูลภายใน เช่น `01/01/2550` ชื่อ/รหัสกลุ่มและข้อมูลติดต่อเป็นข้อมูลส่วนบุคคล ต้องเก็บ response ตามขอบเขตระบบปลายทางที่ได้รับอนุญาต

metadata ของ endpoint นี้กำหนด:

```json
{
  "data_classification": "personal_data_sensitive",
  "sensitive_identifiers_included": true,
  "citizen_id_included": true,
  "profile_included": true,
  "read_only": true
}
```

`data.demographics.citizen_id` คืนเลขบัตรประชาชนของนักศึกษาแบบเต็มจากข้อมูลหลัก `Student.citizenId` เป็น string (คงเลขศูนย์นำหน้า) หรือ `null` หากแหล่งข้อมูลไม่มีค่าที่ใช้ได้ ตามคำขอล่าสุด ไม่ต้องส่ง query parameter เพิ่ม `meta.citizen_id_included: true` ระบุว่า endpoint นี้เปิดฟิลด์เลขบัตรแล้ว ส่วน `sensitive_identifiers_included: true` ครอบคลุมทั้งเลขบัตรและข้อมูลติดต่อ เลขบัตรของญาติ/ผู้ปกครอง, `citizen_id_masked`, `cardid` และ raw/source fields ยังไม่ถูกส่งออก และ endpoint อื่นไม่เพิ่มเลขบัตรประชาชน

อำเภอถูกกำหนดจาก token เสมอ รวมถึงเจ้าของ role `super_admin`; นักศึกษานอกอำเภอหรือไม่พบข้อมูลตอบ `404` ไม่รับ `district_id`/`X-District-Id` และปฏิเสธ browser `Origin` ตามเดิม รองรับ header สำรอง `X-Student-Data-Token` พร้อม expiry/revocation/owner/district checks, rate limit และ `Cache-Control: private, no-store` เช่นเดียวกับ endpoint เดิม

### ผู้สำเร็จการศึกษาแยกตามภาคเรียน

```bash
curl --fail-with-body \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer sdl_student_...' \
  'https://example.com/SDL_school/api/v1/integrations/student-data/graduates?term=1%2F2569&page=1&per_page=100'
```

ใช้ token เดิมที่มี `student-data:read` ได้ ไม่ต้องออก token ใหม่หรือเพิ่ม migration สำหรับ endpoint นี้ อำเภอถูกกำหนดจาก token รวมถึง token ของ `super_admin` และใช้ rate limit/การเพิกถอน/การตรวจสถานะเจ้าของและอำเภอแบบเดียวกับ `/students` รองรับ `X-Student-Data-Token` เป็น header สำรองตามระบบเดิม

| Parameter | ค่า |
| --- | --- |
| `term` | ภาคเรียนที่จบ เช่น `1/2569` หรือ `2/2568`; ไม่ส่งจะใช้ภาคเรียนที่จบล่าสุดที่พบในชุดนำเข้าของอำเภอ |
| `level` | `1` ประถมศึกษา, `2` มัธยมศึกษาตอนต้น, `3` มัธยมศึกษาตอนปลาย |
| `group` | รหัสหรือชื่อกลุ่มตรงตัว; หากไม่มีตารางกลุ่มจะค้นได้เฉพาะรหัส |
| `search` | ค้นบางส่วนจากรหัสนักศึกษา ชื่อ นามสกุล หรือรหัสกลุ่ม ไม่ค้นเลขบัตรประชาชน |
| `page` | ตั้งแต่ `1` ขึ้นไป; เกินหน้าสุดท้ายคืน `data: []` |
| `per_page` | `1-100`, ค่าเริ่มต้น `25` |

ข้อมูลนำเข้าอ่านจาก batch ล่าสุดที่เชื่อมกับประวัตินำเข้าสำเร็จของอำเภอโดยตรง จึงรวมผู้จบย้อนหลังที่ไม่อยู่ในรายชื่อนักศึกษาปัจจุบัน คัดเฉพาะ `fin_cause` (หรือ `fincause`) เท่ากับ `1` และเทียบภาคเรียนจาก `fin_sem`, `fin_sem2` หรือ `finsem` ตามคอลัมน์ที่มี รองรับรูปแบบภาคเรียนต้นทาง เช่น `69/1` โดยคืนรูปแบบมาตรฐาน `1/2569` รายการที่ไม่มีคอลัมน์ยืนยันสถานะจบหรือภาคเรียนจะไม่ถูกนับเป็นผู้จบ

ตัวอย่าง response (ย่อ metadata ทั่วไป):

```json
{
  "data": [
    {
      "code": "6650100001",
      "name": { "full_name": "นายผู้เรียน ตัวอย่าง" },
      "level": { "id": 3, "label": "มัธยมศึกษาตอนปลาย" },
      "group": { "code": "G-01", "name": "กลุ่มตัวอย่าง" },
      "graduation_term": "1/2569"
    }
  ],
  "meta": {
    "api_contract": "student-data-v1",
    "read_only": true,
    "sensitive_identifiers_included": false,
    "terms": ["1/2569", "2/2568"],
    "selected_term": "1/2569",
    "pagination": {
      "current_page": 1,
      "per_page": 100,
      "total": 1,
      "last_page": 1,
      "from": 1,
      "to": 1
    }
  }
}
```

เรียงตามชื่อ นามสกุล รหัสนักศึกษา และระดับ รวมทั้งสามระดับก่อนแบ่งหน้าที่ฐานข้อมูล `meta.terms` เป็นภาคเรียนที่จบที่พบจริงทั้งหมดในชุดนำเข้าของอำเภอ ไม่เปลี่ยนตามตัวกรองระดับ/กลุ่ม/คำค้น หากไม่มีข้อมูลในภาคเรียนที่ขอจะได้ HTTP `200`, `data: []` และ `total: 0` หากไม่มีข้อมูลผู้จบเลยและไม่ส่ง `term` จะได้ `terms: []` และ `selected_term: null`

ข้อมูลย้อนหลังมีได้เท่าที่ชุดนำเข้าล่าสุดยังเก็บไว้ ชื่อและกลุ่มเป็นค่าจากชุดนำเข้าปัจจุบัน ไม่ใช่ snapshot ของภาคเรียนที่จบ โหมด demo ใช้ข้อมูลจำลองของรายงานเดิม โดยใช้ `currentTerm` แทนภาคเรียนที่จบ; ตัวเลือกภาคเรียนอาจรวมช่วงย้อนหลังของ demo

### ตัวกรองรายชื่อนักศึกษา

| Parameter | ค่า |
| --- | --- |
| `search` | รหัสนักศึกษา ชื่อ หรือตัวระบุกลุ่ม ไม่ค้นจากเลขบัตรประชาชน |
| `level` | `1`, `2`, `3` |
| `group` | รหัสหรือชื่อกลุ่ม |
| `term` | รูปแบบ `1/2569` หรือ `2/2569` |
| `sort` | `name`, `code`, `gpax`, `credits`, `kpch_hours` |
| `direction` | `asc`, `desc` |
| `page` | ตั้งแต่ `1` ขึ้นไป |
| `per_page` | `1-100`, ค่าเริ่มต้น `25` |

หน้าที่เกิน `last_page` จะคืน `data: []` โดยคง `current_page` ตามที่ร้องขอ เพื่อให้ตัวดึงข้อมูลหยุดได้โดยไม่วนซ้ำหน้าสุดท้าย

Endpoint เกรด กพช. คุณธรรม และรายวิชารองรับ `term` รูปแบบเดียวกัน และแบ่งหน้าด้วย `page`/`per_page` ค่าเริ่มต้น `100` สูงสุด `500` หากไม่ส่ง `term` จะอ่านข้อมูลทุกภาคเรียนที่มีในชุดนำเข้าปัจจุบันแล้วแบ่งหน้า

### ตัวอย่างรายวิชาที่ลงทะเบียน

```bash
curl --fail-with-body \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer sdl_student_...' \
  'https://example.com/SDL_school/api/v1/integrations/student-data/students/6650100001/subjects?term=1%2F2569&page=1&per_page=100'
```

Response แต่ละรายวิชามี `code` เป็นรหัสวิชาและ `name` เป็นชื่อรายวิชา:

```json
{
  "data": {
    "student": {
      "code": "6650100001",
      "name": { "full_name": "นายสมชาย ตัวอย่าง" }
    },
    "items": [
      {
        "student_code": "6650100001",
        "code": "ทช11001",
        "name": "เศรษฐกิจพอเพียง",
        "credits": 1,
        "type": "compulsory",
        "term": "1/2569",
        "registration_status": "registered",
        "is_transferred": false,
        "grade": null,
        "exam_attended": false
      }
    ],
    "summary": {
      "subject_count": 1,
      "total_credits": 1,
      "transferred_subjects": 0,
      "passed_subjects": 0
    }
  },
  "meta": {
    "api_contract": "student-data-v1",
    "term": "1/2569",
    "pagination": {
      "current_page": 1,
      "per_page": 100,
      "total": 1,
      "last_page": 1,
      "from": 1,
      "to": 1
    }
  }
}
```

`registration_status` อาจเป็น `registered`, `passed`, `needs_improvement` หรือ `transferred` ตามข้อมูลในชุดนำเข้า

## รูปแบบ response

ตัวอย่างข้อมูลนักศึกษา:

```json
{
  "data": {
    "code": "6650100001",
    "name": {
      "prefix": "นาย",
      "first_name": "สมชาย",
      "last_name": "ตัวอย่าง",
      "full_name": "นายสมชาย ตัวอย่าง"
    },
    "district": { "id": 1, "name": "อำเภอเสนา" },
    "level": { "id": 2, "label": "มัธยมศึกษาตอนต้น" },
    "group": { "code": "SENA-M2-A", "name": "กลุ่ม ม.ต้น A" },
    "enrollment_term": "1/2568",
    "current_term": "1/2569",
    "status": { "code": "studying", "label": "กำลังศึกษา" },
    "academic": {
      "gpax": 3.25,
      "credits_earned": 32,
      "credits_current": 38,
      "credits_required": 56,
      "compulsory_credits_earned": 24,
      "compulsory_credits_required": 40,
      "elective_credits_earned": 8,
      "elective_credits_required": 16,
      "kpch_hours": 120,
      "moral_result": "ดี"
    }
  },
  "meta": {
    "api_contract": "student-data-v1",
    "data_classification": "personal_educational_data",
    "sensitive_identifiers_included": false,
    "authentication": "bearer_token"
  }
}
```

Endpoint ข้อมูลการเรียนคืน envelope เดียวกัน โดย `data.student` ใช้ student allowlist ข้างต้น, `data.items` เป็นรายการ และ `data.summary` เป็นค่าสรุปของหมวดนั้น

ค่า `status.code` ที่มาจากข้อมูล production คือ `studying`, `graduated`, `transferred` หรือ `inactive`

## Status codes และ rate limit

| Status | ความหมาย |
| --- | --- |
| `200` | สำเร็จ รวมถึงกรณีนักศึกษามีอยู่แต่ไม่มีรายการในภาคเรียนที่เลือก |
| `401` | ไม่มี token, token ผิด, หมดอายุ, ถูกเพิกถอน, client/อำเภอ/บัญชีถูกปิด |
| `403` | เรียกจาก browser origin แทน backend server-to-server |
| `404` | ไม่พบนักศึกษาในอำเภอของ token หรือรหัสกำกวม |
| `422` | parameter ผิดรูปแบบหรือพยายาม override อำเภอ |
| `429` | เกิน rate limit |

ค่าเริ่มต้นจำกัด token ผิด `20` ครั้งต่อนาทีต่อ IP ด้วย `STUDENT_DATA_API_AUTH_RATE_LIMIT_PER_MINUTE` และจำกัด token ที่ผ่านแล้ว `60` request ต่อนาทีต่อ API client ด้วย `STUDENT_DATA_API_RATE_LIMIT_PER_MINUTE` Response `429` มี `Retry-After` ให้ระบบปลายทางรอก่อนลองใหม่

## ขอบเขตข้อมูล

- รายชื่อนักศึกษาเป็น roster ปัจจุบันของชุดนำเข้าสำเร็จล่าสุด ไม่ใช่ทะเบียนย้อนหลังทั้งหมด
- เกรด กพช. คุณธรรม และรายวิชาถูกอ่านจากชุดนำเข้าเดียวกันภายในอำเภอของ token
- ไม่มี fallback ไปชุดเก่า อำเภออื่น หรือฐานข้อมูลภายนอก
- หากรหัสนักศึกษากำกวม ระบบจะไม่เดาข้อมูลและตอบ `404`
- Live MySQL response time และ query plan บนปริมาณข้อมูล production ยัง `Not verified`
