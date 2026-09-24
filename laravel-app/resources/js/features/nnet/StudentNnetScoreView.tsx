import { useState } from 'react';
import {
    ArrowsClockwise,
    CheckCircle,
    GraduationCap,
    IdentificationCard,
    Info,
    Medal,
    Printer,
    Trophy,
    Warning,
} from '@phosphor-icons/react';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { Button, Card } from '../../components/MaterialUI';
import { StatusBadge } from '../../components/StatusBadge';
import { QuerySkeleton, QueryError } from '../../components/QueryState';
import type { NnetRecord } from './NnetReportPage';

export interface StudentNnetScoreViewProps {
    items: NnetRecord[];
    isLoading: boolean;
    isError: boolean;
    onRetry: () => void;
    studentProfile?: {
        name: string;
        code: string;
        level: string;
        group: string;
    } | null;
    currentUserName?: string;
    currentUserCode?: string;
}

const STANDARD_SUBJECT_NAMES = [
    'ทักษะการเรียนรู้',
    'ความรู้พื้นฐาน',
    'การประกอบอาชีพ',
    'ทักษะการดำเนินชีวิต',
    'การพัฒนาสังคม',
];

interface ScoreEvaluation {
    label: string;
    bgClass: string;
    badgeBg: string;
    badgeText: string;
    borderClass: string;
    rangeLabel: string;
}

function getScoreEvaluation(score: number | null | undefined, importedLevel?: string | null): ScoreEvaluation {
    if (score === null || score === undefined || Number.isNaN(score)) {
        return {
            label: importedLevel || 'ไม่มีข้อมูล',
            bgClass: 'bg-slate-400',
            badgeBg: 'bg-slate-100',
            badgeText: 'text-slate-600',
            borderClass: 'border-slate-200',
            rangeLabel: 'ไม่มีข้อมูลคะแนน',
        };
    }

    const cleanLevel = importedLevel?.trim();
    if (cleanLevel === 'ดีเยี่ยม') {
        return {
            label: 'ดีเยี่ยม',
            bgClass: 'bg-emerald-500',
            badgeBg: 'bg-emerald-50 text-emerald-700 border-emerald-200',
            badgeText: 'text-emerald-700',
            borderClass: 'border-emerald-200',
            rangeLabel: 'ช่วงคะแนน 60 - 100 คะแนน (ผลการทดสอบอยู่ในระดับดีเยี่ยม)',
        };
    }
    if (cleanLevel === 'ดี') {
        return {
            label: 'ดี',
            bgClass: 'bg-sky-500',
            badgeBg: 'bg-sky-50 text-sky-700 border-sky-200',
            badgeText: 'text-sky-700',
            borderClass: 'border-sky-200',
            rangeLabel: 'ช่วงคะแนน 50 - 59.99 คะแนน (ผลการทดสอบอยู่ในเกณฑ์ดี)',
        };
    }
    if (cleanLevel === 'ผ่าน') {
        return {
            label: 'ผ่าน',
            bgClass: 'bg-indigo-500',
            badgeBg: 'bg-indigo-50 text-indigo-700 border-indigo-200',
            badgeText: 'text-indigo-700',
            borderClass: 'border-indigo-200',
            rangeLabel: 'ช่วงคะแนน 40 - 49.99 คะแนน (ผ่านเกณฑ์มาตรฐานขั้นต่ำ)',
        };
    }
    if (cleanLevel === 'ควรพัฒนา') {
        return {
            label: 'ควรพัฒนา',
            bgClass: 'bg-rose-500',
            badgeBg: 'bg-rose-50 text-rose-700 border-rose-200',
            badgeText: 'text-rose-700',
            borderClass: 'border-rose-200',
            rangeLabel: 'ช่วงคะแนน ต่ำกว่า 40 คะแนน (ควรได้รับการพัฒนาการเรียนรู้)',
        };
    }

    // Benchmark threshold fallbacks
    if (score >= 60) {
        return {
            label: 'ดีเยี่ยม',
            bgClass: 'bg-emerald-500',
            badgeBg: 'bg-emerald-50 text-emerald-700 border-emerald-200',
            badgeText: 'text-emerald-700',
            borderClass: 'border-emerald-200',
            rangeLabel: 'ช่วงคะแนน 60 - 100 คะแนน (ผลการทดสอบอยู่ในระดับดีเยี่ยม)',
        };
    }
    if (score >= 50) {
        return {
            label: 'ดี',
            bgClass: 'bg-sky-500',
            badgeBg: 'bg-sky-50 text-sky-700 border-sky-200',
            badgeText: 'text-sky-700',
            borderClass: 'border-sky-200',
            rangeLabel: 'ช่วงคะแนน 50 - 59.99 คะแนน (ผลการทดสอบอยู่ในเกณฑ์ดี)',
        };
    }
    if (score >= 40) {
        return {
            label: 'ผ่าน',
            bgClass: 'bg-indigo-500',
            badgeBg: 'bg-indigo-50 text-indigo-700 border-indigo-200',
            badgeText: 'text-indigo-700',
            borderClass: 'border-indigo-200',
            rangeLabel: 'ช่วงคะแนน 40 - 49.99 คะแนน (ผ่านเกณฑ์มาตรฐานขั้นต่ำ)',
        };
    }

    return {
        label: 'ควรพัฒนา',
        bgClass: 'bg-rose-500',
        badgeBg: 'bg-rose-50 text-rose-700 border-rose-200',
        badgeText: 'text-rose-700',
        borderClass: 'border-rose-200',
        rangeLabel: 'ช่วงคะแนน ต่ำกว่า 40 คะแนน (ควรได้รับการพัฒนาการเรียนรู้)',
    };
}

export function StudentNnetScoreView({
    items,
    isLoading,
    isError,
    onRetry,
    studentProfile,
    currentUserName,
    currentUserCode,
}: StudentNnetScoreViewProps) {
    const [selectedRecordId, setSelectedRecordId] = useState<number | null>(null);

    // Print trigger
    const handlePrint = () => {
        window.print();
    };

    if (isLoading) {
        return (
            <div className="space-y-6">
                <PageHeader
                    title="รายงานผล N-NET ของตนเอง"
                    description="ผลการทดสอบทางการศึกษาระดับชาติด้านการศึกษานอกระบบโรงเรียน (N-NET) รายบุคคล"
                    icon={Medal}
                />
                <QuerySkeleton rows={6} />
            </div>
        );
    }

    if (isError) {
        return (
            <div className="space-y-6">
                <PageHeader
                    title="รายงานผล N-NET ของตนเอง"
                    description="ผลการทดสอบทางการศึกษาระดับชาติด้านการศึกษานอกระบบโรงเรียน (N-NET) รายบุคคล"
                    icon={Medal}
                />
                <QueryError onRetry={onRetry} />
            </div>
        );
    }

    // EMPTY STATE: When the student has no N-NET records yet
    if (items.length === 0) {
        return (
            <div className="space-y-6">
                <PageHeader
                    title="รายงานผล N-NET ของตนเอง"
                    description="ผลการทดสอบทางการศึกษาระดับชาติด้านการศึกษานอกระบบโรงเรียน (N-NET) รายบุคคล"
                    icon={Medal}
                    actions={(
                        <Button appearance="subtle" icon={<ArrowsClockwise size={16} />} onClick={onRetry}>
                            รีเฟรชข้อมูล
                        </Button>
                    )}
                />

                <Card className="p-6 sm:p-10 rounded-3xl bg-white border border-slate-200/80 shadow-sm text-center max-w-3xl mx-auto space-y-6">
                    <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-indigo-50 text-indigo-600 ring-8 ring-indigo-50/50">
                        <GraduationCap size={44} weight="duotone" />
                    </div>

                    <div className="space-y-2 max-w-lg mx-auto">
                        <h3 className="text-xl font-bold text-slate-900">
                            ยังไม่มีข้อมูลผลการทดสอบ N-NET
                        </h3>
                        <p className="text-sm text-slate-500 leading-relaxed">
                            ระบบยังไม่พบข้อมูลคะแนนการทดสอบทางการศึกษาระดับชาติด้านการศึกษานอกระบบโรงเรียน (N-NET) สำหรับบัญชีผู้เรียนนี้
                        </p>
                    </div>

                    {/* Student Account Verification Card */}
                    <div className="p-4 sm:p-5 rounded-2xl bg-slate-50 border border-slate-200/80 text-left space-y-3">
                        <div className="flex items-center gap-2 text-xs font-bold text-slate-700">
                            <IdentificationCard size={18} className="text-indigo-600" />
                            <span>ข้อมูลบัญชีนักศึกษาปัจจุบัน</span>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <div className="bg-white p-3 rounded-xl border border-slate-200/60 shadow-xs">
                                <span className="text-slate-400 block text-[11px]">ชื่อ - สกุล</span>
                                <strong className="text-slate-800 text-sm block truncate">
                                    {studentProfile?.name || currentUserName || '-'}
                                </strong>
                            </div>
                            <div className="bg-white p-3 rounded-xl border border-slate-200/60 shadow-xs">
                                <span className="text-slate-400 block text-[11px]">รหัสนักศึกษา</span>
                                <strong className="text-indigo-700 font-mono text-sm block">
                                    {studentProfile?.code || currentUserCode || '-'}
                                </strong>
                            </div>
                            <div className="bg-white p-3 rounded-xl border border-slate-200/60 shadow-xs">
                                <span className="text-slate-400 block text-[11px]">ระดับชั้น</span>
                                <span className="text-slate-800 font-medium block">
                                    {studentProfile?.level || '-'}
                                </span>
                            </div>
                            <div className="bg-white p-3 rounded-xl border border-slate-200/60 shadow-xs">
                                <span className="text-slate-400 block text-[11px]">กลุ่มเรียน</span>
                                <span className="text-slate-800 font-medium block truncate">
                                    {studentProfile?.group || '-'}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Detailed Guidance & Information */}
                    <div className="text-left space-y-3 pt-2">
                        <h4 className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                            <Info size={16} className="text-indigo-600" />
                            คำแนะนำและสาเหตุที่เป็นไปได้:
                        </h4>
                        <div className="grid gap-3 sm:grid-cols-3 text-xs">
                            <div className="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70 space-y-1.5">
                                <span className="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800">
                                    กรณีที่ 1
                                </span>
                                <div className="font-bold text-slate-800">ยังไม่ถึงภาคเรียนที่เข้าสอบ</div>
                                <p className="text-slate-500 text-[11px] leading-relaxed">
                                    การสอบ N-NET จะจัดสอบสำหรับนักศึกษาในภาคเรียนสุดท้ายก่อนจบหลักสูตร หากยังไม่ถึงภาคเรียนจบจะยังไม่มีข้อมูลสอบ
                                </p>
                            </div>
                            <div className="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70 space-y-1.5">
                                <span className="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800">
                                    กรณีที่ 2
                                </span>
                                <div className="font-bold text-slate-800">รอประกาศผลสอบทางการ</div>
                                <p className="text-slate-500 text-[11px] leading-relaxed">
                                    หากท่านเข้าสอบแล้ว สทศ. หรือสถานศึกษาอาจอยู่ระหว่างการประมวลผลคะแนน หรือกำลังนำเข้าไฟล์คะแนนล่าสุดเข้าสู่ระบบ
                                </p>
                            </div>
                            <div className="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70 space-y-1.5">
                                <span className="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800">
                                    กรณีที่ 3
                                </span>
                                <div className="font-bold text-slate-800">ติดต่อครูประจำกลุ่ม</div>
                                <p className="text-slate-500 text-[11px] leading-relaxed">
                                    หากมีประกาศผลแล้วแต่ยังไม่พบข้อมูล สามารถติดต่อครูประจำกลุ่มเพื่อตรวจสอบความถูกต้องของเลขประจำตัวประชาชน
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="pt-2 flex justify-center">
                        <Button
                            appearance="primary"
                            icon={<ArrowsClockwise size={18} />}
                            onClick={onRetry}
                        >
                            ตรวจสอบข้อมูลอีกครั้ง
                        </Button>
                    </div>
                </Card>
            </div>
        );
    }

    // ACTIVE RECORD: When the student has N-NET records
    const activeRecord = (selectedRecordId ? items.find((r) => r.id === selectedRecordId) : null) ?? items[0];

    // Compute subject data
    const subjectCodes = activeRecord.subject_codes && activeRecord.subject_codes.length > 0
        ? activeRecord.subject_codes
        : Object.keys(activeRecord.subject_scores ?? {});

    const subjectList = subjectCodes.map((code, idx) => {
        const rawScore = activeRecord.subject_scores?.[code];
        const numScore = rawScore !== null && rawScore !== undefined && !Number.isNaN(Number(rawScore))
            ? Number(rawScore)
            : null;
        const rawLevel = activeRecord.subject_levels?.[code] ?? null;
        const name = activeRecord.subject_names?.[code] ?? STANDARD_SUBJECT_NAMES[idx] ?? `สาระที่ ${idx + 1}`;
        const evalData = getScoreEvaluation(numScore, rawLevel);

        return {
            code,
            name,
            score: numScore,
            level: rawLevel,
            evalData,
        };
    });

    const validScores = subjectList.filter((s) => s.score !== null);
    const bestSubject = validScores.length > 0
        ? validScores.reduce((max, s) => (s.score! > max.score! ? s : max), validScores[0])
        : null;
    const averageScore = validScores.length > 0
        ? validScores.reduce((sum, s) => sum + s.score!, 0) / validScores.length
        : null;
    const passedSubjectsCount = validScores.filter((s) => s.score! >= 40).length;

    return (
        <div className="space-y-6">
            {/* Print Styles */}
            <style>{`
                @media print {
                    @page { size: A4 portrait; margin: 12mm; }
                    body { background: white !important; color: black !important; }
                    .no-print { display: none !important; }
                    .print-break-inside-avoid { break-inside: avoid; }
                }
            `}</style>

            {/* Header */}
            <div className="no-print">
                <PageHeader
                    title="รายงานผล N-NET ของตนเอง"
                    description="ผลการทดสอบทางการศึกษาระดับชาติด้านการศึกษานอกระบบโรงเรียน (N-NET) รายบุคคล"
                    icon={Medal}
                    actions={(
                        <div className="flex flex-wrap items-center gap-2">
                            {items.length > 1 && (
                                <select
                                    value={activeRecord.id}
                                    onChange={(e) => setSelectedRecordId(Number(e.target.value))}
                                    aria-label="เลือกรอบการสอบ N-NET"
                                    className="rounded-xl border border-slate-300 p-2 text-xs font-semibold bg-white outline-none focus:border-indigo-500 shadow-xs"
                                >
                                    {items.map((r) => (
                                        <option key={r.id} value={r.id}>
                                            {r.education_level_label} • ปี {r.academic_year} ครั้งที่ {r.round} (รวม {r.total_score !== null ? r.total_score.toFixed(2) : '-'})
                                        </option>
                                    ))}
                                </select>
                            )}
                            <Button
                                appearance="outline"
                                icon={<Printer size={18} />}
                                onClick={handlePrint}
                            >
                                พิมพ์ผลคะแนน
                            </Button>
                            <Button
                                appearance="subtle"
                                icon={<ArrowsClockwise size={18} />}
                                onClick={onRetry}
                            >
                                รีเฟรช
                            </Button>
                        </div>
                    )}
                />
            </div>

            {/* Print Header (Only visible on paper print) */}
            <div className="hidden print:block text-center border-b pb-4 mb-4">
                <h1 className="text-xl font-bold text-slate-900">
                    รายงานผลการทดสอบทางการศึกษาระดับชาติด้านการศึกษานอกระบบโรงเรียน (N-NET)
                </h1>
                <p className="text-xs text-slate-600 mt-1">
                    {activeRecord.education_level_label} • ปีการศึกษา {activeRecord.academic_year} ครั้งที่ {activeRecord.round}
                </p>
            </div>

            {/* Student Profile Card (Matches user screenshot media_1790246466642.png) */}
            <Card className="p-5 rounded-2xl bg-white border border-slate-200/80 shadow-sm space-y-4 print-break-inside-avoid">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                    <div className="flex items-center gap-2.5">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                            <IdentificationCard size={24} weight="bold" />
                        </div>
                        <div>
                            <h3 className="text-base font-bold text-slate-900">
                                ข้อมูลประจำตัวผู้เข้าสอบ
                            </h3>
                            <p className="text-xs text-slate-500">
                                {activeRecord.education_level_label} • ปีการศึกษา {activeRecord.academic_year} ครั้งที่ {activeRecord.round}
                            </p>
                        </div>
                    </div>
                    <div>
                        {activeRecord.has_score ? (
                            <StatusBadge tone="success">มีผลคะแนนสอบ</StatusBadge>
                        ) : (
                            <StatusBadge tone="neutral">ไม่มีคะแนน / ขาดสอบ</StatusBadge>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5 text-xs">
                    <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/60">
                        <span className="text-slate-400 block text-[11px] mb-0.5">ชื่อ - สกุล</span>
                        <strong className="block text-sm text-slate-900 truncate">
                            {activeRecord.student_name}
                        </strong>
                    </div>

                    <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/60">
                        <span className="text-slate-400 block text-[11px] mb-0.5">เลขประจำตัวประชาชน</span>
                        <span className="block font-mono font-bold text-slate-800 text-sm">
                            {activeRecord.citizen_id}
                        </span>
                    </div>

                    <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/60">
                        <span className="text-slate-400 block text-[11px] mb-0.5">เลขที่นั่งสอบ</span>
                        <span className="block font-mono font-bold text-slate-800 text-sm">
                            {activeRecord.seat_no || '-'}
                        </span>
                    </div>

                    <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/60">
                        <span className="text-slate-400 block text-[11px] mb-0.5">รหัสนักศึกษา</span>
                        <span className="block font-mono font-bold text-indigo-700 text-sm">
                            {activeRecord.student_code || studentProfile?.code || '-'}
                        </span>
                    </div>

                    <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/60 col-span-2 sm:col-span-1">
                        <span className="text-slate-400 block text-[11px] mb-0.5">กลุ่มเรียน</span>
                        <span className="block font-medium text-slate-800 truncate text-sm">
                            {activeRecord.group_name || studentProfile?.group || '-'}
                        </span>
                    </div>
                </div>
            </Card>

            {/* Personal Score Summary KPI Cards (เฉพาะตนเอง - ไม่แสดงภาพรวมกลุ่ม) */}
            <div className="grid gap-3.5 grid-cols-2 lg:grid-cols-4 print-break-inside-avoid">
                <Card className="p-4.5 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                    <div>
                        <span className="text-xs font-bold text-indigo-600 flex items-center gap-1.5">
                            <Trophy size={16} />
                            คะแนนรวมทั้งหมด
                        </span>
                        <div className="my-2">
                            <div className="text-2xl sm:text-3xl font-black text-indigo-600 font-mono">
                                {activeRecord.has_score && activeRecord.total_score !== null
                                    ? activeRecord.total_score.toFixed(2)
                                    : '-'}
                            </div>
                        </div>
                    </div>
                    <span className="text-xs text-slate-400">
                        {activeRecord.has_score ? 'คะแนนรวม 5 สาระการเรียนรู้' : 'ไม่มีผลคะแนน'}
                    </span>
                </Card>

                <Card className="p-4.5 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                    <div>
                        <span className="text-xs font-bold text-purple-600 flex items-center gap-1.5">
                            <Medal size={16} />
                            คะแนนเฉลี่ยต่อสาระ
                        </span>
                        <div className="my-2">
                            <div className="text-2xl sm:text-3xl font-black text-purple-600 font-mono">
                                {averageScore !== null ? averageScore.toFixed(2) : '-'}
                            </div>
                        </div>
                    </div>
                    <span className="text-xs text-slate-400">
                        {validScores.length > 0 ? `เฉลี่ยจาก ${validScores.length} สาระที่เข้าสอบ` : 'คะแนนเฉลี่ย'}
                    </span>
                </Card>

                <Card className="p-4.5 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                    <div>
                        <span className="text-xs font-bold text-blue-600">สาระที่ทำคะแนนได้สูงสุด</span>
                        <div className="my-2">
                            <div className="text-lg sm:text-xl font-black text-blue-700 truncate" title={bestSubject ? `${bestSubject.code} ${bestSubject.name}` : ''}>
                                {bestSubject ? bestSubject.code : '-'}
                            </div>
                            <div className="text-xs font-bold text-blue-900 truncate">
                                {bestSubject?.name ?? '-'}
                            </div>
                        </div>
                    </div>
                    <div className="text-xs font-semibold text-blue-600 font-mono">
                        {bestSubject?.score !== null && bestSubject?.score !== undefined ? `${bestSubject.score.toFixed(2)} คะแนน` : '-'}
                    </div>
                </Card>

                <Card className="p-4.5 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                    <div>
                        <span className="text-xs font-bold text-emerald-600">เกณฑ์การผ่านรายสาระ</span>
                        <div className="my-2">
                            <div className="text-2xl sm:text-3xl font-black text-emerald-600 font-mono">
                                {passedSubjectsCount} / {subjectList.length}
                            </div>
                        </div>
                    </div>
                    <span className="text-xs text-slate-500 font-medium">
                        {passedSubjectsCount === subjectList.length && subjectList.length > 0
                            ? '✓ ผ่านเกณฑ์ครบทุกสาระ (>= 40 คะแนน)'
                            : `ผ่านเกณฑ์มาตรฐาน ${passedSubjectsCount} สาระ`}
                    </span>
                </Card>
            </div>

            {/* SUBJECT CARDS WITH VISUAL SCORE RANGE METER (ช่วงคะแนนของตัวเอง แต่ละสาระ) */}
            <Panel
                title="ช่วงคะแนนของตนเองจำแนกตามสาระการเรียนรู้"
                className="space-y-4 print-break-inside-avoid"
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {subjectList.map((sub, idx) => (
                        <div
                            key={sub.code || idx}
                            className="p-4.5 rounded-2xl bg-white border border-slate-200/80 shadow-xs hover:shadow-md transition-all duration-200 flex flex-col justify-between space-y-3"
                        >
                            {/* Card Top: Code, Name, Score, Badge */}
                            <div>
                                <div className="flex items-start justify-between gap-2">
                                    <span className="text-xs font-black px-2.5 py-1 rounded-lg bg-indigo-100 text-indigo-700 font-mono">
                                        {sub.code}
                                    </span>
                                    <span className={`px-2.5 py-0.5 rounded-full text-xs font-bold border ${sub.evalData.badgeBg}`}>
                                        {sub.evalData.label}
                                    </span>
                                </div>

                                <div className="text-sm font-bold text-slate-800 mt-2 line-clamp-1" title={sub.name}>
                                    {sub.name}
                                </div>

                                <div className="mt-2 flex items-baseline gap-1.5">
                                    <span className="text-3xl font-black text-slate-900 font-mono">
                                        {sub.score !== null && sub.score !== undefined ? sub.score.toFixed(2) : '-'}
                                    </span>
                                    <span className="text-xs text-slate-400 font-medium">/ 100 คะแนน</span>
                                </div>
                            </div>

                            {/* VISUAL SCORE RANGE METER (ช่วงคะแนน) */}
                            <div className="space-y-2 pt-2 border-t border-slate-100">
                                <div className="flex items-center justify-between text-[11px] text-slate-500">
                                    <span className="font-semibold text-slate-700">ช่วงคะแนนที่ได้ (0 - 100)</span>
                                    <span className="font-mono font-bold text-slate-800">
                                        {sub.score !== null && sub.score !== undefined ? `${sub.score.toFixed(2)}%` : '-'}
                                    </span>
                                </div>

                                {/* Segmented Range Bar with Benchmark Zones */}
                                <div className="relative pt-1 pb-1">
                                    <div className="h-3.5 w-full rounded-full bg-slate-100 p-0.5 flex overflow-hidden border border-slate-200/80">
                                        {/* Zone 1: 0 - 39.99 (40%) ควรพัฒนา */}
                                        <div
                                            className="h-full w-[40%] bg-rose-100 relative"
                                            title="ควรพัฒนา: 0.00 - 39.99 คะแนน"
                                        />
                                        {/* Zone 2: 40.00 - 49.99 (10%) ผ่าน */}
                                        <div
                                            className="h-full w-[10%] bg-indigo-100 border-l border-indigo-300 relative"
                                            title="ผ่านเกณฑ์: 40.00 - 49.99 คะแนน"
                                        />
                                        {/* Zone 3: 50.00 - 59.99 (10%) ดี */}
                                        <div
                                            className="h-full w-[10%] bg-sky-100 border-l border-sky-300 relative"
                                            title="ดี: 50.00 - 59.99 คะแนน"
                                        />
                                        {/* Zone 4: 60.00 - 100.00 (40%) ดีเยี่ยม */}
                                        <div
                                            className="h-full w-[40%] bg-emerald-100 border-l border-emerald-300 relative"
                                            title="ดีเยี่ยม: 60.00 - 100.00 คะแนน"
                                        />
                                    </div>

                                    {/* Indicator Needle Marker */}
                                    {sub.score !== null && sub.score !== undefined && (
                                        <div
                                            className="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 z-10 transition-all duration-500 pointer-events-none"
                                            style={{ left: `${Math.max(2, Math.min(98, sub.score))}%` }}
                                        >
                                            <div className={`h-5 w-2 rounded-full shadow-md ${sub.evalData.bgClass} ring-2 ring-white`} />
                                        </div>
                                    )}
                                </div>

                                {/* Ticks / Benchmark scale numbers */}
                                <div className="flex justify-between text-[10px] text-slate-400 font-mono px-0.5">
                                    <span className="text-rose-600 font-bold">0</span>
                                    <span className="text-indigo-700 font-bold" title="เกณฑ์มาตรฐานผ่าน 40.00 คะแนน">40 (ผ่าน)</span>
                                    <span className="text-sky-700 font-bold">50 (ดี)</span>
                                    <span className="text-emerald-700 font-bold">60 (ดีเยี่ยม)</span>
                                    <span className="text-slate-600 font-bold">100</span>
                                </div>

                                {/* Descriptive status */}
                                <div className="text-[11px] pt-1 leading-tight">
                                    {sub.score !== null && sub.score !== undefined ? (
                                        sub.score >= 40 ? (
                                            <span className="inline-flex items-center gap-1 text-emerald-700 font-medium">
                                                <CheckCircle size={14} weight="fill" className="text-emerald-600 shrink-0" />
                                                ผ่านเกณฑ์มาตรฐาน (ขั้นต่ำ 40.00 คะแนน)
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-rose-700 font-medium">
                                                <Warning size={14} weight="fill" className="text-rose-500 shrink-0" />
                                                ต่ำกว่าเกณฑ์ผ่าน (ขาดอีก {(40 - sub.score).toFixed(2)} คะแนน)
                                            </span>
                                        )
                                    ) : (
                                        <span className="text-slate-400 italic">ไม่มีข้อมูลคะแนน</span>
                                    )}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </Panel>

            {/* DETAILED SCORES TABLE (Matching modal media_1790246466642.png) */}
            <Panel
                title="ตารางรายละเอียดผลคะแนนรายสาระการเรียนรู้"
                className="space-y-4 print-break-inside-avoid"
            >
                <div className="overflow-x-auto rounded-xl border border-slate-200">
                    <table className="w-full text-left text-xs whitespace-nowrap">
                        <thead>
                            <tr className="bg-slate-50 border-b border-slate-200 font-bold text-slate-700">
                                <th className="p-3 text-center w-12">ลำดับ</th>
                                <th className="p-3">รหัสสาระ</th>
                                <th className="p-3">ชื่อสาระการเรียนรู้</th>
                                <th className="p-3 text-center">คะแนนเต็ม</th>
                                <th className="p-3 text-center font-bold text-indigo-700 bg-indigo-50/50">
                                    คะแนนที่ได้
                                </th>
                                <th className="p-3 text-center">เกณฑ์ผ่าน</th>
                                <th className="p-3 text-center">ช่วงคะแนน / ระดับการประเมิน</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {subjectList.map((sub, idx) => (
                                <tr key={sub.code || idx} className="hover:bg-slate-50/60">
                                    <td className="p-3 text-center font-mono text-slate-500">
                                        {idx + 1}
                                    </td>
                                    <td className="p-3 font-mono font-bold text-indigo-700">
                                        {sub.code}
                                    </td>
                                    <td className="p-3 font-bold text-slate-900">
                                        {sub.name}
                                    </td>
                                    <td className="p-3 text-center font-mono text-slate-500">
                                        100.00
                                    </td>
                                    <td className="p-3 text-center font-mono font-bold text-indigo-700 text-sm bg-indigo-50/30">
                                        {sub.score !== null && sub.score !== undefined ? sub.score.toFixed(2) : '-'}
                                    </td>
                                    <td className="p-3 text-center font-mono text-slate-500">
                                        40.00
                                    </td>
                                    <td className="p-3 text-center">
                                        <span className={`px-2.5 py-0.5 rounded-full text-xs font-bold border ${sub.evalData.badgeBg}`}>
                                            {sub.evalData.label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                            {/* Summary Total Row */}
                            <tr className="bg-indigo-50/60 font-bold text-indigo-950 border-t-2 border-indigo-200">
                                <td colSpan={3} className="p-3 text-right">
                                    คะแนนรวมทั้งหมด:
                                </td>
                                <td className="p-3 text-center font-mono text-slate-600">
                                    {(subjectList.length * 100).toFixed(2)}
                                </td>
                                <td className="p-3 text-center font-mono text-base text-indigo-700">
                                    {activeRecord.has_score && activeRecord.total_score !== null
                                        ? activeRecord.total_score.toFixed(2)
                                        : '-'}
                                </td>
                                <td className="p-3 text-center font-mono text-slate-600">
                                    {(subjectList.length * 40).toFixed(2)}
                                </td>
                                <td className="p-3 text-center">
                                    {activeRecord.has_score ? (
                                        <StatusBadge tone="success">มีผลคะแนน</StatusBadge>
                                    ) : (
                                        <StatusBadge tone="neutral">ไม่มีคะแนน</StatusBadge>
                                    )}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {activeRecord.notes && (
                    <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900">
                        <strong>หมายเหตุ:</strong> {activeRecord.notes}
                    </div>
                )}
            </Panel>

            {/* Score Evaluation Criteria Legend */}
            <div className="p-4.5 rounded-2xl bg-indigo-50/60 border border-indigo-100 space-y-3 text-xs print-break-inside-avoid">
                <div className="flex items-center gap-2 font-bold text-indigo-900">
                    <Info size={18} className="text-indigo-600" />
                    <span>เกณฑ์ช่วงคะแนนและระดับการประเมินผลการทดสอบ N-NET</span>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 pt-1 text-slate-700">
                    <div className="p-3 rounded-xl bg-white border border-emerald-200 shadow-xs">
                        <div className="font-bold text-emerald-700 flex items-center gap-1.5">
                            <span className="h-2 w-2 rounded-full bg-emerald-500" />
                            ช่วงคะแนน 60.00 - 100.00
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1">
                            ระดับ <strong>ดีเยี่ยม</strong> (ผลสัมฤทธิ์ทางการเรียนอยู่ในระดับสูงมาก)
                        </div>
                    </div>

                    <div className="p-3 rounded-xl bg-white border border-sky-200 shadow-xs">
                        <div className="font-bold text-sky-700 flex items-center gap-1.5">
                            <span className="h-2 w-2 rounded-full bg-sky-500" />
                            ช่วงคะแนน 50.00 - 59.99
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1">
                            ระดับ <strong>ดี</strong> (ผลสัมฤทธิ์ทางการเรียนอยู่ในเกณฑ์ดี)
                        </div>
                    </div>

                    <div className="p-3 rounded-xl bg-white border border-indigo-200 shadow-xs">
                        <div className="font-bold text-indigo-700 flex items-center gap-1.5">
                            <span className="h-2 w-2 rounded-full bg-indigo-500" />
                            ช่วงคะแนน 40.00 - 49.99
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1">
                            ระดับ <strong>ผ่าน</strong> (ผ่านเกณฑ์การประเมินมาตรฐานขั้นต่ำ)
                        </div>
                    </div>

                    <div className="p-3 rounded-xl bg-white border border-rose-200 shadow-xs">
                        <div className="font-bold text-rose-700 flex items-center gap-1.5">
                            <span className="h-2 w-2 rounded-full bg-rose-500" />
                            ช่วงคะแนน ต่ำกว่า 40.00
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1">
                            ระดับ <strong>ควรพัฒนา</strong> (ควรได้รับการส่งเสริมและพัฒนาการเรียนรู้)
                        </div>
                    </div>
                </div>
                <p className="text-[11px] text-slate-500 pt-1 leading-relaxed">
                    * หมายเหตุ: การทดสอบทางการศึกษาระดับชาติด้านการศึกษานอกระบบโรงเรียน (N-NET) มีเกณฑ์คะแนนเต็มรายสาระละ 100.00 คะแนน โดยเกณฑ์มาตรฐานผ่านขั้นต่ำอยู่ที่ 40.00 คะแนน
                </p>
            </div>
        </div>
    );
}
