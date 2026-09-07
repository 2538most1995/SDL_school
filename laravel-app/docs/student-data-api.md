# Student Data Integration API

API นี้เป็น read-only สำหรับให้ backend ของเว็บไซต์อื่นอ่านข้อมูลนักศึกษาจากชุดนำเข้า ZIP/DBF ล่าสุดที่สำเร็จของอำเภอหนึ่งแห่ง โดยใช้ Bearer token เฉพาะระบบปลายทาง

ข้อมูลที่ไม่ส่งออกทุก endpoint ได้แก่ เลขบัตรประชาชนทั้งแบบเต็มและปิดบางส่วน วันเกิด ที่อยู่ เบอร์โทร อีเมล ผู้ปกครอง social profile และ raw/source payload

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
| `GET` | `/students/{student_code}` | ข้อมูลนักศึกษาหนึ่งคน |
| `GET` | `/students/{student_code}/grades` | เกรดและสรุปหน่วยกิต |
| `GET` | `/students/{student_code}/kpch` | กิจกรรม กพช. และชั่วโมงสะสม |
| `GET` | `/students/{student_code}/moral` | ผลประเมินคุณธรรม |
| `GET` | `/students/{student_code}/subjects` | รายวิชาที่ลงทะเบียน |

ทุก endpoint รองรับเฉพาะ `GET`/`HEAD` และคืน `Cache-Control: private, no-store` กับ `Vary: Authorization`

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
