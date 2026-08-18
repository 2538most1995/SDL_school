<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แจ้งเตือนตารางสอบ - SDL School</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 16px;
            background: #f8fafc;
            color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .card {
            background: #fff;
            max-width: 440px;
            width: 100%;
            padding: 32px 24px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            text-align: center;
        }
        .icon-circle {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #fef3c7;
            color: #d97706;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 28px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
        }
        p {
            margin: 0 0 16px;
            font-size: 15px;
            line-height: 1.6;
            color: #475569;
        }
        .student-badge {
            display: inline-block;
            background: #f1f5f9;
            color: #334155;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 24px;
        }
        .btn-back {
            display: block;
            width: 100%;
            padding: 12px 16px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-circle">📋</div>
        <h1>ไม่พบข้อมูลตารางสอบ</h1>
        <p>{{ $message }}</p>
        @if(filled($studentCode))
            <div class="student-badge">รหัสนักศึกษา: {{ $studentCode }}</div>
        @endif
        <a href="javascript:history.back()" class="btn-back">ย้อนกลับ</a>
    </div>
</body>
</html>
