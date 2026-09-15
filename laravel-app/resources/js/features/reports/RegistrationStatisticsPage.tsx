import { CalendarBlank, ChartBar, FileXls, FunnelSimple, StackSimple, Trophy, UsersThree, X } from '@phosphor-icons/react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { EmptyState, QueryError, QuerySkeleton } from '../../components/QueryState';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { StatGrid } from '../../components/StatGrid';
import { StatTile } from '../../components/StatTile';
import { useDemoRole } from '../../context/DemoRoleContext';
import { showErrorAlert } from '../../lib/feedback';
import { getFeatureDataWithDemo } from '../api';
import {
    buildRegistrationStatisticsSheets,
    canExportRegistrationStatistics,
    registrationStatisticsFileName,
    type CategoryKey,
    type RegistrationStatisticsPayload,
} from './registrationStatisticsExport';

const categoryOptions: RegistrationStatisticsPayload['categories'] = [
    { key: 'target_group', label: 'กลุ่มเป้าหมาย' },
    { key: 'gender', label: 'เพศ' },
    { key: 'level', label: 'ระดับชั้น' },
    { key: 'occupation', label: 'อาชีพ' },
    { key: 'nationality', label: 'สัญชาติ' },
    { key: 'age', label: 'อายุ' },
];

const emptyFilters: Record<CategoryKey, string> = {
    target_group: '',
    gender: '',
    level: '',
    occupation: '',
    nationality: '',
    age: '',
};

const emptyPayload: RegistrationStatisticsPayload = {
    categories: categoryOptions,
    selected_category: 'target_group',
    selected_category_label: 'กลุ่มเป้าหมาย',
    filter_options: {
        target_group: [], gender: [], level: [], occupation: [], nationality: [], age: [],
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

export function RegistrationStatisticsPage() {
    const { role } = useDemoRole();
    const [category, setCategory] = useState<CategoryKey>('target_group');
    const [term, setTerm] = useState('');
    const [filters, setFilters] = useState<Record<CategoryKey, string>>(emptyFilters);
    const [isExporting, setIsExporting] = useState(false);
    const filterKey = categoryOptions.map((option) => `${option.key}:${filters[option.key]}`).join('|');
    const statistics = useQuery({
        queryKey: ['registration-statistics', category, term, filterKey],
        queryFn: ({ signal }) => {
            const params = new URLSearchParams({ category });
            if (term) params.set('term', term);
            categoryOptions.forEach((option) => {
                const value = filters[option.key];
                if (value) params.set(option.key, value);
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
                if (value) params.set(option.key, value);
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
                description="ดูจำนวนนักศึกษาที่ลงทะเบียน เลือกกรองหลายเงื่อนไขพร้อมกัน และแยกผลตามกลุ่มเป้าหมาย เพศ ระดับชั้น อาชีพ สัญชาติ หรืออายุ"
                icon={ChartBar}
                actions={<div className="flex flex-wrap items-center justify-end gap-2">
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
                description="ข้อมูลถูกจำกัดตามอำเภอ และสำหรับครูจะเห็นเฉพาะกลุ่มเรียนที่ได้รับมอบหมาย"
            >
                <div className="mb-6 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <FunnelSimple className="size-5 text-brand-700" weight="bold" />
                            <div>
                                <h3 className="font-black text-slate-950">กรองข้อมูลร่วมกัน</h3>
                                <p className="text-xs text-slate-500">เลือกได้มากกว่าหนึ่งประเภท เช่น เพศ + ระดับชั้น + อายุ</p>
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
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {categoryOptions.map((option) => (
                            <label key={option.key} className="grid gap-1.5 text-xs font-bold text-slate-600">
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
                <div className="mb-6 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" role="group" aria-label="เลือกประเภทข้อมูลสถิติ">
                    {categoryOptions.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            onClick={() => setCategory(option.key)}
                            aria-pressed={category === option.key}
                            className={`min-h-11 rounded-xl border px-3 py-2 text-sm font-bold transition ${category === option.key ? 'border-brand-700 bg-brand-700 text-white shadow-sm' : 'border-slate-200 bg-white text-slate-700 hover:border-brand-300 hover:bg-brand-50'}`}
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
                        <div className="hidden grid-cols-[minmax(0,1fr)_150px_120px] gap-4 bg-slate-50 px-5 py-3 text-xs font-bold text-slate-500 sm:grid">
                            <span>{payload.selected_category_label}</span>
                            <span className="text-right">สัดส่วน</span>
                            <span className="text-right">จำนวน</span>
                        </div>
                        <ol className="divide-y divide-slate-200">
                            {payload.items.map((item, index) => (
                                <li key={item.key} className="grid gap-3 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_150px_120px] sm:items-center sm:px-5">
                                    <div className="min-w-0">
                                        <div className="flex items-start gap-3">
                                            <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-xs font-black text-slate-600">{index + 1}</span>
                                            <div className="min-w-0">
                                                <p className="font-bold leading-6 text-slate-950">{item.label}</p>
                                                {item.code && <p className="mt-0.5 text-xs text-slate-500">รหัส {item.code}</p>}
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
                                    <div className="flex items-baseline justify-between gap-2 sm:block sm:text-right">
                                        <span className="text-xs font-bold text-slate-500 sm:hidden">จำนวน</span>
                                        <strong className="text-lg font-black text-slate-950">{item.count.toLocaleString('th-TH')} <span className="text-sm font-bold text-slate-500">คน</span></strong>
                                    </div>
                                </li>
                            ))}
                        </ol>
                        <div className="grid gap-2 border-t-2 border-slate-300 bg-slate-50 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_150px_120px] sm:items-center sm:px-5">
                            <strong className="text-base font-black text-slate-950">รวมทั้งหมด</strong>
                            <span className="text-sm font-black text-slate-700 sm:text-right">{payload.summary.registered_students > 0 ? '100%' : '0%'}</span>
                            <strong className="text-lg font-black text-brand-800 sm:text-right">{payload.summary.registered_students.toLocaleString('th-TH')} <span className="text-sm">คน</span></strong>
                        </div>
                    </div>
                )}
            </Panel>
        </div>
    );
}
