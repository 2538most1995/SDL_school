<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; color: #0f172a; font-family: thsarabunnew, "TH Sarabun New", sans-serif; font-size: 16pt; line-height: 1.18; }
        .sheet { width: 100%; padding: 3mm 0 2mm; background: #fff; }
        h1 { margin: 0 0 8mm; text-align: center; font-size: 26pt; line-height: 1; font-weight: bold; }
        .info { width: 180mm; border-collapse: separate; border-spacing: 0 2.5mm; margin: 0 0 7mm; table-layout: fixed; font-size: 17pt; }
        .info .label-cell { width: 22mm; padding: 0 2mm 1.2mm 0; vertical-align: bottom; white-space: nowrap; font-weight: bold; }
        .info .value-cell { width: 62mm; height: 8mm; padding: 0 2mm 0.8mm; vertical-align: bottom; overflow: hidden; font-weight: bold; border-bottom: 0.35mm dotted #64748b; }
        .info .spacer-cell { width: 12mm; }
        .info .right-label { width: 24mm; }
        .info .right-value { width: 60mm; }
        .exam-table { width: 180mm; border-collapse: collapse; table-layout: fixed; font-size: 15pt; }
        .exam-table th, .exam-table td { border: 0.3mm solid #1e293b; padding: 2.2mm 1.3mm; text-align: center; vertical-align: middle; }
        .exam-table th { background: #f8fafc; font-weight: bold; font-size: 16pt; }
        .exam-table td.subject { text-align: left; }
        .exam-table .empty { height: 38mm; color: #64748b; font-size: 16pt; text-align: center; }
        .w-no { width: 8mm; } .w-term { width: 18mm; } .w-subject { width: 48mm; } .w-date { width: 31mm; } .w-time { width: 25mm; } .w-place { width: 28mm; } .w-room { width: 22mm; }
        .footer { margin-top: 4mm; text-align: right; color: #64748b; font-size: 11pt; }
    </style>
</head>
<body>
@php
    $pdfText = static fn (mixed $value): string => e((string) $value);
@endphp
@foreach ($documents as $document)
    <section class="sheet">
        <h1>ตารางสอบ</h1>
        <table class="info" autosize="1">
            <tr>
                <td class="label-cell">สถานศึกษา</td><td class="value-cell">{!! $pdfText($document['student']['district']) !!}</td>
                <td class="spacer-cell"></td><td class="label-cell right-label">ภาคเรียน</td><td class="value-cell right-value">{!! $pdfText($document['term']) !!}</td>
            </tr>
            <tr>
                <td class="label-cell">ชื่อ-สกุล</td><td class="value-cell">{!! $pdfText($document['student']['name']) !!}</td>
                <td class="spacer-cell"></td><td class="label-cell right-label">ระดับชั้น</td><td class="value-cell right-value">{!! $pdfText($document['student']['level']) !!}</td>
            </tr>
            <tr>
                <td class="label-cell">รหัสนักศึกษา</td><td class="value-cell">{!! $pdfText($document['student']['code']) !!}</td>
                <td class="spacer-cell"></td><td class="label-cell right-label">กลุ่ม</td><td class="value-cell right-value">{!! $pdfText($document['student']['group']) !!}</td>
            </tr>
        </table>
        <table class="exam-table" autosize="1">
            <thead><tr>
                <th class="w-no">ที่</th><th class="w-term">เทอม</th><th class="w-subject">วิชา</th><th class="w-date">วัน/เดือน/ปี</th><th class="w-time">เวลา</th><th class="w-place">สถานที่</th><th class="w-room">ห้องสอบ</th>
            </tr></thead>
            <tbody>
            @forelse ($document['rows'] as $index => $row)
                <tr>
                    <td>{{ $index + 1 }}</td><td>{!! $pdfText($row['term']) !!}</td><td class="subject"><strong>{!! $pdfText($row['subject_code']) !!}</strong> {!! $pdfText($row['subject_name']) !!}</td><td>{!! $pdfText($row['exam_date_display']) !!}</td><td>{!! $pdfText($row['start_time']) !!}-{!! $pdfText($row['end_time']) !!} น.</td><td>{!! $pdfText($row['location']) !!}</td><td>{!! $pdfText($row['room']) !!}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">ยังไม่พบตารางสอบในภาคเรียนปัจจุบัน รอเจ้าหน้าที่อัปเดตข้อมูล</td></tr>
            @endforelse
            </tbody>
        </table>
        @if ($count > 1)<div class="footer">เอกสารลำดับที่ {{ $loop->iteration }} จาก {{ $count }}</div>@endif
    </section>
    @if (! $loop->last)<pagebreak />@endif
@endforeach
</body>
</html>
