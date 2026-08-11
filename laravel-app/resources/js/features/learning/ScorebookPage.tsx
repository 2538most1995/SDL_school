import {
    ArrowsClockwise,
    BookOpenText,
    ChartBar,
    FloppyDisk,
    NotePencil,
    Plus,
    Trash,
    Users,
} from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { Button, Field, Input, Select } from '../../components/MaterialUI';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { showSuccessAlert } from '../../lib/feedback';
import { getFeatureDataWithDemo, sendFeatureData } from '../api';

type ScoreComponent = {
    id: string;
    key: string;
    title: string;
    max_score: number;
    position: number;
};

type ScoreSubject = {
    code: string;
    name: string;
    level: number;
    level_label: string;
    student_count: number;
    groups: Array<{ code: string; name: string }>;
};

type ScoreStudent = {
    student_code: string;
    full_name: string;
    group_code: string;
    group_name: string;
    scores: Record<string, number | null>;
    total: number;
    note: string | null;
};

type ScoreWorkspace = {
    terms: string[];
    selected_term: string | null;
    subjects: ScoreSubject[];
    selected_subject: ScoreSubject | null;
    scorebook: null | {
        id: string;
        created_by: string;
        can_edit: boolean;
        group: string;
        components: ScoreComponent[];
        maximum_score: number;
    };
    students: ScoreStudent[];
};

type ComponentDraft = { id?: string; clientKey: string; title: string; maxScore: string };
type StudentDraft = Record<string, { scores: Record<string, string>; note: string }>;
type ScoreTab = 'scores' | 'structure';

const emptyWorkspace: ScoreWorkspace = {
    terms: [],
    selected_term: null,
    subjects: [],
    selected_subject: null,
    scorebook: null,
    students: [],
};

const defaultComponents: ComponentDraft[] = [
    { clientKey: 'default-1', title: 'คะแนนเก็บครั้งที่ 1', maxScore: '20' },
    { clientKey: 'default-2', title: 'ใบงานที่ 1', maxScore: '10' },
    { clientKey: 'default-3', title: 'แบบทดสอบย่อย', maxScore: '20' },
    { clientKey: 'default-4', title: 'การนำเสนอ', maxScore: '20' },
    { clientKey: 'default-5', title: 'พฤติกรรมและคุณลักษณะ', maxScore: '30' },
];

function subjectKey(subject: Pick<ScoreSubject, 'code' | 'level'>): string {
    return `${subject.level}|${subject.code}`;
}

function scorePath(term: string, selected: string, group: string): string {
    const query = new URLSearchParams();
    if (term) query.set('term', term);
    if (selected) {
        const separator = selected.indexOf('|');
        query.set('level', selected.slice(0, separator));
        query.set('subject_code', selected.slice(separator + 1));
    }
    if (group) query.set('group', group);

    return `/api/v1/learning/scores/workspace${query.size ? `?${query.toString()}` : ''}`;
}

function formatScore(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

export function ScorebookPage() {
    const { role } = useDemoRole();

    return role === 'student' ? <StudentScoreSummary /> : <TeacherScorebook />;
}

function TeacherScorebook() {
    const queryClient = useQueryClient();
    const [term, setTerm] = useState('');
    const [selected, setSelected] = useState('');
    const [group, setGroup] = useState('');
    const [tab, setTab] = useState<ScoreTab>('scores');
    const [components, setComponents] = useState<ComponentDraft[]>(defaultComponents);
    const [studentValues, setStudentValues] = useState<StudentDraft>({});
    const [scoresDirty, setScoresDirty] = useState(false);

    const workspace = useQuery({
        queryKey: ['learning', 'scorebook', term, selected, group],
        queryFn: ({ signal }) => getFeatureDataWithDemo<ScoreWorkspace>(scorePath(term, selected, group), emptyWorkspace, signal),
        refetchOnWindowFocus: false,
    });
    const data = workspace.data?.data;

    useEffect(() => {
        if (!data) return;
        if (!term && data.selected_term) setTerm(data.selected_term);
        if (!selected && data.selected_subject) setSelected(subjectKey(data.selected_subject));
    }, [data, selected, term]);

    useEffect(() => {
        if (!data?.scorebook) {
            setComponents(defaultComponents);
            setStudentValues({});
            return;
        }
        setComponents(data.scorebook.components.map((component) => ({
            id: component.id,
            clientKey: `component-${component.id}`,
            title: component.title,
            maxScore: formatScore(component.max_score),
        })));
        if (scoresDirty) return;
        setStudentValues(Object.fromEntries(data.students.map((student) => [
            student.student_code,
            {
                scores: Object.fromEntries(data.scorebook!.components.map((component) => [
                    component.id,
                    student.scores[component.id] == null ? '' : formatScore(student.scores[component.id]!),
                ])),
                note: student.note ?? '',
            },
        ])));
    }, [data?.scorebook?.id, data?.scorebook?.components, data?.students, scoresDirty]);

    const componentTotal = useMemo(() => components.reduce((sum, component) => sum + (Number(component.maxScore) || 0), 0), [components]);
    const selectedSubject = data?.selected_subject;
    const scorebook = data?.scorebook;
    const readOnly = workspace.data?.meta.read_only === true;
    const canEdit = !readOnly && (scorebook?.can_edit ?? true);
    const groupOptions = selectedSubject?.groups ?? [];
    const canSaveStructure = components.length > 0
        && components.every((component) => component.title.trim() !== '' && Number(component.maxScore) > 0)
        && componentTotal <= 100;

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['learning', 'scorebook'] });
    };

    const createScorebook = useMutation({
        mutationFn: () => {
            if (!selectedSubject || !data?.selected_term) throw new Error('กรุณาเลือกรายวิชาก่อนสร้างสมุดคะแนน');
            return sendFeatureData('/api/v1/learning/scores/scorebooks', 'POST', {
                term: data.selected_term,
                subject_code: selectedSubject.code,
                level: selectedSubject.level,
                group,
                components: components.map((component) => ({ title: component.title.trim(), max_score: Number(component.maxScore) })),
            });
        },
        onSuccess: async () => {
            setScoresDirty(false);
            await refresh();
            setTab('scores');
            showSuccessAlert('สร้างสมุดคะแนนแล้ว');
        },
    });

    const saveStructure = useMutation({
        mutationFn: () => sendFeatureData(`/api/v1/learning/scores/scorebooks/${scorebook?.id ?? ''}/structure`, 'PUT', {
            components: components.map((component) => ({
                ...(component.id ? { id: component.id } : {}),
                title: component.title.trim(),
                max_score: Number(component.maxScore),
            })),
        }),
        onSuccess: async () => {
            setScoresDirty(false);
            await refresh();
            showSuccessAlert('บันทึกโครงสร้างคะแนนแล้ว');
        },
    });

    const saveScores = useMutation({
        mutationFn: () => sendFeatureData(`/api/v1/learning/scores/scorebooks/${scorebook?.id ?? ''}/entries`, 'PUT', {
            students: (data?.students ?? []).map((student) => ({
                student_code: student.student_code,
                note: studentValues[student.student_code]?.note || null,
                scores: (scorebook?.components ?? []).map((component) => {
                    const raw = studentValues[student.student_code]?.scores[component.id] ?? '';
                    return { component_id: component.id, score: raw === '' ? null : Number(raw) };
                }),
            })),
        }),
        onSuccess: async () => {
            setScoresDirty(false);
            await refresh();
            showSuccessAlert('บันทึกคะแนนเก็บแล้ว');
        },
    });

    const changeSubject = (value: string) => {
        setScoresDirty(false);
        setSelected(value);
        setGroup('');
        setTab('scores');
    };

    const updateScore = (studentCode: string, componentId: string, value: string) => {
        if (value !== '' && !/^\d{0,3}(?:\.\d{0,2})?$/.test(value)) return;
        setScoresDirty(true);
        setStudentValues((current) => ({
            ...current,
            [studentCode]: {
                note: current[studentCode]?.note ?? '',
                scores: { ...(current[studentCode]?.scores ?? {}), [componentId]: value },
            },
        }));
    };

    const updateNote = (studentCode: string, note: string) => {
        setScoresDirty(true);
        setStudentValues((current) => ({
            ...current,
            [studentCode]: { scores: current[studentCode]?.scores ?? {}, note },
        }));
    };

    const studentTotal = (studentCode: string): number => (scorebook?.components ?? []).reduce((sum, component) => {
        const raw = studentValues[studentCode]?.scores[component.id] ?? '';
        return sum + (raw === '' ? 0 : Number(raw) || 0);
    }, 0);

    const mutationError = createScorebook.error ?? saveStructure.error ?? saveScores.error;
    const invalidScores = (data?.students ?? []).flatMap((student) => (scorebook?.components ?? []).filter((component) => {
        const raw = studentValues[student.student_code]?.scores[component.id] ?? '';
        return raw !== '' && Number(raw) > component.max_score;
    }).map((component) => `${student.full_name}: ${component.title}`));

    return <div>
        <PageHeader category="learning" title="บันทึกคะแนนเก็บ" description="เลือกวิชาที่นักศึกษาลงทะเบียน กำหนดคะแนนเต็ม และบันทึกคะแนนระหว่างภาค" icon={NotePencil} />

        <section className="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
            <div className="grid gap-5 p-5 lg:grid-cols-[minmax(0,1.35fr)_repeat(3,minmax(140px,0.55fr))] lg:items-end">
                <Field label="รายวิชาที่ลงทะเบียน" required>
                    <Select value={selected} onChange={(_, option) => changeSubject(option.value)} size="large" disabled={workspace.isPending || (data?.subjects.length ?? 0) === 0}>
                        {(data?.subjects.length ?? 0) === 0 && <option value="">ไม่พบรายวิชาที่ลงทะเบียน</option>}
                        {(data?.subjects ?? []).map((subject) => <option key={subjectKey(subject)} value={subjectKey(subject)}>{subject.code} {subject.name}</option>)}
                    </Select>
                </Field>
                <Field label="ภาคเรียน">
                    <Select value={term} onChange={(_, option) => { setTerm(option.value); setSelected(''); setGroup(''); }} size="large">
                        {(data?.terms ?? []).map((item) => <option key={item} value={item}>{item}</option>)}
                    </Select>
                </Field>
                <Field label="กลุ่มเรียน">
                    <Select value={group} onChange={(_, option) => setGroup(option.value)} size="large" disabled={!selectedSubject}>
                        <option value="">ทุกกลุ่มในวิชา</option>
                        {groupOptions.map((item) => <option key={item.code || item.name} value={item.code || item.name}>{item.name || item.code}</option>)}
                    </Select>
                </Field>
                <Button type="button" appearance="outline" size="large" icon={<ArrowsClockwise size={18} weight="bold" />} onClick={() => workspace.refetch()} disabled={workspace.isFetching}>โหลดข้อมูลใหม่</Button>
            </div>
            {selectedSubject && <div className="grid border-t border-slate-100 bg-slate-50/70 sm:grid-cols-3">
                <div className="p-4 sm:border-r sm:border-slate-200"><p className="text-xs font-bold text-slate-500">ระดับ</p><p className="mt-1 font-black text-slate-900">{selectedSubject.level_label}</p></div>
                <div className="p-4 sm:border-r sm:border-slate-200"><p className="text-xs font-bold text-slate-500">จำนวนนักศึกษา</p><p className="mt-1 font-black text-slate-900">{data?.students.length ?? selectedSubject.student_count} คน</p></div>
                <div className="p-4"><p className="text-xs font-bold text-slate-500">คะแนนเต็มรวม</p><p className={`mt-1 font-black ${componentTotal > 100 ? 'text-rose-700' : 'text-brand-800'}`}>{formatScore(componentTotal)} คะแนน</p></div>
            </div>}
        </section>

        {workspace.isPending && <QuerySkeleton rows={7} />}
        {workspace.isError && <QueryError onRetry={() => workspace.refetch()} />}
        {readOnly && <p role="status" className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-900">ระบบเปิดให้อ่านข้อมูลเท่านั้น กรุณาให้ผู้ดูแลเปิดการบันทึกข้อมูลก่อน</p>}
        {scorebook && !scorebook.can_edit && <p role="status" className="mb-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm font-bold text-sky-900">สมุดคะแนนนี้สร้างโดยครูท่านอื่น คุณเปิดดูได้แต่ไม่สามารถแก้ไขได้</p>}

        {data && !selectedSubject && <Panel title="ยังไม่มีวิชาให้บันทึกคะแนน" description="ระบบจะแสดงเฉพาะรายวิชาที่มีนักศึกษาลงทะเบียนในภาคเรียนและขอบเขตกลุ่มของครู">
            <div className="grid min-h-48 place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-center text-sm text-slate-600">ตรวจข้อมูลนำเข้า ภาคเรียน และกลุ่มที่ครูรับผิดชอบ</div>
        </Panel>}

        {data && selectedSubject && <>
            <nav className="mb-4 flex gap-1 border-b border-slate-200" aria-label="ส่วนของสมุดคะแนน">
                <button type="button" onClick={() => setTab('scores')} className={`inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-bold ${tab === 'scores' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-900'}`}><NotePencil size={18} /> บันทึกคะแนน</button>
                <button type="button" onClick={() => setTab('structure')} className={`inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-bold ${tab === 'structure' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-900'}`}><ChartBar size={18} /> โครงสร้างคะแนน</button>
            </nav>

            {tab === 'structure' && <Panel title="โครงสร้างคะแนน" description="กำหนดชื่อช่องและคะแนนเต็ม คะแนนรวมทุกช่องต้องไม่เกิน 100 คะแนน" action={<div className={`rounded-xl px-3 py-2 text-sm font-black ${componentTotal > 100 ? 'bg-rose-50 text-rose-800' : 'bg-brand-50 text-brand-800'}`}>รวม {formatScore(componentTotal)} / 100</div>}>
                <div className="space-y-3">
                    {components.map((component, index) => <div key={component.clientKey} className="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[44px_minmax(0,1fr)_150px_44px] sm:items-end">
                        <span className="grid size-10 place-items-center rounded-lg bg-slate-100 text-sm font-black text-slate-600">{index + 1}</span>
                        <Field label="ชื่อช่องคะแนน"><Input value={component.title} onChange={(_, value) => setComponents((items) => items.map((item) => item.clientKey === component.clientKey ? { ...item, title: value.value } : item))} size="large" disabled={!canEdit} /></Field>
                        <Field label="คะแนนเต็ม"><Input type="number" min="0.01" max="100" step="0.01" value={component.maxScore} onChange={(_, value) => setComponents((items) => items.map((item) => item.clientKey === component.clientKey ? { ...item, maxScore: value.value } : item))} size="large" disabled={!canEdit} /></Field>
                        <button type="button" onClick={() => setComponents((items) => items.filter((item) => item.clientKey !== component.clientKey))} className="grid size-10 place-items-center rounded-lg border border-rose-200 text-rose-700 hover:bg-rose-50 disabled:opacity-40" disabled={!canEdit || components.length === 1} aria-label={`ลบช่อง ${component.title}`}><Trash size={18} /></button>
                    </div>)}
                </div>
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <Button type="button" appearance="outline" icon={<Plus size={17} weight="bold" />} disabled={!canEdit} onClick={() => setComponents((items) => [...items, { clientKey: `new-${Date.now()}`, title: `คะแนนเก็บครั้งที่ ${items.length + 1}`, maxScore: '10' }])}>เพิ่มช่องคะแนน</Button>
                    <Button type="button" appearance="primary" icon={<FloppyDisk size={18} weight="bold" />} disabled={!canEdit || !canSaveStructure || createScorebook.isPending || saveStructure.isPending} onClick={() => scorebook ? saveStructure.mutate() : createScorebook.mutate()}>{scorebook ? 'บันทึกโครงสร้าง' : 'สร้างสมุดคะแนน'}</Button>
                </div>
            </Panel>}

            {tab === 'scores' && !scorebook && <Panel title="สร้างสมุดคะแนนก่อนบันทึก" description="ระบบเตรียมโครงสร้างตัวอย่าง 100 คะแนนตามแบบที่แนบไว้แล้ว">
                <div className="flex min-h-48 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                    <BookOpenText size={38} className="text-brand-700" />
                    <p className="max-w-lg text-sm leading-6 text-slate-600">ตรวจชื่อช่องและคะแนนเต็มในแท็บโครงสร้างคะแนน จากนั้นสร้างสมุดคะแนนเพื่อเริ่มกรอกคะแนนนักศึกษา</p>
                    <Button type="button" appearance="primary" onClick={() => setTab('structure')}>ตรวจโครงสร้างคะแนน</Button>
                </div>
            </Panel>}

            {tab === 'scores' && scorebook && <Panel title="บันทึกคะแนนรายคน" description={`${selectedSubject.code} ${selectedSubject.name}, ${data.students.length} คน`} action={<div className="inline-flex items-center gap-2 text-sm font-bold text-slate-600"><Users size={18} /> {data.students.length} คน</div>}>
                {data.students.length === 0 ? <div className="grid min-h-48 place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-center text-sm text-slate-600">ไม่พบนักศึกษาที่ลงทะเบียนวิชานี้ในกลุ่มที่เลือก</div> : <div className="overflow-x-auto rounded-xl border border-slate-200">
                    <table className="w-full border-collapse text-sm" style={{ minWidth: `${560 + (scorebook.components.length * 155)}px` }}>
                        <thead className="bg-slate-50 text-slate-700">
                            <tr>
                                <th className="w-14 border-b border-r border-slate-200 px-3 py-3 text-center">ลำดับ</th>
                                <th className="min-w-60 border-b border-r border-slate-200 px-4 py-3 text-left">ชื่อและรหัสนักศึกษา</th>
                                {scorebook.components.map((component) => <th key={component.id} className="min-w-36 border-b border-r border-slate-200 px-3 py-3 text-center"><span className="block font-black text-slate-900">{component.title}</span><span className="mt-1 block text-xs font-medium text-slate-500">เต็ม {formatScore(component.max_score)}</span></th>)}
                                <th className="w-24 border-b border-r border-slate-200 px-3 py-3 text-center">รวม</th>
                                <th className="min-w-48 border-b border-slate-200 px-3 py-3 text-left">หมายเหตุ</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.students.map((student, index) => {
                                const total = studentTotal(student.student_code);
                                return <tr key={student.student_code} className="bg-white hover:bg-slate-50/70">
                                    <td className="border-b border-r border-slate-200 px-3 py-3 text-center font-bold text-slate-500">{index + 1}</td>
                                    <td className="border-b border-r border-slate-200 px-4 py-3"><p className="font-black text-slate-950">{student.full_name}</p><p className="mt-1 text-xs text-slate-500">{student.student_code} / {student.group_name || student.group_code}</p></td>
                                    {scorebook.components.map((component) => <td key={component.id} className="border-b border-r border-slate-200 p-2 text-center"><input aria-label={`${component.title} ของ ${student.full_name}`} type="number" min="0" max={component.max_score} step="0.01" value={studentValues[student.student_code]?.scores[component.id] ?? ''} onChange={(event) => updateScore(student.student_code, component.id, event.target.value)} disabled={!canEdit} className="h-10 w-full rounded-lg border border-slate-300 bg-white px-2 text-center font-mono font-bold text-slate-900 outline-none focus:border-brand-600 focus:ring-2 focus:ring-brand-100 disabled:bg-slate-100 disabled:text-slate-500" /></td>)}
                                    <td className={`border-b border-r border-slate-200 px-3 py-3 text-center font-mono text-base font-black ${total > scorebook.maximum_score ? 'text-rose-700' : 'text-brand-800'}`}>{formatScore(total)}</td>
                                    <td className="border-b border-slate-200 p-2"><input aria-label={`หมายเหตุของ ${student.full_name}`} value={studentValues[student.student_code]?.note ?? ''} onChange={(event) => updateNote(student.student_code, event.target.value)} maxLength={1000} placeholder="เพิ่มหมายเหตุ" disabled={!canEdit} className="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none placeholder:text-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-100 disabled:bg-slate-100 disabled:text-slate-500" /></td>
                                </tr>;
                            })}
                        </tbody>
                        <tfoot className="bg-slate-50 font-bold text-slate-700">
                            <tr>
                                <td colSpan={2} className="border-r border-slate-200 px-4 py-3 text-right">คะแนนเต็ม</td>
                                {scorebook.components.map((component) => <td key={component.id} className="border-r border-slate-200 px-3 py-3 text-center font-mono">{formatScore(component.max_score)}</td>)}
                                <td className="border-r border-slate-200 px-3 py-3 text-center font-mono text-brand-800">{formatScore(scorebook.maximum_score)}</td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>}
                {invalidScores.length > 0 && <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">มีคะแนนเกินคะแนนเต็ม: {invalidScores.slice(0, 3).join(', ')}{invalidScores.length > 3 ? ` และอีก ${invalidScores.length - 3} รายการ` : ''}</p>}
                <div className="mt-5 flex justify-end">
                    <Button type="button" appearance="primary" size="large" icon={<FloppyDisk size={18} weight="bold" />} onClick={() => saveScores.mutate()} disabled={!canEdit || data.students.length === 0 || invalidScores.length > 0 || saveScores.isPending}>{saveScores.isPending ? 'กำลังบันทึกคะแนน' : 'บันทึกคะแนน'}</Button>
                </div>
            </Panel>}
        </>}

        {mutationError && <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-800">{mutationError.message}</p>}
    </div>;
}

type StudentScorePayload = {
    term: string | null;
    summary: { score: number; maximum_score: number; items: number };
    courses: Array<{ id: string; subject_code: string; subject_name: string; total_score: number | null; status: string }>;
    disclaimer: string;
};

function StudentScoreSummary() {
    const scores = useQuery({
        queryKey: ['learning', 'scores', 'student'],
        queryFn: ({ signal }) => getFeatureDataWithDemo<StudentScorePayload>('/api/v1/learning/scores', { term: null, summary: { score: 0, maximum_score: 0, items: 0 }, courses: [], disclaimer: '' }, signal),
    });
    if (scores.isPending) return <QuerySkeleton rows={6} />;
    if (scores.isError) return <QueryError onRetry={() => scores.refetch()} />;

    return <div>
        <PageHeader category="learning" title="คะแนนเก็บของฉัน" description="คะแนนระหว่างภาคจากงานและแบบประเมินที่ครูบันทึกในระบบ" icon={ChartBar} />
        <Panel title="รายการคะแนน" description={scores.data.data.disclaimer}>
            {scores.data.data.courses.length === 0 ? <div className="grid min-h-48 place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-center text-sm text-slate-600">ครูยังไม่ได้บันทึกคะแนนเก็บ</div> : <div className="overflow-x-auto rounded-xl border border-slate-200">
                <table className="min-w-[680px] w-full text-sm"><thead className="bg-slate-50"><tr><th className="px-4 py-3 text-left">รายวิชา</th><th className="px-4 py-3 text-left">รหัสวิชา</th><th className="px-4 py-3 text-center">คะแนนรวม</th><th className="px-4 py-3 text-center">สถานะ</th></tr></thead><tbody>{scores.data.data.courses.map((course) => <tr key={course.id} className="border-t border-slate-200"><td className="px-4 py-3 font-bold text-slate-950">{course.subject_name}</td><td className="px-4 py-3 font-mono text-slate-600">{course.subject_code}</td><td className="px-4 py-3 text-center font-mono font-black text-brand-800">{course.total_score == null ? '-' : formatScore(course.total_score)}</td><td className="px-4 py-3 text-center text-slate-600">{course.status === 'studying' ? 'กำลังเรียน' : course.status}</td></tr>)}</tbody></table>
            </div>}
        </Panel>
    </div>;
}
