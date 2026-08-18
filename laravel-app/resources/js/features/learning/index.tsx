import {
    ArrowSquareOut,
    BookOpenText,
    Books,
    CalendarBlank,
    CalendarCheck,
    ChalkboardTeacher,
    CheckCircle,
    Clock,
    ClipboardText,
    DownloadSimple,
    FolderOpen,
    GraduationCap,
    Notebook,
    Planet,
    Plus,
    PencilSimple,
    Printer,
    Trash,
    X,
    Trophy,
} from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Button, Field, Input, Select } from '../../components/MaterialUI';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { DataTable } from '../../components/DataTable';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatTile } from '../../components/StatTile';
import { StatusBadge, type StatusTone } from '../../components/StatusBadge';
import { getFeatureDataWithDemo } from '../api';
import { sendFeatureData } from '../api';
import { useDemoRole } from '../../context/DemoRoleContext';
import { withAppBasePath } from '../../lib/urls';
import { showSuccessAlert } from '../../lib/feedback';
import { CalendarPage } from './CalendarPage';
import { ScorebookPage } from './ScorebookPage';
import { AssignmentWorkspacePage } from './AssignmentWorkspacePage';

type LearningOverview = {
    studentName: string;
    dueAssignments: number;
    completedAssignments: number;
    resources: number;
    courses: Array<{ id: string; code: string; title: string; teacher: string; next: string; tone: 'emerald' | 'sky' | 'amber' }>;
    upcoming: Array<{ id: string; date: string; title: string; meta: string; type: string }>;
};

type LearningGroupOption = {
    code: string;
    name: string;
    label: string;
    level: string | null;
};

const demoOverview: LearningOverview = {
    studentName: 'ณัฐชา',
    dueAssignments: 3,
    completedAssignments: 12,
    resources: 28,
    courses: [
        { id: 'thai', code: 'พท31001', title: 'ภาษาไทย', teacher: 'ครูสุภาวดี', next: 'พบกลุ่ม 19 ก.ค.', tone: 'emerald' },
        { id: 'science', code: 'พว31001', title: 'วิทยาศาสตร์', teacher: 'ครูวรวิทย์', next: 'ส่งงาน 21 ก.ค.', tone: 'sky' },
        { id: 'social', code: 'สค31001', title: 'สังคมศึกษา', teacher: 'ครูมณีรัตน์', next: 'ทำแบบทดสอบ 24 ก.ค.', tone: 'amber' },
    ],
    upcoming: [
        { id: '1', date: '19 ก.ค.', title: 'พบกลุ่มวิชาภาษาไทย', meta: '09:00 - 12:00 น. ห้องเรียน 2', type: 'พบกลุ่ม' },
        { id: '2', date: '21 ก.ค.', title: 'ส่งรายงานการทดลอง', meta: 'ก่อน 20:00 น. ผ่านระบบ', type: 'งาน' },
        { id: '3', date: '24 ก.ค.', title: 'แบบทดสอบสังคมศึกษา', meta: 'เปิดทำเวลา 08:00 - 18:00 น.', type: 'สอบ' },
    ],
};

const courseTones = {
    emerald: 'border-brand-200 bg-brand-50 text-brand-900',
    sky: 'border-sky-200 bg-sky-50 text-sky-900',
    amber: 'border-amber-200 bg-amber-50 text-amber-950',
};

function shortThaiDate(value: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short' }).format(date);
}

export function LearningHomePage() {
    const overview = useQuery({ queryKey: ['learning', 'overview'], queryFn: ({ signal }) => getFeatureDataWithDemo<LearningOverview>('/api/v1/learning', demoOverview, signal) });
    if (overview.isPending) return <QuerySkeleton rows={7} />;
    if (overview.isError) return <QueryError onRetry={() => overview.refetch()} />;
    const data = overview.data.data;
    return (
        <div>
            <PageHeader title={`พร้อมเรียนต่อแล้ว ${data.studentName}`} description="รวมวิชา งาน สื่อ และนัดหมายสำคัญไว้ให้เข้าถึงได้ง่าย" icon={Planet} category="learning" actions={<Link to="/learning/assignments" className="whitespace-nowrap rounded-full bg-brand-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-brand-800 active:scale-[0.98]">ดูงานของฉัน</Link>} />
            <div className="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="งานที่ต้องส่ง" value={data.dueAssignments} detail="ตรวจวันกำหนดส่ง" icon={ClipboardText} tone="amber" />
                <StatTile label="งานที่ส่งแล้ว" value={data.completedAssignments} detail="ภาคเรียนปัจจุบัน" icon={CheckCircle} />
                <StatTile label="สื่อการเรียน" value={data.resources} detail="เอกสารและวิดีโอ" icon={FolderOpen} tone="sky" />
            </div>
            <div className="grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">
                <Panel title="วิชาของฉัน" description="กดเลือกวิชาเพื่อดูงานและสื่อที่เกี่ยวข้อง">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {data.courses.map((course, index) => (
                            <Link key={course.id} to={`/learning/resources?course=${course.id}`} className={`rounded-2xl border p-5 transition hover:-translate-y-0.5 active:scale-[0.99] ${courseTones[course.tone]} ${index === 0 ? 'sm:col-span-2' : ''}`}>
                                <p className="text-xs font-bold opacity-70">{course.code}</p>
                                <h2 className="mt-2 text-xl font-bold tracking-[-0.02em]">{course.title}</h2>
                                <p className="mt-3 text-sm opacity-80">{course.teacher}</p>
                                <p className="mt-1 text-sm font-bold">{course.next}</p>
                            </Link>
                        ))}
                    </div>
                </Panel>
                <Panel title="กำลังจะมาถึง" action={<Link to="/learning/calendar" className="text-sm font-bold text-brand-700">ดูปฏิทิน</Link>}>
                    <div className="space-y-3">
                        {data.upcoming.map((item) => (
                            <article key={item.id} className="flex gap-3 rounded-2xl bg-slate-50 p-3.5">
                                <div className="grid min-h-14 w-16 shrink-0 place-items-center rounded-xl bg-white text-center text-xs font-bold text-brand-800 shadow-sm">{shortThaiDate(item.date)}</div>
                                <div className="min-w-0"><h3 className="font-bold text-slate-950">{item.title}</h3><p className="mt-1 text-sm leading-5 text-slate-500">{item.meta}</p></div>
                            </article>
                        ))}
                    </div>
                </Panel>
            </div>
        </div>
    );
}

export type LearningKind = 'assignments' | 'resources' | 'lesson-plans' | 'calendar' | 'schedule' | 'scores';
type LearningRow = { id: string; title: string; subtitle: string; course: string; timing: string; status: string; score?: string; canEdit?: boolean; raw?: Record<string, string> };
type LearningListPayload = { total: number; actionNeeded: number; courses: number; rows: LearningRow[] };

function thaiDate(value: unknown): string {
    if (typeof value !== 'string' || value === '') return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date);
}

function normalizeLearningPayload(kind: LearningKind, payload: unknown, fallback: LearningListPayload): LearningListPayload {
    if (payload && typeof payload === 'object' && 'rows' in payload && Array.isArray((payload as LearningListPayload).rows)) {
        return payload as LearningListPayload;
    }

    if (kind === 'scores' && payload && typeof payload === 'object' && 'courses' in payload) {
        const scorePayload = payload as { courses?: Array<Record<string, unknown>> };
        const courses = scorePayload.courses ?? [];
        const rows: LearningRow[] = courses.map((course, index) => ({
            id: String(course.id ?? index),
            title: String(course.subject_name ?? 'รายวิชา'),
            subtitle: `${String(course.subject_code ?? '')} ${String(course.credits ?? 0)} หน่วยกิต`,
            course: String(course.status === 'passed' ? 'ผ่านแล้ว' : 'อยู่ระหว่างเรียน'),
            timing: course.total_score == null ? 'ยังไม่สรุปผล' : `${String(course.total_score)} คะแนน`,
            status: course.grade == null ? 'กำลังเรียน' : `เกรด ${String(course.grade)}`,
        }));
        return { total: rows.length, actionNeeded: rows.filter((row) => row.status === 'กำลังเรียน').length, courses: rows.length, rows };
    }

    if (!Array.isArray(payload)) return fallback;
    const records = payload as Array<Record<string, unknown>>;
    const rows: LearningRow[] = records.map((record, index) => {
        if (kind === 'assignments') {
            const submission = String(record.submission_status ?? record.status ?? 'pending');
            const statusLabels: Record<string, string> = { not_submitted: 'ยังไม่ส่ง', draft: 'ฉบับร่าง', submitted: 'ส่งแล้ว', graded: 'ตรวจแล้ว' };
            return {
                id: String(record.id ?? index), title: String(record.title ?? 'งานที่มอบหมาย'),
                subtitle: `${String(record.teacher_name ?? '')} ${String(record.points ?? 0)} คะแนน`,
                course: String(record.subject_name ?? record.subject_code ?? 'รายวิชา'), timing: thaiDate(record.due_at), status: statusLabels[submission] ?? submission,
                canEdit: record.can_edit !== false, raw: record.raw as Record<string, string> | undefined,
            };
        }
        if (kind === 'resources') {
            const raw = (record.raw ?? {}) as Record<string, string>;
            return {
                id: String(record.id ?? index), title: String(record.title ?? 'สื่อการเรียน'), subtitle: String(record.description ?? record.size_label ?? ''),
                course: String(record.subject_code ?? 'ทุกวิชา'), timing: thaiDate(record.published_at), status: String(record.category ?? record.type ?? 'สื่อ'),
                canEdit: record.can_edit !== false, raw: { ...raw, file_url: String(record.file_url ?? '') },
            };
        }
        if (kind === 'calendar') {
            const typeLabels: Record<string, string> = { assignment: 'งาน', meeting: 'พบกลุ่ม', exam: 'สอบ', activity: 'กิจกรรม' };
            return {
                id: String(record.id ?? index), title: String(record.title ?? 'กิจกรรม'), subtitle: String(record.location ?? ''),
                course: String(record.subject_code ?? typeLabels[String(record.type)] ?? 'กิจกรรม'), timing: thaiDate(record.starts_at), status: typeLabels[String(record.type)] ?? String(record.type ?? 'กิจกรรม'),
                canEdit: record.can_edit === true, raw: record.raw as Record<string, string> | undefined,
            };
        }
        return {
            id: String(record.id ?? index), title: String(record.title ?? 'รายการ'), subtitle: String(record.description ?? ''),
            course: String(record.course ?? 'ทั่วไป'), timing: String(record.timing ?? '-'), status: String(record.status ?? '-'),
            canEdit: record.can_edit !== false, raw: record.raw as Record<string, string> | undefined,
        };
    });
    return { total: rows.length, actionNeeded: rows.filter((row) => ['ยังไม่ส่ง', 'ฉบับร่าง', 'รอตรวจ'].includes(row.status)).length, courses: new Set(rows.map((row) => row.course)).size, rows };
}

const learningConfig: Record<LearningKind, {
    title: string; description: string; icon: typeof Books; timingLabel: string; actionLabel: string; demo: LearningListPayload;
}> = {
    assignments: {
        title: 'งานและการส่งงาน', description: 'ดูรายละเอียดงาน ส่งไฟล์ และติดตามผลตรวจจากครู', icon: ClipboardText, timingLabel: 'กำหนดส่ง', actionLabel: 'สถานะ',
        demo: { total: 15, actionNeeded: 3, courses: 5, rows: [
            { id: '1', title: 'รายงานการทดลองเรื่องสารละลาย', subtitle: 'ส่งเป็นไฟล์ PDF ไม่เกิน 10 MB', course: 'วิทยาศาสตร์', timing: '21 ก.ค. 2569', status: 'ยังไม่ส่ง' },
            { id: '2', title: 'สรุปบทเรียนวรรณกรรมท้องถิ่น', subtitle: 'ความยาว 2-3 หน้า', course: 'ภาษาไทย', timing: '26 ก.ค. 2569', status: 'ส่งแล้ว' },
            { id: '3', title: 'แบบฝึกหัดหน้าที่พลเมือง', subtitle: 'ตอบคำถามครบ 10 ข้อ', course: 'สังคมศึกษา', timing: '30 ก.ค. 2569', status: 'ตรวจแล้ว', score: '18/20' },
        ] },
    },
    resources: {
        title: 'คลังสื่อการเรียน', description: 'ค้นหาเอกสาร วิดีโอ และลิงก์ที่ครูจัดไว้ให้', icon: FolderOpen, timingLabel: 'อัปเดต', actionLabel: 'ประเภท',
        demo: { total: 28, actionNeeded: 5, courses: 6, rows: [
            { id: '1', title: 'เอกสารสรุปภาษาไทย บทที่ 3', subtitle: 'PDF ขนาด 2.4 MB', course: 'ภาษาไทย', timing: '15 ก.ค. 2569', status: 'เอกสาร' },
            { id: '2', title: 'วิดีโอการทดลองสารละลาย', subtitle: 'ความยาว 18 นาที', course: 'วิทยาศาสตร์', timing: '14 ก.ค. 2569', status: 'วิดีโอ' },
            { id: '3', title: 'แผนที่ชุมชนและแหล่งเรียนรู้', subtitle: 'ลิงก์เว็บไซต์ภายนอก', course: 'สังคมศึกษา', timing: '10 ก.ค. 2569', status: 'ลิงก์' },
        ] },
    },
    'lesson-plans': {
        title: 'แผนการสอน', description: 'วางแผนกิจกรรม เนื้อหา และการประเมินสำหรับแต่ละรายวิชา', icon: Notebook, timingLabel: 'สัปดาห์', actionLabel: 'สถานะ',
        demo: { total: 18, actionNeeded: 2, courses: 6, rows: [
            { id: '1', title: 'การอ่านจับใจความและสรุปสาระ', subtitle: 'แผน 3 ชั่วโมง', course: 'ภาษาไทย', timing: 'สัปดาห์ที่ 4', status: 'เผยแพร่แล้ว' },
            { id: '2', title: 'การทดลองกรดและเบสในชีวิตประจำวัน', subtitle: 'แผน 4 ชั่วโมง', course: 'วิทยาศาสตร์', timing: 'สัปดาห์ที่ 5', status: 'ฉบับร่าง' },
            { id: '3', title: 'ชุมชนของเรา', subtitle: 'แผน 3 ชั่วโมง', course: 'สังคมศึกษา', timing: 'สัปดาห์ที่ 5', status: 'เผยแพร่แล้ว' },
        ] },
    },
    calendar: {
        title: 'ปฏิทินพบกลุ่ม', description: 'รวมวันพบกลุ่ม กิจกรรม กำหนดส่ง และการสอบ', icon: CalendarBlank, timingLabel: 'วันและเวลา', actionLabel: 'ประเภท',
        demo: { total: 12, actionNeeded: 3, courses: 5, rows: demoOverview.upcoming.map((row) => ({ id: row.id, title: row.title, subtitle: row.meta, course: row.type, timing: row.date, status: row.type })) },
    },
    schedule: {
        title: 'ตารางเรียนและสอบ', description: 'ตรวจวัน เวลา รายวิชา ครู และห้องเรียนได้จากทุกอุปกรณ์', icon: Clock, timingLabel: 'เวลา', actionLabel: 'ห้อง',
        demo: { total: 8, actionNeeded: 2, courses: 6, rows: [
            { id: '1', title: 'ภาษาไทย', subtitle: 'ครูสุภาวดี รักษ์เรียน', course: 'วันอาทิตย์', timing: '09:00 - 12:00 น.', status: 'ห้อง 2' },
            { id: '2', title: 'วิทยาศาสตร์', subtitle: 'ครูวรวิทย์ เพียรดี', course: 'วันอาทิตย์', timing: '13:00 - 16:00 น.', status: 'ห้องปฏิบัติการ' },
            { id: '3', title: 'สังคมศึกษา', subtitle: 'ครูมณีรัตน์ ใจงาม', course: 'วันเสาร์', timing: '09:00 - 12:00 น.', status: 'ห้อง 4' },
        ] },
    },
    scores: {
        title: 'คะแนนเก็บ', description: 'ดูคะแนนงาน แบบทดสอบ และผลประเมินระหว่างภาค', icon: Trophy, timingLabel: 'ประเมินเมื่อ', actionLabel: 'คะแนน',
        demo: { total: 11, actionNeeded: 2, courses: 5, rows: [
            { id: '1', title: 'แบบฝึกหัดบทที่ 2', subtitle: 'ตรวจและให้คะแนนแล้ว', course: 'ภาษาไทย', timing: '12 ก.ค. 2569', status: '18/20' },
            { id: '2', title: 'รายงานการทดลองครั้งที่ 1', subtitle: 'ตรวจและให้คะแนนแล้ว', course: 'วิทยาศาสตร์', timing: '10 ก.ค. 2569', status: '17/20' },
            { id: '3', title: 'แบบทดสอบก่อนเรียน', subtitle: 'ตรวจอัตโนมัติ', course: 'สังคมศึกษา', timing: '5 ก.ค. 2569', status: '8/10' },
        ] },
    },
};

function learningTone(status: string): StatusTone {
    if (['ส่งแล้ว', 'ตรวจแล้ว', 'เผยแพร่แล้ว', 'เอกสาร'].includes(status) || status.includes('/')) return 'success';
    if (['ยังไม่ส่ง', 'ฉบับร่าง'].includes(status)) return 'warning';
    if (['วิดีโอ', 'พบกลุ่ม'].includes(status)) return 'info';
    return 'neutral';
}

export function LearningListPage({ kind }: { kind: LearningKind }) {
    if (kind === 'assignments') return <AssignmentWorkspacePage />;
    if (kind === 'schedule') return <ExamSchedulePage />;
    if (kind === 'calendar') return <CalendarPage />;
    if (kind === 'scores') return <ScorebookPage />;

    const config = learningConfig[kind];
    const { role } = useDemoRole();
    const queryClient = useQueryClient();
    const [searchParams] = useSearchParams();
    const [course, setCourse] = useState(() => kind === 'resources' ? searchParams.get('course') || 'ทั้งหมด' : 'ทั้งหมด');
    const [editing, setEditing] = useState<LearningRow | 'new' | null>(null);
    const [draft, setDraft] = useState<Record<string, string>>({});
    const [resourceFile, setResourceFile] = useState<File | null>(null);
    const query = useQuery({
        queryKey: ['learning', kind, course],
        queryFn: async ({ signal }) => {
            const response = await getFeatureDataWithDemo<unknown>(`/api/v1/learning/${kind}?course=${encodeURIComponent(course)}`, config.demo, signal);
            return { ...response, data: normalizeLearningPayload(kind, response.data, { total: 0, actionNeeded: 0, courses: 0, rows: [] }) };
        },
    });
    const rows = query.data?.data.rows ?? [];
    const availableGroups = Array.isArray(query.data?.meta.available_groups)
        ? query.data.meta.available_groups as LearningGroupOption[]
        : [];
    const courses = useMemo(() => ['ทั้งหมด', ...Array.from(new Set(rows.map((row) => row.course)))], [rows]);
    const filteredRows = course === 'ทั้งหมด' ? rows : rows.filter((row) => row.course === course);
    const canManage = query.data !== undefined && role !== 'student' && query.data.meta.read_only !== true;
    const resourcePayload = () => {
        const payload = new FormData();
        const external = ['link', 'video', 'youtube'].includes(draft.resource_type ?? 'link');
        Object.entries(draft).forEach(([key, value]) => {
            if (key !== 'file_url' && (key !== 'url' || external)) payload.append(key, value);
        });
        if (!external && resourceFile) payload.append('file', resourceFile);
        if (editing !== 'new') payload.append('_method', 'PATCH');
        return payload;
    };
    const save = useMutation({
        mutationFn: () => kind === 'resources'
            ? sendFeatureData(`/api/v1/learning/resources${editing === 'new' ? '' : `/${editing?.id ?? ''}`}`, 'POST', resourcePayload())
            : editing === 'new'
            ? sendFeatureData(`/api/v1/learning/${kind}`, 'POST', draft)
            : sendFeatureData(`/api/v1/learning/${kind}/${editing?.id ?? ''}`, 'PATCH', draft),
        onSuccess: () => {
            setEditing(null);
            setResourceFile(null);
            showSuccessAlert(kind === 'resources' ? 'บันทึกสื่อการเรียนแล้ว' : 'บันทึกข้อมูลแล้ว');
            void queryClient.invalidateQueries({ queryKey: ['learning', kind] });
        },
    });
    const remove = useMutation({
        mutationFn: (row: LearningRow) => sendFeatureData(`/api/v1/learning/${kind}/${row.id}`, 'DELETE'),
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['learning', kind] }),
    });
    const openCreate = () => {
        const defaults: Record<string, Record<string, string>> = {
            assignments: { title: '', subject: '', description: '', due_at: '', target_group: '', target_mode: 'all', status: 'open' },
            resources: { title: '', subject: '', description: '', resource_type: 'link', url: '', level: '', target_group: '' },
            'lesson-plans': { title: '', subject: '', level: '1', semester: '', objectives: '', activities: '', assessment: '' },
            calendar: { title: '', event_date: '', start_time: '09:00', end_time: '12:00', location: '', target_group: '', notes: '' },
        };
        setDraft(defaults[kind] ?? {}); setResourceFile(null); setEditing('new'); save.reset();
    };
    const openEdit = (row: LearningRow) => { setDraft(row.raw ?? {}); setResourceFile(null); setEditing(row); save.reset(); };
    const columns = useMemo<ColumnDef<LearningRow>[]>(() => [
        { accessorKey: 'title', header: 'รายการ', size: 300, meta: { compactSize: 150 }, cell: ({ row }) => <div><p className="font-bold text-slate-950">{row.original.title}</p><p className="mt-0.5 text-xs text-slate-500">{row.original.subtitle}</p></div> },
        { accessorKey: 'course', header: 'วิชา', size: 180, meta: { compactSize: 92 } },
        { accessorKey: 'timing', header: config.timingLabel, size: 180, meta: { compactSize: 90, compactTextAlign: 'center' } },
        { accessorKey: 'status', header: config.actionLabel, size: 145, meta: { compactSize: 78, compactTextAlign: 'center' }, cell: ({ getValue }) => <StatusBadge tone={learningTone(getValue<string>())}>{getValue<string>()}</StatusBadge> },
        ...(kind === 'resources' ? [{ id: 'open-resource', header: 'เปิดสื่อ', size: 118, meta: { compactSize: 46, compactTextAlign: 'center' }, enableSorting: false, cell: ({ row }: { row: { original: LearningRow } }) => {
            const source = row.original.raw?.file_url || row.original.raw?.url;
            return source ? <a href={source} target="_blank" rel="noopener noreferrer" className="responsive-table-action inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1.5 text-sm font-bold text-brand-800 hover:bg-brand-100" aria-label={`เปิดสื่อ ${row.original.title}`}><DownloadSimple size={16} /> <span>{row.original.raw?.file_url ? 'ดาวน์โหลด' : 'เปิดสื่อ'}</span></a> : <span className="text-xs text-slate-400" aria-label="ไม่มีสื่อ">-</span>;
        } } as ColumnDef<LearningRow>] : []),
        ...(canManage ? [{ id: 'manage', header: 'จัดการ', size: 118, meta: { compactSize: 76, compactTextAlign: 'center' }, enableSorting: false, cell: ({ row }: { row: { original: LearningRow } }) => row.original.canEdit && row.original.raw ? <div className="flex justify-center gap-1"><button type="button" onClick={() => openEdit(row.original)} className="responsive-table-action rounded-lg border border-slate-200 p-2 text-slate-600 hover:text-brand-700" aria-label={`แก้ไข ${row.original.title}`}><PencilSimple size={16} /></button><button type="button" onClick={() => { if (window.confirm(`ยืนยันลบ ${row.original.title}?`)) remove.mutate(row.original); }} className="responsive-table-action rounded-lg border border-slate-200 p-2 text-slate-600 hover:text-rose-700" aria-label={`ลบ ${row.original.title}`}><Trash size={16} /></button></div> : <span className="text-xs text-slate-400">อ่านอย่างเดียว</span> } as ColumnDef<LearningRow>] : []),
    ], [canManage, config, kind]);
    const summary = query.data?.data;

    return (
        <div>
            <PageHeader category="learning" title={config.title} description={config.description} icon={config.icon} actions={canManage ? <button type="button" onClick={openCreate} className="inline-flex items-center gap-2 rounded-full bg-brand-700 px-5 py-2.5 text-sm font-bold text-white"><Plus size={17} weight="bold" /> เพิ่มข้อมูล</button> : undefined} />
            {summary && (
                <div className="mb-5 grid gap-3 sm:grid-cols-3">
                    <StatTile label="รายการทั้งหมด" value={summary.total} detail="ภาคเรียนปัจจุบัน" icon={BookOpenText} tone="sky" />
                    <StatTile label="ต้องดำเนินการ" value={summary.actionNeeded} detail="รายการที่ควรตรวจสอบ" icon={CalendarCheck} tone="amber" />
                    <StatTile label="รายวิชา" value={summary.courses} detail="ที่เกี่ยวข้อง" icon={ChalkboardTeacher} />
                </div>
            )}
            <Panel title="รายการล่าสุด" action={(
                <label className="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <span>กรอง</span>
                    <select value={course} onChange={(event) => setCourse(event.target.value)} className="h-10 max-w-[200px] rounded-xl border border-slate-300 bg-white px-3 text-sm">
                        {courses.map((item) => <option key={item}>{item}</option>)}
                    </select>
                </label>
            )}>
                {query.isPending && <QuerySkeleton />}
                {query.isError && <QueryError onRetry={() => query.refetch()} />}
                {query.data && <DataTable data={filteredRows} columns={columns} emptyTitle="ยังไม่มีรายการ" emptyDescription="รายการใหม่จะแสดงที่นี่เมื่อครูเผยแพร่" />}
            </Panel>
            {editing && <LearningEditor kind={kind} draft={draft} setDraft={setDraft} availableGroups={availableGroups} resourceFile={resourceFile} setResourceFile={setResourceFile} isNew={editing === 'new'} originalResourceType={editing === 'new' ? null : editing.raw?.resource_type ?? null} pending={save.isPending} error={save.error} onClose={() => setEditing(null)} onSubmit={(event) => { event.preventDefault(); save.mutate(); }} />}
        </div>
    );
}

const editorFields: Partial<Record<LearningKind, Array<{ key: string; label: string; type?: string; options?: Array<[string, string]> }>>> = {
    assignments: [
        { key: 'title', label: 'ชื่องาน' }, { key: 'subject', label: 'รายวิชา' }, { key: 'due_at', label: 'กำหนดส่ง', type: 'datetime-local' },
        { key: 'target_group', label: 'กลุ่มเป้าหมาย (เว้นว่าง = ทุกกลุ่ม)' }, { key: 'target_mode', label: 'ขอบเขต', options: [['all', 'ทุกกลุ่ม'], ['group', 'กลุ่มที่ระบุ']] },
        { key: 'status', label: 'สถานะ', options: [['draft', 'ฉบับร่าง'], ['open', 'เผยแพร่'], ['closed', 'ปิดงาน']] }, { key: 'description', label: 'รายละเอียด', type: 'textarea' },
    ],
    resources: [
        { key: 'title', label: 'ชื่อสื่อ' }, { key: 'subject', label: 'รหัสวิชา' },
        { key: 'resource_type', label: 'ประเภท', options: [['link', 'ลิงก์'], ['video', 'วิดีโอ'], ['youtube', 'YouTube'], ['pdf', 'PDF'], ['exercise', 'แบบฝึกหัด'], ['file', 'ไฟล์เอกสาร']] },
        { key: 'level', label: 'ระดับ', options: [['', 'ทุกระดับ'], ['1', 'ประถม'], ['2', 'ม.ต้น'], ['3', 'ม.ปลาย']] }, { key: 'target_group', label: 'กลุ่มเป้าหมาย' }, { key: 'description', label: 'รายละเอียด', type: 'textarea' },
    ],
    'lesson-plans': [
        { key: 'title', label: 'ชื่อแผน' }, { key: 'subject', label: 'รายวิชา' }, { key: 'level', label: 'ระดับ', options: [['1', 'ประถม'], ['2', 'ม.ต้น'], ['3', 'ม.ปลาย']] },
        { key: 'semester', label: 'ภาคเรียน' }, { key: 'objectives', label: 'วัตถุประสงค์', type: 'textarea' }, { key: 'activities', label: 'กิจกรรม', type: 'textarea' }, { key: 'assessment', label: 'การประเมิน', type: 'textarea' },
    ],
    calendar: [
        { key: 'title', label: 'ชื่อกิจกรรม' }, { key: 'event_date', label: 'วันที่', type: 'date' }, { key: 'start_time', label: 'เวลาเริ่ม', type: 'time' }, { key: 'end_time', label: 'เวลาสิ้นสุด', type: 'time' },
        { key: 'location', label: 'สถานที่' }, { key: 'target_group', label: 'กลุ่มเป้าหมาย' }, { key: 'notes', label: 'หมายเหตุ', type: 'textarea' },
    ],
};

function LearningEditor({ kind, draft, setDraft, availableGroups, resourceFile, setResourceFile, isNew, originalResourceType, pending, error, onClose, onSubmit }: { kind: LearningKind; draft: Record<string, string>; setDraft: (draft: Record<string, string>) => void; availableGroups: LearningGroupOption[]; resourceFile: File | null; setResourceFile: (file: File | null) => void; isNew: boolean; originalResourceType: string | null; pending: boolean; error: Error | null; onClose: () => void; onSubmit: (event: FormEvent) => void }) {
    const externalResource = kind === 'resources' && ['link', 'video', 'youtube'].includes(draft.resource_type ?? 'link');
    const resourceFileRequired = isNew || !draft.file_url || draft.resource_type !== originalResourceType;
    const fields = (editorFields[kind] ?? []).filter((field) => field.key !== 'url');
    return <div className="fixed inset-0 z-[70] grid place-items-center overflow-y-auto bg-slate-950/50 p-3" role="dialog" aria-modal="true"><section className="my-auto w-full max-w-2xl rounded-2xl bg-white shadow-2xl"><header className="flex items-center justify-between border-b border-slate-100 p-5"><div><h2 className="text-xl font-black">{kind === 'resources' ? 'ข้อมูลสื่อการเรียน' : 'เพิ่มหรือแก้ไขข้อมูล'}</h2>{kind === 'resources' && <p className="mt-1 text-sm text-slate-500">แนบไฟล์จริงหรือระบุลิงก์ตามประเภทสื่อ</p>}</div><button type="button" onClick={onClose} className="p-2" aria-label="ปิด"><X size={20} /></button></header><form onSubmit={onSubmit} className="grid max-h-[75vh] gap-4 overflow-y-auto p-5 sm:grid-cols-2">
        {fields.map((field) => <label key={field.key} className={field.type === 'textarea' ? 'sm:col-span-2' : ''}><span className="mb-1.5 block text-sm font-bold text-slate-700">{field.label}</span>{kind === 'resources' && field.key === 'target_group' ? <select value={draft.target_group ?? ''} onChange={(event) => setDraft({ ...draft, target_group: event.target.value })} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">นักศึกษาทุกกลุ่มในอำเภอ</option>{availableGroups.map((group) => <option key={group.code} value={group.code}>{group.label} · รหัส {group.code}</option>)}</select> : field.options ? <select required={field.key !== 'level' && field.key !== 'target_group'} value={draft[field.key] ?? ''} onChange={(event) => { if (field.key === 'resource_type') setResourceFile(null); setDraft({ ...draft, [field.key]: event.target.value }); }} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3">{field.options.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select> : field.type === 'textarea' ? <textarea value={draft[field.key] ?? ''} onChange={(event) => setDraft({ ...draft, [field.key]: event.target.value })} rows={4} className="w-full rounded-xl border border-slate-300 p-3" /> : <input required={!['target_group', 'location'].includes(field.key)} type={field.type ?? 'text'} value={draft[field.key] ?? ''} onChange={(event) => setDraft({ ...draft, [field.key]: event.target.value })} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3" />}</label>)}
        {kind === 'resources' && externalResource && <label className="sm:col-span-2"><span className="mb-1.5 block text-sm font-bold text-slate-700">ลิงก์ http/https</span><input required type="url" value={draft.url ?? ''} onChange={(event) => setDraft({ ...draft, url: event.target.value })} placeholder="https://" className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3" /></label>}
        {kind === 'resources' && !externalResource && <label className="sm:col-span-2"><span className="mb-1.5 block text-sm font-bold text-slate-700">ไฟล์สื่อ {resourceFileRequired ? '(จำเป็น)' : '(เลือกเมื่อจะเปลี่ยนไฟล์)'}</span><input required={resourceFileRequired} type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip" onChange={(event) => setResourceFile(event.target.files?.[0] ?? null)} className="block w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-bold file:text-brand-800" /><p className="mt-1.5 text-xs text-slate-500">รองรับ PDF, Word, PowerPoint, Excel และ ZIP ขนาดไม่เกิน 20 MB{resourceFile ? ` • ${resourceFile.name}` : ''}</p></label>}
        {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm font-bold text-rose-800 sm:col-span-2">{error.message}</p>}
        <div className="flex justify-end gap-2 sm:col-span-2"><button type="button" onClick={onClose} className="rounded-full border border-slate-200 px-5 py-2.5 text-sm font-bold">ยกเลิก</button><button type="submit" disabled={pending} className="rounded-full bg-brand-700 px-5 py-2.5 text-sm font-bold text-white disabled:bg-slate-300">{pending ? 'กำลังบันทึก' : 'บันทึกข้อมูล'}</button></div>
    </form></section></div>;
}

type ExamStudentOption = { code: string; full_name: string; group: { code: string; name: string }; level: { id: number; label: string } };
type ExamPrintScope = 'student' | 'group' | 'level';
type ExamScheduleRow = {
    subject_code: string;
    subject_name: string;
    term: string;
    exam_date: string;
    exam_date_display: string;
    start_time: string;
    end_time: string;
    location: string;
    room: string;
};
type ExamSchedulePayload = {
    student: { code: string; name: string; level: string; group: string; district: string };
    term: string;
    rows: ExamScheduleRow[];
    source_ready: boolean;
    sources?: { schedule: boolean; field: boolean; group: boolean; exam_rooms: boolean };
};

const EXAM_SCHEDULE_EMPTY_MESSAGE = 'ยังไม่พบตารางสอบในภาคเรียนปัจจุบัน รอเจ้าหน้าที่อัปเดตข้อมูล';



function ExamSchedulePage() {
    const { role } = useDemoRole();
    const [params, setParams] = useSearchParams();
    const [search, setSearch] = useState('');
    const [scope, setScope] = useState<ExamPrintScope>('student');
    const [level, setLevel] = useState('');
    const [group, setGroup] = useState('');
    const [pdfUrl, setPdfUrl] = useState<string | null>(null);
    const [pdfName, setPdfName] = useState('exam-schedule.pdf');
    const [pdfLoading, setPdfLoading] = useState(false);
    const [pdfError, setPdfError] = useState('');
    const autoGeneratedStudent = useRef('');
    const isInAppBrowser = typeof navigator !== 'undefined' && /(Line\/|FBAN|FBAV|Instagram|MicroMessenger)/i.test(navigator.userAgent);
    const students = useQuery({
        queryKey: ['exam-schedule', 'students'],
        queryFn: ({ signal }) => getFeatureDataWithDemo<ExamStudentOption[]>('/api/v1/students?per_page=1000&status=studying', [], signal),
    });
    const studentCode = params.get('student') ?? '';
    const autoGenerate = params.get('auto') === '1' && studentCode !== '';
    const schedule = useQuery({
        queryKey: ['exam-schedule', 'preview', studentCode],
        queryFn: ({ signal }) => getFeatureDataWithDemo<ExamSchedulePayload>(`/api/v1/students/${encodeURIComponent(studentCode)}/exam-schedule`, {
            student: { code: studentCode, name: '', level: '', group: '', district: '' },
            term: '', rows: [], source_ready: false,
        }, signal),
        enabled: studentCode !== '',
    });
    const groupOptions = useMemo(() => {
        const unique = new Map<string, { value: string; label: string; level: number }>();
        for (const student of students.data?.data ?? []) {
            if (level !== '' && student.level.id !== Number(level)) continue;
            const label = (student.group.name || student.group.code).trim().replace(/\s+/g, ' ');
            if (label === '') continue;
            const key = label.toLocaleLowerCase('th-TH');
            if (!unique.has(key)) unique.set(key, { value: label, label, level: student.level.id });
        }
        return Array.from(unique.values()).sort((left, right) => left.label.localeCompare(right.label, 'th'));
    }, [level, students.data?.data]);
    const needle = search.trim().toLocaleLowerCase('th-TH');
    const options = useMemo(() => {
        const list = (students.data?.data ?? []).filter((student) => {
            const matchesLevel = level === '' || student.level.id === Number(level);
            const matchesGroup = group === '' || group === student.group.code || group === student.group.name;
            return matchesLevel && matchesGroup && `${student.code} ${student.full_name} ${student.group.name}`.toLocaleLowerCase('th-TH').includes(needle);
        });
        if (studentCode !== '' && !list.some((item) => item.code === studentCode) && schedule.data?.data.student?.name) {
            const s = schedule.data.data.student;
            list.unshift({
                code: s.code,
                full_name: s.name,
                level: { id: 0, label: s.level },
                group: { code: '', name: s.group },
            } as unknown as ExamStudentOption);
        }
        return list;
    }, [students.data?.data, level, group, needle, studentCode, schedule.data?.data.student]);
    useEffect(() => {
        if (studentCode === '' && students.data?.data.length === 1) setParams({ student: students.data.data[0].code }, { replace: true });
    }, [setParams, studentCode, students.data?.data]);
    useEffect(() => {
        if (group !== '' && !groupOptions.some((item) => item.value === group)) setGroup('');
    }, [group, groupOptions]);
    useEffect(() => () => { if (pdfUrl) URL.revokeObjectURL(pdfUrl); }, [pdfUrl]);

    const canGenerate = scope === 'student' ? studentCode !== '' : scope === 'group' ? level !== '' && group !== '' : level !== '';
    const clearPreview = () => { if (pdfUrl) URL.revokeObjectURL(pdfUrl); setPdfUrl(null); setPdfError(''); };
    const changeScope = (next: ExamPrintScope) => { clearPreview(); setScope(next); };
    const buildPdfPath = () => {
        const query = new URLSearchParams({ scope });
        if (scope === 'student') query.set('student', studentCode);
        if (scope === 'group') { query.set('group', group); query.set('level', level); }
        if (scope === 'level') query.set('level', level);
        return `/api/v1/learning/exam-schedule/pdf?${query.toString()}`;
    };

    const signedUrlQuery = useQuery({
        queryKey: ['exam-schedule-signed-url', scope, studentCode, group, level],
        queryFn: ({ signal }) => {
            const query = new URLSearchParams({ scope });
            if (scope === 'student') query.set('student', studentCode);
            if (scope === 'group') { query.set('group', group); query.set('level', level); }
            if (scope === 'level') query.set('level', level);
            const districtId = window.localStorage.getItem('sena-district-id');
            if (districtId) query.set('district_id', districtId);
            return getFeatureDataWithDemo<{ url: string }>(`/api/v1/learning/exam-schedule/signed-url?${query.toString()}`, { url: '' }, signal);
        },
        enabled: canGenerate,
    });

    const directExternalPdfUrl = useMemo(() => {
        if (signedUrlQuery.data?.data?.url) {
            return signedUrlQuery.data.data.url;
        }
        const path = buildPdfPath();
        const separator = path.includes('?') ? '&' : '?';
        return withAppBasePath(`${path}${separator}openExternalBrowser=1`);
    }, [signedUrlQuery.data?.data?.url, scope, studentCode, group, level]);

    const generatePdf = async () => {
        if (!canGenerate) return;
        clearPreview(); setPdfLoading(true);
        try {
            const districtId = window.localStorage.getItem('sena-district-id');
            const response = await fetch(withAppBasePath(buildPdfPath()), { credentials: 'same-origin', headers: { Accept: 'application/pdf', ...(districtId ? { 'X-District-Id': districtId } : {}) } });
            if (!response.ok) {
                const error = await response.json().catch(() => null) as { message?: string; errors?: Record<string, string[]> } | null;
                throw new Error(error?.message ?? Object.values(error?.errors ?? {}).flat()[0] ?? 'สร้าง PDF ไม่สำเร็จ');
            }
            const blob = await response.blob();
            if (blob.type !== 'application/pdf') throw new Error('เซิร์ฟเวอร์ส่งไฟล์ PDF ไม่สมบูรณ์');
            const disposition = response.headers.get('Content-Disposition') ?? '';
            const filename = disposition.match(/filename="([^"]+)"/)?.[1] ?? 'exam-schedule.pdf';
            setPdfName(filename); setPdfUrl(URL.createObjectURL(blob));
        } catch (error) {
            setPdfError(error instanceof Error ? error.message : 'สร้าง PDF ไม่สำเร็จ');
        } finally {
            setPdfLoading(false);
        }
    };

    useEffect(() => {
        if (!autoGenerate || scope !== 'student' || studentCode === '' || autoGeneratedStudent.current === studentCode) return;
        autoGeneratedStudent.current = studentCode;
        void generatePdf();
    }, [autoGenerate, scope, studentCode]);

    const studentFromList = (students.data?.data ?? []).find((student) => student.code === studentCode);
    const selectedStudentName = studentFromList?.full_name ?? schedule.data?.data.student?.name ?? '';
    const selectedStudentLevel = studentFromList?.level?.label ?? schedule.data?.data.student?.level ?? '';
    const selectedStudentGroup = studentFromList?.group?.name ?? schedule.data?.data.student?.group ?? '';
    const scheduleRows = schedule.data?.data.rows ?? [];
    const scheduleColumns = useMemo<ColumnDef<ExamScheduleRow>[]>(() => [
        { accessorKey: 'exam_date_display', header: 'วันสอบ', size: 135, meta: { compactSize: 82, compactTextAlign: 'center' } },
        { id: 'time', header: 'เวลา', size: 135, meta: { compactSize: 82, compactTextAlign: 'center' }, cell: ({ row }) => `${row.original.start_time} - ${row.original.end_time} น.` },
        { accessorKey: 'subject_code', header: 'รหัสวิชา', size: 115, meta: { compactSize: 72 }, cell: ({ getValue }) => <span className="font-mono text-xs font-bold text-slate-600">{getValue<string>()}</span> },
        { accessorKey: 'subject_name', header: 'รายวิชา', size: 260, meta: { compactSize: 140 }, cell: ({ getValue }) => <span className="font-bold text-slate-950">{getValue<string>()}</span> },
        { accessorKey: 'location', header: 'สนามสอบ', size: 220, meta: { compactSize: 112 } },
        { accessorKey: 'room', header: 'ห้องสอบ', size: 130, meta: { compactSize: 76, compactTextAlign: 'center' }, cell: ({ getValue }) => <StatusBadge tone="info">{getValue<string>()}</StatusBadge> },
    ], []);

    return <div>
        <PageHeader category="learning" title="ตารางสอบ" description={autoGenerate ? 'ระบบสร้างไฟล์ PDF ตารางสอบของนักศึกษารายนี้ให้อัตโนมัติ' : 'สร้างไฟล์ PDF ด้วย mPDF และฟอนต์ TH Sarabun New แบบรายคน รายกลุ่ม หรือรายระดับชั้น'} icon={Clock} />

        {isInAppBrowser && (
            <div className="mb-5 rounded-2xl border border-emerald-300 bg-emerald-50/90 p-4 text-emerald-950 shadow-sm">
                <div className="flex items-start gap-3">
                    <span className="text-2xl shrink-0" role="img" aria-label="LINE">📱</span>
                    <div className="min-w-0">
                        <p className="font-bold text-emerald-900 text-sm">
                            เปิดใช้งานผ่านเบราว์เซอร์ของแอป LINE
                        </p>
                        <p className="mt-1 text-xs leading-5 text-emerald-800">
                            ท่านสามารถดูข้อมูลตารางสอบ วัน เวลา สนามสอบ และห้องสอบบนหน้านี้ได้โดยตรง หากต้องการพิมพ์หรือดาวน์โหลดไฟล์ PDF กรุณากดปุ่ม <strong>"เปิดใน Safari / Chrome"</strong>
                        </p>
                    </div>
                </div>
            </div>
        )}

        <Panel title={autoGenerate ? 'ตารางสอบรายบุคคล' : 'ตัวกรองและขอบเขตการพิมพ์'} description={autoGenerate ? `รหัสนักศึกษา ${studentCode}` : 'รายชื่อและกลุ่มเรียนถูกจำกัดตามอำเภอและขอบเขตที่บัญชีของคุณรับผิดชอบ'}>
            {autoGenerate ? (
                <div className="flex items-center gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sky-950">
                    <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-white text-sky-700 shadow-sm"><Printer size={20} weight="bold" aria-hidden="true" /></span>
                    <div className="min-w-0">
                        <p className="font-bold">{selectedStudentName || 'กำลังสร้างตารางสอบรายบุคคล'}</p>
                        <p className="mt-0.5 text-sm text-sky-800">{studentCode}{selectedStudentLevel ? ` • ${selectedStudentLevel}` : ''}{selectedStudentGroup ? ` • ${selectedStudentGroup}` : ''}</p>
                    </div>
                </div>
            ) : <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <Field label="รูปแบบการพิมพ์"><Select value={scope} onChange={(_, data) => changeScope(data.value as ExamPrintScope)} size="large"><option value="student">รายนักศึกษา</option>{role !== 'student' && <><option value="group">ทั้งกลุ่มเรียน</option><option value="level">ทั้งระดับชั้น</option></>}</Select></Field>
                <Field label="ระดับชั้น" required={scope === 'group' || scope === 'level'}><Select value={level} onChange={(_, data) => { clearPreview(); setLevel(data.value); }} required={scope === 'group' || scope === 'level'} size="large"><option value="">{scope === 'group' || scope === 'level' ? 'เลือกระดับชั้น' : 'ทุกระดับชั้น'}</option><option value="1">ประถมศึกษา</option><option value="2">มัธยมศึกษาตอนต้น</option><option value="3">มัธยมศึกษาตอนปลาย</option></Select></Field>
                <Field label="กลุ่มเรียน" required={scope === 'group'}><Select value={group} onChange={(_, data) => { clearPreview(); setGroup(data.value); }} required={scope === 'group'} disabled={scope === 'level' || (scope === 'group' && level === '')} size="large"><option value="">{scope === 'group' ? (level === '' ? 'เลือกระดับชั้นก่อน' : 'เลือกกลุ่มเรียน') : 'ทุกกลุ่มเรียน'}</option>{groupOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</Select></Field>
                {scope === 'student' && <Field label="ค้นหานักศึกษา"><Input value={search} onChange={(_, data) => setSearch(data.value)} placeholder="ชื่อ รหัส หรือกลุ่ม" size="large" /></Field>}
                {scope === 'student' && <Field label="นักศึกษา" className="md:col-span-2 xl:col-span-4"><Select value={studentCode} onChange={(_, data) => { clearPreview(); setParams(data.value ? { student: data.value } : {}, { replace: true }); }} size="large"><option value="">เลือกนักศึกษา</option>{options.map((student) => <option key={student.code} value={student.code}>{student.code} · {student.full_name} · {student.level.label} · {student.group.name}</option>)}</Select></Field>}
            </div>}
            {!autoGenerate && students.isPending && <QuerySkeleton rows={2} />}
            {!autoGenerate && students.isError && <QueryError onRetry={() => students.refetch()} />}
            {pdfError && <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">{pdfError}</p>}
            <div className="mt-5 flex flex-wrap justify-end gap-2">
                <Button type="button" appearance="primary" size="large" icon={<DownloadSimple size={18} weight="bold" />} onClick={generatePdf} disabled={!canGenerate || pdfLoading}>{pdfLoading ? 'กำลังสร้าง PDF' : autoGenerate ? 'สร้าง PDF ใหม่' : 'สร้างและแสดงตัวอย่าง PDF'}</Button>
                {pdfUrl && <>
                    <Button as="a" href={isInAppBrowser ? `${directExternalPdfUrl}&disposition=attachment` : pdfUrl} download={pdfName} target={isInAppBrowser ? '_blank' : undefined} rel={isInAppBrowser ? 'noopener noreferrer' : undefined} appearance="outline" size="large" icon={<DownloadSimple size={18} weight="bold" />}>ดาวน์โหลด PDF</Button>
                    <Button as="a" href={isInAppBrowser ? directExternalPdfUrl : pdfUrl} target="_blank" rel="noopener noreferrer" appearance="outline" size="large" icon={<Printer size={18} weight="bold" />}>{isInAppBrowser ? 'เปิดใน Safari / Chrome' : 'เปิดเพื่อพิมพ์'}</Button>
                </>}
            </div>
        </Panel>
        {scope === 'student' && studentCode !== '' && <Panel title="ตารางสอบจากฐานข้อมูลระบบ" description={`${selectedStudentName || studentCode} · ภาคเรียน ${schedule.data?.data.term || '-'}`}>
            {schedule.isPending && <QuerySkeleton rows={5} />}
            {schedule.isError && <QueryError onRetry={() => schedule.refetch()} />}
            {!schedule.isPending && scheduleRows.length === 0 && <p role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-center text-sm font-bold leading-6 text-amber-950">{EXAM_SCHEDULE_EMPTY_MESSAGE}</p>}
            {schedule.data?.data.sources?.field === false && scheduleRows.length > 0 && <p role="alert" className="mb-4 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm font-bold text-sky-900">ยังไม่มีข้อมูลสนามสอบในชุดข้อมูลปัจจุบัน ระบบจึงใช้ชื่ออำเภอแทนชื่อสนามสอบชั่วคราว</p>}
            {scheduleRows.length > 0 && <DataTable data={scheduleRows} columns={scheduleColumns} minWidth="wide" responsiveMode="cards" />}
        </Panel>}
        <div className="hidden lg:block">
            <Panel title="ตัวอย่างไฟล์ PDF" description={pdfUrl ? 'ตัวอย่างนี้เป็นไฟล์เดียวกับที่ดาวน์โหลดและพิมพ์ ตำแหน่งจึงตรงกันทุกจุด' : autoGenerate ? 'ระบบกำลังสร้างตารางสอบของนักศึกษารายนี้' : 'เลือกขอบเขตแล้วกดสร้าง PDF เพื่อแสดงตัวอย่าง'}>
                {pdfLoading && <QuerySkeleton rows={8} />}
                {pdfUrl ? (
                    <iframe src={pdfUrl} title="ตัวอย่างตารางสอบ PDF" className="h-[min(1120px,78vh)] min-h-[680px] w-full rounded-xl border border-slate-200 bg-slate-100" />
                ) : (
                    !pdfLoading && <div className="grid min-h-72 place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-center text-sm text-slate-500">ยังไม่ได้สร้างตัวอย่าง PDF</div>
                )}
            </Panel>
        </div>
    </div>;
}
