import {
    ArrowLeft,
    BookOpenText,
    CalendarCheck,
    ChartLineUp,
    CheckCircle,
    ClockCounterClockwise,
    GraduationCap,
    Heart,
    IdentificationCard,
    Eye,
    MagnifyingGlass,
    Printer,
    Sparkle,
    Student,
    UsersThree,
    X,
} from '@phosphor-icons/react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Button, Field, Input, Select } from '../../components/MaterialUI';
import { useDeferredValue, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import { DataTable } from '../../components/DataTable';
import { EllipsisText } from '../../components/EllipsisText';
import { PageHeader } from '../../components/PageHeader';
import { Pagination } from '../../components/Pagination';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatTile } from '../../components/StatTile';
import { StatGrid } from '../../components/StatGrid';
import { StatusBadge, type StatusTone } from '../../components/StatusBadge';
import { getFeatureDataWithDemo } from '../api';
import { useDemoRole } from '../../context/DemoRoleContext';

type StudentRow = {
    id: number | string;
    code: string;
    name: string;
    level: string;
    groupCode: string;
    group: string;
    statusCode: string;
    status: string;
    district: string;
    currentTerm: string;
    citizenIdMasked: string;
    birthDate: string;
    gender: string;
    gpax: number;
    creditsEarned: number;
    creditsCurrent: number;
    creditsRequired: number;
};

const demoStudents: StudentRow[] = [
    { id: 'SENA-670142', code: 'SENA-670142', name: 'ณัฐชา ศรีสวัสดิ์', level: 'มัธยมศึกษาตอนปลาย', groupCode: 'G-01', group: 'กลุ่มวันอาทิตย์ 1', statusCode: 'studying', status: 'กำลังศึกษา', district: 'อำเภอเสนา', currentTerm: '1/2569', citizenIdMasked: '1-xxxx-xxxxx-xx-1', birthDate: '01/01/2548', gender: 'หญิง', gpax: 3.24, creditsEarned: 68, creditsCurrent: 73, creditsRequired: 76 },
];

function studentStatusTone(status: string): StatusTone {
    return status === 'studying' ? 'success' : status === 'graduated' || status === 'transferred' ? 'info' : 'warning';
}

function sortAcademicTermsDescending(left: string, right: string): number {
    const key = (value: string) => {
        const match = value.match(/^(\d{1,2})\/(\d{2,4})$/);
        if (!match) return 0;
        const semester = Number(match[1]);
        const rawYear = Number(match[2]);
        const year = rawYear < 100 ? 2500 + rawYear : rawYear < 2400 ? rawYear + 543 : rawYear;
        return (year * 10) + semester;
    };

    return key(right) - key(left);
}

function DetailDialog({ title, description, items, onClose }: {
    title: string;
    description?: string;
    items: Array<{ label: string; value: ReactNode }>;
    onClose: () => void;
}) {
    useEffect(() => {
        const previousOverflow = document.body.style.overflow;
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-[70] grid place-items-center p-4" role="presentation">
            <button type="button" className="absolute inset-0 bg-slate-950/55" onClick={onClose} aria-label="ปิดรายละเอียด" />
            <section role="dialog" aria-modal="true" aria-labelledby="record-detail-title" className="relative max-h-[min(720px,calc(100dvh-2rem))] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-950/20">
                <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-100 bg-white px-5 py-4 sm:px-6">
                    <div className="min-w-0">
                        <h2 id="record-detail-title" className="text-xl font-black leading-8 text-slate-950">{title}</h2>
                        {description && <p className="mt-1 text-sm leading-6 text-slate-600">{description}</p>}
                    </div>
                    <button type="button" onClick={onClose} autoFocus className="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-100 active:scale-[0.98]" aria-label="ปิดรายละเอียด"><X size={20} weight="bold" /></button>
                </header>
                <dl className="grid gap-3 p-5 sm:grid-cols-2 sm:p-6">
                    {items.map((item) => (
                        <div key={item.label} className="min-w-0 rounded-xl bg-slate-50 p-4">
                            <dt className="text-xs font-bold leading-5 text-slate-500">{item.label}</dt>
                            <dd className="mt-1 break-words text-base font-bold leading-7 text-slate-950">{item.value ?? '-'}</dd>
                        </div>
                    ))}
                </dl>
            </section>
        </div>
    );
}

type FilterOption = { value: string | number; label: string };
type StudentDirectoryMeta = {
    mode?: string;
    pagination: { current_page: number; per_page: number; total: number; last_page: number; from: number | null; to: number | null };
    filter_options: { levels: FilterOption[]; groups: FilterOption[]; statuses: FilterOption[]; terms: FilterOption[]; districts: FilterOption[] };
    summary: { total: number; studying: number; graduated: number; transferred: number; male: number; female: number; groups: number; levels: number; average_gpax: number | null };
};

export function StudentsPage() {
    const { role } = useDemoRole();
    const [search, setSearch] = useState('');
    const [level, setLevel] = useState('');
    const [group, setGroup] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(25);
    const deferredSearch = useDeferredValue(search);
    const canFilterGroups = role === 'admin' || role === 'super_admin';

    useEffect(() => setPage(1), [deferredSearch, level, group, status, perPage]);
    useEffect(() => { if (!canFilterGroups && group !== '') setGroup(''); }, [canFilterGroups, group]);

    const queryString = useMemo(() => {
        const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
        if (deferredSearch) params.set('search', deferredSearch);
        if (level) params.set('level', level);
        if (canFilterGroups && group) params.set('group', group);
        if (status) params.set('status', status);

        return params.toString();
    }, [canFilterGroups, deferredSearch, group, level, page, perPage, status]);

    const students = useQuery({
        queryKey: ['students', { search: deferredSearch, level, group, status, page, perPage }],
        queryFn: async ({ signal }) => {
            type StudentApi = {
                code: string;
                full_name: string;
                district: { name: string };
                level: { id: number; label: string };
                group: { code: string; name: string };
                status: { code: string; label: string };
                current_term: string;
                demographics?: { citizen_id?: string; citizen_id_masked?: string; birth_date?: string; gender?: string };
                academic: { gpax: number; credits_earned: number; credits_current: number; credits_required: number };
            };
            const response = await getFeatureDataWithDemo<StudentRow[] | StudentApi[]>(`/api/v1/students?${queryString}`, demoStudents, signal);
            const data = response.data.map((student): StudentRow => 'full_name' in student ? {
                id: student.code,
                code: student.code,
                name: student.full_name,
                level: student.level.label,
                groupCode: student.group.code,
                group: student.group.name,
                statusCode: student.status.code,
                status: student.status.label,
                district: student.district.name,
                currentTerm: student.current_term,
                citizenIdMasked: student.demographics?.citizen_id ?? student.demographics?.citizen_id_masked ?? '-',
                birthDate: student.demographics?.birth_date ?? '-',
                gender: student.demographics?.gender ?? '-',
                gpax: student.academic.gpax,
                creditsEarned: student.academic.credits_earned,
                creditsCurrent: student.academic.credits_current,
                creditsRequired: student.academic.credits_required,
            } : student);
            return { ...response, data, meta: response.meta as unknown as StudentDirectoryMeta };
        },
        placeholderData: keepPreviousData,
    });

    const rows = students.data?.data ?? [];
    const meta = students.data?.meta;
    const pagination = meta?.pagination;
    const summary = meta?.summary;

    const columns = useMemo<ColumnDef<StudentRow>[]>(() => [
        {
            accessorKey: 'name',
            header: 'นักศึกษา',
            size: 240,
            meta: { compactSize: 120 },
            cell: ({ row }) => (
                <div className="min-w-0">
                    <Link to={`/students/${row.original.id}`} className="block min-w-0 text-sm font-black leading-6 text-slate-950 hover:text-brand-700"><EllipsisText title={row.original.name}>{row.original.name}</EllipsisText></Link>
                    <p className="mt-0.5 whitespace-nowrap font-mono text-xs text-slate-500">{row.original.code}</p>
                </div>
            ),
        },
        { accessorKey: 'citizenIdMasked', header: 'เลขบัตรประชาชน', size: 175, meta: { compactSize: 92 }, cell: ({ getValue }) => <span className="whitespace-nowrap font-mono text-sm font-bold text-slate-700">{getValue<string>()}</span> },
        { accessorKey: 'birthDate', header: 'วันเกิด', size: 120, meta: { compactSize: 62, compactTextAlign: 'center' }, cell: ({ getValue }) => <span className="whitespace-nowrap">{getValue<string>()}</span> },
        {
            accessorKey: 'group',
            header: 'กลุ่มเรียน',
            size: 245,
            meta: { compactSize: 116 },
            cell: ({ row }) => <div className="min-w-0"><EllipsisText title={row.original.group}><span className="text-[13px] font-semibold leading-5 text-slate-900">{row.original.group}</span></EllipsisText><EllipsisText title={`${row.original.level} · ${row.original.groupCode}`}><span className="mt-0.5 text-[10px] leading-4 text-slate-500">{row.original.level} · {row.original.groupCode}</span></EllipsisText></div>,
        },
        { accessorKey: 'gender', header: 'เพศ', size: 75, meta: { compactSize: 42, compactTextAlign: 'center' } },
        {
            id: 'details',
            header: 'รายละเอียด',
            size: 220,
            meta: { compactSize: 86, compactTextAlign: 'center' },
            enableSorting: false,
            cell: ({ row }) => <div className="flex flex-wrap justify-center gap-1.5"><Link to={`/students/${encodeURIComponent(String(row.original.id))}`} className="responsive-table-action inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1.5 text-xs font-bold text-brand-800 hover:bg-brand-100 active:scale-[0.98]" aria-label={`เปิดรายละเอียด ${row.original.name}`}><Eye size={16} weight="bold" /> <span>เปิดดู</span></Link><Link to={`/learning/schedule?student=${encodeURIComponent(String(row.original.id))}&auto=1`} className="responsive-table-action inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs font-bold text-sky-800 hover:bg-sky-100 active:scale-[0.98]" aria-label={`สร้างตารางสอบ ${row.original.name}`}><Printer size={16} weight="bold" /> <span>ตารางสอบ</span></Link></div>,
        },
    ], []);

    const resetFilters = () => {
        setSearch('');
        setLevel('');
        setGroup('');
        setStatus('');
        setPage(1);
    };

    return (
        <div>
            <PageHeader
                category="ฐานข้อมูลนักศึกษา"
                title="ค้นหานักศึกษาได้รวดเร็ว"
                description="แสดงข้อมูลนักศึกษาภาคเรียนล่าสุดครบทุกหน้า พร้อมกรองระดับ กลุ่มเรียน และสถานะตามขอบเขตที่รับผิดชอบ"
                icon={UsersThree}
                actions={<StatusBadge tone="info">ข้อมูลจริงล่าสุด</StatusBadge>}
            />

            <div className="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="นักศึกษาที่พบ" value={pagination?.total ?? 0} detail="ครบทุกหน้าตามตัวกรอง" icon={Student} tone="sky" />
                <StatTile label="กลุ่มเรียน" value={summary?.groups ?? 0} detail="กลุ่มที่อยู่ในผลลัพธ์" icon={GraduationCap} tone="amber" />
                <StatTile label="ชาย / หญิง" value={`${summary?.male ?? 0} / ${summary?.female ?? 0}`} detail={`เฉลี่ย GPAX ${summary?.average_gpax ?? '-'}`} icon={UsersThree} tone="rose" />
            </div>

            <Panel title="รายชื่อนักศึกษา" description={pagination ? `แสดง ${pagination.from ?? 0}-${pagination.to ?? 0} จากทั้งหมด ${pagination.total} คน · แสดงข้อมูลตามสิทธิ์และกลุ่มที่รับผิดชอบ` : 'ข้อมูลส่วนบุคคลละเอียดจะแสดงตามสิทธิ์ของผู้ใช้งาน'}>
                <div className={`mb-5 grid gap-3 md:grid-cols-2 ${canFilterGroups ? 'xl:grid-cols-[minmax(260px,1.5fr)_minmax(180px,0.8fr)_minmax(220px,1fr)_minmax(170px,0.8fr)]' : 'xl:grid-cols-[minmax(300px,1.5fr)_minmax(200px,0.8fr)_minmax(190px,0.8fr)]'}`}>
                    <Field label="ค้นหา">
                        <Input value={search} onChange={(_, data) => setSearch(data.value)} contentBefore={<MagnifyingGlass size={18} aria-hidden="true" />} placeholder="ชื่อ รหัส เลขบัตร หรือกลุ่ม" size="large" />
                    </Field>
                    <Field label="ระดับการศึกษา">
                        <Select value={level} onChange={(_, data) => setLevel(data.value)} size="large">
                            <option value="">ทั้งหมด</option>
                            <option value="1">ประถมศึกษา</option>
                            <option value="2">มัธยมศึกษาตอนต้น</option>
                            <option value="3">มัธยมศึกษาตอนปลาย</option>
                        </Select>
                    </Field>
                    {canFilterGroups && <Field label="กลุ่มเรียน">
                        <Select value={group} onChange={(_, data) => setGroup(data.value)} size="large">
                            <option value="">ทุกกลุ่มเรียน</option>
                            {(meta?.filter_options.groups ?? []).map((option) => <option key={String(option.value)} value={String(option.value)}>{option.label}</option>)}
                        </Select>
                    </Field>}
                    <Field label="สถานะ">
                        <Select value={status} onChange={(_, data) => setStatus(data.value)} size="large">
                            <option value="">ทุกสถานะ</option>
                            {(meta?.filter_options.statuses ?? []).map((option) => <option key={String(option.value)} value={String(option.value)}>{option.label}</option>)}
                        </Select>
                    </Field>
                </div>
                {(search || level || group || status) && <Button type="button" appearance="subtle" onClick={resetFilters} className="mb-4">ล้างตัวกรองทั้งหมด</Button>}
                {students.isPending && <QuerySkeleton />}
                {students.isError && <QueryError onRetry={() => students.refetch()} />}
                {students.data && <DataTable data={rows} columns={columns} pageSize={perPage} showPagination={false} minWidth="wide" emptyTitle="ไม่พบนักศึกษา" emptyDescription="ลองเปลี่ยนคำค้น ระดับ หรือกลุ่มเรียน" />}
                {pagination && <Pagination currentPage={pagination.current_page} totalPages={pagination.last_page} totalItems={pagination.total} pageSize={perPage} itemLabel="คน" disabled={students.isFetching} onPageChange={setPage} onPageSizeChange={(nextPageSize) => { setPerPage(nextPageSize); setPage(1); }} />}
            </Panel>
        </div>
    );
}

type StudentDetail = {
    id: number | string;
    code: string;
    name: string;
    level: string;
    groupCode: string;
    group: string;
    district: string;
    statusCode: string;
    status: string;
    currentTerm: string;
    enrollmentTerm: string;
    citizenIdMasked: string;
    birthDate: string;
    gender: string;
    age: string;
    phone: string;
    email: string;
    registeredAddress: string;
    currentAddress: string;
    applicationDate: string;
    lastUpdated: string;
    creditsEarned: number;
    creditsCurrent: number;
    creditsRequired: number;
    compulsory: { earned: number; required: number; remaining: number };
    elective: { earned: number; required: number; remaining: number };
    gpax: number;
    activityHours: number;
    moralResult: string;
};

const demoStudent: StudentDetail = {
    id: 1,
    code: 'SENA-670142',
    name: 'ณัฐชา ศรีสวัสดิ์',
    level: 'มัธยมศึกษาตอนปลาย',
    groupCode: 'G-01',
    group: 'กลุ่มวันอาทิตย์ 1',
    district: 'อำเภอเสนา',
    statusCode: 'studying',
    status: 'กำลังศึกษา',
    currentTerm: '1/2569',
    enrollmentTerm: '1/2567',
    citizenIdMasked: '1-xxxx-xxxxx-xx-1',
    birthDate: '01/01/2548',
    gender: 'หญิง',
    age: '21',
    phone: '0812342841',
    email: 'n***@example.test',
    registeredAddress: '-',
    currentAddress: '-',
    applicationDate: '-',
    lastUpdated: '-',
    creditsEarned: 68,
    creditsCurrent: 73,
    creditsRequired: 76,
    compulsory: { earned: 41, required: 44, remaining: 3 },
    elective: { earned: 27, required: 32, remaining: 5 },
    gpax: 3.24,
    activityHours: 76,
    moralResult: 'ดี',
};

type KpchDetailRow = {
    id: string;
    name: string;
    term: string;
    hours: number;
    category: string;
    completed_on: string | null;
};

type MoralDetailAssessment = {
    term: string;
    categories: Array<{ name: string; items: Array<{ label: string; score: number | null }> }>;
    summary: { score: number; maximum_score: number; percent: number; result: string };
    assessed_on: string | null;
};

type MoralDetailRow = {
    id: string;
    term: string;
    group: string;
    item: string;
    score: number | null;
    result: string;
    assessmentResult: string;
};

type StaffGradeDetailRow = {
    student_code: string;
    subject: { code: string; name: string; credits: number; type: string };
    term: string;
    grade: string | null;
    numeric_grade: number | null;
    is_passed: boolean;
    is_transferred: boolean;
    exam_attended: boolean;
};

type StaffGradeSummary = {
    gpax: number | null;
    earned_credits: number;
    compulsory_credits: number;
    elective_credits: number;
    graded_credits: number;
    registered_subjects: number;
    passed_subjects: number;
};

export function StudentDetailPage() {
    const { studentId = '1' } = useParams();
    const student = useQuery({
        queryKey: ['student', studentId],
        queryFn: async ({ signal }) => {
            type StudentDetailApi = {
                code: string; full_name?: string; name?: { full_name: string }; level: { label: string }; group: { code: string; name: string }; district: { name: string };
                status: { code: string; label: string }; current_term: string; enrollment_term: string;
                contact?: { phone?: string; phone_masked?: string; email?: string; email_masked?: string; registered_address?: string; current_address?: string };
                demographics?: { citizen_id?: string; citizen_id_masked?: string; birth_date?: string; gender?: string; age?: number; application_date?: string; last_updated?: string };
                academic: {
                    credits_earned: number; credits_current: number; credits_required: number; gpax: number; kpch_hours: number; moral_result: string;
                    compulsory: { earned: number; required: number; remaining: number };
                    elective: { earned: number; required: number; remaining: number };
                };
            };
            const response = await getFeatureDataWithDemo<StudentDetail | StudentDetailApi>(`/api/v1/students/${encodeURIComponent(studentId)}`, demoStudent, signal);
            if ('academic' in response.data) {
                const api = response.data;
                const data: StudentDetail = {
                    id: api.code, code: api.code, name: api.name?.full_name ?? api.full_name ?? api.code, level: api.level.label, groupCode: api.group.code, group: api.group.name,
                    district: api.district.name, statusCode: api.status.code, status: api.status.label, currentTerm: api.current_term, enrollmentTerm: api.enrollment_term,
                    citizenIdMasked: api.demographics?.citizen_id ?? api.demographics?.citizen_id_masked ?? '-', birthDate: api.demographics?.birth_date ?? '-', gender: api.demographics?.gender ?? '-', age: api.demographics?.age ? String(api.demographics.age) : '-',
                    phone: api.contact?.phone ?? api.contact?.phone_masked ?? '-', email: api.contact?.email ?? api.contact?.email_masked ?? '-',
                    registeredAddress: api.contact?.registered_address ?? '-', currentAddress: api.contact?.current_address ?? api.contact?.registered_address ?? '-',
                    applicationDate: api.demographics?.application_date ?? '-', lastUpdated: api.demographics?.last_updated ?? '-',
                    creditsEarned: api.academic.credits_earned, creditsCurrent: api.academic.credits_current, creditsRequired: api.academic.credits_required,
                    compulsory: api.academic.compulsory, elective: api.academic.elective, gpax: api.academic.gpax, activityHours: api.academic.kpch_hours, moralResult: api.academic.moral_result,
                };
                return { ...response, data };
            }
            return { ...response, data: response.data };
        },
    });

    if (student.isPending) return <QuerySkeleton rows={7} />;
    if (student.isError) return <QueryError onRetry={() => student.refetch()} />;

    const data = student.data.data;
    return (
        <div>
            <Link to="/students" className="mb-4 inline-flex items-center gap-2 text-sm font-bold text-brand-700 hover:text-brand-900">
                <ArrowLeft size={17} weight="bold" /> กลับไปรายชื่อนักศึกษา
            </Link>
            <PageHeader category={data.code} title={data.name} description={`${data.level} · ${data.group}`} icon={IdentificationCard} actions={<StatusBadge tone={studentStatusTone(data.statusCode)}>{data.status}</StatusBadge>} />
            <div className="space-y-5">
                <Panel title="ข้อมูลประจำตัวและการศึกษา" description="จัดวางข้อมูลเป็นช่องแยกเพื่อให้อ่านง่าย และแสดงข้อมูลส่วนบุคคลเฉพาะผู้ใช้ที่มีสิทธิ์">
                    <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {[
                            ['เลขบัตรประชาชน', data.citizenIdMasked], ['วันเกิด', data.birthDate], ['เพศ', data.gender], ['อายุ', data.age === '-' ? '-' : `${data.age} ปี`],
                            ['ระดับการศึกษา', data.level], ['กลุ่มเรียน', `${data.group} (${data.groupCode})`], ['ภาคเรียนปัจจุบัน', data.currentTerm], ['ภาคเรียนที่เข้าเรียน', data.enrollmentTerm],
                            ['พื้นที่', data.district], ['วันที่สมัคร', data.applicationDate], ['ปรับปรุงข้อมูลล่าสุด', data.lastUpdated],
                        ].map(([label, value]) => (
                            <div key={label} className="min-w-0 rounded-xl bg-slate-50 p-4">
                                <dt className="text-xs font-bold leading-5 text-slate-500">{label}</dt><dd className="mt-1 break-words text-base font-bold leading-7 text-slate-950">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <div className="mt-6 border-t border-slate-100 pt-5">
                        <h3 className="text-base font-black text-slate-950">ที่อยู่และข้อมูลติดต่อ</h3>
                        <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                            <div className="min-w-0 rounded-xl bg-brand-50 p-4"><dt className="text-xs font-bold text-brand-800">โทรศัพท์</dt><dd className="mt-1 break-words font-bold leading-7 text-slate-950">{data.phone}</dd></div>
                            <div className="min-w-0 rounded-xl bg-brand-50 p-4"><dt className="text-xs font-bold text-brand-800">อีเมล</dt><dd className="mt-1 break-all font-bold leading-7 text-slate-950">{data.email}</dd></div>
                            <div className="min-w-0 rounded-xl bg-slate-50 p-4"><dt className="text-xs font-bold text-slate-500">ที่อยู่ตามทะเบียน</dt><dd className="mt-1 whitespace-pre-wrap break-words font-bold leading-7 text-slate-950">{data.registeredAddress}</dd></div>
                            <div className="min-w-0 rounded-xl bg-slate-50 p-4"><dt className="text-xs font-bold text-slate-500">ที่อยู่ปัจจุบัน</dt><dd className="mt-1 whitespace-pre-wrap break-words font-bold leading-7 text-slate-950">{data.currentAddress}</dd></div>
                        </dl>
                    </div>
                </Panel>
            </div>
        </div>
    );
}

type GradeRow = { code: string; subject: string; credits: number; type: 'compulsory' | 'elective'; grade: string; term: string };
const demoGrades: GradeRow[] = [
    { code: 'ทช31001', subject: 'เศรษฐกิจพอเพียง', credits: 1, type: 'compulsory', grade: '3.5', term: '1/2569' },
    { code: 'พท31001', subject: 'ภาษาไทย', credits: 5, type: 'compulsory', grade: '3.0', term: '1/2569' },
    { code: 'พว31001', subject: 'วิทยาศาสตร์', credits: 5, type: 'compulsory', grade: '3.5', term: '1/2569' },
    { code: 'สค32034', subject: 'การเงินเพื่อชีวิต', credits: 3, type: 'elective', grade: '4.0', term: '1/2569' },
];

export function GradesPage() {
    const { role } = useDemoRole();

    return role === 'student' ? <StudentGradesPage /> : <StaffStudentMetricPage kind="grades" />;
}

function StudentGradesPage() {
    const [term, setTerm] = useState('');
    const [selectedGrade, setSelectedGrade] = useState<GradeRow | null>(null);
    const grades = useQuery({
        queryKey: ['grades', 'all'],
        queryFn: ({ signal }) => getFeatureDataWithDemo<GradeRow[]>('/api/v1/grades', demoGrades, signal),
    });
    const rows = grades.data?.data ?? [];
    const terms = useMemo(() => Array.from(new Set(rows.map((row) => row.term).filter(Boolean))).sort(sortAcademicTermsDescending), [rows]);
    const filteredRows = useMemo(() => term ? rows.filter((row) => row.term === term) : rows, [rows, term]);
    const summary = useMemo(() => {
        let points = 0;
        let credits = 0;
        let earned = 0;
        let compulsory = 0;
        let elective = 0;
        let passed = 0;
        rows.forEach((row) => {
            const grade = Number(row.grade);
            if (Number.isFinite(grade) && grade >= 1) {
                points += grade * row.credits;
                credits += row.credits;
                earned += row.credits;
                if (row.type === 'compulsory') compulsory += row.credits;
                if (row.type === 'elective') elective += row.credits;
                passed += 1;
            }
        });

        return { gpax: credits > 0 ? (Math.floor((points / credits) * 100) / 100).toFixed(2) : '-', earned, compulsory, elective, passed };
    }, [rows]);
    const columns = useMemo<ColumnDef<GradeRow>[]>(() => [
        { accessorKey: 'code', header: 'รหัสวิชา', size: 100, meta: { compactSize: 72 } },
        { accessorKey: 'subject', header: 'รายวิชา', size: 280, meta: { compactSize: 168 }, cell: ({ getValue }) => <span className="font-bold text-slate-950">{getValue<string>()}</span> },
        { accessorKey: 'credits', header: 'หน่วยกิต', size: 92, meta: { compactSize: 58, compactTextAlign: 'center' } },
        { accessorKey: 'grade', header: 'ผลการเรียน', size: 124, meta: { compactSize: 70, compactHeader: 'เกรด', compactTextAlign: 'center' }, cell: ({ getValue }) => { const value = getValue<string>(); const numeric = Number(value); const tone: StatusTone = Number.isFinite(numeric) ? (numeric >= 3 ? 'success' : numeric >= 1.5 ? 'warning' : 'danger') : 'neutral'; return <StatusBadge tone={tone}>{value || '-'}</StatusBadge>; } },
        { accessorKey: 'term', header: 'ภาคเรียน', size: 104, meta: { compactSize: 68, compactTextAlign: 'center' } },
        { id: 'details', header: 'รายละเอียด', size: 116, meta: { compactSize: 36, compactTextAlign: 'center' }, enableSorting: false, cell: ({ row }) => <button type="button" onClick={() => setSelectedGrade(row.original)} className="responsive-table-action inline-flex items-center gap-2 whitespace-nowrap rounded-xl border border-brand-200 bg-brand-50 px-3 py-2 text-sm font-bold text-brand-800 hover:bg-brand-100 active:scale-[0.98]" aria-label={`ดูรายละเอียดวิชา ${row.original.subject}`}><Eye size={18} weight="bold" /> <span>เปิดดู</span></button> },
    ], []);

    return (
        <div>
            <PageHeader title="ผลการเรียนของฉัน" description="ดูเกรด หน่วยกิต และความก้าวหน้ารายภาคเรียนได้ในหน้าจอเดียว" icon={ChartLineUp} category="ความก้าวหน้าการเรียน" />
            <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <StatTile label="GPAX" value={summary.gpax} detail="คำนวณจากผลการเรียนจริง" icon={ChartLineUp} />
                <StatTile label="หน่วยกิตสะสม" value={summary.earned} detail="รายวิชาที่ผ่านแล้ว" icon={BookOpenText} tone="sky" />
                <StatTile label="หน่วยกิตวิชาบังคับ" value={summary.compulsory} detail="วิชาบังคับที่ผ่านแล้ว" icon={BookOpenText} tone="amber" />
                <StatTile label="หน่วยกิตวิชาเลือก" value={summary.elective} detail="วิชาเลือกที่ผ่านแล้ว" icon={Sparkle} tone="rose" />
                <StatTile label="รายวิชาที่ผ่าน" value={summary.passed} detail={`จากทั้งหมด ${rows.length} รายการ`} icon={CheckCircle} tone="amber" />
            </div>
            <Panel title="ผลการเรียนรายวิชา" action={(
                <label className="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <span>ภาคเรียน</span>
                    <select value={term} onChange={(event) => setTerm(event.target.value)} className="h-10 rounded-xl border border-slate-300 bg-white px-3 text-sm">
                        <option value="">ทุกภาคเรียน</option>
                        {terms.map((item) => <option key={item} value={item}>{item}</option>)}
                    </select>
                </label>
            )}>
                {grades.isPending && <QuerySkeleton />}
                {grades.isError && <QueryError onRetry={() => grades.refetch()} />}
                {grades.data && <DataTable data={filteredRows} columns={columns} responsiveMode="compact-table" emptyTitle="ไม่พบข้อมูลผลการเรียน" emptyDescription="ยังไม่มีข้อมูลเกรดในภาคเรียนที่เลือก" />}
            </Panel>
            {selectedGrade && <DetailDialog title={selectedGrade.subject} description="รายละเอียดผลการเรียนรายวิชา" onClose={() => setSelectedGrade(null)} items={[
                { label: 'รหัสวิชา', value: selectedGrade.code }, { label: 'ภาคเรียน', value: selectedGrade.term }, { label: 'หน่วยกิต', value: selectedGrade.credits }, { label: 'ผลการเรียน', value: selectedGrade.grade || '-' }, { label: 'เกรดเฉลี่ยสะสม (GPAX)', value: summary.gpax }, { label: 'สรุปรายวิชา', value: `ผ่าน ${summary.passed} จาก ${rows.length} วิชา` },
            ]} />}
        </div>
    );
}

type LearningProfile = {
    name: string;
    code: string;
    level: string;
    group: string;
    advisor: string;
    currentTerm: string;
    enrollmentStatus: string;
    nextMeeting: string;
};

const demoLearningProfile: LearningProfile = {
    name: 'ณัฐชา ศรีสวัสดิ์', code: 'SENA-670142', level: 'มัธยมศึกษาตอนปลาย', group: 'กลุ่มวันอาทิตย์ 1', advisor: 'ครูสุภาวดี รักษ์เรียน', currentTerm: '1/2569', enrollmentStatus: 'ลงทะเบียนแล้ว', nextMeeting: 'อาทิตย์ 19 ก.ค. เวลา 09:00 น.',
};

export function MyLearningPage() {
    const profile = useQuery({ queryKey: ['my-learning'], queryFn: ({ signal }) => getFeatureDataWithDemo<LearningProfile>('/api/v1/my-learning', demoLearningProfile, signal) });
    if (profile.isPending) return <QuerySkeleton rows={6} />;
    if (profile.isError) return <QueryError onRetry={() => profile.refetch()} />;
    const data = profile.data.data;
    return (
        <div>
            <PageHeader title={`ข้อมูลการเรียนของ ${data.name}`} description="ตรวจข้อมูลประจำตัว กลุ่มเรียน และสถานะลงทะเบียนของคุณ" icon={Student} category={data.code} actions={<StatusBadge tone="success">{data.enrollmentStatus}</StatusBadge>} />
            <div className="grid gap-5 lg:grid-cols-[1fr_0.8fr]">
                <Panel title="ข้อมูลภาคเรียนปัจจุบัน">
                    <dl className="grid gap-4 sm:grid-cols-2">
                        {[["ระดับการศึกษา", data.level], ["กลุ่มเรียน", data.group], ["ครูที่ปรึกษา", data.advisor], ["ภาคเรียน", data.currentTerm]].map(([label, value]) => (
                            <div key={label} className="rounded-2xl bg-slate-50 p-4"><dt className="text-xs font-bold text-slate-500">{label}</dt><dd className="mt-1 font-bold text-slate-900">{value}</dd></div>
                        ))}
                    </dl>
                </Panel>
                <Panel title="นัดหมายถัดไป" className="border-amber-200 bg-amber-50">
                    <CalendarCheck size={30} weight="duotone" className="text-amber-800" />
                    <p className="mt-4 text-lg font-bold text-slate-950">วันพบกลุ่ม</p>
                    <p className="mt-1 text-sm leading-6 text-slate-700">{data.nextMeeting}</p>
                    <Link to="/learning/calendar" className="mt-5 inline-flex whitespace-nowrap rounded-full bg-amber-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-amber-900 active:scale-[0.98]">ดูปฏิทิน</Link>
                </Panel>
            </div>
        </div>
    );
}

type AchievementRow = { term: string; group?: string; item: string; result: string; note: string; hours?: number; score?: number | null };

const achievementConfig = {
    kpch: {
        title: 'กิจกรรม กพช.', description: 'ติดตามชั่วโมงกิจกรรมและรายการที่ผ่านการรับรองแล้ว', icon: Sparkle,
        data: [{ term: '1/2569', item: 'จิตอาสาพัฒนาศูนย์การเรียน', result: '12 ชั่วโมง', note: 'รับรองแล้ว' }, { term: '2/2568', item: 'โครงการอ่านหนังสือให้น้อง', result: '16 ชั่วโมง', note: 'รับรองแล้ว' }],
        endpoint: '/api/v1/kpch',
    },
    moral: {
        title: 'ผลประเมินคุณธรรม', description: 'ดูผลประเมินรายภาคเรียนและหัวข้อที่ควรพัฒนาต่อ', icon: Heart,
        data: [{ term: '1/2569', item: 'ความรับผิดชอบ', result: 'ดีมาก', note: 'ผ่าน' }, { term: '1/2569', item: 'ความซื่อสัตย์', result: 'ดีมาก', note: 'ผ่าน' }, { term: '1/2569', item: 'จิตอาสา', result: 'ดี', note: 'ผ่าน' }],
        endpoint: '/api/v1/moral',
    },
};

export function AchievementPage({ kind }: { kind: keyof typeof achievementConfig }) {
    const { role } = useDemoRole();

    return role === 'student' ? <StudentAchievementPage kind={kind} /> : <StaffStudentMetricPage kind={kind} />;
}

function StudentAchievementPage({ kind }: { kind: keyof typeof achievementConfig }) {
    const config = achievementConfig[kind];
    const [term, setTerm] = useState('');
    const [selectedAchievement, setSelectedAchievement] = useState<AchievementRow | null>(null);
    const query = useQuery({ queryKey: [kind], queryFn: ({ signal }) => getFeatureDataWithDemo<AchievementRow[]>(config.endpoint, config.data, signal) });
    const rows = query.data?.data ?? [];
    const terms = useMemo(() => Array.from(new Set(rows.map((row) => row.term).filter(Boolean))).sort(sortAcademicTermsDescending), [rows]);
    const filteredRows = useMemo(() => term ? rows.filter((row) => row.term === term) : rows, [rows, term]);
    const latestTerm = terms[0] ?? '-';
    const totalHours = rows.reduce((sum, row) => sum + (row.hours ?? (Number.parseFloat(row.result) || 0)), 0);
    const latestMoralRows = rows.filter((row) => row.term === latestTerm && row.score !== null && row.score !== undefined);
    const moralAverage = latestMoralRows.length > 0 ? latestMoralRows.reduce((sum, row) => sum + Number(row.score), 0) / latestMoralRows.length : null;
    const moralLabel = moralAverage === null ? 'ยังไม่มีผล' : moralAverage >= 90 ? 'ดีมาก' : moralAverage >= 70 ? 'ดี' : moralAverage >= 50 ? 'พอใช้' : 'ปรับปรุง';
    const columns = useMemo<ColumnDef<AchievementRow>[]>(() => [
        { accessorKey: 'term', header: 'ภาคเรียน', size: 104, meta: { compactSize: 68, compactTextAlign: 'center' } },
        ...(kind === 'moral' ? [{ accessorKey: 'group', header: 'หมวดคุณธรรม', size: 180, meta: { compactSize: 105 } } as ColumnDef<AchievementRow>] : []),
        { accessorKey: 'item', header: 'รายการ', size: 300, meta: { compactSize: kind === 'moral' ? 148 : 178 }, cell: ({ getValue }) => <span className="font-bold text-slate-950">{getValue<string>()}</span> },
        { accessorKey: 'result', header: kind === 'kpch' ? 'ชั่วโมง' : 'คะแนน', size: 112, meta: { compactSize: 62, compactTextAlign: 'center' } },
        { accessorKey: 'note', header: 'ผลประเมิน', size: 136, meta: { compactSize: 70, compactHeader: 'ผล', compactTextAlign: 'center' }, cell: ({ getValue }) => { const value = getValue<string>(); return <StatusBadge tone={value === 'ปรับปรุง' ? 'danger' : value === 'พอใช้' ? 'warning' : 'success'}>{value}</StatusBadge>; } },
        { id: 'details', header: 'รายละเอียด', size: 116, meta: { compactSize: 36, compactTextAlign: 'center' }, enableSorting: false, cell: ({ row }) => <button type="button" onClick={() => setSelectedAchievement(row.original)} className="responsive-table-action inline-flex items-center gap-2 whitespace-nowrap rounded-xl border border-brand-200 bg-brand-50 px-3 py-2 text-sm font-bold text-brand-800 hover:bg-brand-100 active:scale-[0.98]" aria-label={`ดูรายละเอียด ${row.original.item}`}><Eye size={18} weight="bold" /> <span>เปิดดู</span></button> },
    ], [kind]);
    return (
        <div>
            <PageHeader title={config.title} description={config.description} icon={config.icon} category="ข้อมูลนักศึกษา" />
            <div className="mb-5 grid gap-3 sm:grid-cols-2">
                {kind === 'kpch' ? (
                    <>
                        <StatTile label="ชั่วโมงสะสม" value={Number(totalHours.toFixed(1))} detail="เกณฑ์ผ่าน 200 ชั่วโมง" icon={ClockCounterClockwise} tone="amber" />
                        <StatTile label="กิจกรรมทั้งหมด" value={rows.length} detail={`คงเหลือ ${Math.max(0, Number((200 - totalHours).toFixed(1)))} ชั่วโมง`} icon={CheckCircle} />
                    </>
                ) : (
                    <>
                        <StatTile label="ผลล่าสุด" value={moralLabel} detail={`ภาคเรียน ${latestTerm}`} icon={Heart} tone="rose" />
                        <StatTile label="ตัวชี้วัด" value={latestMoralRows.length} detail={`คะแนนเฉลี่ย ${moralAverage === null ? '-' : moralAverage.toFixed(2)}`} icon={CheckCircle} />
                    </>
                )}
            </div>
            <Panel title="ประวัติรายภาคเรียน" action={(
                <label className="flex items-center gap-2 text-sm font-bold text-slate-700"><span>ภาคเรียน</span><select value={term} onChange={(event) => setTerm(event.target.value)} className="h-10 rounded-xl border border-slate-300 bg-white px-3 text-sm"><option value="">ทุกภาคเรียน</option>{terms.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
            )}>
                {query.isPending && <QuerySkeleton />}
                {query.isError && <QueryError onRetry={() => query.refetch()} />}
                {query.data && <DataTable data={filteredRows} columns={columns} responsiveMode="compact-table" emptyTitle="ไม่พบข้อมูล" emptyDescription="ยังไม่มีข้อมูลจากระบบต้นทางในภาคเรียนที่เลือก" />}
            </Panel>
            {selectedAchievement && <DetailDialog title={selectedAchievement.item} description={kind === 'kpch' ? 'รายละเอียดกิจกรรม กพช.' : 'รายละเอียดตัวชี้วัดคุณธรรม'} onClose={() => setSelectedAchievement(null)} items={[
                { label: 'ภาคเรียน', value: selectedAchievement.term },
                ...(kind === 'moral' ? [{ label: 'หมวดคุณธรรม', value: selectedAchievement.group ?? '-' }] : [{ label: 'ประเภทกิจกรรม', value: 'กิจกรรม กพช.' }]),
                { label: kind === 'kpch' ? 'จำนวนชั่วโมง' : 'คะแนน', value: selectedAchievement.result },
                { label: 'ผลประเมิน', value: selectedAchievement.note },
            ]} />}
        </div>
    );
}

type MetricKind = 'grades' | 'kpch' | 'moral';
type StaffMetricStudent = {
    code: string;
    name: string;
    level: string;
    group: string;
    groupCode: string;
    statusCode: string;
    status: string;
    gpax: number;
    creditsEarned: number;
    creditsRequired: number;
    kpchHours: number;
    moralResult: string;
};

function StaffAcademicDetailDialog({ kind, student, onClose }: { kind: MetricKind; student: StaffMetricStudent; onClose: () => void }) {
    const grades = useQuery({
        queryKey: ['staff-student-grades', student.code],
        queryFn: ({ signal }) => getFeatureDataWithDemo<{ items: StaffGradeDetailRow[]; summary: StaffGradeSummary }>(`/api/v1/students/${encodeURIComponent(student.code)}/grades`, { items: [], summary: { gpax: null, earned_credits: 0, compulsory_credits: 0, elective_credits: 0, graded_credits: 0, registered_subjects: 0, passed_subjects: 0 } }, signal),
        enabled: kind === 'grades',
    });
    const kpch = useQuery({
        queryKey: ['staff-student-kpch', student.code],
        queryFn: ({ signal }) => getFeatureDataWithDemo<{ items: KpchDetailRow[]; summary: { total_hours: number; target_hours: number; remaining_hours: number; activity_count: number } }>(`/api/v1/students/${encodeURIComponent(student.code)}/kpch`, { items: [], summary: { total_hours: 0, target_hours: 200, remaining_hours: 200, activity_count: 0 } }, signal),
        enabled: kind === 'kpch',
    });
    const moral = useQuery({
        queryKey: ['staff-student-moral', student.code],
        queryFn: ({ signal }) => getFeatureDataWithDemo<{ items: MoralDetailAssessment[]; summary: { latest_result: string | null; latest_term: string | null; latest_score: number | null } }>(`/api/v1/students/${encodeURIComponent(student.code)}/moral`, { items: [], summary: { latest_result: null, latest_term: null, latest_score: null } }, signal),
        enabled: kind === 'moral',
    });
    const moralRows = useMemo<MoralDetailRow[]>(() => (moral.data?.data.items ?? []).flatMap((assessment) => assessment.categories.flatMap((category) => category.items.map((item, index) => ({
        id: `${assessment.term}-${category.name}-${index}`,
        term: assessment.term,
        group: category.name,
        item: item.label,
        score: item.score,
        result: item.score === null ? 'ไม่มีผลประเมิน' : item.score >= 90 ? 'ดีมาก' : item.score >= 70 ? 'ดี' : item.score >= 50 ? 'พอใช้' : 'ปรับปรุง',
        assessmentResult: assessment.summary.result,
    })))), [moral.data]);
    const subjectColumns = useMemo<ColumnDef<StaffGradeDetailRow>[]>(() => [
        { id: 'code', accessorFn: (row) => row.subject.code, header: 'รหัสวิชา', size: 100, meta: { compactSize: 72 } },
        { id: 'name', accessorFn: (row) => row.subject.name, header: 'รายวิชา', size: 320, meta: { compactSize: 174 }, cell: ({ getValue }) => <span className="font-bold text-slate-950">{getValue<string>()}</span> },
        { accessorKey: 'term', header: 'ภาคเรียน', size: 104, meta: { compactSize: 68, compactTextAlign: 'center' } },
        { id: 'credits', accessorFn: (row) => row.subject.credits, header: 'หน่วยกิต', size: 92, meta: { compactSize: 58, compactTextAlign: 'center' } },
        { accessorKey: 'grade', header: 'ผลการเรียน', size: 124, meta: { compactSize: 70, compactHeader: 'เกรด', compactTextAlign: 'center' }, cell: ({ getValue }) => getValue<string | null>() ?? 'รอผล' },
    ], []);
    const kpchColumns = useMemo<ColumnDef<KpchDetailRow>[]>(() => [
        { accessorKey: 'term', header: 'ภาคเรียน', size: 104, meta: { compactSize: 64, compactTextAlign: 'center' } },
        { accessorKey: 'name', header: 'กิจกรรม', size: 340, meta: { compactSize: 190 }, cell: ({ getValue }) => <span className="font-bold text-slate-950">{getValue<string>() || 'ไม่ระบุชื่อกิจกรรม'}</span> },
        { accessorKey: 'hours', header: 'ชั่วโมง', size: 120, meta: { compactSize: 64, compactTextAlign: 'center' }, cell: ({ getValue }) => <span className="font-black text-amber-700">{getValue<number>()} ชม.</span> },
        { accessorKey: 'category', header: 'ประเภท', size: 132, meta: { compactSize: 64, compactTextAlign: 'center' }, cell: ({ getValue }) => getValue<string>() === 'transfer' ? 'เทียบโอน' : 'กิจกรรม' },
    ], []);
    const moralColumns = useMemo<ColumnDef<MoralDetailRow>[]>(() => [
        { accessorKey: 'term', header: 'ภาคเรียน', size: 104, meta: { compactSize: 64, compactTextAlign: 'center' } },
        { accessorKey: 'group', header: 'หมวดคุณธรรม', size: 190, meta: { compactSize: 100, compactHeader: 'หมวด' } },
        { accessorKey: 'item', header: 'ตัวชี้วัด', size: 300, meta: { compactSize: 158 }, cell: ({ getValue }) => <span className="font-bold text-slate-950">{getValue<string>()}</span> },
        { accessorKey: 'score', header: 'คะแนน', size: 92, meta: { compactSize: 54, compactTextAlign: 'center' }, cell: ({ getValue }) => getValue<number | null>() ?? '-' },
        { accessorKey: 'result', header: 'ผลประเมิน', size: 132, meta: { compactSize: 68, compactHeader: 'ผล', compactTextAlign: 'center' }, cell: ({ getValue }) => { const value = getValue<string>(); return <StatusBadge tone={value === 'ปรับปรุง' ? 'danger' : value === 'พอใช้' ? 'warning' : value === 'ไม่มีผลประเมิน' ? 'neutral' : 'success'}>{value}</StatusBadge>; } },
    ], []);
    const query = kind === 'grades' ? grades : kind === 'kpch' ? kpch : moral;
    const title = kind === 'grades' ? 'รายละเอียดผลการเรียน' : kind === 'kpch' ? 'รายละเอียดกิจกรรม กพช.' : 'รายละเอียดคุณธรรม';

    useEffect(() => {
        const previousOverflow = document.body.style.overflow;
        const onKeyDown = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKeyDown);
        return () => { document.body.style.overflow = previousOverflow; window.removeEventListener('keydown', onKeyDown); };
    }, [onClose]);

    return <div className="fixed inset-0 z-[70] grid place-items-center p-3 sm:p-5" role="presentation">
        <button type="button" className="absolute inset-0 bg-slate-950/55" onClick={onClose} aria-label="ปิดรายละเอียด" />
        <section role="dialog" aria-modal="true" aria-labelledby="staff-academic-detail-title" className="relative max-h-[calc(100dvh-2rem)] w-full max-w-6xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-950/20">
            <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-100 bg-white px-5 py-4 sm:px-6">
                <div className="min-w-0"><h2 id="staff-academic-detail-title" className="text-xl font-black leading-8 text-slate-950">{title}</h2><p className="mt-1 text-sm leading-6 text-slate-600">{student.name} ({student.code})<br />{student.level} / {student.group}</p></div>
                <button type="button" onClick={onClose} autoFocus className="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-100 active:scale-[0.98]" aria-label="ปิดรายละเอียด"><X size={20} weight="bold" /></button>
            </header>
            <div className="p-4 sm:p-6">
                {query.isPending && <QuerySkeleton rows={6} />}
                {query.isError && <QueryError onRetry={() => query.refetch()} />}
                {kind === 'grades' && grades.data && <>
                    <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" aria-label="สรุปผลการเรียน">
                        <div className="rounded-xl bg-brand-50 px-3 py-2.5"><p className="text-[10px] font-bold text-brand-700">เกรดเฉลี่ยสะสม (GPAX)</p><p className="mt-0.5 text-xl font-black tabular-nums text-brand-950">{grades.data.data.summary.gpax === null ? '-' : Number(grades.data.data.summary.gpax).toFixed(2)}</p></div>
                        <div className="rounded-xl bg-sky-50 px-3 py-2.5"><p className="text-[10px] font-bold text-sky-700">วิชาที่ผ่าน</p><p className="mt-0.5 text-xl font-black tabular-nums text-sky-950">{grades.data.data.summary.passed_subjects}<span className="ml-1 text-xs font-bold text-sky-700">/ {grades.data.data.summary.registered_subjects}</span></p></div>
                        <div className="rounded-xl bg-amber-50 px-3 py-2.5"><p className="text-[10px] font-bold text-amber-700">หน่วยกิตที่ผ่าน</p><p className="mt-0.5 text-xl font-black tabular-nums text-amber-950">{grades.data.data.summary.earned_credits}</p></div>
                        <div className="rounded-xl bg-teal-50 px-3 py-2.5"><p className="text-[10px] font-bold text-teal-700">หน่วยกิตวิชาบังคับ</p><p className="mt-0.5 text-xl font-black tabular-nums text-teal-950">{grades.data.data.summary.compulsory_credits}</p></div>
                        <div className="rounded-xl bg-rose-50 px-3 py-2.5"><p className="text-[10px] font-bold text-rose-700">หน่วยกิตวิชาเลือก</p><p className="mt-0.5 text-xl font-black tabular-nums text-rose-950">{grades.data.data.summary.elective_credits}</p></div>
                        <div className="rounded-xl bg-slate-100 px-3 py-2.5"><p className="text-[10px] font-bold text-slate-600">หน่วยกิตคำนวณ GPAX</p><p className="mt-0.5 text-xl font-black tabular-nums text-slate-950">{grades.data.data.summary.graded_credits}</p></div>
                    </div>
                    <DataTable data={grades.data.data.items} columns={subjectColumns} minWidth="wide" responsiveMode="compact-table" emptyTitle="ไม่พบผลการเรียน" emptyDescription="ยังไม่มีข้อมูลรายวิชาจากระบบต้นทาง" />
                </>}
                {kind === 'kpch' && kpch.data && <DataTable data={kpch.data.data.items} columns={kpchColumns} minWidth="wide" responsiveMode="compact-table" emptyTitle="ไม่พบกิจกรรม กพช." emptyDescription="ยังไม่มีข้อมูลกิจกรรมจากระบบต้นทาง" />}
                {kind === 'moral' && moral.data && <DataTable data={moralRows} columns={moralColumns} minWidth="wide" responsiveMode="compact-table" emptyTitle="ไม่พบผลประเมินคุณธรรม" emptyDescription="ยังไม่มีผลประเมินจากระบบต้นทาง" />}
            </div>
        </section>
    </div>;
}

function StaffStudentMetricPage({ kind }: { kind: MetricKind }) {
    const { role } = useDemoRole();
    const [search, setSearch] = useState('');
    const [level, setLevel] = useState('');
    const [group, setGroup] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(25);
    const [selectedStudent, setSelectedStudent] = useState<StaffMetricStudent | null>(null);
    const deferredSearch = useDeferredValue(search);
    const canFilterGroups = role === 'admin' || role === 'super_admin';
    useEffect(() => setPage(1), [deferredSearch, group, level, perPage]);
    useEffect(() => { if (!canFilterGroups && group !== '') setGroup(''); }, [canFilterGroups, group]);
    const params = useMemo(() => {
        const query = new URLSearchParams({ page: String(page), per_page: String(perPage) });
        if (deferredSearch) query.set('search', deferredSearch);
        if (level) query.set('level', level);
        if (canFilterGroups && group) query.set('group', group);
        return query.toString();
    }, [canFilterGroups, deferredSearch, group, level, page, perPage]);
    const directory = useQuery({
        queryKey: ['student-metric-directory', kind, deferredSearch, level, group, page, perPage],
        queryFn: async ({ signal }) => {
            type ApiStudent = {
                code: string; full_name: string; level: { label: string }; group: { code: string; name: string }; status: { code: string; label: string };
                academic: { gpax: number; credits_earned: number; credits_required: number; kpch_hours: number; moral_result: string };
            };
            const response = await getFeatureDataWithDemo<ApiStudent[]>(`/api/v1/students?${params}`, [], signal);
            const data: StaffMetricStudent[] = response.data.map((student) => ({
                code: student.code, name: student.full_name, level: student.level.label, group: student.group.name, groupCode: student.group.code,
                statusCode: student.status.code, status: student.status.label, gpax: student.academic.gpax, creditsEarned: student.academic.credits_earned,
                creditsRequired: student.academic.credits_required, kpchHours: student.academic.kpch_hours, moralResult: student.academic.moral_result,
            }));
            return { data, meta: response.meta as unknown as StudentDirectoryMeta };
        },
        placeholderData: keepPreviousData,
    });
    const pageRows = directory.data?.data ?? [];
    const meta = directory.data?.meta;
    const title = kind === 'grades' ? 'ผลการเรียนและ GPAX นักศึกษา' : kind === 'kpch' ? 'กิจกรรม กพช. นักศึกษา' : 'ผลประเมินคุณธรรมนักศึกษา';
    const description = kind === 'grades' ? 'ตรวจ GPAX และหน่วยกิตสะสม พร้อมเปิดดูรายวิชาและผลการเรียนรายคน' : kind === 'kpch' ? 'ตรวจชั่วโมงสะสม กพช. และสถานะผ่านเกณฑ์ 200 ชั่วโมง' : 'ตรวจผลคุณธรรมล่าสุดจากตัวชี้วัด 11 รายการของแต่ละคน';
    const columns = useMemo<ColumnDef<StaffMetricStudent>[]>(() => [
        { accessorKey: 'name', header: 'นักศึกษา', size: 255, meta: { compactSize: kind === 'grades' ? 150 : 140 }, cell: ({ row }) => <div className="min-w-0"><Link to={`/students/${row.original.code}`} title={row.original.name} className="thai-table-primary hover:text-brand-700 sm:text-sm">{row.original.name}</Link><p className="thai-table-secondary font-mono sm:text-[10px]">{row.original.code}</p></div> },
        { accessorKey: 'group', header: 'ระดับ / กลุ่มเรียน', size: 235, meta: { compactSize: kind === 'grades' ? 160 : kind === 'moral' ? 140 : 134, compactHeader: 'ระดับ/กลุ่ม' }, cell: ({ row }) => <div className="min-w-0" title={`${row.original.group}\n${row.original.level} ${row.original.groupCode}`}><p className="thai-table-primary sm:text-[13px]">{row.original.group}</p><p className="thai-table-secondary sm:text-[10px]">{row.original.level} <span aria-hidden="true">•</span> {row.original.groupCode}</p></div> },
        ...(kind === 'grades' ? [
            { accessorKey: 'gpax', header: 'GPAX', size: 115, meta: { compactSize: 56, compactTextAlign: 'center' }, cell: ({ getValue }) => <span className="font-bold tabular-nums text-sky-700 sm:text-sm">{Number(getValue<number>()).toFixed(2)}</span> },
            { accessorKey: 'creditsEarned', header: 'หน่วยกิตสะสม', size: 150, meta: { compactSize: 72, compactHeader: 'หน่วยกิต', compactTextAlign: 'center' }, cell: ({ row }) => <span className="whitespace-nowrap font-semibold tabular-nums">{row.original.creditsEarned} / {row.original.creditsRequired}</span> },
        ] as ColumnDef<StaffMetricStudent>[] : kind === 'kpch' ? [
            { accessorKey: 'kpchHours', header: 'ชั่วโมงสะสม', size: 130, meta: { compactSize: 60, compactHeader: 'ชั่วโมง', compactTextAlign: 'center' }, cell: ({ getValue }) => <span className="font-bold tabular-nums text-amber-700 sm:text-sm">{getValue<number>()}</span> },
            { id: 'kpch_status', header: 'สถานะ กพช.', size: 160, meta: { compactSize: 84, compactHeader: 'สถานะ', compactTextAlign: 'center' }, cell: ({ row }) => <StatusBadge tone={row.original.kpchHours >= 200 ? 'success' : 'warning'}>{row.original.kpchHours >= 200 ? 'ผ่านเกณฑ์' : `ขาด ${Math.max(0, 200 - row.original.kpchHours)} ชม.`}</StatusBadge> },
        ] as ColumnDef<StaffMetricStudent>[] : [
            { accessorKey: 'moralResult', header: 'ผลคุณธรรมล่าสุด', size: 175, meta: { compactSize: 96, compactHeader: 'คุณธรรม', compactTextAlign: 'center' }, cell: ({ getValue }) => { const value = getValue<string>(); return <StatusBadge tone={value === 'ปรับปรุง' ? 'danger' : value === 'พอใช้' ? 'warning' : value === 'ยังไม่มีผลประเมิน' ? 'neutral' : 'success'}>{value}</StatusBadge>; } },
        ] as ColumnDef<StaffMetricStudent>[]),
        { id: 'details', header: 'รายละเอียด', size: 170, meta: { compactSize: 48, compactTextAlign: 'center' }, enableSorting: false, cell: ({ row }) => <button type="button" className="metric-detail-button" onClick={() => setSelectedStudent(row.original)} aria-label={`เปิดรายละเอียด ${row.original.name}`} title={`เปิดรายละเอียด ${row.original.name}`}><Eye size={15} weight="bold" aria-hidden="true" /><span className="metric-detail-button__label">เปิดดู</span></button> },
    ], [kind]);
    const pagination = meta?.pagination;

    return (
        <div>
            <PageHeader title={title} description={description} icon={kind === 'grades' ? ChartLineUp : kind === 'kpch' ? Sparkle : Heart} category="ข้อมูลนักศึกษา" actions={<StatusBadge tone="info">ข้อมูลจากระบบจริง</StatusBadge>} />
            <div className="mb-5 grid gap-3 sm:grid-cols-2">
                <StatTile label="นักศึกษาที่พบ" value={pagination?.total ?? 0} detail="ตามขอบเขตและตัวกรอง" icon={Student} tone="sky" />
                <StatTile label="กลุ่มเรียน" value={meta?.summary.groups ?? 0} detail="กลุ่มในผลลัพธ์ปัจจุบัน" icon={UsersThree} />
            </div>
            <Panel title="รายชื่อนักศึกษา" description={pagination ? `แสดง ${pagination.from ?? 0}-${pagination.to ?? 0} จาก ${pagination.total} คน` : 'กำลังโหลดข้อมูล'}>
                <div className={`mb-5 grid gap-3 ${canFilterGroups ? 'md:grid-cols-3' : 'md:grid-cols-2'}`}>
                    <Field label="ค้นหา"><Input value={search} onChange={(_, data) => setSearch(data.value)} contentBefore={<MagnifyingGlass size={18} aria-hidden="true" />} placeholder="ชื่อ รหัส หรือกลุ่ม" size="large" /></Field>
                    <Field label="ระดับการศึกษา"><Select value={level} onChange={(_, data) => setLevel(data.value)} size="large"><option value="">ทุกระดับ</option><option value="1">ประถมศึกษา</option><option value="2">มัธยมศึกษาตอนต้น</option><option value="3">มัธยมศึกษาตอนปลาย</option></Select></Field>
                    {canFilterGroups && <Field label="กลุ่มเรียน"><Select value={group} onChange={(_, data) => setGroup(data.value)} size="large"><option value="">ทุกกลุ่มเรียน</option>{(meta?.filter_options.groups ?? []).map((option) => <option key={String(option.value)} value={String(option.value)}>{option.label}</option>)}</Select></Field>}
                </div>
                {directory.isPending && <QuerySkeleton />}
                {directory.isError && <QueryError onRetry={() => directory.refetch()} />}
                {directory.data && <DataTable data={pageRows} columns={columns} pageSize={perPage} showPagination={false} minWidth="wide" responsiveMode="compact-table" emptyTitle="ไม่พบข้อมูลนักศึกษา" emptyDescription="ลองเปลี่ยนคำค้น ระดับ หรือกลุ่มเรียน" />}
                {pagination && <Pagination currentPage={pagination.current_page} totalPages={pagination.last_page} totalItems={pagination.total} pageSize={perPage} itemLabel="คน" disabled={directory.isFetching} onPageChange={setPage} onPageSizeChange={setPerPage} />}
            </Panel>
            {selectedStudent && <StaffAcademicDetailDialog kind={kind} student={selectedStudent} onClose={() => setSelectedStudent(null)} />}
        </div>
    );
}
