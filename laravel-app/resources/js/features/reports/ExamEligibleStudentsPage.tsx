import { CheckCircle, ClipboardText, FunnelSimple, MagnifyingGlass, Prohibit, Student, UsersThree, X } from '@phosphor-icons/react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { DataTable } from '../../components/DataTable';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatGrid } from '../../components/StatGrid';
import { StatTile } from '../../components/StatTile';
import { useDemoRole } from '../../context/DemoRoleContext';
import { getFeatureDataWithDemo } from '../api';

type FilterOption = { value: string | number; label: string };

type ExamEligibleItem = {
    student: {
        code: string;
        full_name: string;
        level: { id: number; label: string };
        group: { code: string; name: string };
    };
    term: string | null;
    exam_status: 'eligible';
};

type ExamEligiblePayload = {
    items: ExamEligibleItem[];
    summary: {
        total_students: number;
        eligible_students: number;
        disqualified_students: number;
        group_count: number;
    };
    terms: string[];
    selected_term: string | null;
};

const emptyPayload: ExamEligiblePayload = {
    items: [],
    summary: { total_students: 0, eligible_students: 0, disqualified_students: 0, group_count: 0 },
    terms: [],
    selected_term: null,
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

export function ExamEligibleStudentsPage() {
    const { role } = useDemoRole();
    const [term, setTerm] = useState('');
    const [search, setSearch] = useState('');
    const [level, setLevel] = useState('');
    const [group, setGroup] = useState('');
    const deferredSearch = useDeferredValue(search);
    const canFilterGroups = role === 'teacher' || role === 'admin' || role === 'super_admin';
    const directoryOptions = useQuery({
        queryKey: ['exam-eligible-filter-options'],
        queryFn: ({ signal }) => getFeatureDataWithDemo<unknown[]>('/api/v1/students?per_page=1', [], signal),
        enabled: canFilterGroups,
        staleTime: 60_000,
    });
    const groupOptions = useMemo(() => {
        const meta = directoryOptions.data?.meta as unknown as { filter_options?: { groups?: FilterOption[] } } | undefined;
        return meta?.filter_options?.groups ?? [];
    }, [directoryOptions.data]);
    const report = useQuery({
        queryKey: ['exam-eligible-students', term, deferredSearch, level, group],
        queryFn: ({ signal }) => {
            const params = new URLSearchParams();
            if (term) params.set('term', term);
            if (deferredSearch.trim()) params.set('search', deferredSearch.trim());
            if (level) params.set('level', level);
            if (group) params.set('group', group);

            const query = params.toString();
            return getFeatureDataWithDemo<ExamEligiblePayload>(
                `/api/v1/reports/students/exam-eligible${query ? `?${query}` : ''}`,
                emptyPayload,
                signal,
            );
        },
        placeholderData: keepPreviousData,
    });
    const payload = report.data?.data;
    const termOptions = useMemo(
        () => Array.from(new Set([...(term ? [term] : []), ...(payload?.terms ?? [])])).sort(compareAcademicTermsDescending),
        [payload?.terms, term],
    );
    const activeFilterCount = [search.trim(), level, group].filter(Boolean).length;

    useEffect(() => {
        if (term !== '') return;
        const latestTerm = [...(payload?.terms ?? [])].sort(compareAcademicTermsDescending)[0] ?? payload?.selected_term;
        if (latestTerm) setTerm(latestTerm);
    }, [payload?.selected_term, payload?.terms, term]);

    const columns = useMemo<ColumnDef<ExamEligibleItem>[]>(() => [
        {
            id: 'student',
            header: 'นักศึกษา',
            accessorFn: (item) => item.student.full_name,
            size: 320,
            cell: ({ row }) => (
                <div>
                    <p className="font-bold text-slate-950">{row.original.student.full_name}</p>
                    <p className="mt-0.5 text-xs text-slate-500">รหัส {row.original.student.code}</p>
                </div>
            ),
        },
        {
            id: 'group',
            header: 'กลุ่มเรียน',
            accessorFn: (item) => item.student.group.name,
            size: 260,
            cell: ({ row }) => (
                <div>
                    <p className="font-semibold text-slate-900">{row.original.student.group.name}</p>
                    {row.original.student.group.code && row.original.student.group.code !== row.original.student.group.name && (
                        <p className="mt-0.5 text-xs text-slate-500">{row.original.student.group.code}</p>
                    )}
                </div>
            ),
        },
        {
            id: 'level',
            header: 'ระดับชั้น',
            accessorFn: (item) => item.student.level.label,
            size: 220,
        },
    ], []);

    const clearFilters = () => {
        setSearch('');
        setLevel('');
        setGroup('');
    };

    return (
        <div>
            <PageHeader
                category="รายงานนักศึกษา"
                title="นักศึกษามีสิทธิ์สอบ"
                description="รายชื่อนักศึกษาที่ไม่พบสถานะตัดสิทธิ์สอบในภาคเรียนที่เลือก โดยไม่นำคะแนนผลการเรียนมาเป็นเกณฑ์"
                icon={ClipboardText}
                actions={(
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
                )}
            />

            {payload && (
                <StatGrid>
                    <StatTile label="ผู้มีสิทธิ์สอบ" value={`${payload.summary.eligible_students.toLocaleString('th-TH')} คน`} detail={`ภาคเรียน ${payload.selected_term ?? '-'}`} icon={CheckCircle} tone="emerald" />
                    <StatTile label="นักศึกษาทั้งหมด" value={`${payload.summary.total_students.toLocaleString('th-TH')} คน`} detail="ในขอบเขตและตัวกรองปัจจุบัน" icon={Student} tone="sky" />
                    <StatTile label="ถูกตัดสิทธิ์สอบ" value={`${payload.summary.disqualified_students.toLocaleString('th-TH')} คน`} detail="ไม่นำมาแสดงในรายชื่อด้านล่าง" icon={Prohibit} tone="rose" />
                    <StatTile label="กลุ่มเรียน" value={`${payload.summary.group_count.toLocaleString('th-TH')} กลุ่ม`} detail="เฉพาะกลุ่มที่มีผู้มีสิทธิ์สอบ" icon={UsersThree} tone="amber" />
                </StatGrid>
            )}

            <Panel title="รายชื่อผู้มีสิทธิ์สอบ" description="ข้อมูลถูกจำกัดตามอำเภอ และครูจะเห็นเฉพาะกลุ่มเรียนที่ได้รับมอบหมาย">
                <div className="mb-5 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <FunnelSimple className="size-5 text-brand-700" weight="bold" aria-hidden="true" />
                            <div>
                                <h2 className="font-black text-slate-950">ตัวกรองรายชื่อ</h2>
                                <p className="text-xs text-slate-500">ค้นหาชื่อหรือรหัส และกรองตามระดับชั้นหรือกลุ่มเรียน</p>
                            </div>
                        </div>
                        {activeFilterCount > 0 && (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:border-rose-300 hover:text-rose-700"
                            >
                                <X className="size-4" weight="bold" aria-hidden="true" />
                                ล้างตัวกรอง ({activeFilterCount})
                            </button>
                        )}
                    </div>
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <label>
                            <span className="mb-2 block text-sm font-bold text-slate-700">ค้นหานักศึกษา</span>
                            <span className="relative block">
                                <MagnifyingGlass size={18} className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="ชื่อ นามสกุล หรือรหัสนักศึกษา"
                                    className="h-11 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-sm placeholder:text-slate-400"
                                />
                            </span>
                        </label>
                        <label>
                            <span className="mb-2 block text-sm font-bold text-slate-700">ระดับชั้น</span>
                            <select
                                value={level}
                                onChange={(event) => { setLevel(event.target.value); setGroup(''); }}
                                className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                            >
                                <option value="">ทุกระดับชั้น</option>
                                <option value="1">ประถมศึกษา</option>
                                <option value="2">มัธยมศึกษาตอนต้น</option>
                                <option value="3">มัธยมศึกษาตอนปลาย</option>
                            </select>
                        </label>
                        <label className="md:col-span-2 xl:col-span-1">
                            <span className="mb-2 block text-sm font-bold text-slate-700">กลุ่มเรียน</span>
                            <select
                                value={group}
                                onChange={(event) => setGroup(event.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"
                            >
                                <option value="">ทุกกลุ่มเรียน</option>
                                {groupOptions.map((option) => <option key={String(option.value)} value={String(option.value)}>{option.label}</option>)}
                            </select>
                        </label>
                    </div>
                </div>

                {report.isPending && <QuerySkeleton rows={7} />}
                {report.isError && <QueryError onRetry={() => report.refetch()} />}
                {payload && (
                    <DataTable
                        data={payload.items}
                        columns={columns}
                        pageSize={25}
                        responsiveMode="cards"
                        disableExport
                        emptyTitle="ไม่พบนักศึกษาที่มีสิทธิ์สอบ"
                        emptyDescription="ลองเปลี่ยนภาคเรียน ระดับชั้น กลุ่มเรียน หรือคำค้นหา"
                    />
                )}
            </Panel>
        </div>
    );
}
