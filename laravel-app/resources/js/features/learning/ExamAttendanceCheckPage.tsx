import { BookOpenText, CheckCircle, CheckSquare, ClockCountdown, ClipboardText, FloppyDisk, Prohibit, Student, UsersThree } from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useEffect, useMemo, useState } from 'react';
import { DataTable } from '../../components/DataTable';
import { Button } from '../../components/MaterialUI';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatGrid } from '../../components/StatGrid';
import { StatTile } from '../../components/StatTile';
import { StatusBadge } from '../../components/StatusBadge';
import { useDemoRole } from '../../context/DemoRoleContext';
import { showErrorAlert, showSuccessAlert } from '../../lib/feedback';
import { getFeatureDataWithDemo, sendFeatureData } from '../api';

type ViewMode = 'subject' | 'student' | 'my-subjects';
type Subject = { code: string; name: string; level: number; level_label: string; student_count: number; groups: Array<{ code: string; name: string }> };
type StudentOption = { student_code: string; full_name: string; level: number; level_label: string; group_code: string; group_name: string };
type AttendanceItem = StudentOption & { subject_code: string; subject_name: string; attended: boolean; recorded: boolean; checked_at: string | null };
type Workspace = {
    view: ViewMode;
    read_only: boolean;
    terms: string[];
    selected_term: string | null;
    subjects: Subject[];
    students: StudentOption[];
    selected_subject: Subject | null;
    items: AttendanceItem[];
    summary: { registered_students: number; attended_students: number; absent_students: number; pending_records: number; attendance_rate: number };
};

const emptyWorkspace: Workspace = { view: 'subject', read_only: false, terms: [], selected_term: null, subjects: [], students: [], selected_subject: null, items: [], summary: { registered_students: 0, attended_students: 0, absent_students: 0, pending_records: 0, attendance_rate: 0 } };
const rowKey = (item: AttendanceItem) => `${item.level}|${item.student_code}|${item.subject_code}`;

function checkedAtLabel(value: string | null): string {
    if (!value) return '-';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '-' : new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

export function ExamAttendanceCheckPage() {
    const { role } = useDemoRole();
    const isStudent = role === 'student';
    const queryClient = useQueryClient();
    const [view, setView] = useState<ViewMode>('subject');
    const [term, setTerm] = useState('');
    const [level, setLevel] = useState('');
    const [group, setGroup] = useState('');
    const [subject, setSubject] = useState('');
    const [attendance, setAttendance] = useState<Record<string, boolean>>({});
    const params = useMemo(() => {
        const query = new URLSearchParams();
        if (!isStudent) query.set('view', view);
        if (term) query.set('term', term);
        if (!isStudent && level) query.set('level', level);
        if (!isStudent && group) query.set('group', group);
        if (!isStudent && subject) query.set('subject_code', subject);
        return query;
    }, [group, isStudent, level, subject, term, view]);
    const workspace = useQuery({
        queryKey: ['exam-attendance-check', params.toString()],
        queryFn: ({ signal }) => getFeatureDataWithDemo<Workspace>(`/api/v1/learning/exam-attendance/workspace?${params.toString()}`, emptyWorkspace, signal),
        refetchOnWindowFocus: false,
    });
    const data = workspace.data?.data;

    useEffect(() => {
        if (!data) return;
        if (!term && data.selected_term) setTerm(data.selected_term);
        if (!isStudent && view === 'subject' && !subject && data.selected_subject) {
            setLevel(String(data.selected_subject.level));
            setSubject(data.selected_subject.code);
        }
        setAttendance(Object.fromEntries(data.items.map((item) => [rowKey(item), item.attended])));
    }, [data, isStudent, subject, term, view]);

    const items = data?.items ?? [];
    const attendedCount = isStudent ? data?.summary.attended_students ?? 0 : items.filter((item) => attendance[rowKey(item)]).length;
    const absentCount = isStudent ? data?.summary.absent_students ?? 0 : items.length - attendedCount;
    const pendingCount = data?.summary.pending_records ?? 0;
    const attendanceRate = items.length > 0 ? (attendedCount / items.length) * 100 : 0;
    const groups = useMemo(() => {
        const catalog = new Map<string, string>();
        for (const option of data?.students ?? []) {
            const name = option.group_name.trim() || option.group_code.trim();
            if (name) catalog.set(name.toLocaleLowerCase('th'), name);
        }
        return Array.from(catalog.values()).sort((a, b) => a.localeCompare(b, 'th'));
    }, [data?.students]);

    const save = useMutation({
        mutationFn: () => sendFeatureData<{ saved_records: number }>('/api/v1/learning/exam-attendance', 'PUT', {
            term: data?.selected_term,
            records: items.map((item) => ({ student_code: item.student_code, subject_code: item.subject_code, level: item.level, attended: Boolean(attendance[rowKey(item)]) })),
        }),
        onSuccess: async (response) => {
            showSuccessAlert(`บันทึกการเข้าสอบแล้ว ${response.data.saved_records.toLocaleString('th-TH')} รายการ`);
            await queryClient.invalidateQueries({ queryKey: ['exam-attendance-check'] });
        },
        onError: (error) => showErrorAlert(error instanceof Error ? error.message : undefined),
    });

    const columns = useMemo<ColumnDef<AttendanceItem>[]>(() => isStudent ? [
        {
            id: 'subject',
            header: 'รายวิชา',
            accessorFn: (item) => item.subject_name,
            size: 360,
            cell: ({ row }) => <div><p className="font-bold text-slate-950">{row.original.subject_name}</p><p className="text-xs text-slate-500">{row.original.subject_code}</p></div>,
        },
        { accessorKey: 'level_label', header: 'ระดับชั้น', size: 220 },
        {
            id: 'status', header: 'สถานะการเข้าสอบ', size: 180, enableSorting: false,
            cell: ({ row }) => row.original.attended
                ? <StatusBadge tone="success">เข้าสอบแล้ว</StatusBadge>
                : row.original.recorded
                    ? <StatusBadge tone="danger">ขาดสอบ</StatusBadge>
                    : <StatusBadge tone="neutral">ยังไม่บันทึก</StatusBadge>,
        },
        {
            id: 'checked_at', header: 'ตรวจสอบล่าสุด', size: 220,
            accessorFn: (item) => item.checked_at ?? '',
            cell: ({ row }) => <span className="text-sm text-slate-600">{checkedAtLabel(row.original.checked_at)}</span>,
        },
    ] : [
        {
            id: 'student',
            header: 'นักศึกษา',
            accessorFn: (item) => item.full_name,
            size: 320,
            cell: ({ row }) => <div><p className="font-bold text-slate-950">{row.original.full_name}</p><p className="text-xs text-slate-500">{row.original.student_code}</p></div>,
        },
        { accessorKey: 'group_name', header: 'กลุ่มเรียน', size: 250 },
        { accessorKey: 'level_label', header: 'ระดับชั้น', size: 210 },
        {
            id: 'attended', header: 'เข้าสอบ', size: 130, enableSorting: false, meta: { compactSize: 76, compactTextAlign: 'center' },
            cell: ({ row }) => <label className="inline-flex cursor-pointer items-center gap-2 font-bold text-slate-800"><input type="checkbox" checked={Boolean(attendance[rowKey(row.original)])} onChange={(event) => setAttendance((current) => ({ ...current, [rowKey(row.original)]: event.target.checked }))} className="size-6 rounded border-slate-300 accent-brand-700" aria-label={`เข้าสอบ ${row.original.full_name}`} /><span>{attendance[rowKey(row.original)] ? 'มาสอบ' : 'ขาดสอบ'}</span></label>,
        },
    ], [attendance, isStudent]);

    const switchView = (next: ViewMode) => {
        setView(next);
        setLevel('');
        setGroup('');
        setSubject('');
    };

    return <div>
        <PageHeader category="จัดการการสอบ" title={isStudent ? 'การเข้าสอบของฉัน' : 'เช็คชื่อเข้าสอบ'} description={isStudent ? 'ตรวจสอบรายวิชาที่เข้าสอบแล้ว ขาดสอบ หรือยังไม่มีการบันทึกจากครู' : 'บันทึกการเข้าสอบด้วย checkbox ได้ทั้งรายวิชาและรายคนแบบภาพรวม พร้อมสรุปจำนวนและร้อยละทันที'} icon={ClipboardText} />
        {!isStudent && <div className="mb-5 inline-flex rounded-xl bg-white p-1 shadow-sm ring-1 ring-slate-200" role="group" aria-label="รูปแบบการเช็คชื่อ">
            <button type="button" onClick={() => switchView('subject')} aria-pressed={view === 'subject'} className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold ${view === 'subject' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-slate-100'}`}><BookOpenText size={18} /> รายวิชา</button>
            <button type="button" onClick={() => switchView('student')} aria-pressed={view === 'student'} className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold ${view === 'student' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-slate-100'}`}><Student size={18} /> รายคน</button>
        </div>}
        <Panel title={isStudent ? 'เลือกภาคเรียน' : 'เลือกขอบเขตการเช็คชื่อ'} description={isStudent ? 'ระบบแสดงเฉพาะข้อมูลการเข้าสอบของบัญชีคุณ' : 'รายชื่อจำกัดตามอำเภอและกลุ่มที่ผู้ใช้งานรับผิดชอบ'}>
            <div className={`grid gap-3 ${isStudent ? 'max-w-xl' : view === 'subject' ? 'md:grid-cols-2 xl:grid-cols-4' : 'md:grid-cols-2 xl:grid-cols-3'}`}>
                <label><span className="mb-2 block text-sm font-bold">ภาคเรียน</span><select value={term} onChange={(e) => { setTerm(e.target.value); setSubject(''); }} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">เลือกภาคเรียน</option>{(data?.terms ?? []).map((item) => <option key={item}>{item}</option>)}</select></label>
                {!isStudent && <label><span className="mb-2 block text-sm font-bold">ระดับชั้น</span><select value={level} onChange={(e) => { setLevel(e.target.value); setGroup(''); setSubject(''); }} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">ทุกระดับ</option><option value="1">ประถมศึกษา</option><option value="2">มัธยมศึกษาตอนต้น</option><option value="3">มัธยมศึกษาตอนปลาย</option></select></label>}
                {!isStudent && <label><span className="mb-2 block text-sm font-bold">กลุ่มเรียน</span><select value={group} onChange={(e) => { setGroup(e.target.value); setSubject(''); }} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">ทุกกลุ่ม</option>{groups.map((name) => <option key={name} value={name}>{name}</option>)}</select></label>}
                {!isStudent && view === 'subject' && <label><span className="mb-2 block text-sm font-bold">รายวิชา</span><select value={subject} onChange={(e) => setSubject(e.target.value)} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">เลือกรายวิชา</option>{(data?.subjects ?? []).map((item) => <option key={`${item.level}|${item.code}`} value={item.code}>{item.code} · {item.name}</option>)}</select></label>}
            </div>
        </Panel>
        {isStudent ? <StatGrid>
            <StatTile label="รายวิชาที่ลงทะเบียน" value={`${items.length.toLocaleString('th-TH')} วิชา`} detail="ในภาคเรียนที่เลือก" icon={BookOpenText} tone="sky" />
            <StatTile label="เข้าสอบแล้ว" value={`${attendedCount.toLocaleString('th-TH')} วิชา`} detail={`ร้อยละ ${attendanceRate.toFixed(1)}`} icon={CheckCircle} tone="emerald" />
            <StatTile label="ขาดสอบ" value={`${absentCount.toLocaleString('th-TH')} วิชา`} detail="ตามที่ครูบันทึก" icon={Prohibit} tone="rose" />
            <StatTile label="ยังไม่บันทึก" value={`${pendingCount.toLocaleString('th-TH')} วิชา`} detail="รอครูตรวจสอบสถานะ" icon={ClockCountdown} tone="amber" />
        </StatGrid> : <StatGrid>
            <StatTile label="ผู้มีสิทธิ์สอบ" value={`${items.length.toLocaleString('th-TH')} คน`} detail={view === 'subject' ? 'นักศึกษาในรายวิชาที่เลือก' : 'นักศึกษาตามตัวกรองที่เลือก'} icon={UsersThree} tone="sky" />
            <StatTile label="มาสอบ" value={`${attendedCount.toLocaleString('th-TH')} คน`} detail={`ร้อยละ ${attendanceRate.toFixed(1)}`} icon={CheckCircle} tone="emerald" />
            <StatTile label="ขาดสอบ" value={`${absentCount.toLocaleString('th-TH')} คน`} detail={`ร้อยละ ${(items.length ? 100 - attendanceRate : 0).toFixed(1)}`} icon={Prohibit} tone="rose" />
            <StatTile label="อัตราการเข้าสอบ" value={`${attendanceRate.toFixed(1)}%`} detail={`${attendedCount.toLocaleString('th-TH')} จาก ${items.length.toLocaleString('th-TH')} คน`} icon={CheckSquare} tone="amber" />
        </StatGrid>}
        <Panel title={isStudent ? 'สถานะการเข้าสอบรายวิชา' : view === 'subject' ? 'เช็คชื่อตามรายวิชา' : 'เช็คชื่อตามรายคน'} description={isStudent ? 'หน้านี้ดูข้อมูลได้อย่างเดียว หากข้อมูลไม่ถูกต้องโปรดติดต่อครูผู้สอน' : 'ติ๊กถูกเมื่อมาสอบ รายการที่ไม่ติ๊กจะบันทึกเป็นขาดสอบ'} action={isStudent ? undefined : <div className="flex flex-wrap gap-2"><Button appearance="outline" onClick={() => setAttendance(Object.fromEntries(items.map((item) => [rowKey(item), false])))} disabled={items.length === 0}>ล้างทั้งหมด</Button><Button appearance="outline" onClick={() => setAttendance(Object.fromEntries(items.map((item) => [rowKey(item), true])))} disabled={items.length === 0}>เลือกทั้งหมด</Button><Button appearance="primary" icon={<FloppyDisk size={18} />} onClick={() => save.mutate()} disabled={items.length === 0 || save.isPending}>{save.isPending ? 'กำลังบันทึก' : 'บันทึกการเข้าสอบ'}</Button></div>}>
            {workspace.isPending && <QuerySkeleton rows={8} />}
            {workspace.isError && <QueryError onRetry={() => workspace.refetch()} />}
            {data && <DataTable data={items} columns={columns} pageSize={50} responsiveMode="cards" disableExport emptyTitle={isStudent ? 'ยังไม่พบข้อมูลการเข้าสอบ' : 'ไม่พบรายชื่อสำหรับเช็คชื่อ'} emptyDescription={isStudent ? 'ยังไม่มีรายวิชาหรือข้อมูลการเข้าสอบในภาคเรียนนี้' : view === 'subject' ? 'ลองเปลี่ยนภาคเรียน ระดับ กลุ่ม หรือรายวิชา' : 'ลองเปลี่ยนภาคเรียน ระดับ หรือกลุ่มเรียน'} />}
        </Panel>
    </div>;
}
