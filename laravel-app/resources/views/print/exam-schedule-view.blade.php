<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตารางสอบ - SDL School</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #e2e8f0;
            color: #0f172a;
            font-family: "TH Sarabun New", "Sarabun", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 16px;
            line-height: 1.35;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Action Bar */
        .no-print-bar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #0f172a;
            color: #ffffff;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .bar-title {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .bar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.15s ease;
        }
        .btn:active { transform: scale(0.96); }
        .btn-primary {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(37,99,235,0.4);
        }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-outline {
            background: #334155;
            color: #ffffff;
            border: 1px solid #475569;
        }
        .btn-outline:hover { background: #475569; }

        /* Document Container (Simulated A4 Paper Sheet) */
        .document-wrapper {
            max-width: 900px;
            margin: 20px auto 40px;
            padding: 0 12px;
        }
        .a4-sheet {
            background: #ffffff;
            width: 100%;
            padding: 36px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }

        h1.doc-title {
            margin: 0 0 20px;
            text-align: center;
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.5px;
        }

        /* Header Info Layout matching PDF */
        .doc-info-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            margin-bottom: 20px;
            font-size: 15px;
        }
        .doc-info-table td {
            vertical-align: bottom;
            padding: 0;
        }
        .doc-info-table .label {
            font-weight: 700;
            color: #334155;
            white-space: nowrap;
            padding-right: 8px;
            width: 1%;
        }
        .doc-info-table .value {
            font-weight: 700;
            color: #0f172a;
            border-bottom: 1px dotted #64748b;
            padding: 0 4px 2px;
            width: 44%;
        }
        .doc-info-table .spacer {
            width: 8%;
        }

        /* Official Exam Schedule Table */
        .table-container {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-top: 8px;
            border-radius: 4px;
        }
        .exam-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            table-layout: auto;
        }
        .exam-table th, .exam-table td {
            border: 1px solid #334155;
            padding: 8px 6px;
            text-align: center;
            vertical-align: middle;
        }
        .exam-table th {
            background: #f8fafc;
            font-weight: 800;
            color: #0f172a;
            white-space: nowrap;
        }
        .exam-table td.subject-col {
            text-align: left;
            padding-left: 10px;
        }
        .subject-code {
            font-weight: 800;
            color: #0f172a;
            margin-right: 4px;
        }
        .subject-name {
            font-weight: 600;
            color: #1e293b;
        }
        .room-text {
            font-weight: 700;
            color: #0369a1;
            white-space: nowrap;
        }
        .empty-row {
            padding: 36px 16px;
            color: #64748b;
            font-weight: 600;
            text-align: center;
        }
        .doc-footer {
            margin-top: 14px;
            text-align: right;
            font-size: 12px;
            color: #64748b;
        }

        /* Mobile specific adjustments */
        @media (max-width: 640px) {
            html, body { background: #f1f5f9; font-size: 14px; }
            .document-wrapper { margin: 8px auto 24px; padding: 0 6px; }
            .a4-sheet { padding: 20px 14px; border-radius: 6px; }
            h1.doc-title { font-size: 22px; margin-bottom: 14px; }
            .doc-info-table { font-size: 13px; border-spacing: 0 6px; }
            .doc-info-table .label { padding-right: 4px; }
            .doc-info-table .value { padding: 0 2px 2px; }
            .doc-info-table .spacer { width: 4%; }
            .exam-table { min-width: 580px; }
            .exam-table th, .exam-table td { padding: 6px 4px; font-size: 13px; }
            .no-print-bar { padding: 8px 12px; }
            .bar-title { font-size: 13px; }
            .btn { padding: 7px 12px; font-size: 13px; }
        }

        /* Print Mode: Exact 1:1 match with official A4 paper */
        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 15mm;
            }
            html, body {
                background: #ffffff !important;
                color: #000000 !important;
                font-family: "TH Sarabun New", "Sarabun", sans-serif !important;
                font-size: 16pt !important;
                line-height: 1.18 !important;
            }
            .no-print-bar {
                display: none !important;
            }
            .document-wrapper {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .a4-sheet {
                padding: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                page-break-after: always;
            }
            .a4-sheet:last-child {
                page-break-after: auto;
            }
            h1.doc-title {
                font-size: 26pt !important;
                margin: 0 0 6mm !important;
            }
            .doc-info-table {
                font-size: 16pt !important;
                border-spacing: 0 2mm !important;
                margin-bottom: 6mm !important;
            }
            .doc-info-table .value {
                border-bottom: 0.35mm dotted #000000 !important;
            }
            .table-container {
                overflow: visible !important;
            }
            .exam-table {
                width: 100% !important;
                min-width: 100% !important;
                font-size: 14pt !important;
            }
            .exam-table th, .exam-table td {
                border: 0.3mm solid #000000 !important;
                padding: 2mm 1.5mm !important;
                color: #000000 !important;
            }
            .exam-table th {
                background: #f8fafc !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .room-text {
                color: #000000 !important;
            }
            .doc-footer {
                margin-top: 4mm !important;
                font-size: 11pt !important;
                color: #000000 !important;
            }
        }
    </style>
</head>
<body>
    <!-- Sticky Action Bar for Mobile & Desktop -->
    <header class="no-print-bar">
        <div class="bar-title">
            <span>ตารางสอบ</span>
            <span style="font-size: 12px; opacity: 0.8;">({{ $count }} ชุด)</span>
        </div>
        <div class="bar-actions">
            @if(isset($pdfPrintUrl))
                <a href="{{ $pdfPrintUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 256 256" fill="currentColor"><path d="M200,80V48a16,16,0,0,0-16-16H72A16,16,0,0,0,56,48V80a8,8,0,0,0,16,0V48H184V80a8,8,0,0,0,16,0ZM224,88H32a16,16,0,0,0-16,16v64a16,16,0,0,0,16,16H56v40a16,16,0,0,0,16,16H184a16,16,0,0,0,16-16V184h24a16,16,0,0,0,16-16V104A16,16,0,0,0,224,88Zm-40,136H72V168H184Zm40-56H200V152a8,8,0,0,0-8-8H64a8,8,0,0,0-8,8v16H32V104H224v64Z"></path></svg>
                    <span>พิมพ์เอกสาร (เปิดไฟล์ PDF)</span>
                </a>
            @endif
            @if(isset($pdfDownloadUrl))
                <a href="{{ $pdfDownloadUrl }}" class="btn btn-outline">
                    <svg width="16" height="16" viewBox="0 0 256 256" fill="currentColor"><path d="M224,144v64a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V144a8,8,0,0,1,16,0v56H208V144a8,8,0,0,1,16,0Zm-101.66,7.66a8,8,0,0,0,11.32,0l40-40a8,8,0,0,0-11.32-11.32L136,126.69V32a8,8,0,0,0-16,0V126.69L93.66,100.34a8,8,0,0,0-11.32,11.32Z"></path></svg>
                    <span>ดาวน์โหลด PDF</span>
                </a>
            @endif
        </div>
    </header>

    <!-- Main Documents Container -->
    <main class="document-wrapper">
        @foreach ($documents as $document)
            <article class="a4-sheet">
                <h1 class="doc-title">ตารางสอบ</h1>

                <!-- 2-Column Info Table matching PDF layout -->
                <table class="doc-info-table">
                    <tr>
                        <td class="label">สถานศึกษา:</td>
                        <td class="value">{{ $document['student']['district'] ?? '-' }}</td>
                        <td class="spacer"></td>
                        <td class="label">ภาคเรียน:</td>
                        <td class="value">{{ $document['term'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="label">รหัสสถานศึกษา:</td>
                        <td class="value">{{ $document['student']['school_code'] ?? '-' }}</td>
                        <td class="spacer"></td>
                        <td class="label">ครูประจำกลุ่ม:</td>
                        <td class="value">{{ $document['student']['advisor'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="label">ชื่อ-สกุล:</td>
                        <td class="value">{{ $document['student']['name'] ?? '-' }}</td>
                        <td class="spacer"></td>
                        <td class="label">ระดับชั้น:</td>
                        <td class="value">{{ $document['student']['level'] ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="label">รหัสนักศึกษา:</td>
                        <td class="value">{{ $document['student']['code'] ?? '-' }}</td>
                        <td class="spacer"></td>
                        <td class="label">กลุ่ม:</td>
                        <td class="value">{{ $document['student']['group'] ?? '-' }}</td>
                    </tr>
                </table>

                <!-- Official Exam Table matching PDF columns -->
                <div class="table-container">
                    <table class="exam-table">
                        <thead>
                            <tr>
                                <th style="width: 38px;">ที่</th>
                                <th style="width: 65px;">เทอม</th>
                                <th>วิชา</th>
                                <th style="width: 105px;">วัน/เดือน/ปี</th>
                                <th style="width: 120px;">เวลา</th>
                                <th style="width: 115px;">สถานที่</th>
                                <th style="width: 75px;">ห้องสอบ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($document['rows'] as $index => $row)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $row['term'] }}</td>
                                    <td class="subject-col">
                                        <span class="subject-code">{{ $row['subject_code'] }}</span>
                                        <span class="subject-name">{{ $row['subject_name'] }}</span>
                                    </td>
                                    <td>{{ $row['exam_date_display'] }}</td>
                                    <td style="white-space: nowrap;">{{ $row['start_time'] }} - {{ $row['end_time'] }} น.</td>
                                    <td>{{ $row['location'] }}</td>
                                    <td><span class="room-text">{{ $row['room'] }}</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="empty-row">ยังไม่พบตารางสอบในภาคเรียนปัจจุบัน รอเจ้าหน้าที่อัปเดตข้อมูล</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($count > 1)
                    <div class="doc-footer">เอกสารลำดับที่ {{ $loop->iteration }} จาก {{ $count }}</div>
                @endif
            </article>
        @endforeach
    </main>

</body>
</html>
