<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            color: #0f172a;
            font-family: thsarabunnew, "TH Sarabun New", sans-serif;
            font-size: 14pt;
            line-height: 1.15;
        }
        .sheet {
            width: 100%;
            padding: 0;
            background: #fff;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2mm;
        }
        .header-table td {
            vertical-align: middle;
        }
        .doc-title {
            font-size: 18pt;
            font-weight: bold;
            text-align: center;
        }
        .term-title {
            font-size: 16pt;
            font-weight: bold;
            text-align: right;
            white-space: nowrap;
        }
        .district-title {
            font-size: 17pt;
            font-weight: bold;
            text-align: center;
            margin-bottom: 3mm;
        }
        .dotted-line {
            border-bottom: 0.3mm dotted #334155;
            display: inline-block;
            padding: 0 1.5mm;
            font-weight: bold;
            color: #000;
        }
        .student-info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2mm;
            font-size: 14pt;
        }
        .student-info-table td {
            vertical-align: middle;
            padding: 0.8mm 0;
        }
        .side-box-table {
            border: 0.35mm solid #000;
            border-collapse: collapse;
            width: 54mm;
        }
        .side-box-table td {
            border: none !important;
            padding: 2mm 1.5mm;
            font-size: 13pt;
            line-height: 1.3;
            vertical-align: middle;
            text-align: center;
        }
        .digit-table {
            border-collapse: collapse;
            margin: 0;
            padding: 0;
        }
        .digit-cell {
            width: 4.8mm;
            height: 5.2mm;
            border: 0.35mm solid #000;
            text-align: center;
            vertical-align: middle;
            font-size: 13pt;
            font-weight: bold;
            padding: 0;
            line-height: 1;
        }
        .digit-gap {
            width: 1.5mm;
            border: none !important;
            padding: 0;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5mm;
            font-size: 12.5pt;
        }
        .main-table th, .main-table td {
            border: 0.3mm solid #1e293b;
            padding: 1.1mm 1.5mm;
            vertical-align: middle;
        }
        .main-table th {
            background-color: #fff;
            font-weight: bold;
            text-align: center;
            font-size: 13pt;
        }
        .main-table td.subject-name {
            text-align: left;
            padding-left: 2.5mm;
        }
        .main-table td.code-cell, .main-table td.credit-cell, .main-table td.check-cell {
            text-align: center;
        }
        .main-table td.subhead {
            font-weight: bold;
            background-color: #fff;
            padding: 1mm 2.5mm;
            text-align: left;
        }
        .checkmark {
            font-family: DejaVu Sans, sans-serif, thsarabunnew;
            font-size: 13pt;
            font-weight: bold;
            color: #000;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4.5mm;
            font-size: 16pt;
            font-family: "TH Sarabun New", thsarabunnew, sans-serif;
        }
        .signature-table td {
            vertical-align: bottom;
            line-height: 1.3;
        }
        .w-subject { width: 44%; }
        .w-code { width: 16%; }
        .w-credit { width: 14%; }
        .w-reg { width: 8.5%; }
        .w-trn { width: 8.5%; }
        .w-note { width: 9%; }
    </style>
</head>
<body>
@foreach ($documents as $document)
    @php
        $st = $document['student'];
    @endphp
    <section class="sheet">
        <table class="header-table">
            <tr>
                <td style="width: 25%;"></td>
                <td style="width: 50%; text-align: center;" class="doc-title">
                    ใบลงทะเบียน {{ $document['level_title'] }}
                </td>
                <td style="width: 25%;" class="term-title">
                    ภาคเรียนที่<span class="dotted-line" style="min-width: 8mm; text-align: center;">{!! $document['term_no'] !== '' ? e($document['term_no']) : '&nbsp;&nbsp;&nbsp;&nbsp;' !!}</span>/<span class="dotted-line" style="min-width: 12mm; text-align: center;">{!! $document['term_year'] !== '' ? e($document['term_year']) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' !!}</span>
                </td>
            </tr>
        </table>

        <div class="district-title">
            {{ $document['district_center_name'] }}
        </div>

        <table class="student-info-table">
            <tr>
                <td colspan="2">
                    ชื่อ - สกุล<span class="dotted-line" style="min-width: 155mm;">{!! $st['name'] !== '' ? e($st['name']) : '&nbsp;' !!}</span>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    เบอร์โทร<span class="dotted-line" style="min-width: 38mm; text-align: center;">{!! $st['phone'] !== '' ? e($st['phone']) : '&nbsp;' !!}</span>
                    Facebook<span class="dotted-line" style="min-width: 52mm; text-align: center;">{!! (! empty($st['facebook']) && trim($st['facebook']) !== '') ? e($st['facebook']) : '-' !!}</span>
                    ID Line<span class="dotted-line" style="min-width: 44mm; text-align: center;">{!! (! empty($st['line_id']) && trim($st['line_id']) !== '') ? e($st['line_id']) : '-' !!}</span>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    บ้านเลขที่<span class="dotted-line" style="min-width: 16mm; text-align: center;">{!! $st['house_no'] !== '' ? e($st['house_no']) : '&nbsp;' !!}</span>
                    หมู่<span class="dotted-line" style="min-width: 12mm; text-align: center;">{!! $st['moo'] !== '' ? e($st['moo']) : '&nbsp;' !!}</span>
                    ตำบล<span class="dotted-line" style="min-width: 32mm; text-align: center;">{!! $st['subdistrict'] !== '' ? e($st['subdistrict']) : '&nbsp;' !!}</span>
                    อำเภอ<span class="dotted-line" style="min-width: 32mm; text-align: center;">{!! $st['district'] !== '' ? e($st['district']) : '&nbsp;' !!}</span>
                    จังหวัด<span class="dotted-line" style="min-width: 32mm; text-align: center;">{!! $st['province'] !== '' ? e($st['province']) : '&nbsp;' !!}</span>
                </td>
            </tr>
            <tr>
                <td style="width: 71%; vertical-align: top; padding: 0.5mm 0;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: 36mm; white-space: nowrap; vertical-align: middle; padding: 0.8mm 0; font-size: 14pt;">
                                รหัสประจำตัวประชาชน
                            </td>
                            <td style="vertical-align: middle; padding: 0.8mm 0;">
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
                            <td style="width: 36mm; white-space: nowrap; vertical-align: middle; padding: 0.8mm 0; font-size: 14pt;">
                                รหัสประจำตัวนักศึกษา
                            </td>
                            <td style="vertical-align: middle; padding: 0.8mm 0;">
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
                <td style="width: 29%; text-align: right; vertical-align: middle; padding: 0.5mm 0 0.5mm 2mm;">
                    @php
                        $groupDisplay = ! empty($st['group']) ? (str_starts_with(trim($st['group']), 'กลุ่ม') ? trim($st['group']) : 'กลุ่ม ' . trim($st['group'])) : 'กลุ่ม ....................................';
                        $subdistrictDisplay = ! empty($st['box_subdistrict']) ? (str_starts_with(trim($st['box_subdistrict']), 'ตำบล') ? trim($st['box_subdistrict']) : 'ตำบล ' . trim($st['box_subdistrict'])) : 'ตำบล ....................................';
                    @endphp
                    <table class="side-box-table" style="margin-left: auto;">
                        <tr>
                            <td style="text-align: center; vertical-align: middle;">
                                <div style="text-align: center; font-size: 13pt; line-height: 1.3;">
                                    {{ $groupDisplay }}
                                </div>
                                <div style="text-align: center; font-size: 13pt; line-height: 1.3; margin-top: 1.5mm;">
                                    {{ $subdistrictDisplay }}
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            <tr>
                <td colspan="2" style="padding-top: 1.5mm; font-size: 13.5pt; line-height: 1.3;">
                    จำนวนหน่วยกิตที่ได้&nbsp;&nbsp;วิชาบังคับ<span class="dotted-line" style="min-width: 16mm; text-align: center;">{!! $st['compulsory_earned'] !== '' ? e($st['compulsory_earned']) : '&nbsp;' !!}</span>หน่วยกิต&nbsp;&nbsp;วิชาเลือก<span class="dotted-line" style="min-width: 16mm; text-align: center;">{!! $st['elective_earned'] !== '' ? e($st['elective_earned']) : '&nbsp;' !!}</span>หน่วยกิต<br>
                    เหลือ&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;วิชาบังคับ<span class="dotted-line" style="min-width: 16mm; text-align: center;">{!! $st['compulsory_remaining'] !== '' ? e($st['compulsory_remaining']) : '&nbsp;' !!}</span>หน่วยกิต&nbsp;&nbsp;วิชาเลือก<span class="dotted-line" style="min-width: 16mm; text-align: center;">{!! $st['elective_remaining'] !== '' ? e($st['elective_remaining']) : '&nbsp;' !!}</span>หน่วยกิต
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
                    <td colspan="6" class="subhead">รายวิชาบังคับ({{ $document['compulsory_total'] }} หน่วยกิต)</td>
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
                    <td colspan="6" class="subhead">รายวิชาเลือก({{ $document['elective_total'] }} หน่วยกิต)</td>
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
                <td style="width: 34%; text-align: center; white-space: nowrap; font-size: 16pt;">..................................</td>
                <td style="width: 9%; text-align: left; white-space: nowrap; font-size: 16pt;">นักศึกษา</td>
                <td style="width: 2%;"></td>
                <td style="width: 6%; text-align: right; white-space: nowrap; font-size: 16pt;">ลงชื่อ</td>
                <td style="width: 32%; text-align: center; white-space: nowrap; font-size: 16pt;">..................................</td>
                <td style="width: 11%; text-align: left; white-space: nowrap; font-size: 16pt;">ครูประจำกลุ่ม</td>
            </tr>
            <tr>
                <td></td>
                <td style="text-align: center; white-space: nowrap; padding-top: 1.5mm; font-size: 16pt; font-family: thsarabunnew, sans-serif;">
                    @if (! empty($st['name']))
                        ( {{ $st['name'] }} )
                    @else
                        (..................................)
                    @endif
                </td>
                <td></td>
                <td></td>
                <td></td>
                <td style="text-align: center; white-space: nowrap; padding-top: 1.5mm; font-size: 16pt; font-family: thsarabunnew, sans-serif;">
                    @if (! empty($document['teacher_name']))
                        ( {{ $document['teacher_name'] }} )
                    @else
                        (..................................)
                    @endif
                </td>
                <td></td>
            </tr>
        </table>
    </section>
    @if (! $loop->last)
        <pagebreak />
    @endif
@endforeach
</body>
</html>
