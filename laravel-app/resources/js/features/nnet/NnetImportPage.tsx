import { useState, useRef, type ChangeEvent, type DragEvent } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import * as XLSX from 'xlsx';
import Swal from 'sweetalert2';
import {
    ArrowRight,
    CheckCircle,
    FileXls,
    UploadSimple,
    Warning,
    Trash,
    ChartLineUp,
    ListNumbers,
    Users,
    Sparkle,
    ArrowLeft,
} from '@phosphor-icons/react';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { Button } from '../../components/MaterialUI';
import { StatusBadge } from '../../components/StatusBadge';
import { sendFeatureData } from '../api';

interface ParsedNnetRow {
    no: number;
    seat: string;
    citizen: string;
    name: string;
    total: number | string | null;
    scores: (number | string | null)[];
    levels: (string | null)[];
}

const LEVEL_OPTIONS = [
    { value: 1, label: 'ประถมศึกษา' },
    { value: 2, label: 'มัธยมศึกษาตอนต้น' },
    { value: 3, label: 'มัธยมศึกษาตอนปลาย' },
];

const STANDARD_SUBJECT_NAMES = [
    'ทักษะการเรียนรู้',
    'ความรู้พื้นฐาน',
    'การประกอบอาชีพ',
    'ทักษะการดำเนินชีวิต',
    'การพัฒนาสังคม',
];

export function NnetImportPage() {
    const navigate = useNavigate();
    const fileInputRef = useRef<HTMLInputElement>(null);

    const [educationLevel, setEducationLevel] = useState<number>(2);
    const [academicYear, setAcademicYear] = useState<string>('2569');
    const [round, setRound] = useState<number>(1);

    const [fileName, setFileName] = useState<string>('');
    const [detectedLevel, setDetectedLevel] = useState<string>('');
    const [detectedSubjectCodes, setDetectedSubjectCodes] = useState<string[]>(['411', '412', '413', '414', '415']);
    const [parsedRows, setParsedRows] = useState<ParsedNnetRow[]>([]);
    const [isDragOver, setIsDragOver] = useState<boolean>(false);
    const [isImporting, setIsImporting] = useState<boolean>(false);
    const [validationMessage, setValidationMessage] = useState<{ type: 'warn' | 'success' | 'error'; text: string } | null>(null);

    const detectLevelFromRows = (rows: any[][]): string => {
        const textSample = rows.slice(0, 12).flat().filter(Boolean).join(' ');
        if (textSample.includes('ประถมศึกษา')) return 'ประถมศึกษา';
        if (textSample.includes('มัธยมศึกษาตอนต้น') || textSample.includes('ม.ต้น')) return 'มัธยมศึกษาตอนต้น';
        if (textSample.includes('มัธยมศึกษาตอนปลาย') || textSample.includes('ม.ปลาย')) return 'มัธยมศึกษาตอนปลาย';
        return '';
    };

    const isFiniteNumber = (val: any): boolean => typeof val === 'number' && Number.isFinite(val);

    const handleProcessWorkbook = (buffer: ArrayBuffer, name: string) => {
        try {
            const wb = XLSX.read(buffer, { type: 'array' });
            const firstSheetName = wb.SheetNames[0];
            if (!firstSheetName) {
                throw new Error('ไม่พบแผ่นงาน (Worksheet) ในไฟล์ Excel');
            }

            const ws = wb.Sheets[firstSheetName];
            const rows = XLSX.utils.sheet_to_json<any[]>(ws, { header: 1, raw: true, defval: null });

            if (!rows || rows.length < 5) {
                throw new Error('โครงสร้างไฟล์ไม่ถูกต้องหรือไม่พบข้อมูลผลสอบ');
            }

            const detected = detectLevelFromRows(rows);
            setDetectedLevel(detected);
            setFileName(name);

            const selectedLabel = LEVEL_OPTIONS.find((l) => l.value === educationLevel)?.label ?? '';

            if (detected && detected !== selectedLabel) {
                setValidationMessage({
                    type: 'warn',
                    text: `ไฟล์นี้ตรวจพบว่าเป็น "${detected}" แต่คุณเลือกระดับชั้นเป็น "${selectedLabel}" กรุณาตรวจสอบให้ตรงกันก่อนนำเข้า`,
                });
            } else {
                setValidationMessage({
                    type: 'success',
                    text: 'ตรวจสอบโครงสร้างไฟล์สำเร็จ ข้อมูลพร้อมสำหรับการนำเข้า',
                });
            }

            // Detect subject codes from header rows
            let codes: string[] = [];
            for (let i = 0; i < Math.min(rows.length, 12); i++) {
                const r = rows[i];
                if (Array.isArray(r)) {
                    const potential = [r[5], r[6], r[7], r[8], r[9]].map((v) => String(v ?? '').trim());
                    if (potential.every((v) => /^\d{3}$/.test(v))) {
                        codes = potential;
                        break;
                    }
                }
            }

            if (codes.length === 0) {
                codes = educationLevel === 1 ? ['111', '112', '113', '114', '115']
                    : educationLevel === 2 ? ['211', '212', '213', '214', '215']
                    : ['411', '412', '413', '414', '415'];
            }
            setDetectedSubjectCodes(codes);

            const extracted: ParsedNnetRow[] = [];
            for (const r of rows) {
                if (typeof r[0] === 'string' && r[0].startsWith('ทั้งหมด')) {
                    break;
                }

                // Student row: r[0] is number, r[3] is student name, r[4] is total score or '-'
                if (isFiniteNumber(r[0]) && typeof r[3] === 'string' && (isFiniteNumber(r[4]) || r[4] === '-' || r[4] === null)) {
                    extracted.push({
                        no: r[0],
                        seat: r[1] ? String(r[1]).trim() : '',
                        citizen: r[2] ? String(r[2]).trim().replace(/\D+/g, '') : '',
                        name: String(r[3]).trim(),
                        total: r[4],
                        scores: [r[5], r[6], r[7], r[8], r[9]],
                        levels: [r[10], r[11], r[12], r[13], r[14]],
                    });
                }
            }

            if (extracted.length === 0) {
                throw new Error('ไม่พบข้อมูลผลคะแนนรายบุคคลในไฟล์ กรุณาตรวจสอบว่าเป็นรายงาน "ผลการทดสอบ N-NET รายบุคคล"');
            }

            setParsedRows(extracted);
        } catch (err: any) {
            setValidationMessage({
                type: 'error',
                text: err.message || 'เกิดข้อผิดพลาดในการอ่านไฟล์ Excel',
            });
            setParsedRows([]);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    };

    const handleFileChange = (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (event) => {
            const buffer = event.target?.result as ArrayBuffer;
            if (buffer) {
                handleProcessWorkbook(buffer, file.name);
            }
        };
        reader.readAsArrayBuffer(file);
    };

    const handleDrop = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setIsDragOver(false);
        const file = e.dataTransfer.files?.[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (event) => {
            const buffer = event.target?.result as ArrayBuffer;
            if (buffer) {
                handleProcessWorkbook(buffer, file.name);
            }
        };
        reader.readAsArrayBuffer(file);
    };

    const handleClear = () => {
        setParsedRows([]);
        setFileName('');
        setDetectedLevel('');
        setValidationMessage(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleImportSubmit = async () => {
        if (parsedRows.length === 0) return;

        const selectedLabel = LEVEL_OPTIONS.find((l) => l.value === educationLevel)?.label ?? '';

        if (detectedLevel && detectedLevel !== selectedLabel) {
            const result = await Swal.fire({
                title: 'ยืนยันการนำเข้า?',
                html: `ไฟล์นี้ตรวจพบระดับ <b>${detectedLevel}</b> แต่คุณเลือกระดับชั้น <b>${selectedLabel}</b><br/>ต้องการดำเนินการต่อหรือไม่?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ดำเนินการต่อ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#4f46e5',
            });
            if (!result.isConfirmed) return;
        }

        setIsImporting(true);

        try {
            const subjectNamesMap: Record<string, string> = {};
            detectedSubjectCodes.forEach((code, idx) => {
                subjectNamesMap[code] = STANDARD_SUBJECT_NAMES[idx] ?? `สาระที่ ${code}`;
            });

            const payload = {
                education_level: educationLevel,
                level_name: selectedLabel,
                academic_year: academicYear,
                round,
                subject_codes: detectedSubjectCodes,
                subject_names: subjectNamesMap,
                rows: parsedRows.map((r) => ({
                    seat_no: r.seat,
                    citizen_id: r.citizen,
                    name: r.name,
                    total_score: r.total,
                    scores: r.scores,
                    levels: r.levels,
                })),
            };

            const response = await sendFeatureData<{
                total_processed: number;
                imported: number;
                updated: number;
                matched_students: number;
                unmatched_students: number;
            }>('/api/v1/nnet/import', 'POST', payload);

            const data = response.data;

            await Swal.fire({
                title: 'นำเข้าข้อมูล N-NET สำเร็จ!',
                html: `
                    <div class="text-left space-y-2 text-sm">
                        <p class="text-slate-700">ประมวลผลข้อมูลทั้งหมด: <b>${data.total_processed}</b> คน</p>
                        <p class="text-emerald-700">✓ เพิ่มรายการใหม่: <b>${data.imported}</b> คน</p>
                        <p class="text-blue-700">✓ ปรับปรุงข้อมูลเดิม: <b>${data.updated}</b> คน</p>
                        <div class="mt-3 p-3 bg-slate-50 rounded-xl border border-slate-200">
                            <p class="text-slate-900 font-semibold">การเชื่อมโยงกับฐานข้อมูลนักศึกษา:</p>
                            <p class="text-emerald-600">✓ ตรงกับรหัสนักศึกษาในระบบ: <b>${data.matched_students}</b> คน</p>
                            <p class="text-amber-600">• ไม่พบในทะเบียนปัจจุบัน: <b>${data.unmatched_students}</b> คน</p>
                        </div>
                    </div>
                `,
                icon: 'success',
                confirmButtonText: 'ดูรายงานผล N-NET',
                showCancelButton: true,
                cancelButtonText: 'อยู่หน้านี้ต่อ',
                confirmButtonColor: '#4f46e5',
            }).then((res) => {
                if (res.isConfirmed) {
                    navigate('/n-net/report');
                } else {
                    handleClear();
                }
            });
        } catch (err: any) {
            Swal.fire({
                title: 'ไม่สามารถนำเข้าข้อมูลได้',
                text: err.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล',
                icon: 'error',
                confirmButtonColor: '#4f46e5',
            });
        } finally {
            setIsImporting(false);
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                title="นำเข้าผล N-NET"
                subtitle="เลือกระดับชั้นก่อนอัปโหลดไฟล์ผลสอบ ระบบจะตรวจรูปแบบและอ่านคะแนน 5 สาระอัตโนมัติ"
                actions={(
                    <div className="flex items-center gap-2">
                        <Link to="/n-net/report">
                            <Button appearance="secondary" icon={<ChartLineUp size={18} />}>
                                ไปยังรายงานผล N-NET
                            </Button>
                        </Link>
                    </div>
                )}
            />

            {/* Config & Dropzone Panel */}
            <Panel title="เงื่อนไขและไฟล์นำเข้า" className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label className="block text-xs font-bold text-slate-700 mb-1.5">
                            ระดับชั้น *
                        </label>
                        <select
                            value={educationLevel}
                            onChange={(e) => setEducationLevel(Number(e.target.value))}
                            className="w-full rounded-xl border border-slate-300 p-2.5 text-sm bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none"
                        >
                            {LEVEL_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-700 mb-1.5">
                            ปีการศึกษา *
                        </label>
                        <input
                            type="text"
                            value={academicYear}
                            onChange={(e) => setAcademicYear(e.target.value)}
                            placeholder="เช่น 2569"
                            className="w-full rounded-xl border border-slate-300 p-2.5 text-sm bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none"
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-700 mb-1.5">
                            ครั้งที่ *
                        </label>
                        <select
                            value={round}
                            onChange={(e) => setRound(Number(e.target.value))}
                            className="w-full rounded-xl border border-slate-300 p-2.5 text-sm bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none"
                        >
                            <option value={1}>ครั้งที่ 1</option>
                            <option value={2}>ครั้งที่ 2</option>
                        </select>
                    </div>
                </div>

                {/* Dropzone */}
                <input
                    type="file"
                    ref={fileInputRef}
                    onChange={handleFileChange}
                    accept=".xlsx,.xls"
                    className="hidden"
                />

                <div
                    onClick={() => fileInputRef.current?.click()}
                    onDragOver={(e) => {
                        e.preventDefault();
                        setIsDragOver(true);
                    }}
                    onDragLeave={() => setIsDragOver(false)}
                    onDrop={handleDrop}
                    className={`border-2 border-dashed rounded-2xl p-8 text-center cursor-pointer transition-all duration-200 ${
                        isDragOver
                            ? 'border-indigo-500 bg-indigo-50/60'
                            : 'border-indigo-200 bg-indigo-50/20 hover:bg-indigo-50/40'
                    }`}
                >
                    <div className="flex flex-col items-center justify-center space-y-3">
                        <div className="w-14 h-14 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center shadow-sm">
                            <UploadSimple size={28} weight="bold" />
                        </div>
                        <div>
                            <strong className="block text-base font-bold text-slate-900">
                                ลากไฟล์ Excel มาวาง หรือคลิกเพื่อเลือกไฟล์
                            </strong>
                            <span className="text-xs text-slate-500 mt-1 block">
                                รองรับ .xlsx / .xls • รูปแบบรายงาน “ฉบับที่ 1 - ผลการทดสอบ N-NET รายบุคคล”
                            </span>
                        </div>
                    </div>
                </div>

                {/* Validation Message */}
                {validationMessage && (
                    <div
                        className={`rounded-xl p-3.5 text-sm flex items-start gap-2.5 ${
                            validationMessage.type === 'warn'
                                ? 'bg-amber-50 text-amber-800 border border-amber-200'
                                : validationMessage.type === 'success'
                                ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
                                : 'bg-red-50 text-red-800 border border-red-200'
                        }`}
                    >
                        {validationMessage.type === 'warn' && <Warning size={20} className="shrink-0 text-amber-600 mt-0.5" />}
                        {validationMessage.type === 'success' && <CheckCircle size={20} className="shrink-0 text-emerald-600 mt-0.5" />}
                        {validationMessage.type === 'error' && <Warning size={20} className="shrink-0 text-red-600 mt-0.5" />}
                        <div>{validationMessage.text}</div>
                    </div>
                )}

                {/* File Meta Tags */}
                {parsedRows.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
                            <FileXls size={15} /> ไฟล์: {fileName}
                        </span>
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700">
                            ตรวจพบระดับ: {detectedLevel || 'ไม่ระบุ'}
                        </span>
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700">
                            จำนวน: {parsedRows.length} คน
                        </span>
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-purple-50 text-purple-700">
                            รหัสสาระ: {detectedSubjectCodes.join(', ')}
                        </span>
                    </div>
                )}

                {/* Preview Table */}
                {parsedRows.length > 0 && (
                    <div className="space-y-2 pt-2">
                        <div className="flex items-center justify-between text-xs text-slate-500 font-semibold px-1">
                            <span>ตัวอย่างข้อมูลในไฟล์ (แสดง 10 แถวแรกจากทั้งหมด {parsedRows.length} แถว)</span>
                            <span>{parsedRows.filter((r) => isFiniteNumber(r.total)).length} คนมีคะแนน</span>
                        </div>
                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                            <table className="w-full text-left text-xs whitespace-nowrap">
                                <thead>
                                    <tr className="bg-slate-50 border-b border-slate-200 text-slate-700 font-bold">
                                        <th className="p-2.5 text-center w-12">ลำดับ</th>
                                        <th className="p-2.5">เลขที่นั่งสอบ</th>
                                        <th className="p-2.5">เลขประจำตัวประชาชน</th>
                                        <th className="p-2.5">ชื่อ - สกุล</th>
                                        <th className="p-2.5 text-center font-bold text-indigo-700 bg-indigo-50/50">คะแนนรวม</th>
                                        {detectedSubjectCodes.map((code, idx) => (
                                            <th key={code} className="p-2.5 text-center">
                                                {code} ({STANDARD_SUBJECT_NAMES[idx] ?? `สาระ ${code}`})
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {parsedRows.slice(0, 10).map((r) => (
                                        <tr key={r.no} className="hover:bg-slate-50/60">
                                            <td className="p-2.5 text-center font-mono text-slate-500">{r.no}</td>
                                            <td className="p-2.5 font-mono text-slate-700">{r.seat || '-'}</td>
                                            <td className="p-2.5 font-mono text-slate-700">{r.citizen || '-'}</td>
                                            <td className="p-2.5 font-bold text-slate-900">{r.name}</td>
                                            <td className="p-2.5 text-center font-bold text-indigo-700 bg-indigo-50/30">
                                                {isFiniteNumber(r.total) ? Number(r.total).toFixed(2) : '-'}
                                            </td>
                                            {r.scores.map((sc, scIdx) => (
                                                <td key={scIdx} className="p-2.5 text-center font-mono text-slate-600">
                                                    {isFiniteNumber(sc) ? Number(sc).toFixed(2) : '-'}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Actions */}
                <div className="flex flex-wrap items-center justify-end gap-3 pt-3 border-t border-slate-100">
                    <Button
                        appearance="subtle"
                        icon={<Trash size={16} />}
                        onClick={handleClear}
                        disabled={parsedRows.length === 0 || isImporting}
                    >
                        ล้างข้อมูล
                    </Button>
                    <Button
                        appearance="primary"
                        icon={<UploadSimple size={18} weight="bold" />}
                        onClick={handleImportSubmit}
                        disabled={parsedRows.length === 0 || isImporting}
                    >
                        {isImporting ? 'กำลังนำเข้าข้อมูล...' : `นำเข้าข้อมูล N-NET (${parsedRows.length} รายการ)`}
                    </Button>
                </div>
            </Panel>

            {/* Rules card */}
            <Panel title="กติกาและข้อกำหนดการนำเข้า" className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="p-4 rounded-xl bg-blue-50/70 border border-blue-100 text-xs text-blue-900 space-y-1.5">
                        <strong className="block font-bold text-blue-950 text-sm">การตรวจสอบตัวตน</strong>
                        <p>ระบบใช้ “เลขประจำตัวประชาชน” 13 หลัก เป็นตัวอ้างอิงนักศึกษา และจะไม่สร้างข้อมูลซ้ำเมื่ออัปโหลดไฟล์เดิมในภาคและรอบเดียวกัน</p>
                    </div>
                    <div className="p-4 rounded-xl bg-purple-50/70 border border-purple-100 text-xs text-purple-900 space-y-1.5">
                        <strong className="block font-bold text-purple-950 text-sm">การเชื่อมโยงข้อมูลนักศึกษา</strong>
                        <p>ระบบจะค้นหาและจับคู่กับรหัสนักศึกษาและกลุ่มเรียนในระบบโดยอัตโนมัติ เพื่อให้คะแนนไปปรากฏในข้อมูลการเรียนของเด็กตรงคน</p>
                    </div>
                    <div className="p-4 rounded-xl bg-amber-50/70 border border-amber-100 text-xs text-amber-900 space-y-1.5">
                        <strong className="block font-bold text-amber-950 text-sm">สถานะขาดสอบ / ไม่มีคะแนน</strong>
                        <p>แถวที่คะแนนรวมเป็น “-” หรือว่าง จะถูกจัดเป็น “ไม่มีคะแนน/ขาดสอบ” โดยระบบจะไม่นำคะแนนไปคำนวณในค่าเฉลี่ย</p>
                    </div>
                </div>
            </Panel>
        </div>
    );
}
