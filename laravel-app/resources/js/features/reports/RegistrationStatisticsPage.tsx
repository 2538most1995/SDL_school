import { Books, CalendarBlank, ChartBar, FileXls, FunnelSimple, MagnifyingGlass, StackSimple, Trophy, UsersThree, X } from '@phosphor-icons/react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useEffect, useMemo, useState } from 'react';
import { DataTable } from '../../components/DataTable';
import { EmptyState, QueryError, QuerySkeleton } from '../../components/QueryState';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { StatGrid } from '../../components/StatGrid';
import { StatusBadge } from '../../components/StatusBadge';
import { StatTile } from '../../components/StatTile';
import { useDemoRole } from '../../context/DemoRoleContext';
import { showErrorAlert } from '../../lib/feedback';
import { getFeatureDataWithDemo } from '../api';
import { StudentSubjectsDialog } from './StudentSubjectsDialog';
import {
    buildRegistrationStatisticsSheets,
    canExportRegistrationStatistics,
    registrationStatisticsFileName,
    registrationStatisticsFilterParameter,
    type CategoryKey,
    type RegistrationStatisticsPayload,
    type StatisticItem,
} from './registrationStatisticsExport';

type StudentRegistrationRow = {
    id: string;
    student: {
        code: string;
        full_name: string;
        level: { id: string; label: string };
        group: { code: string; name: string };
    };
    primary: string;
    secondary: string;
    group: string;
    metric: string;
    category: string;
    category_label: string;
    category_value: string;
    category_value_label: string;
    nnet_status: string;
    target_group: string;
    gender: string;
    occupation: string;
    nationality: string;
    age: string;
};

const categoryOptions: RegistrationStatisticsPayload['categories'] = [
    { key: 'target_group', label: 'กลุ่มเป้าหมาย' },
    { key: 'group', label: 'กลุ่มเรียน' },
    { key: 'gender', label: 'เพศ' },
    { key: 'level', label: 'ระดับชั้น' },
    { key: 'occupation', label: 'อาชีพ' },
    { key: 'nationality', label: 'สัญชาติ' },
    { key: 'age', label: 'อายุ' },
    { key: 'nnet', label: 'สถานะ N-Net / E-Exam' },
];

const emptyFilters: Record<CategoryKey, string> = {
    target_group: '',
    group: '',
    gender: '',
    level: '',
    occupation: '',
    nationality: '',
    age: '',
    nnet: '',
};

const emptyPayload: RegistrationStatisticsPayload = {
    categories: categoryOptions,
    selected_category: 'target_group',
    selected_category_label: 'กลุ่มเป้าหมาย',
    filter_options: {
        target_group: [], group: [], gender: [], level: [], occupation: [], nationality: [], age: [], nnet: [],
    },
    applied_filters: {},
    terms: [],
    selected_term: null,
    summary: { registered_students: 0, category_count: 0, largest_category: null },
    items: [],
};

function compareAcademicTermsDescending(left: string, right: string): number {
    const parse = (value: string) => {
        const match = value.match(/^([1-4])\/(25\d{2})$/);
        return match ? [Number(match[2]), Number(match[1])] : [0, 0];
    };
    const [leftYear, leftSemester] = parse(left);
    const [rightYear, rightSemester] = parse(right);

    return rightYear - leftYear || rightSemester - leftSemester || right.localeCompare(left, 'th');
}

const barTones = ['bg-brand-600', 'bg-emerald-600', 'bg-amber-500', 'bg-sky-600', 'bg-violet-600'];

function RegistrationStudentListDialog({
    isOpen,
    onClose,
    category,
    categoryLabel,
    selectedItem,
    term,
    filters,
}: {
    isOpen: boolean;
    onClose: () => void;
    category: CategoryKey;
    categoryLabel: string;
    selectedItem: StatisticItem | null;
    term: string;
    filters: Record<CategoryKey, string>;
}) {
    const [searchTerm, setSearchTerm] = useState('');
    const [isExporting, setIsExporting] = useState(false);
    const [subjectStudent, setSubjectStudent] = useState<{ code: string; name: string; level: string; group: string } | null>(null);

    const queryKey = [
        'registration-statistics-students',
        category,
        selectedItem?.code ?? 'all',
        term,
        categoryOptions.map((opt) => `${opt.key}:${filters[opt.key]}`).join('|'),
    ];

    const { data, isPending, isError, refetch } = useQuery({
        queryKey,
        enabled: isOpen && selectedItem !== null,
        queryFn: ({ signal }) => {
            const params = new URLSearchParams({
                category,
                view: 'student',
            });
            if (selectedItem?.code) {
                params.set('category_value', selectedItem.code);
            }
            if (term) params.set('term', term);
            categoryOptions.forEach((option) => {
                const value = filters[option.key];
                if (value) params.set(registrationStatisticsFilterParameter(option.key), value);
            });

            return getFeatureDataWithDemo<{ rows: StudentRegistrationRow[]; total: number }>(
                `/api/v1/reports/students/registration-statistics?${params.toString()}`,
                { rows: [], total: 0 },
                signal,
            );
        },
    });

    const rows = data?.data.rows ?? [];

    const filteredRows = useMemo(() => {
        if (!searchTerm.trim()) return rows;
        const q = searchTerm.toLowerCase();
        return rows.filter(
            (r) =>
                r.student.code.toLowerCase().includes(q) ||
                r.student.full_name.toLowerCase().includes(q) ||
                r.student.group.name.toLowerCase().includes(q) ||
                r.student.group.code.toLowerCase().includes(q) ||
                r.student.level.label.toLowerCase().includes(q),
        );
    }, [rows, searchTerm]);

    const columns: ColumnDef<StudentRegistrationRow, unknown>[] = useMemo(
        () => [
            {
                id: 'index',
                header: 'ลำดับ',
                cell: ({ row }) => (
                    <span className="font-bold text-slate-500">{row.index + 1}</span>
                ),
            },
            {
                id: 'code',
                header: 'รหัสนักศึกษา',
                cell: ({ row }) => (
                    <span className="font-mono text-xs font-black text-slate-900">
                        {row.original.student.code}
                    </span>
                ),
            },
            {
                id: 'name',
                header: 'ชื่อ - สกุล',
                cell: ({ row }) => (
                    <div className="font-bold text-slate-950">
                        {row.original.student.full_name}
                    </div>
                ),
            },
            {
                id: 'level',
                header: 'ระดับชั้น',
                cell: ({ row }) => (
                    <span className="text-xs font-semibold text-slate-700">
                        {row.original.student.level.label}
                    </span>
                ),
            },
            {
                id: 'group',
                header: 'กลุ่มเรียน',
                cell: ({ row }) => (
                    <span className="text-xs font-semibold text-slate-700">
                        {row.original.student.group.name || row.original.student.group.code || '-'}
                    </span>
                ),
            },
            {
                id: 'category_val',
                header: categoryLabel,
                cell: ({ row }) => (
                    <span className="inline-flex rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-800">
                        {row.original.category_value_label || row.original.metric || '-'}
                    </span>
                ),
            },
            {
                id: 'nnet',
                header: 'สถานะ N-Net',
                cell: ({ row }) => (
                    <StatusBadge
                        tone={
                            row.original.nnet_status === 'สอบแล้ว'
                                ? 'success'
                                : row.original.nnet_status === 'มีสิทธิ์สอบ'
                                ? 'info'
                                : 'warning'
                        }
                    >
                        {row.original.nnet_status || 'ยังไม่ได้สอบ'}
                    </StatusBadge>
                ),
            },
            {
                id: 'actions',
                header: 'รายวิชา',
                cell: ({ row }) => (
                    <button
                        type="button"
                        onClick={() => setSubjectStudent({
                            code: row.original.student.code,
                            name: row.original.student.full_name,
                            level: row.original.student.level.label,
                            group: row.original.student.group.name || row.original.student.group.code || '',
                        })}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-brand-200 bg-brand-50 px-2.5 py-1 text-xs font-bold text-brand-800 transition hover:border-brand-400 hover:bg-brand-100 active:scale-95"
                        title={`ดูรายวิชาที่ลงทะเบียนของ ${row.original.student.full_name}`}
                    >
                        <Books size={14} weight="bold" />
                        ดูรายวิชา
                    </button>
                ),
            },
        ],
        [categoryLabel],
    );

    const exportExcel = async () => {
        if (rows.length === 0 || isExporting) return;
        setIsExporting(true);
        try {
            const { downloadExcel } = await import('../../lib/excel');
            const sheetData = rows.map((r, i) => [
                i + 1,
                r.student.code,
                r.student.full_name,
                r.student.level.label,
                r.student.group.name || r.student.group.code,
                r.category_value_label || r.metric,
                r.nnet_status || 'ยังไม่ได้สอบ',
                r.target_group,
                r.gender,
                r.occupation,
                r.nationality,
                r.age,
            ]);
            const labelSanitized = (selectedItem?.label ?? 'ทั้งหมด').replace(/[/\\?%*:|"<>]/g, '-');
            downloadExcel(`รายชื่อนักศึกษา-${categoryLabel}-${labelSanitized}-ภาคเรียน-${term || 'all'}`, [
                {
                    name: 'รายชื่อนักศึกษา',
                    columns: [
                        'ลำดับ',
                        'รหัสนักศึกษา',
                        'ชื่อ - สกุล',
                        'ระดับการศึกษา',
                        'กลุ่มเรียน',
                        categoryLabel,
                        'สถานะ N-Net',
                        'กลุ่มเป้าหมาย',
                        'เพศ',
                        'อาชีพ',
                        'สัญชาติ',
                        'อายุ',
                    ],
                    rows: sheetData,
                },
            ]);
        } catch (error) {
            showErrorAlert(error instanceof Error ? error.message : 'ไม่สามารถส่งออก Excel ได้');
        } finally {
            setIsExporting(false);
        }
    };

    if (!isOpen || !selectedItem) return null;

    return (
        <div
            className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-[2px]"
            role="presentation"
            onMouseDown={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="student-list-title"
                className="max-h-[calc(100dvh-2rem)] w-full max-w-5xl overflow-y-auto rounded-[24px] border border-white/70 bg-white shadow-[0_30px_100px_rgb(2_6_23_/_0.35)]"
            >
                <header className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-7">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="inline-flex rounded-lg bg-brand-50 px-2.5 py-1 text-xs font-bold text-brand-700">
                                {categoryLabel}
                            </span>
                            <span className="text-xs text-slate-500">
                                ภาคเรียน {term || '-'}
                            </span>
                        </div>
                        <h2 id="student-list-title" className="mt-1 text-xl font-black text-slate-950">
                            {selectedItem.label} ({selectedItem.count.toLocaleString('th-TH')} คน)
                        </h2>
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => void exportExcel()}
                            disabled={isExporting || rows.length === 0}
                            className="inline-flex h-10 items-center gap-2 rounded-xl border border-brand-700 bg-white px-3 text-sm font-bold text-brand-800 transition hover:bg-brand-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-400"
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
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="relative w-full sm:w-80">
                            <input
                                type="text"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                placeholder="ค้นหารหัสนักศึกษา, ชื่อ-สกุล, กลุ่ม..."
                                className="h-10 w-full rounded-xl border border-slate-300 bg-white pl-9 pr-3 text-sm font-semibold text-slate-900 placeholder:text-slate-400 focus:border-brand-600 focus:outline-none"
                            />
                            <MagnifyingGlass size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        </div>
                        <p className="text-xs font-bold text-slate-500">
                            แสดง {filteredRows.length.toLocaleString('th-TH')} จาก {rows.length.toLocaleString('th-TH')} คน
                        </p>
                    </div>

                    {isPending && <QuerySkeleton rows={6} />}
                    {isError && <QueryError onRetry={() => refetch()} />}
                    {!isPending && !isError && (
                        filteredRows.length === 0 ? (
                            <EmptyState
                                title="ไม่พบรายชื่อนักศึกษา"
                                description={searchTerm ? 'ไม่พบข้อมูลที่ตรงกับคำค้นหา' : 'ไม่มีรายชื่อในประเภทนี้'}
                            />
                        ) : (
                            <DataTable data={filteredRows} columns={columns} minWidth="wide" pageSize={50} />
                        )
                    )}
                </div>

                <StudentSubjectsDialog
                    isOpen={subjectStudent !== null}
                    onClose={() => setSubjectStudent(null)}
                    studentCode={subjectStudent?.code ?? ''}
                    studentName={subjectStudent?.name ?? ''}
                    level={subjectStudent?.level ?? ''}
                    group={subjectStudent?.group ?? ''}
                    term={term}
                />
            </section>
        </div>
    );
}

export function RegistrationStatisticsPage() {
    const { role } = useDemoRole();
    const [category, setCategory] = useState<CategoryKey>('target_group');
    const [term, setTerm] = useState('');
    const [filters, setFilters] = useState<Record<CategoryKey, string>>(emptyFilters);
    const [selectedItem, setSelectedItem] = useState<StatisticItem | null>(null);
    const [isExporting, setIsExporting] = useState(false);
    const filterKey = categoryOptions.map((option) => `${option.key}:${filters[option.key]}`).join('|');
    const statistics = useQuery({
        queryKey: ['registration-statistics', category, term, filterKey],
        queryFn: ({ signal }) => {
            const params = new URLSearchParams({ category });
            if (term) params.set('term', term);
            categoryOptions.forEach((option) => {
                const value = filters[option.key];
                if (value) params.set(registrationStatisticsFilterParameter(option.key), value);
            });

            return getFeatureDataWithDemo<RegistrationStatisticsPayload>(
                `/api/v1/reports/students/registration-statistics?${params.toString()}`,
                emptyPayload,
                signal,
            );
        },
        placeholderData: keepPreviousData,
    });
    const payload = statistics.data?.data;
    const termOptions = useMemo(
        () => Array.from(new Set([...(term ? [term] : []), ...(payload?.terms ?? [])])).sort(compareAcademicTermsDescending),
        [payload?.terms, term],
    );

    useEffect(() => {
        if (term !== '') return;
        const latestTerm = [...(payload?.terms ?? [])].sort(compareAcademicTermsDescending)[0] ?? payload?.selected_term;
        if (latestTerm) setTerm(latestTerm);
    }, [payload?.selected_term, payload?.terms, term]);

    const selectedCategoryLabel = categoryOptions.find((option) => option.key === category)?.label ?? 'กลุ่มเป้าหมาย';
    const largest = payload?.summary.largest_category;
    const activeFilterCount = Object.values(filters).filter(Boolean).length;
    const canExport = canExportRegistrationStatistics(role);

    const updateFilter = (key: CategoryKey, value: string) => {
        setFilters((current) => ({ ...current, [key]: value }));
    };

    const exportStatistics = async () => {
        if (!canExport || isExporting) return;

        setIsExporting(true);
        try {
            const params = new URLSearchParams({ category });
            if (term) params.set('term', term);
            categoryOptions.forEach((option) => {
                const value = filters[option.key];
                if (value) params.set(registrationStatisticsFilterParameter(option.key), value);
            });
            const response = await getFeatureDataWithDemo<RegistrationStatisticsPayload>(
                `/api/v1/reports/students/registration-statistics/export-data?${params.toString()}`,
                emptyPayload,
            );
            if (response.data.items.length === 0) throw new Error('ไม่มีข้อมูลสำหรับส่งออก');

            const { downloadExcel } = await import('../../lib/excel');
            downloadExcel(registrationStatisticsFileName(response.data), buildRegistrationStatisticsSheets(response.data));
        } catch (error) {
            showErrorAlert(error instanceof Error ? error.message : 'ไม่สามารถส่งออก Excel ได้ กรุณาลองใหม่');
        } finally {
            setIsExporting(false);
        }
    };

    return (
        <div>
            <PageHeader
                category="รายงานสถิติ"
                title="สถิตินักศึกษาลงทะเบียน"
                description="ดูจำนวนนักศึกษาที่ลงทะเบียน เลือกกรองหลายเงื่อนไขพร้อมกัน และแยกผลตามกลุ่มเรียน กลุ่มเป้าหมาย เพศ ระดับชั้น อาชีพ สัญชาติ หรืออายุ"
                icon={ChartBar}
                actions={<div className="flex flex-wrap items-center justify-end gap-2">
                    <label className="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <span>กลุ่มเรียน</span>
                        <select
                            value={filters.group}
                            onChange={(event) => updateFilter('group', event.target.value)}
                            className="h-10 rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-900"
                            aria-label="เลือกกลุ่มเรียนด่วน"
                        >
                            <option value="">ทุกกลุ่มเรียน</option>
                            {(payload?.filter_options.group ?? []).filter((item) => item.value !== '').map((item) => (
                                <option key={item.value} value={item.value}>{item.label} ({item.count.toLocaleString('th-TH')})</option>
                            ))}
                        </select>
                    </label>
                    <label className="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <span>ภาคเรียน</span>
                        <select
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            className="h-10 rounded-xl border border-slate-300 bg-white px-3 text-sm"
                            aria-label="เลือกภาคเรียน"
                        >
                            {termOptions.length === 0 && <option value="">ไม่มีข้อมูลภาคเรียน</option>}
                            {termOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                        </select>
                    </label>
                    {canExport && <button
                        type="button"
                        disabled={isExporting || !payload || payload.items.length === 0}
                        onClick={() => void exportStatistics()}
                        className="inline-flex h-10 items-center gap-2 rounded-xl border border-brand-700 bg-white px-3 text-sm font-bold text-brand-800 transition hover:bg-brand-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-400"
                    >
                        <FileXls size={18} weight="bold" />
                        {isExporting ? 'กำลังส่งออก...' : 'ส่งออก Excel'}
                    </button>}
                </div>}
            />

            {payload && (
                <StatGrid>
                    <StatTile label="นักศึกษาที่ลงทะเบียน" value={`${payload.summary.registered_students.toLocaleString('th-TH')} คน`} detail={`ภาคเรียน ${payload.selected_term ?? '-'}`} icon={UsersThree} tone="sky" />
                    <StatTile label={`จำนวนประเภท${payload.selected_category_label}`} value={payload.summary.category_count.toLocaleString('th-TH')} detail="นับเฉพาะประเภทที่พบในข้อมูล" icon={StackSimple} tone="emerald" />
                    <StatTile label="ประเภทที่มีจำนวนสูงสุด" value={largest ? `${largest.count.toLocaleString('th-TH')} คน` : '-'} detail={largest?.label ?? 'ยังไม่มีข้อมูล'} icon={Trophy} tone="amber" />
                    <StatTile label="ภาคเรียนที่แสดง" value={payload.selected_term ?? '-'} detail="เปลี่ยนภาคเรียนได้จากด้านบน" icon={CalendarBlank} tone="rose" />
                </StatGrid>
            )}

            <Panel
                title={`แยกตาม${selectedCategoryLabel}`}
                description="ข้อมูลถูกจำกัดตามอำเภอ และสำหรับครูจะเห็นเฉพาะกลุ่มเรียนที่ได้รับมอบหมาย สามารถกดดูรายชื่อนักศึกษาในแต่ละรายการได้"
            >
                <div className="mb-6 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <FunnelSimple className="size-5 text-brand-700" weight="bold" />
                            <div>
                                <h3 className="font-black text-slate-950">กรองข้อมูลร่วมกัน</h3>
                                <p className="text-xs text-slate-500">เลือกได้มากกว่าหนึ่งประเภท เช่น กลุ่มเรียน + เพศ + ระดับชั้น</p>
                            </div>
                        </div>
                        {activeFilterCount > 0 && (
                            <button
                                type="button"
                                onClick={() => setFilters(emptyFilters)}
                                className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:border-rose-300 hover:text-rose-700"
                            >
                                <X className="size-4" weight="bold" />
                                ล้างตัวกรอง ({activeFilterCount})
                            </button>
                        )}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {categoryOptions.map((option, index) => (
                            <label key={option.key} className={`grid gap-1.5 text-xs font-bold text-slate-600 ${index === categoryOptions.length - 1 ? 'sm:col-span-2 xl:col-span-1' : ''}`}>
                                <span>{option.label}</span>
                                <select
                                    value={filters[option.key]}
                                    onChange={(event) => updateFilter(option.key, event.target.value)}
                                    className="h-11 min-w-0 rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-900"
                                    aria-label={`กรองตาม${option.label}`}
                                >
                                    <option value="">ทั้งหมด</option>
                                    {(payload?.filter_options[option.key] ?? []).filter((item) => item.value !== '').map((item) => (
                                        <option key={item.value} value={item.value}>{item.label} ({item.count.toLocaleString('th-TH')})</option>
                                    ))}
                                </select>
                            </label>
                        ))}
                    </div>
                </div>

                <p className="mb-2 text-xs font-bold uppercase tracking-[0.12em] text-slate-500">เลือกหัวข้อที่ใช้แยกผลในตาราง</p>
                <div className="mb-6 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8" role="group" aria-label="เลือกประเภทข้อมูลสถิติ">
                    {categoryOptions.map((option, index) => (
                        <button
                            key={option.key}
                            type="button"
                            onClick={() => setCategory(option.key)}
                            aria-pressed={category === option.key}
                            className={`min-h-11 rounded-xl border px-3 py-2 text-sm font-bold transition ${index === categoryOptions.length - 1 ? 'col-span-2 sm:col-span-1' : ''} ${category === option.key ? 'border-brand-700 bg-brand-700 text-white shadow-sm' : 'border-slate-200 bg-white text-slate-700 hover:border-brand-300 hover:bg-brand-50'}`}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>

                {statistics.isPending && <QuerySkeleton rows={6} />}
                {statistics.isError && <QueryError onRetry={() => statistics.refetch()} />}
                {payload && payload.items.length === 0 && (
                    <EmptyState title="ไม่พบข้อมูลการลงทะเบียน" description="ยังไม่มีรายการลงทะเบียนในภาคเรียนที่เลือก" />
                )}
                {payload && payload.items.length > 0 && (
                    <div className="overflow-hidden rounded-2xl border border-slate-200">
                        <div className="hidden grid-cols-[minmax(0,1fr)_150px_170px] gap-4 bg-slate-50 px-5 py-3 text-xs font-bold text-slate-500 sm:grid">
                            <span>{payload.selected_category_label}</span>
                            <span className="text-right">สัดส่วน</span>
                            <span className="text-right">จำนวน / ดูรายชื่อ</span>
                        </div>
                        <ol className="divide-y divide-slate-200">
                            {payload.items.map((item, index) => (
                                <li key={item.key} className="grid gap-3 px-4 py-4 transition-colors hover:bg-slate-50/80 sm:grid-cols-[minmax(0,1fr)_150px_170px] sm:items-center sm:px-5">
                                    <div className="min-w-0">
                                        <div className="flex items-start gap-3">
                                            <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-xs font-black text-slate-600">{index + 1}</span>
                                            <div className="min-w-0">
                                                <p className="font-bold leading-6 text-slate-950">{item.label}</p>
                                                {item.code && item.code !== item.label && <p className="mt-0.5 text-xs text-slate-500">รหัส {item.code}</p>}
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <div className="mb-1.5 flex items-center justify-between text-xs font-bold text-slate-600 sm:justify-end">
                                            <span className="sm:hidden">สัดส่วน</span>
                                            <span>{item.percentage.toLocaleString('th-TH')}%</span>
                                        </div>
                                        <div className="h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                            <div className={`h-full rounded-full ${barTones[index % barTones.length]}`} style={{ width: `${Math.min(100, Math.max(0, item.percentage))}%` }} />
                                        </div>
                                    </div>
                                    <div className="flex items-center justify-between gap-2 sm:justify-end">
                                        <div className="text-right">
                                            <strong className="text-lg font-black text-slate-950">{item.count.toLocaleString('th-TH')} <span className="text-sm font-bold text-slate-500">คน</span></strong>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setSelectedItem(item)}
                                            className="inline-flex items-center gap-1.5 rounded-xl border border-brand-200 bg-brand-50 px-2.5 py-1.5 text-xs font-bold text-brand-800 transition hover:border-brand-400 hover:bg-brand-100 active:scale-95"
                                            title={`ดูรายชื่อนักศึกษาใน ${item.label}`}
                                        >
                                            <UsersThree size={15} weight="bold" />
                                            ดูรายชื่อ
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ol>
                        <div className="grid gap-2 border-t-2 border-slate-300 bg-slate-50 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_150px_170px] sm:items-center sm:px-5">
                            <strong className="text-base font-black text-slate-950">รวมทั้งหมด</strong>
                            <span className="text-sm font-black text-slate-700 sm:text-right">{payload.summary.registered_students > 0 ? '100%' : '0%'}</span>
                            <div className="flex items-center justify-between gap-2 sm:justify-end">
                                <strong className="text-lg font-black text-brand-800 sm:text-right">{payload.summary.registered_students.toLocaleString('th-TH')} <span className="text-sm">คน</span></strong>
                                <button
                                    type="button"
                                    disabled={payload.summary.registered_students === 0}
                                    onClick={() => setSelectedItem({ key: 'all', code: '', label: 'นักศึกษาลงทะเบียนทั้งหมด', count: payload.summary.registered_students, percentage: 100 })}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-brand-200 bg-white px-2.5 py-1.5 text-xs font-bold text-brand-800 transition hover:border-brand-400 hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-50 active:scale-95"
                                    title="ดูรายชื่อนักศึกษาทั้งหมด"
                                >
                                    <UsersThree size={15} weight="bold" />
                                    ดูทั้งหมด
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </Panel>

            <RegistrationStudentListDialog
                isOpen={selectedItem !== null}
                onClose={() => setSelectedItem(null)}
                category={category}
                categoryLabel={selectedCategoryLabel}
                selectedItem={selectedItem}
                term={term}
                filters={filters}
            />
        </div>
    );
}
