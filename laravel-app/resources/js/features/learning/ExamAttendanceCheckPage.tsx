import { BookOpenText, CheckCircle, CheckSquare, ClipboardText, FloppyDisk, Prohibit, Student, UsersThree } from '@phosphor-icons/react';
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
import { showErrorAlert, showSuccessAlert } from '../../lib/feedback';
import { getFeatureDataWithDemo, sendFeatureData } from '../api';

type ViewMode = 'subject' | 'student';
type Subject = { code: string; name: string; level: number; level_label: string; student_count: number; groups: Array<{ code: string; name: string }> };
type StudentOption = { student_code: string; full_name: string; level: number; level_label: string; group_code: string; group_name: string };
type AttendanceItem = StudentOption & { subject_code: string; subject_name: string; attended: boolean; recorded: boolean; checked_at: string | null };
type Workspace = {
    view: ViewMode;
    terms: string[];
    selected_term: string | null;
    subjects: Subject[];
    students: StudentOption[];
    selected_subject: Subject | null;
    items: AttendanceItem[];
    summary: { registered_students: number; attended_students: number; absent_students: number; attendance_rate: number };
};

const emptyWorkspace: Workspace = { view: 'subject', terms: [], selected_term: null, subjects: [], students: [], selected_subject: null, items: [], summary: { registered_students: 0, attended_students: 0, absent_students: 0, attendance_rate: 0 } };
const rowKey = (item: AttendanceItem) => `${item.level}|${item.student_code}|${item.subject_code}`;

export function ExamAttendanceCheckPage() {
    const queryClient = useQueryClient();
    const [view, setView] = useState<ViewMode>('subject');
    const [term, setTerm] = useState('');
    const [level, setLevel] = useState('');
    const [group, setGroup] = useState('');
    const [subject, setSubject] = useState('');
    const [attendance, setAttendance] = useState<Record<string, boolean>>({});
    const params = useMemo(() => {
        const query = new URLSearchParams({ view });
        if (term) query.set('term', term);
        if (level) query.set('level', level);
        if (group) query.set('group', group);
        if (subject) query.set('subject_code', subject);
        return query;
    }, [group, level, subject, term, view]);
    const workspace = useQuery({
        queryKey: ['exam-attendance-check', params.toString()],
        queryFn: ({ signal }) => getFeatureDataWithDemo<Workspace>(`/api/v1/learning/exam-attendance/workspace?${params.toString()}`, emptyWorkspace, signal),
        refetchOnWindowFocus: false,
    });
    const data = workspace.data?.data;

    useEffect(() => {
        if (!data) return;
        if (!term && data.selected_term) setTerm(data.selected_term);
        if (view === 'subject' && !subject && data.selected_subject) {
            setLevel(String(data.selected_subject.level));
            setSubject(data.selected_subject.code);
        }
        setAttendance(Object.fromEntries(data.items.map((item) => [rowKey(item), item.attended])));
    }, [data, subject, term, view]);

    const items = data?.items ?? [];
    const attendedCount = items.filter((item) => attendance[rowKey(item)]).length;
    const absentCount = items.length - attendedCount;
    const attendanceRate = items.length > 0 ? (attendedCount / items.length) * 100 : 0;
    const groups = useMemo(() => {
        const catalog = new Map<string, string>();
        for (const option of data?.students ?? []) catalog.set(option.group_code, option.group_name);
        return Array.from(catalog, ([code, name]) => ({ code, name })).sort((a, b) => a.name.localeCompare(b.name, 'th'));
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

    const columns = useMemo<ColumnDef<AttendanceItem>[]>(() => [
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
    ], [attendance]);

    const switchView = (next: ViewMode) => {
        setView(next);
        setLevel('');
        setGroup('');
        setSubject('');
    };

    return <div>
        <PageHeader category="จัดการการสอบ" title="เช็คชื่อเข้าสอบ" description="บันทึกการเข้าสอบด้วย checkbox ได้ทั้งรายวิชาและรายคนแบบภาพรวม พร้อมสรุปจำนวนและร้อยละทันที" icon={ClipboardText} />
        <div className="mb-5 inline-flex rounded-xl bg-white p-1 shadow-sm ring-1 ring-slate-200" role="group" aria-label="รูปแบบการเช็คชื่อ">
            <button type="button" onClick={() => switchView('subject')} aria-pressed={view === 'subject'} className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold ${view === 'subject' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-slate-100'}`}><BookOpenText size={18} /> รายวิชา</button>
            <button type="button" onClick={() => switchView('student')} aria-pressed={view === 'student'} className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold ${view === 'student' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-slate-100'}`}><Student size={18} /> รายคน</button>
        </div>
        <Panel title="เลือกขอบเขตการเช็คชื่อ" description="รายชื่อจำกัดตามอำเภอและกลุ่มที่ผู้ใช้งานรับผิดชอบ">
            <div className={`grid gap-3 md:grid-cols-2 ${view === 'subject' ? 'xl:grid-cols-4' : 'xl:grid-cols-3'}`}>
                <label><span className="mb-2 block text-sm font-bold">ภาคเรียน</span><select value={term} onChange={(e) => { setTerm(e.target.value); setSubject(''); }} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">เลือกภาคเรียน</option>{(data?.terms ?? []).map((item) => <option key={item}>{item}</option>)}</select></label>
                <label><span className="mb-2 block text-sm font-bold">ระดับชั้น</span><select value={level} onChange={(e) => { setLevel(e.target.value); setGroup(''); setSubject(''); }} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">ทุกระดับ</option><option value="1">ประถมศึกษา</option><option value="2">มัธยมศึกษาตอนต้น</option><option value="3">มัธยมศึกษาตอนปลาย</option></select></label>
                <label><span className="mb-2 block text-sm font-bold">กลุ่มเรียน</span><select value={group} onChange={(e) => { setGroup(e.target.value); setSubject(''); }} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">ทุกกลุ่ม</option>{groups.map((item) => <option key={item.code} value={item.code}>{item.name}</option>)}</select></label>
                {view === 'subject' && <label><span className="mb-2 block text-sm font-bold">รายวิชา</span><select value={subject} onChange={(e) => setSubject(e.target.value)} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">เลือกรายวิชา</option>{(data?.subjects ?? []).map((item) => <option key={`${item.level}|${item.code}`} value={item.code}>{item.code} · {item.name}</option>)}</select></label>}
            </div>
        </Panel>
        <StatGrid>
            <StatTile label="ผู้มีสิทธิ์สอบ" value={`${items.length.toLocaleString('th-TH')} คน`} detail={view === 'subject' ? 'นักศึกษาในรายวิชาที่เลือก' : 'นักศึกษาตามตัวกรองที่เลือก'} icon={UsersThree} tone="sky" />
            <StatTile label="มาสอบ" value={`${attendedCount.toLocaleString('th-TH')} คน`} detail={`ร้อยละ ${attendanceRate.toFixed(1)}`} icon={CheckCircle} tone="emerald" />
            <StatTile label="ขาดสอบ" value={`${absentCount.toLocaleString('th-TH')} คน`} detail={`ร้อยละ ${(items.length ? 100 - attendanceRate : 0).toFixed(1)}`} icon={Prohibit} tone="rose" />
            <StatTile label="อัตราการเข้าสอบ" value={`${attendanceRate.toFixed(1)}%`} detail={`${attendedCount.toLocaleString('th-TH')} จาก ${items.length.toLocaleString('th-TH')} คน`} icon={CheckSquare} tone="amber" />
        </StatGrid>
        <Panel title={view === 'subject' ? 'เช็คชื่อตามรายวิชา' : 'เช็คชื่อตามรายคน'} description="ติ๊กถูกเมื่อมาสอบ รายการที่ไม่ติ๊กจะบันทึกเป็นขาดสอบ" action={<div className="flex flex-wrap gap-2"><Button appearance="outline" onClick={() => setAttendance(Object.fromEntries(items.map((item) => [rowKey(item), false])))} disabled={items.length === 0}>ล้างทั้งหมด</Button><Button appearance="outline" onClick={() => setAttendance(Object.fromEntries(items.map((item) => [rowKey(item), true])))} disabled={items.length === 0}>เลือกทั้งหมด</Button><Button appearance="primary" icon={<FloppyDisk size={18} />} onClick={() => save.mutate()} disabled={items.length === 0 || save.isPending}>{save.isPending ? 'กำลังบันทึก' : 'บันทึกการเข้าสอบ'}</Button></div>}>
            {workspace.isPending && <QuerySkeleton rows={8} />}
            {workspace.isError && <QueryError onRetry={() => workspace.refetch()} />}
            {data && <DataTable data={items} columns={columns} pageSize={50} responsiveMode="cards" disableExport emptyTitle="ไม่พบรายชื่อสำหรับเช็คชื่อ" emptyDescription={view === 'subject' ? 'ลองเปลี่ยนภาคเรียน ระดับ กลุ่ม หรือรายวิชา' : 'ลองเปลี่ยนภาคเรียน ระดับ หรือกลุ่มเรียน'} />}
        </Panel>
    </div>;
}
