import {
    ArrowsClockwise,
    BookOpenText,
    CalendarBlank,
    CaretRight,
    CheckCircle,
    ChatCircle,
    Clock,
    FilePdf,
    LinkSimple,
    MagnifyingGlass,
    PencilSimple,
    Plus,
    Trash,
    UploadSimple,
    Users,
    X,
} from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { showSuccessAlert } from '../../lib/feedback';
import { withAppBasePath } from '../../lib/urls';
import { getFeatureDataWithDemo, sendFeatureData, uploadFeatureData } from '../api';

type AssignmentSubject = {
    code: string;
    name: string;
    level: number;
    level_label: string;
    student_count: number;
    groups: Array<{ code: string; name: string }>;
};

type Submission = {
    id: string;
    student_code: string;
    type: 'link' | 'pdf';
    url: string;
    filename: string;
    file_size: number | null;
    submitted_at: string;
    status: 'submitted' | 'reviewed';
    score: number | null;
    feedback: string;
    reviewed_at: string | null;
    download_url: string | null;
};

type Assignment = {
    id: string;
    title: string;
    instructions: string;
    academic_term: string;
    subject_code: string;
    subject_name: string;
    education_level: number | null;
    target_group: string;
    max_score: number;
    opens_at: string | null;
    due_at: string;
    status: 'draft' | 'open' | 'closed';
    teacher_name: string;
    student_count: number;
    submitted_count: number;
    can_edit: boolean;
    submission: Submission | null;
};

type AssignmentStudent = {
    student_code: string;
    full_name: string;
    group_code: string;
    group_name: string;
    education_level: number;
    submission: Submission | null;
};

type AssignmentWorkspace = {
    term: string | null;
    terms: string[];
    subjects: AssignmentSubject[];
    assignments: Assignment[];
    selected_assignment: Assignment | null;
    students: AssignmentStudent[];
};

type AssignmentDraft = {
    title: string;
    instructions: string;
    subject_code: string;
    education_level: string;
    target_group: string;
    max_score: string;
    opens_at: string;
    due_at: string;
    status: Assignment['status'];
};

const emptyWorkspace: AssignmentWorkspace = {
    term: null,
    terms: [],
    subjects: [],
    assignments: [],
    selected_assignment: null,
    students: [],
};

const emptyDraft: AssignmentDraft = {
    title: '',
    instructions: '',
    subject_code: '',
    education_level: '',
    target_group: '',
    max_score: '20',
    opens_at: '',
    due_at: '',
    status: 'open',
};

function thaiDateTime(value: string | null): string {
    if (!value) return 'ไม่ระบุ';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('th-TH', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(date);
}

function localDateTime(value: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value.slice(0, 16);
    const offset = date.getTimezoneOffset() * 60_000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function fileSize(bytes: number | null): string {
    if (!bytes) return '';
    return bytes >= 1_048_576 ? `${(bytes / 1_048_576).toFixed(2)} MB` : `${Math.ceil(bytes / 1024)} KB`;
}

function assignmentStatus(status: Assignment['status']): string {
    return { draft: 'ฉบับร่าง', open: 'เปิดรับงาน', closed: 'ปิดรับงาน' }[status];
}

function submissionStatus(submission: Submission | null, dueAt: string): { label: string; tone: string } {
    if (!submission) return { label: 'ยังไม่ส่ง', tone: 'border-rose-200 bg-rose-50 text-rose-800' };
    if (submission.status === 'reviewed') return { label: 'ตรวจแล้ว', tone: 'border-brand-200 bg-brand-50 text-brand-800' };
    const late = new Date(submission.submitted_at).getTime() > new Date(dueAt).getTime();
    return late
        ? { label: 'ส่งล่าช้า', tone: 'border-amber-200 bg-amber-50 text-amber-900' }
        : { label: 'ส่งแล้ว', tone: 'border-emerald-200 bg-emerald-50 text-emerald-800' };
}

function StatusPill({ label, tone }: { label: string; tone: string }) {
    return <span className={`inline-flex whitespace-nowrap rounded-full border px-2.5 py-1 text-xs font-bold ${tone}`}>{label}</span>;
}

export function AssignmentWorkspacePage() {
    const { role } = useDemoRole();
    const queryClient = useQueryClient();
    const canManage = role !== 'student';
    const [selectedId, setSelectedId] = useState<string>('');
    const [search, setSearch] = useState('');
    const [submissionFilter, setSubmissionFilter] = useState<'all' | 'submitted' | 'missing'>('all');
    const [assignmentEditor, setAssignmentEditor] = useState<Assignment | 'new' | null>(null);
    const [submissionEditor, setSubmissionEditor] = useState<Assignment | null>(null);
    const [reviewEditor, setReviewEditor] = useState<{ assignment: Assignment; student: AssignmentStudent } | null>(null);
    const query = useQuery({
        queryKey: ['learning', 'assignment-workspace', selectedId],
        queryFn: ({ signal }) => getFeatureDataWithDemo<AssignmentWorkspace>(
            `/api/v1/learning/assignments${selectedId ? `?assignment_id=${encodeURIComponent(selectedId)}` : ''}`,
            emptyWorkspace,
            signal,
        ),
    });
    const data = query.data?.data;

    useEffect(() => {
        if (!selectedId && data?.selected_assignment?.id) setSelectedId(data.selected_assignment.id);
    }, [data?.selected_assignment?.id, selectedId]);

    const filteredAssignments = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase('th');
        if (!needle) return data?.assignments ?? [];
        return (data?.assignments ?? []).filter((assignment) => [
            assignment.title, assignment.subject_code, assignment.subject_name,
        ].some((value) => value.toLocaleLowerCase('th').includes(needle)));
    }, [data?.assignments, search]);

    const visibleStudents = useMemo(() => (data?.students ?? []).filter((student) => {
        if (submissionFilter === 'submitted') return student.submission !== null;
        if (submissionFilter === 'missing') return student.submission === null;
        return true;
    }), [data?.students, submissionFilter]);

    const remove = useMutation({
        mutationFn: (assignment: Assignment) => sendFeatureData(`/api/v1/learning/assignments/${assignment.id}`, 'DELETE'),
        onSuccess: async () => {
            setSelectedId('');
            await queryClient.invalidateQueries({ queryKey: ['learning', 'assignment-workspace'] });
            showSuccessAlert('ลบงานเรียบร้อยแล้ว');
        },
    });

    if (query.isPending) return <QuerySkeleton rows={8} />;
    if (query.isError) return <QueryError onRetry={() => query.refetch()} />;
    if (!data) return null;

    const selected = data.selected_assignment;
    const selectedSubject = selected
        ? data.subjects.find((subject) => subject.code === selected.subject_code && subject.level === selected.education_level)
        : data.subjects[0];

    return <div className="pb-6">
        <PageHeader
            category="learning"
            title="งานและการส่งงาน"
            description={canManage ? 'สร้างงานจากรายวิชาที่ลงทะเบียน ตรวจงาน และให้คะแนนผู้เรียน' : 'ดูรายละเอียด ส่งงาน และติดตามผลตรวจจากครู'}
            icon={BookOpenText}
            actions={canManage ? <button type="button" onClick={() => setAssignmentEditor('new')} disabled={data.subjects.length === 0} className="inline-flex items-center gap-2 whitespace-nowrap rounded-full bg-brand-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-brand-800 active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-slate-300"><Plus size={17} weight="bold" /> เพิ่มงานใหม่</button> : undefined}
        />

        <section className="mb-5 grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[minmax(0,1.4fr)_0.7fr_0.7fr_auto] md:items-center md:p-5">
            <div className="flex min-w-0 items-center gap-4">
                <div className="grid size-14 shrink-0 place-items-center rounded-2xl bg-brand-700 text-white"><BookOpenText size={30} weight="duotone" /></div>
                <div className="min-w-0"><p className="truncate text-lg font-black text-slate-950">{selectedSubject?.name ?? 'ยังไม่พบรายวิชาลงทะเบียน'}</p><p className="mt-1 text-sm font-bold text-slate-500">{selectedSubject ? `${selectedSubject.code} ระดับ ${selectedSubject.level_label}` : 'รอข้อมูลลงทะเบียนภาคเรียนปัจจุบัน'}</p></div>
            </div>
            <div className="border-slate-200 md:border-l md:pl-5"><p className="flex items-center gap-2 text-xs font-bold text-slate-500"><CalendarBlank size={17} /> ภาคเรียน</p><p className="mt-1 text-lg font-black text-slate-900">{data.term ?? '-'}</p></div>
            <div className="border-slate-200 md:border-l md:pl-5"><p className="flex items-center gap-2 text-xs font-bold text-slate-500"><Users size={17} /> ผู้เรียน</p><p className="mt-1 text-lg font-black text-slate-900">{selected?.student_count ?? selectedSubject?.student_count ?? 0} คน</p></div>
            <button type="button" onClick={() => void query.refetch()} className="inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-700 hover:border-brand-300 hover:text-brand-800 active:scale-[0.98]"><ArrowsClockwise size={17} /> ดึงข้อมูลรายวิชา</button>
        </section>

        {data.subjects.length === 0 ? <section className="rounded-2xl border border-amber-200 bg-amber-50 p-8 text-center"><BookOpenText size={38} className="mx-auto text-amber-700" /><h2 className="mt-3 text-lg font-black text-amber-950">ยังไม่พบรายวิชาที่ลงทะเบียนในภาคเรียนปัจจุบัน</h2><p className="mt-2 text-sm leading-6 text-amber-900">เมื่อนำเข้าข้อมูลผู้เรียนและรายวิชาลงทะเบียนแล้ว ครูจะสามารถสร้างงานจากหน้านี้ได้</p></section> : <div className="grid gap-5 xl:grid-cols-[360px_minmax(0,1fr)]">
            <aside className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-100 p-4">
                    <div className="flex items-center justify-between gap-3"><h2 className="font-black text-slate-950">งานที่มอบหมาย</h2>{canManage && <button type="button" onClick={() => setAssignmentEditor('new')} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-700 px-3 py-2 text-xs font-bold text-white active:scale-[0.98]"><Plus size={15} /> เพิ่มงาน</button>}</div>
                    <label className="relative mt-3 block"><span className="sr-only">ค้นหาชื่องาน</span><MagnifyingGlass size={18} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="ค้นหาชื่องานหรือรายวิชา" className="h-11 w-full rounded-xl border border-slate-300 bg-white pl-10 pr-3 text-sm text-slate-900 outline-none focus:border-brand-600 focus:ring-2 focus:ring-brand-100" /></label>
                </div>
                <div className="max-h-[660px] overflow-y-auto">
                    {filteredAssignments.length === 0 ? <div className="p-8 text-center"><BookOpenText size={32} className="mx-auto text-slate-300" /><p className="mt-3 font-bold text-slate-700">ยังไม่มีงานในภาคเรียนนี้</p><p className="mt-1 text-sm text-slate-500">กดเพิ่มงานเพื่อเริ่มมอบหมายงาน</p></div> : filteredAssignments.map((assignment) => {
                        const active = selected?.id === assignment.id;
                        const state = submissionStatus(assignment.submission, assignment.due_at);
                        return <button key={assignment.id} type="button" onClick={() => setSelectedId(assignment.id)} className={`block w-full border-b border-slate-100 p-4 text-left transition last:border-b-0 ${active ? 'bg-brand-50 shadow-[inset_4px_0_0_var(--color-brand-700)]' : 'hover:bg-slate-50'}`}>
                            <div className="flex items-start gap-3"><div className={`grid size-10 shrink-0 place-items-center rounded-xl ${active ? 'bg-brand-700 text-white' : 'bg-slate-100 text-slate-600'}`}><BookOpenText size={20} /></div><div className="min-w-0 flex-1"><p className="line-clamp-2 font-bold leading-5 text-slate-950">{assignment.title}</p><p className="mt-1 truncate text-xs font-bold text-slate-500">{assignment.subject_code} {assignment.subject_name}</p></div><CaretRight size={17} className="mt-2 shrink-0 text-slate-400" /></div>
                            <div className="mt-3 flex items-end justify-between gap-2"><div><p className="text-xs text-slate-500">กำหนดส่ง</p><p className="mt-0.5 text-xs font-bold text-slate-700">{thaiDateTime(assignment.due_at)}</p></div>{canManage ? <p className="shrink-0 text-right text-xs text-slate-500">ส่งแล้ว<br /><strong className="text-base text-slate-900">{assignment.submitted_count}/{assignment.student_count}</strong></p> : <StatusPill label={state.label} tone={state.tone} />}</div>
                        </button>;
                    })}
                </div>
            </aside>

            <main className="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                {!selected ? <div className="grid min-h-[440px] place-items-center p-8 text-center"><div><BookOpenText size={44} className="mx-auto text-slate-300" /><h2 className="mt-3 text-lg font-black text-slate-800">เลือกงานเพื่อดูรายละเอียด</h2></div></div> : <>
                    <header className="border-b border-slate-100 p-4 md:p-5">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h2 className="text-xl font-black leading-8 text-slate-950">{selected.title}</h2><StatusPill label={assignmentStatus(selected.status)} tone={selected.status === 'open' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : selected.status === 'draft' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-slate-200 bg-slate-100 text-slate-700'} /></div><p className="mt-1 text-sm font-bold text-slate-500">{selected.subject_code} {selected.subject_name}</p></div>
                            {canManage ? <div className="flex gap-2"><button type="button" onClick={() => setAssignmentEditor(selected)} className="inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 px-3.5 text-sm font-bold text-slate-700 hover:text-brand-800"><PencilSimple size={17} /> แก้ไข</button><button type="button" onClick={() => { if (window.confirm(`ยืนยันลบงาน ${selected.title}? งานที่ผู้เรียนส่งไว้จะถูกลบด้วย`)) remove.mutate(selected); }} disabled={remove.isPending} className="inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-xl border border-rose-200 px-3.5 text-sm font-bold text-rose-700 hover:bg-rose-50"><Trash size={17} /> ลบงาน</button></div> : selected.status === 'open' && <button type="button" onClick={() => setSubmissionEditor(selected)} className="inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-700 px-5 text-sm font-bold text-white hover:bg-brand-800 active:scale-[0.98]"><UploadSimple size={18} /> {selected.submission ? 'ส่งงานอีกครั้ง' : 'ส่งงาน'}</button>}
                        </div>
                        {selected.instructions && <p className="mt-4 max-w-3xl whitespace-pre-wrap text-sm leading-6 text-slate-700">{selected.instructions}</p>}
                        <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div className="rounded-xl bg-slate-50 p-3"><dt className="flex items-center gap-2 text-xs font-bold text-slate-500"><CalendarBlank size={16} /> กำหนดส่ง</dt><dd className="mt-1 text-sm font-bold text-slate-900">{thaiDateTime(selected.due_at)}</dd></div>
                            <div className="rounded-xl bg-slate-50 p-3"><dt className="flex items-center gap-2 text-xs font-bold text-slate-500"><Users size={16} /> ผู้เรียน</dt><dd className="mt-1 text-sm font-bold text-slate-900">{selected.student_count} คน</dd></div>
                            <div className="rounded-xl bg-slate-50 p-3"><dt className="flex items-center gap-2 text-xs font-bold text-slate-500"><CheckCircle size={16} /> ส่งแล้ว</dt><dd className="mt-1 text-sm font-bold text-slate-900">{selected.submitted_count} คน</dd></div>
                            <div className="rounded-xl bg-slate-50 p-3"><dt className="flex items-center gap-2 text-xs font-bold text-slate-500"><Clock size={16} /> คะแนนเต็ม</dt><dd className="mt-1 text-sm font-bold text-slate-900">{selected.max_score} คะแนน</dd></div>
                        </dl>
                    </header>

                    {canManage ? <section>
                        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center md:justify-between"><div><h3 className="font-black text-slate-950">งานที่ผู้เรียนส่ง</h3><p className="mt-1 text-xs text-slate-500">รายชื่อจากข้อมูลลงทะเบียนภาคเรียน {data.term}</p></div><label className="flex items-center gap-2 text-sm font-bold text-slate-700"><span>สถานะ</span><select value={submissionFilter} onChange={(event) => setSubmissionFilter(event.target.value as typeof submissionFilter)} className="h-10 rounded-xl border border-slate-300 bg-white px-3"><option value="all">ทั้งหมด</option><option value="submitted">ส่งแล้ว</option><option value="missing">ยังไม่ส่ง</option></select></label></div>
                        <div className="hidden overflow-x-auto md:block"><table className="w-full min-w-[820px] text-left text-sm"><thead className="bg-slate-50 text-xs font-bold text-slate-600"><tr><th className="px-4 py-3">ผู้เรียน</th><th className="px-4 py-3">สถานะ</th><th className="px-4 py-3">ไฟล์งาน</th><th className="px-4 py-3">วันที่ส่ง</th><th className="px-4 py-3 text-center">คะแนน</th><th className="px-4 py-3 text-center">จัดการ</th></tr></thead><tbody>{visibleStudents.map((student) => <SubmissionRow key={student.student_code} assignment={selected} student={student} onReview={() => setReviewEditor({ assignment: selected, student })} />)}</tbody></table></div>
                        <div className="grid gap-3 p-4 md:hidden">{visibleStudents.map((student) => <SubmissionCard key={student.student_code} assignment={selected} student={student} onReview={() => setReviewEditor({ assignment: selected, student })} />)}</div>
                        {visibleStudents.length === 0 && <p className="p-8 text-center text-sm font-bold text-slate-500">ไม่พบผู้เรียนในสถานะที่เลือก</p>}
                    </section> : <StudentSubmissionDetail assignment={selected} onSubmit={() => setSubmissionEditor(selected)} />}
                </>}
            </main>
        </div>}

        {canManage && <aside className="mt-5 flex flex-col gap-3 rounded-2xl border border-brand-200 bg-brand-50 p-4 text-sm text-brand-950 sm:flex-row sm:items-center sm:justify-between"><div><p className="font-black">ข้อมูลรายวิชาและผู้เรียนเชื่อมจากระบบลงทะเบียน</p><p className="mt-1 leading-6 text-brand-800">หน้านี้แสดงเฉพาะภาคเรียนปัจจุบันและกลุ่มที่บัญชีครูรับผิดชอบ</p></div></aside>}

        {assignmentEditor && <AssignmentEditor assignment={assignmentEditor} term={data.term} subjects={data.subjects} onClose={() => setAssignmentEditor(null)} onSaved={(id) => { setAssignmentEditor(null); setSelectedId(id); void queryClient.invalidateQueries({ queryKey: ['learning', 'assignment-workspace'] }); }} />}
        {submissionEditor && <SubmissionEditor assignment={submissionEditor} onClose={() => setSubmissionEditor(null)} onSaved={() => { setSubmissionEditor(null); void queryClient.invalidateQueries({ queryKey: ['learning', 'assignment-workspace'] }); }} />}
        {reviewEditor && <ReviewEditor assignment={reviewEditor.assignment} student={reviewEditor.student} onClose={() => setReviewEditor(null)} onSaved={() => { setReviewEditor(null); void queryClient.invalidateQueries({ queryKey: ['learning', 'assignment-workspace'] }); }} />}
    </div>;
}

function SubmissionLink({ assignment, submission }: { assignment: Assignment; submission: Submission }) {
    const href = submission.type === 'pdf' && submission.download_url
        ? withAppBasePath(submission.download_url)
        : submission.url;
    return <a href={href} target="_blank" rel="noopener noreferrer" className="inline-flex max-w-[240px] items-center gap-2 font-bold text-brand-800 hover:underline">{submission.type === 'pdf' ? <FilePdf size={18} /> : <LinkSimple size={18} />}<span className="truncate">{submission.type === 'pdf' ? submission.filename : submission.url}</span>{submission.type === 'pdf' && <span className="shrink-0 text-xs font-normal text-slate-500">{fileSize(submission.file_size)}</span>}<span className="sr-only">งาน {assignment.title}</span></a>;
}

function SubmissionRow({ assignment, student, onReview }: { assignment: Assignment; student: AssignmentStudent; onReview: () => void }) {
    const state = submissionStatus(student.submission, assignment.due_at);
    return <tr className="border-b border-slate-100 last:border-b-0"><td className="px-4 py-3"><p className="font-bold text-slate-950">{student.full_name}</p><p className="mt-0.5 text-xs text-slate-500">รหัส {student.student_code} กลุ่ม {student.group_name}</p></td><td className="px-4 py-3"><StatusPill label={state.label} tone={state.tone} /></td><td className="px-4 py-3">{student.submission ? <SubmissionLink assignment={assignment} submission={student.submission} /> : <span className="text-slate-400">-</span>}</td><td className="px-4 py-3 text-slate-600">{student.submission ? thaiDateTime(student.submission.submitted_at) : '-'}</td><td className="px-4 py-3 text-center font-black text-slate-900">{student.submission?.score ?? '-'}<span className="font-normal text-slate-400">/{assignment.max_score}</span></td><td className="px-4 py-3 text-center">{student.submission ? <button type="button" onClick={onReview} className="inline-flex size-9 items-center justify-center rounded-xl border border-slate-200 text-slate-700 hover:text-brand-800" aria-label={`ตรวจงาน ${student.full_name}`}><ChatCircle size={18} /></button> : '-'}</td></tr>;
}

function SubmissionCard({ assignment, student, onReview }: { assignment: Assignment; student: AssignmentStudent; onReview: () => void }) {
    const state = submissionStatus(student.submission, assignment.due_at);
    return <article className="rounded-xl border border-slate-200 p-4"><div className="flex items-start justify-between gap-3"><div><p className="font-bold text-slate-950">{student.full_name}</p><p className="mt-1 text-xs text-slate-500">{student.student_code} กลุ่ม {student.group_name}</p></div><StatusPill label={state.label} tone={state.tone} /></div>{student.submission && <div className="mt-3 rounded-xl bg-slate-50 p-3"><SubmissionLink assignment={assignment} submission={student.submission} /><p className="mt-2 text-xs text-slate-500">ส่งเมื่อ {thaiDateTime(student.submission.submitted_at)}</p><div className="mt-3 flex items-center justify-between"><p className="font-black text-slate-900">{student.submission.score ?? '-'} / {assignment.max_score} คะแนน</p><button type="button" onClick={onReview} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700"><ChatCircle size={16} /> ตรวจงาน</button></div></div>}</article>;
}

function StudentSubmissionDetail({ assignment, onSubmit }: { assignment: Assignment; onSubmit: () => void }) {
    const submission = assignment.submission;
    const state = submissionStatus(submission, assignment.due_at);
    return <section className="p-4 md:p-5"><div className="flex items-center justify-between gap-3"><div><h3 className="font-black text-slate-950">การส่งงานของฉัน</h3><p className="mt-1 text-sm text-slate-500">ส่งได้ทั้งลิงก์และไฟล์ PDF ขนาดไม่เกิน 20 MB</p></div><StatusPill label={state.label} tone={state.tone} /></div>{submission ? <article className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4"><SubmissionLink assignment={assignment} submission={submission} /><p className="mt-2 text-sm text-slate-600">ส่งเมื่อ {thaiDateTime(submission.submitted_at)}</p>{submission.status === 'reviewed' && <div className="mt-4 grid gap-3 rounded-xl bg-white p-4 sm:grid-cols-[140px_1fr]"><div><p className="text-xs font-bold text-slate-500">คะแนน</p><p className="mt-1 text-2xl font-black text-brand-800">{submission.score ?? '-'} <span className="text-sm text-slate-500">/ {assignment.max_score}</span></p></div><div><p className="text-xs font-bold text-slate-500">ข้อเสนอแนะจากครู</p><p className="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-700">{submission.feedback || 'ไม่มีข้อเสนอแนะเพิ่มเติม'}</p></div></div>}<button type="button" onClick={onSubmit} className="mt-4 inline-flex h-10 items-center gap-2 rounded-xl border border-brand-200 bg-white px-4 text-sm font-bold text-brand-800"><UploadSimple size={17} /> ส่งงานอีกครั้ง</button></article> : <div className="mt-4 rounded-xl border border-dashed border-slate-300 p-8 text-center"><UploadSimple size={36} className="mx-auto text-slate-400" /><p className="mt-3 font-bold text-slate-800">ยังไม่ได้ส่งงาน</p><button type="button" onClick={onSubmit} className="mt-4 inline-flex h-11 items-center gap-2 rounded-xl bg-brand-700 px-5 text-sm font-bold text-white"><UploadSimple size={18} /> ส่งงานตอนนี้</button></div>}</section>;
}

function ModalShell({ title, description, onClose, children }: { title: string; description?: string; onClose: () => void; children: ReactNode }) {
    return <div className="fixed inset-0 z-[70] grid place-items-center overflow-y-auto bg-slate-950/55 p-3" role="dialog" aria-modal="true"><section className="my-auto w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl"><header className="flex items-start justify-between gap-4 border-b border-slate-100 p-5"><div><h2 className="text-xl font-black text-slate-950">{title}</h2>{description && <p className="mt-1 text-sm leading-6 text-slate-500">{description}</p>}</div><button type="button" onClick={onClose} className="grid size-9 shrink-0 place-items-center rounded-xl text-slate-600 hover:bg-slate-100" aria-label="ปิด"><X size={20} /></button></header>{children}</section></div>;
}

function AssignmentEditor({ assignment, term, subjects, onClose, onSaved }: { assignment: Assignment | 'new'; term: string | null; subjects: AssignmentSubject[]; onClose: () => void; onSaved: (id: string) => void }) {
    const initial = assignment === 'new' ? emptyDraft : {
        title: assignment.title, instructions: assignment.instructions, subject_code: assignment.subject_code,
        education_level: String(assignment.education_level ?? ''), target_group: assignment.target_group,
        max_score: String(assignment.max_score), opens_at: localDateTime(assignment.opens_at), due_at: localDateTime(assignment.due_at), status: assignment.status,
    };
    const [draft, setDraft] = useState<AssignmentDraft>(initial);
    const selectedSubject = subjects.find((subject) => subject.code === draft.subject_code && String(subject.level) === draft.education_level);
    const save = useMutation({
        mutationFn: () => sendFeatureData<{ id: string }>(`/api/v1/learning/assignments${assignment === 'new' ? '' : `/${assignment.id}`}`, assignment === 'new' ? 'POST' : 'PATCH', {
            ...draft,
            education_level: Number(draft.education_level),
            max_score: Number(draft.max_score),
            opens_at: draft.opens_at || null,
        }),
        onSuccess: (response) => { showSuccessAlert(assignment === 'new' ? 'เพิ่มงานเรียบร้อยแล้ว' : 'แก้ไขงานเรียบร้อยแล้ว'); onSaved(response.data.id); },
    });
    const chooseSubject = (value: string) => {
        const [level, code] = value.split('|');
        setDraft({ ...draft, subject_code: code ?? '', education_level: level ?? '', target_group: '' });
    };
    return <ModalShell title={assignment === 'new' ? 'เพิ่มงานใหม่' : 'แก้ไขงาน'} description={`เลือกรายวิชาจากข้อมูลลงทะเบียนภาคเรียน ${term ?? 'ปัจจุบัน'}`} onClose={onClose}><form onSubmit={(event) => { event.preventDefault(); save.mutate(); }} className="grid max-h-[75vh] gap-4 overflow-y-auto p-5 sm:grid-cols-2"><label className="sm:col-span-2"><span className="mb-1.5 block text-sm font-bold text-slate-700">ชื่องาน *</span><input required maxLength={220} value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} className="h-11 w-full rounded-xl border border-slate-300 px-3 outline-none focus:border-brand-600 focus:ring-2 focus:ring-brand-100" /></label><label><span className="mb-1.5 block text-sm font-bold text-slate-700">รายวิชาที่ลงทะเบียน *</span><select required value={draft.education_level && draft.subject_code ? `${draft.education_level}|${draft.subject_code}` : ''} onChange={(event) => chooseSubject(event.target.value)} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">เลือกรายวิชา</option>{subjects.map((subject) => <option key={`${subject.level}|${subject.code}`} value={`${subject.level}|${subject.code}`}>{subject.code} {subject.name} ({subject.level_label})</option>)}</select></label><label><span className="mb-1.5 block text-sm font-bold text-slate-700">กลุ่มเป้าหมาย</span><select value={draft.target_group} onChange={(event) => setDraft({ ...draft, target_group: event.target.value })} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">ผู้เรียนทุกกลุ่มในรายวิชา</option>{selectedSubject?.groups.map((group) => <option key={group.code} value={group.code}>{group.name} ({group.code})</option>)}</select></label><label><span className="mb-1.5 block text-sm font-bold text-slate-700">เปิดรับงาน</span><input type="datetime-local" value={draft.opens_at} onChange={(event) => setDraft({ ...draft, opens_at: event.target.value })} className="h-11 w-full rounded-xl border border-slate-300 px-3" /></label><label><span className="mb-1.5 block text-sm font-bold text-slate-700">กำหนดส่ง *</span><input required type="datetime-local" value={draft.due_at} onChange={(event) => setDraft({ ...draft, due_at: event.target.value })} className="h-11 w-full rounded-xl border border-slate-300 px-3" /></label><label><span className="mb-1.5 block text-sm font-bold text-slate-700">คะแนนเต็ม *</span><input required type="number" min="0" max="100" step="0.01" value={draft.max_score} onChange={(event) => setDraft({ ...draft, max_score: event.target.value })} className="h-11 w-full rounded-xl border border-slate-300 px-3" /></label><label><span className="mb-1.5 block text-sm font-bold text-slate-700">สถานะ *</span><select value={draft.status} onChange={(event) => setDraft({ ...draft, status: event.target.value as Assignment['status'] })} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="draft">ฉบับร่าง</option><option value="open">เปิดรับงาน</option><option value="closed">ปิดรับงาน</option></select></label><label className="sm:col-span-2"><span className="mb-1.5 block text-sm font-bold text-slate-700">คำชี้แจง</span><textarea rows={5} maxLength={10000} value={draft.instructions} onChange={(event) => setDraft({ ...draft, instructions: event.target.value })} className="w-full rounded-xl border border-slate-300 p-3 outline-none focus:border-brand-600 focus:ring-2 focus:ring-brand-100" /></label>{save.error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm font-bold text-rose-800 sm:col-span-2">{save.error.message}</p>}<div className="flex justify-end gap-2 sm:col-span-2"><button type="button" onClick={onClose} className="h-11 rounded-full border border-slate-200 px-5 text-sm font-bold text-slate-700">ยกเลิก</button><button type="submit" disabled={save.isPending} className="h-11 rounded-full bg-brand-700 px-5 text-sm font-bold text-white disabled:bg-slate-300">{save.isPending ? 'กำลังบันทึก' : 'บันทึกงาน'}</button></div></form></ModalShell>;
}

function SubmissionEditor({ assignment, onClose, onSaved }: { assignment: Assignment; onClose: () => void; onSaved: () => void }) {
    const [type, setType] = useState<'link' | 'pdf'>(assignment.submission?.type ?? 'link');
    const [url, setUrl] = useState(assignment.submission?.type === 'link' ? assignment.submission.url : '');
    const [file, setFile] = useState<File | null>(null);
    const [progress, setProgress] = useState(0);
    const save = useMutation({
        mutationFn: () => {
            const payload = new FormData();
            payload.append('submission_type', type);
            if (type === 'link') payload.append('url', url);
            if (type === 'pdf' && file) payload.append('file', file);
            return uploadFeatureData(`/api/v1/learning/assignments/${assignment.id}/submit`, payload, setProgress);
        },
        onSuccess: () => { showSuccessAlert('ส่งงานเรียบร้อยแล้ว'); onSaved(); },
    });
    return <ModalShell title="ส่งงาน" description={assignment.title} onClose={onClose}><form onSubmit={(event) => { event.preventDefault(); save.mutate(); }} className="space-y-4 p-5"><fieldset><legend className="mb-2 text-sm font-bold text-slate-700">รูปแบบการส่ง *</legend><div className="grid grid-cols-2 gap-3"><label className={`cursor-pointer rounded-xl border p-4 ${type === 'link' ? 'border-brand-600 bg-brand-50 text-brand-900' : 'border-slate-200 text-slate-700'}`}><input type="radio" name="submission_type" value="link" checked={type === 'link'} onChange={() => { setType('link'); setFile(null); }} className="sr-only" /><LinkSimple size={24} /><span className="mt-2 block font-bold">ลิงก์งาน</span><span className="mt-1 block text-xs">Google Drive, OneDrive หรือเว็บไซต์</span></label><label className={`cursor-pointer rounded-xl border p-4 ${type === 'pdf' ? 'border-brand-600 bg-brand-50 text-brand-900' : 'border-slate-200 text-slate-700'}`}><input type="radio" name="submission_type" value="pdf" checked={type === 'pdf'} onChange={() => setType('pdf')} className="sr-only" /><FilePdf size={24} /><span className="mt-2 block font-bold">ไฟล์ PDF</span><span className="mt-1 block text-xs">ขนาดไม่เกิน 20 MB</span></label></div></fieldset>{type === 'link' ? <label className="block"><span className="mb-1.5 block text-sm font-bold text-slate-700">ลิงก์ http/https *</span><input required type="url" value={url} onChange={(event) => setUrl(event.target.value)} placeholder="https://drive.google.com/..." className="h-11 w-full rounded-xl border border-slate-300 px-3 outline-none focus:border-brand-600 focus:ring-2 focus:ring-brand-100" /></label> : <label className="block"><span className="mb-1.5 block text-sm font-bold text-slate-700">ไฟล์ PDF *</span><input required type="file" accept="application/pdf,.pdf" onChange={(event) => setFile(event.target.files?.[0] ?? null)} className="block w-full rounded-xl border border-slate-300 p-2.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-bold file:text-brand-800" />{file && <p className="mt-2 text-xs font-bold text-slate-600">{file.name} {fileSize(file.size)}</p>}</label>}{save.isPending && <div><div className="h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-brand-700 transition-[width]" style={{ width: `${progress}%` }} /></div><p className="mt-1 text-right text-xs font-bold text-slate-500">อัปโหลด {progress}%</p></div>}{save.error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm font-bold text-rose-800">{save.error.message}</p>}<div className="flex justify-end gap-2"><button type="button" onClick={onClose} className="h-11 rounded-full border border-slate-200 px-5 text-sm font-bold">ยกเลิก</button><button type="submit" disabled={save.isPending} className="h-11 rounded-full bg-brand-700 px-5 text-sm font-bold text-white disabled:bg-slate-300">{save.isPending ? 'กำลังส่งงาน' : 'ยืนยันส่งงาน'}</button></div></form></ModalShell>;
}

function ReviewEditor({ assignment, student, onClose, onSaved }: { assignment: Assignment; student: AssignmentStudent; onClose: () => void; onSaved: () => void }) {
    const [score, setScore] = useState(student.submission?.score == null ? '' : String(student.submission.score));
    const [feedback, setFeedback] = useState(student.submission?.feedback ?? '');
    const save = useMutation({
        mutationFn: () => sendFeatureData(`/api/v1/learning/assignments/${assignment.id}/submissions/${student.submission?.id}`, 'PATCH', { score: score === '' ? null : Number(score), feedback }),
        onSuccess: () => { showSuccessAlert('บันทึกผลตรวจเรียบร้อยแล้ว'); onSaved(); },
    });
    return <ModalShell title="ตรวจงานและให้คะแนน" description={`${student.full_name} รหัส ${student.student_code}`} onClose={onClose}><form onSubmit={(event) => { event.preventDefault(); save.mutate(); }} className="space-y-4 p-5">{student.submission && <div className="rounded-xl bg-slate-50 p-4"><SubmissionLink assignment={assignment} submission={student.submission} /><p className="mt-2 text-xs text-slate-500">ส่งเมื่อ {thaiDateTime(student.submission.submitted_at)}</p></div>}<label className="block"><span className="mb-1.5 block text-sm font-bold text-slate-700">คะแนน (เต็ม {assignment.max_score})</span><input type="number" min="0" max={assignment.max_score} step="0.01" value={score} onChange={(event) => setScore(event.target.value)} className="h-11 w-full rounded-xl border border-slate-300 px-3" /></label><label className="block"><span className="mb-1.5 block text-sm font-bold text-slate-700">ข้อเสนอแนะ</span><textarea rows={5} maxLength={5000} value={feedback} onChange={(event) => setFeedback(event.target.value)} className="w-full rounded-xl border border-slate-300 p-3" /></label>{save.error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm font-bold text-rose-800">{save.error.message}</p>}<div className="flex justify-end gap-2"><button type="button" onClick={onClose} className="h-11 rounded-full border border-slate-200 px-5 text-sm font-bold">ยกเลิก</button><button type="submit" disabled={save.isPending} className="h-11 rounded-full bg-brand-700 px-5 text-sm font-bold text-white disabled:bg-slate-300">{save.isPending ? 'กำลังบันทึก' : 'บันทึกผลตรวจ'}</button></div></form></ModalShell>;
}
