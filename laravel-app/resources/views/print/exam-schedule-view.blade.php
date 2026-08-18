<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตารางสอบ - SDL School</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Sarabun", "TH Sarabun New", "Segoe UI", Roboto, sans-serif;
            font-size: 16px;
            line-height: 1.4;
            -webkit-font-smoothing: antialiased;
        }
        .no-print-bar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: #0f172a;
            color: #fff;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .no-print-bar h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .btn-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: transform 0.1s, opacity 0.15s;
        }
        .btn:active { transform: scale(0.97); }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-outline { background: #334155; color: #fff; }
        .page-container {
            max-width: 820px;
            margin: 20px auto;
            padding: 0 16px 40px;
        }
        .sheet {
            background: #fff;
            padding: 32px 36px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        h1 {
            margin: 0 0 20px;
            text-align: center;
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px 24px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-item {
            display: flex;
            align-items: baseline;
            gap: 8px;
            font-size: 15px;
        }
        .info-label {
            font-weight: 700;
            color: #475569;
            white-space: nowrap;
            min-width: 75px;
        }
        .info-value {
            font-weight: 700;
            color: #0f172a;
            border-bottom: 1px dotted #94a3b8;
            flex: 1;
            padding-bottom: 2px;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .exam-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            table-layout: auto;
        }
        .exam-table th, .exam-table td {
            border: 1px solid #cbd5e1;
            padding: 10px 8px;
            text-align: center;
            vertical-align: middle;
        }
        .exam-table th {
            background: #f8fafc;
            font-weight: 700;
            color: #334155;
            white-space: nowrap;
        }
        .exam-table td.subject {
            text-align: left;
            font-weight: 600;
        }
        .room-badge {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 13px;
        }
        .empty-cell {
            padding: 36px 16px;
            color: #64748b;
            font-weight: 600;
        }
        .footer-note {
            margin-top: 16px;
            text-align: right;
            font-size: 12px;
            color: #64748b;
        }

        @media (max-width: 640px) {
            .page-container { margin: 8px auto; padding: 0 8px 30px; }
            .sheet { padding: 20px 16px; border-radius: 8px; }
            h1 { font-size: 22px; margin-bottom: 16px; }
            .info-grid { grid-template-columns: 1fr; gap: 8px; }
            .exam-table th, .exam-table td { padding: 8px 5px; font-size: 13px; }
        }

        @media print {
            body { background: #fff; font-size: 14pt; }
            .no-print-bar { display: none !important; }
            .page-container { max-width: 100%; margin: 0; padding: 0; }
            .sheet { padding: 0; border-radius: 0; box-shadow: none; page-break-after: always; }
            .sheet:last-child { page-break-after: auto; }
            .info-grid { border-bottom: none; }
            .exam-table th { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .room-badge { background: transparent !important; color: #000 !important; border: 1px solid #000; }
        }
    </style>
</head>
<body>
    <div class="no-print-bar">
        <h2>ตารางสอบ ({{ $count }} ชุด)</h2>
        <div class="btn-group">
            <button class="btn btn-primary" onclick="window.print()">
                <svg width="16" height="16" viewBox="0 0 256 256" fill="currentColor"><path d="M200,80V48a16,16,0,0,0-16-16H72A16,16,0,0,0,56,48V80a8,8,0,0,0,16,0V48H184V80a8,8,0,0,0,16,0ZM224,88H32a16,16,0,0,0-16,16v64a16,16,0,0,0,16,16H56v40a16,16,0,0,0,16,16H184a16,16,0,0,0,16-16V184h24a16,16,0,0,0,16-16V104A16,16,0,0,0,224,88Zm-40,136H72V168H184Zm40-56H200V152a8,8,0,0,0-8-8H64a8,8,0,0,0-8,8v16H32V104H224v64Z"></path></svg>
                <span>พิมพ์เอกสาร</span>
            </button>
            @if(isset($pdfDownloadUrl))
                <a href="{{ $pdfDownloadUrl }}" class="btn btn-outline">
                    <svg width="16" height="16" viewBox="0 0 256 256" fill="currentColor"><path d="M224,144v64a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V144a8,8,0,0,1,16,0v56H208V144a8,8,0,0,1,16,0Zm-101.66,7.66a8,8,0,0,0,11.32,0l40-40a8,8,0,0,0-11.32-11.32L136,126.69V32a8,8,0,0,0-16,0V126.69L93.66,100.34a8,8,0,0,0-11.32,11.32Z"></path></svg>
                    <span>ดาวน์โหลด PDF</span>
                </a>
            @endif
        </div>
    </div>

    <main class="page-container">
        @foreach ($documents as $document)
            <article class="sheet">
                <h1>ตารางสอบ</h1>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">สถานศึกษา:</span>
                        <span class="info-value">{{ $document['student']['district'] ?? '-' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">ภาคเรียน:</span>
                        <span class="info-value">{{ $document['term'] ?? '-' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">ชื่อ-สกุล:</span>
                        <span class="info-value">{{ $document['student']['name'] ?? '-' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">ระดับชั้น:</span>
                        <span class="info-value">{{ $document['student']['level'] ?? '-' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">รหัสนักศึกษา:</span>
                        <span class="info-value">{{ $document['student']['code'] ?? '-' }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">กลุ่ม:</span>
                        <span class="info-value">{{ $document['student']['group'] ?? '-' }}</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="exam-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">ที่</th>
                                <th style="width: 70px;">เทอม</th>
                                <th>รหัส / รายวิชา</th>
                                <th style="width: 105px;">วันสอบ</th>
                                <th style="width: 110px;">เวลา</th>
                                <th style="width: 120px;">สถานที่</th>
                                <th style="width: 75px;">ห้องสอบ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($document['rows'] as $index => $row)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $row['term'] }}</td>
                                    <td class="subject">
                                        <div style="font-family: monospace; font-size: 12px; color: #475569;">{{ $row['subject_code'] }}</div>
                                        <div style="font-weight: 700; color: #0f172a;">{{ $row['subject_name'] }}</div>
                                    </td>
                                    <td>{{ $row['exam_date_display'] }}</td>
                                    <td style="white-space: nowrap;">{{ $row['start_time'] }} - {{ $row['end_time'] }} น.</td>
                                    <td>{{ $row['location'] }}</td>
                                    <td><span class="room-badge">{{ $row['room'] }}</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="empty-cell">ยังไม่พบตารางสอบในภาคเรียนปัจจุบัน รอเจ้าหน้าที่อัปเดตข้อมูล</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($count > 1)
                    <div class="footer-note">เอกสารลำดับที่ {{ $loop->iteration }} จาก {{ $count }}</div>
                @endif
            </article>
        @endforeach
    </main>
</body>
</html>
