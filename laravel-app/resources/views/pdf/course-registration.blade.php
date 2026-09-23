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
        .side-box {
            border: 0.35mm solid #0f172a;
            border-radius: 1mm;
            padding: 1.5mm 2.5mm;
            width: 52mm;
            font-size: 13.5pt;
            line-height: 1.25;
        }
        .digit-table {
            border-collapse: collapse;
            display: inline-table;
            vertical-align: middle;
            margin-left: 1.5mm;
        }
        .digit-cell {
            width: 4.8mm;
            height: 5.2mm;
            border: 0.3mm solid #1e293b;
            text-align: center;
            vertical-align: middle;
            font-size: 13pt;
            font-weight: bold;
            padding: 0;
            line-height: 1;
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
            font-size: 13.5pt;
        }
        .signature-table td {
            text-align: center;
            vertical-align: top;
            width: 50%;
            line-height: 1.4;
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
@php
    $escape = static fn (mixed $v): string => e((string) $v);
@endphp
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
                    ภาคเรียนที่<span class="dotted-line" style="min-width: 8mm; text-align: center;">{{ $document['term_no'] ?: '&nbsp;&nbsp;&nbsp;&nbsp;' }}</span>/<span class="dotted-line" style="min-width: 12mm; text-align: center;">{{ $document['term_year'] ?: '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' }}</span>
                </td>
            </tr>
        </table>

        <div class="district-title">
            {{ $document['district_center_name'] }}
        </div>

        <table class="student-info-table">
            <tr>
                <td colspan="2">
                    ชื่อ - สกุล<span class="dotted-line" style="min-width: 155mm;">&nbsp;{{ $st['name'] ?: '&nbsp;' }}&nbsp;</span>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    เบอร์โทร<span class="dotted-line" style="min-width: 38mm; text-align: center;">&nbsp;{{ $st['phone'] ?: '&nbsp;' }}&nbsp;</span>
                    Facebook<span class="dotted-line" style="min-width: 52mm; text-align: center;">&nbsp;{{ $st['facebook'] ?: '&nbsp;' }}&nbsp;</span>
                    ID Line<span class="dotted-line" style="min-width: 44mm; text-align: center;">&nbsp;{{ $st['line_id'] ?: '&nbsp;' }}&nbsp;</span>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    บ้านเลขที่<span class="dotted-line" style="min-width: 16mm; text-align: center;">&nbsp;{{ $st['house_no'] ?: '&nbsp;' }}&nbsp;</span>
                    หมู่<span class="dotted-line" style="min-width: 12mm; text-align: center;">&nbsp;{{ $st['moo'] ?: '&nbsp;' }}&nbsp;</span>
                    ตำบล<span class="dotted-line" style="min-width: 32mm; text-align: center;">&nbsp;{{ $st['subdistrict'] ?: '&nbsp;' }}&nbsp;</span>
                    อำเภอ<span class="dotted-line" style="min-width: 32mm; text-align: center;">&nbsp;{{ $st['district'] ?: '&nbsp;' }}&nbsp;</span>
                    จังหวัด<span class="dotted-line" style="min-width: 32mm; text-align: center;">&nbsp;{{ $st['province'] ?: '&nbsp;' }}&nbsp;</span>
                </td>
            </tr>
            <tr>
                <td style="width: 70%; vertical-align: top; padding-top: 1mm;">
                    <div style="margin-bottom: 1.5mm;">
                        รหัสประจำตัวประชาชน
                        <table class="digit-table">
                            <tr>
                                @foreach ($st['citizen_digits'] as $d)
                                    <td class="digit-cell">{{ $d !== '' ? $d : '&nbsp;' }}</td>
                                @endforeach
                            </tr>
                        </table>
                    </div>
                    <div>
                        รหัสประจำตัวนักศึกษา
                        <table class="digit-table" style="margin-left: 2.8mm;">
                            <tr>
                                @foreach ($st['student_code_digits'] as $d)
                                    <td class="digit-cell">{{ $d !== '' ? $d : '&nbsp;' }}</td>
                                @endforeach
                            </tr>
                        </table>
                    </div>
                </td>
                <td style="width: 30%; text-align: right; vertical-align: top;">
                    <div class="side-box" style="float: right; text-align: left;">
                        กลุ่ม<span class="dotted-line" style="min-width: 38mm;">&nbsp;{{ $st['group'] ?: '&nbsp;' }}&nbsp;</span><br>
                        ตำบล<span class="dotted-line" style="min-width: 36mm;">&nbsp;{{ $st['box_subdistrict'] ?: '&nbsp;' }}&nbsp;</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="2" style="padding-top: 1.5mm;">
                    จำนวนหน่วยกิตที่ได้&nbsp;&nbsp;วิชาบังคับ<span class="dotted-line" style="min-width: 18mm; text-align: center;">&nbsp;{{ $st['compulsory_earned'] !== '' ? $st['compulsory_earned'] : '&nbsp;' }}&nbsp;</span>หน่วยกิต&nbsp;&nbsp;วิชาเลือก<span class="dotted-line" style="min-width: 18mm; text-align: center;">&nbsp;{{ $st['elective_earned'] !== '' ? $st['elective_earned'] : '&nbsp;' }}&nbsp;</span>หน่วยกิต<br>
                    เหลือ&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;วิชาบังคับ<span class="dotted-line" style="min-width: 18mm; text-align: center;">&nbsp;{{ $st['compulsory_remaining'] !== '' ? $st['compulsory_remaining'] : '&nbsp;' }}&nbsp;</span>หน่วยกิต&nbsp;&nbsp;วิชาเลือก<span class="dotted-line" style="min-width: 18mm; text-align: center;">&nbsp;{{ $st['elective_remaining'] !== '' ? $st['elective_remaining'] : '&nbsp;' }}&nbsp;</span>หน่วยกิต
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
                <td>
                    ลงชื่อ...................................................นักศึกษา<br>
                    (...................................................)
                </td>
                <td>
                    ลงชื่อ...................................................ครูศูนย์การเรียนรู้<br>
                    (...................................................)
                </td>
            </tr>
        </table>
    </section>
    @if (! $loop->last)
        <pagebreak />
    @endif
@endforeach
</body>
</html>
