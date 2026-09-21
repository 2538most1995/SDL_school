import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    ArrowsClockwise,
    Books,
    CheckCircle,
    FileText,
    FileXls,
    GraduationCap,
    MagnifyingGlass,
    Student,
    X,
} from '@phosphor-icons/react';
import { StatusBadge } from '../../components/StatusBadge';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { getFeatureDataWithDemo } from '../api';
import { showErrorAlert } from '../../lib/feedback';

export type SubjectItem = {
    student_code?: string;
    code: string;
    name: string;
    credits: number;
    type: string;
    term: string;
    registration_status: string;
    is_transferred: boolean;
    grade?: string | null;
    exam_attended?: boolean;
};

export type StudentSubjectsResponse = {
    student: {
        code: string;
        name: string;
        level?: string;
        group?: string;
    };
    items: SubjectItem[];
    summary: {
        subject_count: number;
        total_credits: number;
        transferred_subjects: number;
        passed_subjects: number;
    };
};

export function StudentSubjectsDialog({
    isOpen,
    onClose,
    studentCode,
    studentName,
    term = '',
    initialFilter = 'all',
    level = '',
    group = '',
}: {
    isOpen: boolean;
    onClose: () => void;
    studentCode: string;
    studentName: string;
    term?: string;
    initialFilter?: 'all' | 'transferred';
    level?: string;
    group?: string;
}) {
    const [filterType, setFilterType] = useState<'all' | 'transferred' | 'compulsory' | 'elective'>(initialFilter);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedTerm, setSelectedTerm] = useState<string>(term);
    const [isExporting, setIsExporting] = useState(false);

    useEffect(() => {
        if (isOpen) {
            setFilterType(initialFilter);
            setSelectedTerm(term);
            setSearchTerm('');
        }
    }, [isOpen, initialFilter, term]);

    useEffect(() => {
        if (!isOpen) return;
        const previousOverflow = document.body.style.overflow;
        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', handleKeyDown);
        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [isOpen, onClose]);

    const queryKey = ['student-subjects', studentCode, selectedTerm];

    const { data, isPending, isError, refetch } = useQuery({
        queryKey,
        enabled: isOpen && Boolean(studentCode),
        queryFn: ({ signal }) => {
            const url = selectedTerm
                ? `/api/v1/students/${encodeURIComponent(studentCode)}/subjects?term=${encodeURIComponent(selectedTerm)}`
                : `/api/v1/students/${encodeURIComponent(studentCode)}/subjects`;
            return getFeatureDataWithDemo<StudentSubjectsResponse>(
                url,
                {
                    student: { code: studentCode, name: studentName, level, group },
                    items: [],
                    summary: { subject_count: 0, total_credits: 0, transferred_subjects: 0, passed_subjects: 0 },
                },
                signal,
            );
        },
    });

    const responseData = data?.data;
    const rawItems = responseData?.items ?? [];

    const availableTerms = useMemo(() => {
        const set = new Set<string>();
        rawItems.forEach((item) => {
            if (item.term) set.add(item.term);
        });
        return Array.from(set);
    }, [rawItems]);

    const filteredItems = useMemo(() => {
        let items = rawItems;

        if (filterType === 'transferred') {
            items = items.filter((item) => item.is_transferred || item.registration_status === 'transferred');
        } else if (filterType === 'compulsory') {
            items = items.filter((item) => item.type === 'compulsory');
        } else if (filterType === 'elective') {
            items = items.filter((item) => item.type === 'elective');
        }

        if (searchTerm.trim()) {
            const q = searchTerm.toLowerCase();
            items = items.filter(
                (item) =>
                    item.code.toLowerCase().includes(q) ||
                    item.name.toLowerCase().includes(q) ||
                    (item.term && item.term.toLowerCase().includes(q)),
            );
        }

        return items;
    }, [rawItems, filterType, searchTerm]);

    const summary = useMemo(() => {
        const totalCredits = rawItems.reduce((sum, item) => sum + (Number(item.credits) || 0), 0);
        const transferredCount = rawItems.filter((i) => i.is_transferred || i.registration_status === 'transferred').length;
        const transferredCredits = rawItems
            .filter((i) => i.is_transferred || i.registration_status === 'transferred')
            .reduce((sum, item) => sum + (Number(item.credits) || 0), 0);
        const passedCount = rawItems.filter((i) => i.registration_status === 'passed' || (i.grade && Number(i.grade) >= 1)).length;

        return {
            totalSubjects: rawItems.length,
            totalCredits,
            transferredCount,
            transferredCredits,
            passedCount,
        };
    }, [rawItems]);

    const exportExcel = async () => {
        if (filteredItems.length === 0 || isExporting) return;
        setIsExporting(true);
        try {
            const { downloadExcel } = await import('../../lib/excel');
            const sheetRows = filteredItems.map((item, index) => [
                index + 1,
                item.code,
                item.name,
                item.type === 'compulsory' ? 'วิชาบังคับ' : 'วิชาเลือก',
                item.credits,
                item.term || '-',
                item.is_transferred ? 'วิชาเทียบโอน' : item.registration_status === 'passed' ? 'สอบผ่าน' : 'ลงทะเบียน',
                item.grade ?? '-',
                item.exam_attended ? 'เข้าสอบ' : 'ไม่ได้เข้าสอบ',
            ]);

            const cleanName = (studentName || studentCode).replace(/[/\\?%*:|"<>]/g, '-');
            downloadExcel(`รายวิชา-${cleanName}-ภาคเรียน-${selectedTerm || 'all'}`, [
                {
                    name: 'รายการวิชา',
                    columns: [
                        'ลำดับ',
                        'รหัสวิชา',
                        'ชื่อรายวิชา',
                        'ประเภทวิชา',
                        'หน่วยกิต',
                        'ภาคเรียน',
                        'สถานะ',
                        'ผลการเรียน',
                        'การเข้าสอบ',
                    ],
                    rows: sheetRows,
                },
            ]);
        } catch (error) {
            showErrorAlert(error instanceof Error ? error.message : 'ไม่สามารถส่งออก Excel ได้');
        } finally {
            setIsExporting(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div
            className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-[2px]"
            role="presentation"
            onMouseDown={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="student-subjects-dialog-title"
                className="max-h-[calc(100dvh-2rem)] w-full max-w-5xl overflow-y-auto rounded-[24px] border border-white/70 bg-white shadow-[0_30px_100px_rgb(2_6_23_/_0.35)]"
            >
                <header className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-7">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="inline-flex items-center gap-1 rounded-lg bg-brand-50 px-2.5 py-1 text-xs font-bold text-brand-700">
                                <GraduationCap size={15} weight="bold" />
                                ข้อมูลรายวิชา
                            </span>
                            {selectedTerm && (
                                <span className="text-xs font-semibold text-slate-500">
                                    ภาคเรียน {selectedTerm}
                                </span>
                            )}
                            {level && (
                                <span className="inline-flex rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                    {level}
                                </span>
                            )}
                        </div>
                        <h2 id="student-subjects-dialog-title" className="mt-1 text-xl font-black text-slate-950">
                            {studentName || 'ไม่พบชื่อนักศึกษา'}
                        </h2>
                        <p className="mt-0.5 font-mono text-xs font-bold text-slate-500">
                            รหัสนักศึกษา: {studentCode} {group ? `· กลุ่ม: ${group}` : ''}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => void exportExcel()}
                            disabled={isExporting || filteredItems.length === 0}
                            className="inline-flex h-10 items-center gap-2 rounded-xl border border-brand-700 bg-white px-3.5 text-sm font-bold text-brand-800 transition hover:bg-brand-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-400"
                        >
                            <FileXls size={18} weight="bold" />
                            {isExporting ? 'กำลังส่งออก...' : 'ส่งออก Excel'}
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            className="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-100"
                            aria-label="ปิดหน้าต่าง"
                        >
                            <X size={20} weight="bold" />
                        </button>
                    </div>
                </header>

                <div className="p-5 sm:p-7">
                    {/* Summary Cards */}
                    <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div className="rounded-2xl border border-brand-200 bg-brand-50/60 p-4">
                            <p className="text-xs font-bold text-brand-700">รายวิชาทั้งหมด</p>
                            <p className="mt-1 text-2xl font-black text-brand-950">
                                {summary.totalSubjects.toLocaleString('th-TH')}{' '}
                                <span className="text-xs font-bold text-brand-700">วิชา</span>
                            </p>
                            <p className="mt-0.5 text-[11px] font-semibold text-slate-500">
                                รวม {summary.totalCredits.toFixed(1)} หน่วยกิต
                            </p>
                        </div>
                        <div className="rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
                            <p className="text-xs font-bold text-amber-700">วิชาเทียบโอน</p>
                            <p className="mt-1 text-2xl font-black text-amber-950">
                                {summary.transferredCount.toLocaleString('th-TH')}{' '}
                                <span className="text-xs font-bold text-amber-700">วิชา</span>
                            </p>
                            <p className="mt-0.5 text-[11px] font-semibold text-slate-500">
                                {summary.transferredCredits.toFixed(1)} หน่วยกิตเทียบโอน
                            </p>
                        </div>
                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4">
                            <p className="text-xs font-bold text-emerald-700">สอบผ่านแล้ว</p>
                            <p className="mt-1 text-2xl font-black text-emerald-950">
                                {summary.passedCount.toLocaleString('th-TH')}{' '}
                                <span className="text-xs font-bold text-emerald-700">วิชา</span>
                            </p>
                            <p className="mt-0.5 text-[11px] font-semibold text-slate-500">
                                มีผลการเรียนผ่าน
                            </p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p className="text-xs font-bold text-slate-600">ภาคเรียนที่แสดง</p>
                            <div className="mt-1">
                                <select
                                    value={selectedTerm}
                                    onChange={(e) => setSelectedTerm(e.target.value)}
                                    className="h-8 w-full rounded-lg border border-slate-300 bg-white px-2 text-xs font-bold text-slate-800"
                                    aria-label="เลือกภาคเรียนที่แสดง"
                                >
                                    <option value="">ทุกภาคเรียน</option>
                                    {term && <option value={term}>ภาคเรียน {term} (ตามรายงาน)</option>}
                                    {availableTerms.filter((t) => t !== term).map((t) => (
                                        <option key={t} value={t}>ภาคเรียน {t}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Filter Tabs & Search */}
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap gap-1.5" role="tablist" aria-label="กรองประเภทรายวิชา">
                            <button
                                type="button"
                                role="tab"
                                aria-selected={filterType === 'all'}
                                onClick={() => setFilterType('all')}
                                className={`rounded-xl px-3.5 py-1.5 text-xs font-bold transition ${
                                    filterType === 'all'
                                        ? 'bg-brand-700 text-white shadow-sm'
                                        : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                ทั้งหมด ({rawItems.length})
                            </button>
                            <button
                                type="button"
                                role="tab"
                                aria-selected={filterType === 'transferred'}
                                onClick={() => setFilterType('transferred')}
                                className={`rounded-xl px-3.5 py-1.5 text-xs font-bold transition ${
                                    filterType === 'transferred'
                                        ? 'bg-amber-600 text-white shadow-sm'
                                        : 'border border-slate-200 bg-white text-slate-700 hover:bg-amber-50 hover:text-amber-800'
                                }`}
                            >
                                วิชาเทียบโอน ({summary.transferredCount})
                            </button>
                            <button
                                type="button"
                                role="tab"
                                aria-selected={filterType === 'compulsory'}
                                onClick={() => setFilterType('compulsory')}
                                className={`rounded-xl px-3.5 py-1.5 text-xs font-bold transition ${
                                    filterType === 'compulsory'
                                        ? 'bg-brand-700 text-white shadow-sm'
                                        : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                วิชาบังคับ
                            </button>
                            <button
                                type="button"
                                role="tab"
                                aria-selected={filterType === 'elective'}
                                onClick={() => setFilterType('elective')}
                                className={`rounded-xl px-3.5 py-1.5 text-xs font-bold transition ${
                                    filterType === 'elective'
                                        ? 'bg-brand-700 text-white shadow-sm'
                                        : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                วิชาเลือก
                            </button>
                        </div>

                        <div className="relative w-full sm:w-64">
                            <input
                                type="text"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                placeholder="ค้นหารหัส หรือชื่อวิชา..."
                                className="h-9 w-full rounded-xl border border-slate-300 bg-white pl-8 pr-3 text-xs font-semibold text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none"
                            />
                            <MagnifyingGlass size={16} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
                        </div>
                    </div>

                    {isPending && <QuerySkeleton rows={6} />}
                    {isError && (
                        <div className="my-6">
                            <QueryError onRetry={() => void refetch()} />
                        </div>
                    )}

                    {!isPending && !isError && filteredItems.length === 0 && (
                        <div className="grid min-h-48 place-items-center rounded-2xl border border-slate-200 bg-slate-50 p-8 text-center">
                            <div className="max-w-sm">
                                <Books size={36} className="mx-auto text-slate-400" weight="duotone" />
                                <h3 className="mt-2 text-sm font-black text-slate-800">
                                    {searchTerm ? 'ไม่พบวิชาที่ตรงกับคำค้นหา' : 'ไม่พบรายการวิชา'}
                                </h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    {selectedTerm
                                        ? `ไม่มีรายวิชาในภาคเรียน ${selectedTerm} ลองเลือก "ทุกภาคเรียน"`
                                        : 'ไม่พบข้อมูลรายวิชาของนักศึกษาท่านนี้'}
                                </p>
                                {selectedTerm && (
                                    <button
                                        type="button"
                                        onClick={() => setSelectedTerm('')}
                                        className="mt-3 inline-flex items-center gap-1.5 rounded-xl border border-brand-300 bg-white px-3 py-1.5 text-xs font-bold text-brand-800 transition hover:bg-brand-50"
                                    >
                                        <ArrowsClockwise size={14} />
                                        ดูทุกภาคเรียน
                                    </button>
                                )}
                            </div>
                        </div>
                    )}

                    {!isPending && !isError && filteredItems.length > 0 && (
                        <div className="overflow-x-auto rounded-2xl border border-slate-200">
                            <table className="w-full border-collapse text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold text-slate-600">
                                        <th className="px-4 py-3 text-center">ลำดับ</th>
                                        <th className="px-4 py-3 text-left">รหัสวิชา</th>
                                        <th className="px-4 py-3 text-left">ชื่อรายวิชา</th>
                                        <th className="px-4 py-3 text-center">ประเภท</th>
                                        <th className="px-4 py-3 text-center">หน่วยกิต</th>
                                        <th className="px-4 py-3 text-center">ภาคเรียน</th>
                                        <th className="px-4 py-3 text-center">สถานะ</th>
                                        <th className="px-4 py-3 text-center">ผลการเรียน</th>
                                        <th className="px-4 py-3 text-center">เข้าสอบ</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 bg-white">
                                    {filteredItems.map((item, idx) => (
                                        <tr
                                            key={`${item.code}-${item.term}-${idx}`}
                                            className={`transition-colors hover:bg-slate-50/80 ${
                                                item.is_transferred ? 'bg-amber-50/30' : ''
                                            }`}
                                        >
                                            <td className="px-4 py-3 text-center text-xs font-bold text-slate-500">
                                                {idx + 1}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs font-black text-slate-900">
                                                {item.code}
                                            </td>
                                            <td className="px-4 py-3 font-bold text-slate-950">
                                                {item.name}
                                                {item.is_transferred && (
                                                    <span className="ml-2 inline-flex rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-black text-amber-800">
                                                        เทียบโอน
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <StatusBadge tone={item.type === 'compulsory' ? 'info' : 'warning'}>
                                                    {item.type === 'compulsory' ? 'บังคับ' : 'เลือก'}
                                                </StatusBadge>
                                            </td>
                                            <td className="px-4 py-3 text-center font-bold text-slate-900">
                                                {item.credits.toFixed(1)}
                                            </td>
                                            <td className="px-4 py-3 text-center font-mono text-xs text-slate-600">
                                                {item.term || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {item.is_transferred ? (
                                                    <span className="inline-flex rounded-lg bg-amber-100 px-2 py-0.5 text-xs font-black text-amber-800">
                                                        วิชาเทียบโอน
                                                    </span>
                                                ) : item.registration_status === 'passed' ? (
                                                    <span className="inline-flex rounded-lg bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-800">
                                                        สอบผ่าน
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                                        ลงทะเบียน
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-center font-bold text-slate-950">
                                                {item.grade !== null && item.grade !== undefined && item.grade !== ''
                                                    ? item.grade
                                                    : '-'}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {item.exam_attended ? (
                                                    <span className="inline-flex items-center gap-1 text-xs font-bold text-emerald-700">
                                                        <CheckCircle size={14} weight="bold" />
                                                        เข้าสอบ
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-slate-400">
                                                        -
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}
