<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบลงทะเบียนเรียน - SDL School</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #e2e8f0;
            color: #0f172a;
            font-family: "TH Sarabun New", "Sarabun", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 16px;
            line-height: 1.25;
            -webkit-font-smoothing: antialiased;
        }

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
        }
        .bar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
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
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #334155; color: #fff; }
        .btn-secondary:hover { background: #475569; }

        .page-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px;
            gap: 24px;
        }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            padding: 14mm 15mm;
            background: #ffffff;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            border-radius: 4px;
            color: #0f172a;
            position: relative;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }
        .header-table td {
            vertical-align: middle;
        }
        .doc-title {
            font-size: 21px;
            font-weight: bold;
            text-align: center;
        }
        .term-title {
            font-size: 18px;
            font-weight: bold;
            text-align: right;
            white-space: nowrap;
        }
        .district-title {
            font-size: 19px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 12px;
        }
        .dotted-line {
            border-bottom: 1.5px dotted #0f172a;
            display: inline-block;
            padding: 0 4px;
            line-height: 0.85;
            vertical-align: baseline;
            font-weight: bold;
            color: #000;
        }
        .credits-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            font-size: 15px;
        }
        .credits-table td {
            padding: 3px 0;
            vertical-align: middle;
        }
        .student-info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 16px;
        }
        .student-info-table td {
            vertical-align: middle;
            padding: 3px 0;
        }
        .side-box-table {
            border: 1.5px solid #000;
            border-collapse: collapse;
            width: 210px;
        }
        .side-box-table td {
            border: none !important;
            padding: 8px 6px;
            font-size: 14.5px;
            line-height: 1.35;
            vertical-align: middle;
            text-align: center;
        }
        .digit-table {
            border-collapse: collapse;
            margin: 0;
            padding: 0;
        }
        .digit-cell {
            width: 20px;
            height: 22px;
            border: 1.2px solid #000;
            text-align: center;
            vertical-align: middle;
            font-size: 15px;
            font-weight: bold;
            padding: 0;
        }
        .digit-gap {
            width: 6px;
            border: none !important;
            padding: 0;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 14.5px;
        }
        .main-table th, .main-table td {
            border: 1px solid #1e293b;
            padding: 4px 6px;
            vertical-align: middle;
        }
        .main-table th {
            background-color: #f8fafc;
            font-weight: bold;
            text-align: center;
            font-size: 15px;
        }
        .main-table td.subject-name {
            text-align: left;
            padding-left: 8px;
        }
        .main-table td.code-cell, .main-table td.credit-cell, .main-table td.check-cell {
            text-align: center;
        }
        .main-table td.subhead {
            font-weight: bold;
            background-color: #fff;
            padding: 4px 8px;
            text-align: left;
        }
        .checkmark {
            font-size: 16px;
            font-weight: bold;
            color: #000;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 16pt;
            font-family: "TH Sarabun New", thsarabunnew, sans-serif;
        }
        .signature-table td {
            vertical-align: bottom;
            line-height: 1.0;
        }
        .w-subject { width: 44%; }
        .w-code { width: 16%; }
        .w-credit { width: 14%; }
        .w-reg { width: 8.5%; }
        .w-trn { width: 8.5%; }
        .w-note { width: 9%; }

        @media print {
            body { background: #fff; font-size: 14pt; }
            .no-print-bar { display: none !important; }
            .page-container { padding: 0; gap: 0; }
            .sheet {
                width: 100%;
                min-height: auto;
                padding: 10mm 12mm;
                box-shadow: none;
                border-radius: 0;
                page-break-after: always;
            }
            .sheet:last-child { page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="no-print-bar">
        <h1 class="bar-title">
            <span>ใบลงทะเบียนเรียน ({{ $count }} ฉบับ)</span>
        </h1>
        <div class="bar-actions">
            @if (! empty($pdfDownloadUrl))
                <a href="{{ $pdfDownloadUrl }}" class="btn btn-secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    ดาวน์โหลด PDF
                </a>
            @endif
            <button onclick="window.print()" class="btn btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                พิมพ์เอกสาร
            </button>
        </div>
    </div>

    <main class="page-container">
    @foreach ($documents as $document)
        @php
            $st = $document['student'];
        @endphp
        <section class="sheet">
            <table class="header-table">
                <tr>
                    <td style="width: 25%;"></td>
                    <td style="width: 50%;" class="doc-title">
                        ใบลงทะเบียน {{ $document['level_title'] }}
                    </td>
                    <td style="width: 25%;" class="term-title">
                        ภาคเรียนที่ &nbsp;<span class="dotted-line" style="min-width: 32px; text-align: center;">{!! $document['term_no'] !== '' ? e($document['term_no']) : '&nbsp;&nbsp;&nbsp;&nbsp;' !!}</span>&nbsp; / &nbsp;<span class="dotted-line" style="min-width: 44px; text-align: center;">{!! $document['term_year'] !== '' ? e($document['term_year']) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' !!}</span>
                    </td>
                </tr>
            </table>

            <div class="district-title">
                {{ $document['district_center_name'] }}
            </div>

            <table class="student-info-table">
                <tr>
                    <td colspan="2" style="padding: 0 0 3px 0; font-size: 16px;">
                        ชื่อ - สกุล &nbsp;&nbsp;@if ($st['name'] !== '')<span class="dotted-line" style="min-width: 200px; text-align: left; padding: 0 6px;">{{ $st['name'] }}</span>@else .................................................... @endif
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top: 4px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="width: 30%; white-space: nowrap; padding: 0;">
                                    เบอร์โทร &nbsp;&nbsp;@if ($st['phone'] !== '')<span class="dotted-line" style="min-width: 110px; text-align: center;">{{ $st['phone'] }}</span>@else ................................ @endif
                                </td>
                                <td style="width: 44%; white-space: nowrap; padding: 0;">
                                    @php
                                        $isBlank = ! empty($document['is_blank']);
                                        $fbVal = (! empty($st['facebook_display']) && trim($st['facebook_display']) !== '') ? trim($st['facebook_display']) : (! empty($st['facebook']) && trim($st['facebook']) !== '' ? trim($st['facebook']) : ($isBlank ? '' : '-'));
                                    @endphp
                                    Facebook &nbsp;&nbsp;@if ($isBlank && $fbVal === '') ................................................ @elseif ($fbVal !== '-')<span class="dotted-line" style="min-width: 170px; text-align: center;">{{ $fbVal }}</span>@else - @endif
                                </td>
                                <td style="width: 26%; white-space: nowrap; padding: 0;">
                                    @php
                                        $lineVal = (! empty($st['line_id_display']) && trim($st['line_id_display']) !== '') ? trim($st['line_id_display']) : (! empty($st['line_id']) && trim($st['line_id']) !== '' ? trim($st['line_id']) : ($isBlank ? '' : '-'));
                                    @endphp
                                    ID Line &nbsp;&nbsp;@if ($isBlank && $lineVal === '') ................................ @elseif ($lineVal !== '-')<span class="dotted-line" style="min-width: 80px; text-align: center;">{{ $lineVal }}</span>@else - @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top: 4px;">
                        บ้านเลขที่ &nbsp;@if ($st['house_no'] !== '')<span class="dotted-line" style="min-width: 50px; text-align: center;">{{ $st['house_no'] }}</span>@else .................... @endif
                        &nbsp;&nbsp;&nbsp;หมู่ที่ &nbsp;@if ($st['moo'] !== '')<span class="dotted-line" style="min-width: 35px; text-align: center;">{{ $st['moo'] }}</span>@else .......... @endif
                        &nbsp;&nbsp;&nbsp;ตำบล &nbsp;@if ($st['subdistrict'] !== '')<span class="dotted-line" style="min-width: 95px; text-align: center;">{{ $st['subdistrict'] }}</span>@else ........................ @endif
                        &nbsp;&nbsp;&nbsp;อำเภอ &nbsp;@if ($st['district'] !== '')<span class="dotted-line" style="min-width: 95px; text-align: center;">{{ $st['district'] }}</span>@else ........................ @endif
                        &nbsp;&nbsp;&nbsp;จังหวัด &nbsp;@if ($st['province'] !== '')<span class="dotted-line" style="min-width: 105px; text-align: center;">{{ $st['province'] }}</span>@else ........................ @endif
                    </td>
                </tr>
                <tr>
                    <td style="width: 71%; vertical-align: top; padding: 4px 0;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="width: 150px; white-space: nowrap; vertical-align: middle; padding: 4px 0; font-size: 16px;">
                                    รหัสประจำตัวประชาชน
                                </td>
                                <td style="vertical-align: middle; padding: 4px 0;">
                                    <table class="digit-table">
                                        <tr>
                                            <td class="digit-cell">{{ $st['citizen_digits'][0] ?? '' }}</td>
                                            <td class="digit-gap"></td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][1] ?? '' }}</td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][2] ?? '' }}</td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][3] ?? '' }}</td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][4] ?? '' }}</td>
                                            <td class="digit-gap"></td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][5] ?? '' }}</td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][6] ?? '' }}</td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][7] ?? '' }}</td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][8] ?? '' }}</td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][9] ?? '' }}</td>
                                            <td class="digit-gap"></td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][10] ?? '' }}</td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][11] ?? '' }}</td>
                                            <td class="digit-gap"></td>
                                            <td class="digit-cell">{{ $st['citizen_digits'][12] ?? '' }}</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td style="width: 150px; white-space: nowrap; vertical-align: middle; padding: 4px 0; font-size: 16px;">
                                    รหัสประจำตัวนักศึกษา
                                </td>
                                <td style="vertical-align: middle; padding: 4px 0;">
                                    <table class="digit-table">
                                        <tr>
                                            @for ($i = 0; $i < 10; $i++)
                                                <td class="digit-cell">{{ $st['student_code_digits'][$i] ?? '' }}</td>
                                            @endfor
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td style="width: 29%; text-align: right; vertical-align: middle; padding: 4px 0 4px 12px;">
                        @php
                            $groupDisplay = ! empty($st['group']) ? (str_starts_with(trim($st['group']), 'กลุ่ม') ? trim($st['group']) : 'กลุ่ม ' . trim($st['group'])) : 'กลุ่ม ....................................';
                            $subdistrictDisplay = ! empty($st['box_subdistrict']) ? (str_starts_with(trim($st['box_subdistrict']), 'ตำบล') ? trim($st['box_subdistrict']) : 'ตำบล ' . trim($st['box_subdistrict'])) : 'ตำบล ....................................';
                        @endphp
                        <table class="side-box-table" style="margin-left: auto;">
                            <tr>
                                <td style="text-align: center; vertical-align: middle;">
                                    <div style="text-align: center; font-size: 14.5px; line-height: 1.35;">
                                        {{ $groupDisplay }}
                                    </div>
                                    <div style="text-align: center; font-size: 14.5px; line-height: 1.35; margin-top: 6px;">
                                        {{ $subdistrictDisplay }}
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top: 4px;">
                        <table class="credits-table">
                            <tr>
                                <td style="width: 140px; white-space: nowrap;">จำนวนหน่วยกิตที่ได้</td>
                                <td style="width: 220px; white-space: nowrap;">
                                    วิชาบังคับ &nbsp;&nbsp;@if ($st['compulsory_earned'] !== '')<span class="dotted-line" style="min-width: 55px; text-align: center;">{{ $st['compulsory_earned'] }}</span>@else .......... @endif&nbsp;&nbsp; หน่วยกิต
                                </td>
                                <td style="white-space: nowrap;">
                                    วิชาเลือก &nbsp;&nbsp;@if ($st['elective_earned'] !== '')<span class="dotted-line" style="min-width: 55px; text-align: center;">{{ $st['elective_earned'] }}</span>@else .......... @endif&nbsp;&nbsp; หน่วยกิต
                                </td>
                            </tr>
                            <tr>
                                <td style="white-space: nowrap;">เหลือ</td>
                                <td style="white-space: nowrap;">
                                    วิชาบังคับ &nbsp;&nbsp;@if ($st['compulsory_remaining'] !== '')<span class="dotted-line" style="min-width: 55px; text-align: center;">{{ $st['compulsory_remaining'] }}</span>@else .......... @endif&nbsp;&nbsp; หน่วยกิต
                                </td>
                                <td style="white-space: nowrap;">
                                    วิชาเลือก &nbsp;&nbsp;@if ($st['elective_remaining'] !== '')<span class="dotted-line" style="min-width: 55px; text-align: center;">{{ $st['elective_remaining'] }}</span>@else .......... @endif&nbsp;&nbsp; หน่วยกิต
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <table class="main-table">
                <thead>
                    <tr>
                        <th class="w-subject">สาระการเรียนรู้</th>
                        <th class="w-code">รหัสวิชา</th>
                        <th class="w-credit">จำนวนหน่วยกิต</th>
                        <th class="w-reg">ลงทะเบียน</th>
                        <th class="w-trn">เทียบโอน</th>
                        <th class="w-note">หมายเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="6" class="subhead">รายวิชาบังคับ ({{ $document['compulsory_total'] }} หน่วยกิต)</td>
                    </tr>
                    @foreach ($document['compulsory_subjects'] as $sub)
                        <tr>
                            <td class="subject-name">{{ $sub['name'] }}</td>
                            <td class="code-cell">{{ $sub['code'] }}</td>
                            <td class="credit-cell">{{ $sub['credits'] }}</td>
                            <td class="check-cell">
                                @if (! empty($sub['registered']))
                                    <span class="checkmark">&#10003;</span>
                                @endif
                            </td>
                            <td class="check-cell">
                                @if (! empty($sub['transferred']))
                                    <span class="checkmark">&#10003;</span>
                                @endif
                            </td>
                            <td class="check-cell">{{ $sub['remark'] ?? '' }}</td>
                        </tr>
                    @endforeach

                    <tr>
                        <td colspan="6" class="subhead">รายวิชาเลือก ({{ $document['elective_total'] }} หน่วยกิต)</td>
                    </tr>
                    @foreach ($document['elective_subjects'] as $sub)
                        <tr>
                            <td class="subject-name">{{ $sub['name'] }}</td>
                            <td class="code-cell">{{ $sub['code'] }}</td>
                            <td class="credit-cell">{{ $sub['credits'] !== '' ? $sub['credits'] : '' }}</td>
                            <td class="check-cell">
                                @if (! empty($sub['registered']))
                                    <span class="checkmark">&#10003;</span>
                                @endif
                            </td>
                            <td class="check-cell">
                                @if (! empty($sub['transferred']))
                                    <span class="checkmark">&#10003;</span>
                                @endif
                            </td>
                            <td class="check-cell">{{ $sub['remark'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="signature-table">
                <tr>
                    <td style="width: 6%; text-align: right; white-space: nowrap; font-size: 16pt;">ลงชื่อ</td>
                    <td style="width: 34%; text-align: center; white-space: nowrap; font-size: 16pt;">..........................</td>
                    <td style="width: 9%; text-align: left; white-space: nowrap; font-size: 16pt;">นักศึกษา</td>
                    <td style="width: 2%;"></td>
                    <td style="width: 6%; text-align: right; white-space: nowrap; font-size: 16pt;">ลงชื่อ</td>
                    <td style="width: 32%; text-align: center; white-space: nowrap; font-size: 16pt;">..........................</td>
                    <td style="width: 11%; text-align: left; white-space: nowrap; font-size: 16pt;">ครูประจำกลุ่ม</td>
                </tr>
                <tr>
                    <td></td>
                    <td style="text-align: center; white-space: nowrap; padding-top: 1px; font-size: 16pt; font-family: 'TH Sarabun New', thsarabunnew, sans-serif;">
                        @if (! empty($st['name']))
                            ( {{ $st['name'] }} )
                        @else
                            (..........................)
                        @endif
                    </td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td style="text-align: center; white-space: nowrap; padding-top: 1px; font-size: 16pt; font-family: 'TH Sarabun New', thsarabunnew, sans-serif;">
                        @if (! empty($document['teacher_name']))
                            ( {{ $document['teacher_name'] }} )
                        @else
                            (..........................)
                        @endif
                    </td>
                    <td></td>
                </tr>
            </table>
        </section>
    @endforeach
    </main>
</body>
</html>
