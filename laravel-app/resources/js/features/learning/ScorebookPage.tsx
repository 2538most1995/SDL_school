import {
    ArrowsClockwise,
    BookOpenText,
    ChartBar,
    FileXls,
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
import { downloadExcel } from '../../lib/excel';
import { getFeatureDataWithDemo, sendFeatureData } from '../api';

type ScoreComponent = {
    id: string;
    key: string;
    category: 'coursework' | 'final_exam';
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
    coursework_score: number;
    final_exam_score: number | null;
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
        score_ratio: ScoreRatio | null;
        coursework_weight: number | null;
        final_exam_weight: number | null;
        components: ScoreComponent[];
        maximum_score: number;
    };
    students: ScoreStudent[];
};

type ScoreRatio = '60:40' | '70:30' | '80:20';
type ComponentDraft = { id?: string; clientKey: string; category: 'coursework' | 'final_exam'; title: string; maxScore: string };
type StudentDraft = Record<string, { scores: Record<string, string>; note: string }>;
type ScoreTab = 'scores' | 'structure';
type ScoreTemplate = {
    id: string;
    name: string;
    score_ratio: ScoreRatio;
    applies_to_all: boolean;
    subject_codes: string[];
    components: Array<{ category: 'coursework' | 'final_exam'; title: string; max_score: number; position: number }>;
    can_delete: boolean;
};

const emptyWorkspace: ScoreWorkspace = {
    terms: [],
    selected_term: null,
    subjects: [],
    selected_subject: null,
    scorebook: null,
    students: [],
};

function ratioWeights(ratio: ScoreRatio): [number, number] {
    return ratio.split(':').map(Number) as [number, number];
}

function defaultComponents(ratio: ScoreRatio): ComponentDraft[] {
    const [coursework, finalExam] = ratioWeights(ratio);
    return [
        { clientKey: 'default-coursework', category: 'coursework', title: 'คะแนนเก็บ', maxScore: String(coursework) },
        { clientKey: 'default-final', category: 'final_exam', title: 'สอบปลายภาค', maxScore: String(finalExam) },
    ];
}

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
    const [scoreRatio, setScoreRatio] = useState<ScoreRatio>('60:40');
    const [components, setComponents] = useState<ComponentDraft[]>(() => defaultComponents('60:40'));
    const [studentValues, setStudentValues] = useState<StudentDraft>({});
    const [scoresDirty, setScoresDirty] = useState(false);
    const [selectedTemplateId, setSelectedTemplateId] = useState('');
    const [bulkTemplateId, setBulkTemplateId] = useState('');
    const [templateName, setTemplateName] = useState('');
    const [templateAppliesToAll, setTemplateAppliesToAll] = useState(true);
    const [templateSubjectCodes, setTemplateSubjectCodes] = useState<string[]>([]);

    const workspace = useQuery({
        queryKey: ['learning', 'scorebook', term, selected, group],
        queryFn: ({ signal }) => getFeatureDataWithDemo<ScoreWorkspace>(scorePath(term, selected, group), emptyWorkspace, signal),
        refetchOnWindowFocus: false,
    });
    const data = workspace.data?.data;
    const templates = useQuery({
        queryKey: ['learning', 'score-templates'],
        queryFn: ({ signal }) => getFeatureDataWithDemo<ScoreTemplate[]>('/api/v1/learning/scores/templates', [], signal),
        refetchOnWindowFocus: false,
    });
    const templateRows = templates.data?.data ?? [];

    useEffect(() => {
        if (!data) return;
        if (!term && data.selected_term) setTerm(data.selected_term);
        if (!selected && data.selected_subject) setSelected(subjectKey(data.selected_subject));
    }, [data, selected, term]);

    useEffect(() => {
        if (!data?.scorebook) {
            setComponents(defaultComponents(scoreRatio));
            setStudentValues({});
            return;
        }
        if (data.scorebook.score_ratio) setScoreRatio(data.scorebook.score_ratio);
        setComponents(data.scorebook.components.map((component) => ({
            id: component.id,
            clientKey: `component-${component.id}`,
            category: component.category,
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
    }, [data?.scorebook?.id, data?.scorebook?.components, data?.scorebook?.score_ratio, data?.students, scoresDirty]);

    const componentTotal = useMemo(() => components.reduce((sum, component) => sum + (Number(component.maxScore) || 0), 0), [components]);
    const [courseworkWeight, finalExamWeight] = ratioWeights(scoreRatio);
    const courseworkTotal = useMemo(() => components.filter((component) => component.category === 'coursework').reduce((sum, component) => sum + (Number(component.maxScore) || 0), 0), [components]);
    const finalExamTotal = useMemo(() => components.filter((component) => component.category === 'final_exam').reduce((sum, component) => sum + (Number(component.maxScore) || 0), 0), [components]);
    const selectedSubject = data?.selected_subject;
    const scorebook = data?.scorebook;
    const availableSubjects = useMemo(() => Array.from(new Map((data?.subjects ?? []).map((subject) => [subject.code, subject])).values()), [data?.subjects]);
    const applicableTemplates = useMemo(() => templateRows.filter((template) => template.applies_to_all
        || (selectedSubject !== null && selectedSubject !== undefined && template.subject_codes.includes(selectedSubject.code))), [selectedSubject, templateRows]);
    const allSubjectTemplates = useMemo(() => templateRows.filter((template) => template.applies_to_all), [templateRows]);
    const readOnly = workspace.data?.meta.read_only === true;
    const canEdit = !readOnly && (scorebook?.can_edit ?? true);
    const groupOptions = selectedSubject?.groups ?? [];
    const canSaveStructure = components.length > 0
        && components.every((component) => component.title.trim() !== '' && Number(component.maxScore) > 0)
        && components.filter((component) => component.category === 'final_exam').length === 1
        && Math.abs(courseworkTotal - courseworkWeight) < 0.001
        && Math.abs(finalExamTotal - finalExamWeight) < 0.001;
    const selectedTemplate = applicableTemplates.find((template) => template.id === selectedTemplateId);
    const bulkTemplate = allSubjectTemplates.find((template) => template.id === bulkTemplateId);
    const canCreateTemplate = canEdit && canSaveStructure && templateName.trim() !== ''
        && (templateAppliesToAll || templateSubjectCodes.length > 0);

    const applyScoreRatio = (nextRatio: ScoreRatio) => {
        const [nextCoursework, nextFinalExam] = ratioWeights(nextRatio);
        setScoreRatio(nextRatio);
        setComponents((current) => {
            const coursework = current.filter((component) => component.category === 'coursework');
            const oldTotal = coursework.reduce((sum, component) => sum + (Number(component.maxScore) || 0), 0);
            let allocated = 0;
            const adjustedCoursework = coursework.map((component, index) => {
                const maximum = index === coursework.length - 1
                    ? nextCoursework - allocated
                    : Math.round((oldTotal > 0 ? (Number(component.maxScore) || 0) / oldTotal : 1 / Math.max(coursework.length, 1)) * nextCoursework * 100) / 100;
                allocated += maximum;
                return { ...component, maxScore: formatScore(maximum) };
            });
            const finalComponent = current.find((component) => component.category === 'final_exam');
            return [
                ...(adjustedCoursework.length > 0 ? adjustedCoursework : [{ clientKey: `coursework-${Date.now()}`, category: 'coursework' as const, title: 'คะแนนเก็บ', maxScore: String(nextCoursework) }]),
                finalComponent
                    ? { ...finalComponent, maxScore: String(nextFinalExam) }
                    : { clientKey: `final-${Date.now()}`, category: 'final_exam' as const, title: 'สอบปลายภาค', maxScore: String(nextFinalExam) },
            ];
        });
    };

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
                score_ratio: scoreRatio,
                components: components.map((component) => ({ category: component.category, title: component.title.trim(), max_score: Number(component.maxScore) })),
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
            score_ratio: scoreRatio,
            components: components.map((component) => ({
                ...(component.id ? { id: component.id } : {}),
                category: component.category,
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
            showSuccessAlert('บันทึกคะแนนแล้ว');
        },
    });

    const createTemplate = useMutation({
        mutationFn: () => sendFeatureData('/api/v1/learning/scores/templates', 'POST', {
            name: templateName.trim(),
            score_ratio: scoreRatio,
            applies_to_all: templateAppliesToAll,
            subject_codes: templateAppliesToAll ? [] : templateSubjectCodes,
            components: components.map((component) => ({
                category: component.category,
                title: component.title.trim(),
                max_score: Number(component.maxScore),
            })),
        }),
        onSuccess: async () => {
            setTemplateName('');
            await queryClient.invalidateQueries({ queryKey: ['learning', 'score-templates'] });
            showSuccessAlert('บันทึกต้นแบบโครงสร้างคะแนนแล้ว');
        },
    });

    const deleteTemplate = useMutation({
        mutationFn: (templateId: string) => sendFeatureData(`/api/v1/learning/scores/templates/${templateId}`, 'DELETE'),
        onSuccess: async () => {
            setSelectedTemplateId('');
            await queryClient.invalidateQueries({ queryKey: ['learning', 'score-templates'] });
            showSuccessAlert('ลบต้นแบบแล้ว');
        },
    });

    const applyTemplateToAllSubjects = useMutation({
        mutationFn: () => sendFeatureData<{ created_count: number; skipped_count: number; eligible_count: number }>(`/api/v1/learning/scores/templates/${bulkTemplateId}/apply`, 'POST', {
            term: data?.selected_term ?? term,
        }),
        onSuccess: async (response) => {
            setScoresDirty(false);
            setStudentValues({});
            await refresh();
            showSuccessAlert(`สร้างสมุดคะแนน ${response.data.created_count} วิชา${response.data.skipped_count > 0 ? ` · ข้ามวิชาที่มีอยู่แล้ว ${response.data.skipped_count}` : ''}`);
        },
    });

    const applyTemplate = () => {
        const template = applicableTemplates.find((item) => item.id === selectedTemplateId);
        if (!template) return;
        setScoreRatio(template.score_ratio);
        setComponents(template.components.map((component, index) => ({
            clientKey: `template-${template.id}-${index}-${Date.now()}`,
            category: component.category,
            title: component.title,
            maxScore: formatScore(component.max_score),
        })));
        showSuccessAlert('นำต้นแบบมาใช้แล้ว กรุณาตรวจสอบและกดบันทึกโครงสร้าง');
    };

    const toggleTemplateSubject = (subjectCode: string) => {
        setTemplateSubjectCodes((current) => current.includes(subjectCode)
            ? current.filter((code) => code !== subjectCode)
            : [...current, subjectCode]);
    };

    const changeSubject = (value: string) => {
        setScoresDirty(false);
        setStudentValues({});
        setSelected(value);
        setGroup('');
        setTab('scores');
    };

    const reloadWorkspace = () => {
        setScoresDirty(false);
        setStudentValues({});
        void workspace.refetch();
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
    const studentCategoryTotal = (studentCode: string, category: ScoreComponent['category']): number => (scorebook?.components ?? [])
        .filter((component) => component.category === category)
        .reduce((sum, component) => {
            const raw = studentValues[studentCode]?.scores[component.id] ?? '';
            return sum + (raw === '' ? 0 : Number(raw) || 0);
        }, 0);

    const exportScores = () => {
        if (!scorebook || !selectedSubject) return;
        downloadExcel(`คะแนน-${selectedSubject.code}-${data?.selected_term ?? ''}`, [
            {
                name: 'คะแนนรายคน',
                columns: ['ลำดับ', 'รหัสนักศึกษา', 'ชื่อ-นามสกุล', 'กลุ่มเรียน', ...scorebook.components.map((component) => `${component.title} (เต็ม ${formatScore(component.max_score)})`), `คะแนนเก็บ (${courseworkWeight})`, `สอบปลายภาค (${finalExamWeight})`, 'รวม (100)', 'หมายเหตุ'],
                rows: (data?.students ?? []).map((student, index) => [
                    index + 1,
                    student.student_code,
                    student.full_name,
                    student.group_name || student.group_code,
                    ...scorebook.components.map((component) => {
                        const raw = studentValues[student.student_code]?.scores[component.id] ?? '';
                        return raw === '' ? null : Number(raw);
                    }),
                    studentCategoryTotal(student.student_code, 'coursework'),
                    studentCategoryTotal(student.student_code, 'final_exam'),
                    studentTotal(student.student_code),
                    studentValues[student.student_code]?.note ?? '',
                ]),
            },
            {
                name: 'โครงสร้างคะแนน',
                columns: ['อัตราส่วน', 'ประเภท', 'รายการ', 'คะแนนเต็ม'],
                rows: scorebook.components.map((component) => [scoreRatio, component.category === 'coursework' ? 'คะแนนเก็บ' : 'สอบปลายภาค', component.title, component.max_score]),
            },
        ]);
    };

    const mutationError = createScorebook.error ?? saveStructure.error ?? saveScores.error ?? createTemplate.error ?? deleteTemplate.error ?? applyTemplateToAllSubjects.error;
    const invalidScores = (data?.students ?? []).flatMap((student) => (scorebook?.components ?? []).filter((component) => {
        const raw = studentValues[student.student_code]?.scores[component.id] ?? '';
        return raw !== '' && Number(raw) > component.max_score;
    }).map((component) => `${student.full_name}: ${component.title}`));

    return <div>
        <PageHeader category="learning" title="คะแนน" description="กำหนดสัดส่วนคะแนนเก็บและคะแนนสอบปลายภาค แล้วบันทึกคะแนนนักศึกษา" icon={NotePencil} actions={scorebook ? <Button type="button" appearance="outline" icon={<FileXls size={18} weight="bold" />} onClick={exportScores}>ส่งออก Excel</Button> : undefined} />

        <section className="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
            <div className="grid gap-5 p-5 lg:grid-cols-[minmax(0,1.35fr)_repeat(3,minmax(140px,0.55fr))] lg:items-end">
                <Field label="รายวิชาที่ลงทะเบียน" required>
                    <Select value={selected} onChange={(_, option) => changeSubject(option.value)} size="large" disabled={workspace.isPending || (data?.subjects.length ?? 0) === 0}>
                        {(data?.subjects.length ?? 0) === 0 && <option value="">ไม่พบรายวิชาที่ลงทะเบียน</option>}
                        {(data?.subjects ?? []).map((subject) => <option key={subjectKey(subject)} value={subjectKey(subject)}>{subject.code} {subject.name}</option>)}
                    </Select>
                </Field>
                <Field label="ภาคเรียน">
                    <Select value={term} onChange={(_, option) => { setScoresDirty(false); setStudentValues({}); setTerm(option.value); setSelected(''); setGroup(''); }} size="large">
                        {(data?.terms ?? []).map((item) => <option key={item} value={item}>{item}</option>)}
                    </Select>
                </Field>
                <Field label="กลุ่มเรียน">
                    <Select value={group} onChange={(_, option) => { setScoresDirty(false); setStudentValues({}); setGroup(option.value); }} size="large" disabled={!selectedSubject}>
                        <option value="">ทุกกลุ่มในวิชา</option>
                        {groupOptions.map((item) => <option key={item.code || item.name} value={item.code || item.name}>{item.name || item.code}</option>)}
                    </Select>
                </Field>
                <Button type="button" appearance="outline" size="large" icon={<ArrowsClockwise size={18} weight="bold" />} onClick={reloadWorkspace} disabled={workspace.isFetching}>โหลดข้อมูลใหม่</Button>
            </div>
            {selectedSubject && <div className="grid border-t border-slate-100 bg-slate-50/70 sm:grid-cols-3">
                <div className="p-4 sm:border-r sm:border-slate-200"><p className="text-xs font-bold text-slate-500">ระดับ</p><p className="mt-1 font-black text-slate-900">{selectedSubject.level_label}</p></div>
                <div className="p-4 sm:border-r sm:border-slate-200"><p className="text-xs font-bold text-slate-500">จำนวนนักศึกษา</p><p className="mt-1 font-black text-slate-900">{data?.students.length ?? selectedSubject.student_count} คน</p></div>
                <div className="p-4"><p className="text-xs font-bold text-slate-500">คะแนนเต็มรวม</p><p className={`mt-1 font-black ${componentTotal > 100 ? 'text-rose-700' : 'text-brand-800'}`}>{formatScore(componentTotal)} คะแนน</p></div>
            </div>}
        </section>

        {data && allSubjectTemplates.length > 0 && <section className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50/70 p-5 shadow-sm shadow-emerald-100/70">
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.75fr)_auto] lg:items-end">
                <div>
                    <p className="text-lg font-black text-emerald-950">สร้างสมุดคะแนนทุกรายวิชาในครั้งเดียว</p>
                    <p className="mt-1 text-sm leading-6 text-emerald-800">เลือกต้นแบบสำหรับทุกรายวิชา ระบบจะสร้างสมุดคะแนนให้ทุกวิชาที่ครูเข้าถึงได้ในภาคเรียน {data.selected_term ?? term} และข้ามวิชาที่มีสมุดคะแนนอยู่แล้ว</p>
                </div>
                <Field label="ต้นแบบสำหรับทุกรายวิชา">
                    <Select value={bulkTemplateId} onChange={(_, option) => setBulkTemplateId(option.value)} size="large" disabled={readOnly || applyTemplateToAllSubjects.isPending}>
                        <option value="">เลือกต้นแบบ</option>
                        {allSubjectTemplates.map((template) => <option key={template.id} value={template.id}>{template.name} ({template.score_ratio})</option>)}
                    </Select>
                </Field>
                <Button type="button" appearance="primary" size="large" icon={<BookOpenText size={18} weight="bold" />} disabled={!bulkTemplate || readOnly || applyTemplateToAllSubjects.isPending || !data.selected_term} onClick={() => {
                    if (bulkTemplate && window.confirm(`ใช้ต้นแบบ “${bulkTemplate.name}” สร้างสมุดคะแนนให้ทุกรายวิชาในภาคเรียน ${data.selected_term ?? term}?\n\nวิชาที่มีสมุดคะแนนอยู่แล้วจะไม่ถูกเปลี่ยนแปลง`)) applyTemplateToAllSubjects.mutate();
                }}>{applyTemplateToAllSubjects.isPending ? 'กำลังสร้างสมุดคะแนน' : 'ใช้กับทุกรายวิชา'}</Button>
            </div>
        </section>}

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

            {tab === 'structure' && <Panel title="โครงสร้างคะแนน" description="เลือกสัดส่วนคะแนนเก็บต่อคะแนนสอบปลายภาค คะแนนรวมต้องครบ 100 คะแนน" action={<div className={`rounded-xl px-3 py-2 text-sm font-black ${canSaveStructure ? 'bg-brand-50 text-brand-800' : 'bg-rose-50 text-rose-800'}`}>รวม {formatScore(componentTotal)} / 100</div>}>
                <div className="mb-5 rounded-xl border border-violet-200 bg-violet-50/60 p-4">
                    <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto_auto] lg:items-end">
                        <Field label="เลือกต้นแบบโครงสร้างคะแนน">
                            <Select value={selectedTemplateId} onChange={(_, option) => setSelectedTemplateId(option.value)} size="large" disabled={templates.isPending || applicableTemplates.length === 0}>
                                <option value="">{applicableTemplates.length === 0 ? 'ยังไม่มีต้นแบบสำหรับรายวิชานี้' : 'เลือกต้นแบบ'}</option>
                                {applicableTemplates.map((template) => <option key={template.id} value={template.id}>{template.name} ({template.score_ratio}) · {template.applies_to_all ? 'ทุกรายวิชา' : 'เฉพาะบางวิชา'}</option>)}
                            </Select>
                        </Field>
                        <Button type="button" appearance="primary" onClick={applyTemplate} disabled={!selectedTemplate || !canEdit}>ใช้ต้นแบบ</Button>
                        <Button type="button" appearance="outline" onClick={() => { if (selectedTemplate && window.confirm(`ยืนยันลบต้นแบบ “${selectedTemplate.name}”?`)) deleteTemplate.mutate(selectedTemplate.id); }} disabled={!selectedTemplate?.can_delete || deleteTemplate.isPending}>ลบต้นแบบ</Button>
                    </div>
                    <div className="mt-4 border-t border-violet-200 pt-4">
                        <p className="font-black text-violet-950">สร้างต้นแบบจากโครงสร้างที่กำลังแก้ไข</p>
                        <div className="mt-3 grid gap-3 lg:grid-cols-[minmax(220px,0.8fr)_minmax(0,1.2fr)_auto] lg:items-end">
                            <Field label="ชื่อต้นแบบ"><Input value={templateName} onChange={(_, value) => setTemplateName(value.value)} maxLength={120} placeholder="เช่น แบบมาตรฐาน 70:30" size="large" disabled={!canEdit} /></Field>
                            <div>
                                <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-violet-200 bg-white px-3 text-sm font-bold text-violet-950">
                                    <input type="checkbox" checked={templateAppliesToAll} onChange={(event) => { setTemplateAppliesToAll(event.target.checked); if (event.target.checked) setTemplateSubjectCodes([]); }} disabled={!canEdit} />
                                    ใช้ได้กับทุกรายวิชา
                                </label>
                                {!templateAppliesToAll && <div className="mt-2 flex max-h-32 flex-wrap gap-2 overflow-y-auto rounded-lg border border-violet-200 bg-white p-2">
                                    {availableSubjects.map((subject) => <label key={subject.code} className="inline-flex cursor-pointer items-center gap-2 rounded-md bg-slate-50 px-2 py-1 text-xs font-bold text-slate-700">
                                        <input type="checkbox" checked={templateSubjectCodes.includes(subject.code)} onChange={() => toggleTemplateSubject(subject.code)} disabled={!canEdit} />
                                        {subject.code} {subject.name}
                                    </label>)}
                                </div>}
                            </div>
                            <Button type="button" appearance="outline" icon={<FloppyDisk size={18} weight="bold" />} onClick={() => createTemplate.mutate()} disabled={!canCreateTemplate || createTemplate.isPending}>บันทึกเป็นต้นแบบ</Button>
                        </div>
                        {!templateAppliesToAll && templateSubjectCodes.length === 0 && <p className="mt-2 text-xs font-bold text-amber-800">กรุณาเลือกอย่างน้อย 1 รายวิชา</p>}
                    </div>
                </div>
                <div className="mb-5 grid gap-3 rounded-xl border border-brand-200 bg-brand-50 p-4 sm:grid-cols-[minmax(0,1fr)_220px] sm:items-end">
                    <div><p className="font-black text-brand-950">สัดส่วนคะแนนเก็บ : คะแนนสอบปลายภาค</p><p className="mt-1 text-sm text-brand-800">คะแนนเก็บรวม {courseworkWeight} คะแนน และสอบปลายภาค {finalExamWeight} คะแนน</p></div>
                    <Field label="เลือกโครงสร้างคะแนน"><Select value={scoreRatio} onChange={(_, option) => applyScoreRatio(option.value as ScoreRatio)} size="large" disabled={!canEdit}><option value="60:40">60 : 40</option><option value="70:30">70 : 30</option><option value="80:20">80 : 20</option></Select></Field>
                </div>
                {scorebook?.score_ratio === null && <p role="status" className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-900">สมุดคะแนนเดิมยังไม่มีสัดส่วน กรุณาเลือก 60:40, 70:30 หรือ 80:20 แล้วตรวจคะแนนเต็มก่อนบันทึก</p>}
                <div className="space-y-3">
                    {components.map((component, index) => <div key={component.clientKey} className={`grid gap-3 rounded-xl border p-3 sm:grid-cols-[120px_minmax(0,1fr)_150px_44px] sm:items-end ${component.category === 'final_exam' ? 'border-amber-200 bg-amber-50/50' : 'border-slate-200'}`}>
                        <span className={`inline-flex h-10 items-center justify-center rounded-lg px-3 text-xs font-black ${component.category === 'final_exam' ? 'bg-amber-100 text-amber-900' : 'bg-sky-100 text-sky-900'}`}>{component.category === 'final_exam' ? 'สอบปลายภาค' : `คะแนนเก็บ ${index + 1}`}</span>
                        <Field label="ชื่อช่องคะแนน"><Input value={component.title} onChange={(_, value) => setComponents((items) => items.map((item) => item.clientKey === component.clientKey ? { ...item, title: value.value } : item))} size="large" disabled={!canEdit} /></Field>
                        <Field label="คะแนนเต็ม"><Input type="number" min="0.01" max="100" step="0.01" value={component.maxScore} onChange={(_, value) => setComponents((items) => items.map((item) => item.clientKey === component.clientKey ? { ...item, maxScore: value.value } : item))} size="large" disabled={!canEdit} /></Field>
                        <button type="button" onClick={() => setComponents((items) => items.filter((item) => item.clientKey !== component.clientKey))} className="grid size-10 place-items-center rounded-lg border border-rose-200 text-rose-700 hover:bg-rose-50 disabled:opacity-40" disabled={!canEdit || component.category === 'final_exam' || components.filter((item) => item.category === 'coursework').length === 1} aria-label={`ลบช่อง ${component.title}`}><Trash size={18} /></button>
                    </div>)}
                </div>
                {!canSaveStructure && <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">คะแนนเก็บรวมต้องเท่ากับ {courseworkWeight} คะแนน และสอบปลายภาครวมต้องเท่ากับ {finalExamWeight} คะแนน</p>}
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <Button type="button" appearance="outline" icon={<Plus size={17} weight="bold" />} disabled={!canEdit} onClick={() => setComponents((items) => {
                        const finalIndex = items.findIndex((item) => item.category === 'final_exam');
                        const newItem: ComponentDraft = { clientKey: `new-${Date.now()}`, category: 'coursework', title: `คะแนนเก็บครั้งที่ ${items.filter((item) => item.category === 'coursework').length + 1}`, maxScore: '10' };
                        return finalIndex < 0 ? [...items, newItem] : [...items.slice(0, finalIndex), newItem, ...items.slice(finalIndex)];
                    })}>เพิ่มช่องคะแนนเก็บ</Button>
                    <Button type="button" appearance="primary" icon={<FloppyDisk size={18} weight="bold" />} disabled={!canEdit || !canSaveStructure || createScorebook.isPending || saveStructure.isPending} onClick={() => scorebook ? saveStructure.mutate() : createScorebook.mutate()}>{scorebook ? 'บันทึกโครงสร้าง' : 'สร้างสมุดคะแนน'}</Button>
                </div>
            </Panel>}

            {tab === 'scores' && !scorebook && <Panel title="ยังไม่มีสมุดคะแนนสำหรับวิชานี้" description={allSubjectTemplates.length > 0 ? 'เลือกต้นแบบในส่วนสร้างสมุดคะแนนทุกรายวิชาด้านบนได้ทันที โดยไม่ต้องตรวจทีละวิชา' : 'สร้างต้นแบบสำหรับทุกรายวิชาในแท็บโครงสร้างคะแนนก่อนใช้งานแบบอัตโนมัติ'}>
                <div className="flex min-h-48 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                    <BookOpenText size={38} className="text-brand-700" />
                    <p className="max-w-lg text-sm leading-6 text-slate-600">{allSubjectTemplates.length > 0 ? 'เลือกต้นแบบด้านบนแล้วกด “ใช้กับทุกรายวิชา” ระบบจะเตรียมสมุดคะแนนทั้งหมดให้โดยอัตโนมัติ' : 'ไปที่โครงสร้างคะแนนเพื่อสร้างต้นแบบแรก จากนั้นจะใช้สร้างสมุดคะแนนทุกวิชาได้ในครั้งเดียว'}</p>
                    {allSubjectTemplates.length === 0 && <Button type="button" appearance="primary" onClick={() => setTab('structure')}>สร้างต้นแบบโครงสร้างคะแนน</Button>}
                </div>
            </Panel>}

            {tab === 'scores' && scorebook && <Panel title="บันทึกคะแนนรายคน" description={`${selectedSubject.code} ${selectedSubject.name}, ${data.students.length} คน`} action={<div className="inline-flex items-center gap-2 text-sm font-bold text-slate-600"><Users size={18} /> {data.students.length} คน</div>}>
                {data.students.length === 0 ? <div className="grid min-h-48 place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-center text-sm text-slate-600">ไม่พบนักศึกษาที่ลงทะเบียนวิชานี้ในกลุ่มที่เลือก</div> : <div className="overflow-x-auto rounded-xl border border-slate-200">
                    <table className="w-full border-collapse text-sm" style={{ minWidth: `${760 + (scorebook.components.length * 155)}px` }}>
                        <thead className="bg-slate-50 text-slate-700">
                            <tr>
                                <th className="w-14 border-b border-r border-slate-200 px-3 py-3 text-center">ลำดับ</th>
                                <th className="min-w-60 border-b border-r border-slate-200 px-4 py-3 text-left">ชื่อและรหัสนักศึกษา</th>
                                {scorebook.components.map((component) => <th key={component.id} className={`min-w-36 border-b border-r border-slate-200 px-3 py-3 text-center ${component.category === 'final_exam' ? 'bg-amber-50' : ''}`}><span className="block font-black text-slate-900">{component.title}</span><span className="mt-1 block text-xs font-medium text-slate-500">{component.category === 'final_exam' ? 'สอบปลายภาค' : 'คะแนนเก็บ'} · เต็ม {formatScore(component.max_score)}</span></th>)}
                                <th className="w-24 border-b border-r border-slate-200 bg-sky-50 px-3 py-3 text-center">คะแนนเก็บ</th>
                                <th className="w-24 border-b border-r border-slate-200 bg-amber-50 px-3 py-3 text-center">ปลายภาค</th>
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
                                    <td className="border-b border-r border-slate-200 bg-sky-50/50 px-3 py-3 text-center font-mono font-black text-sky-800">{formatScore(studentCategoryTotal(student.student_code, 'coursework'))}</td>
                                    <td className="border-b border-r border-slate-200 bg-amber-50/50 px-3 py-3 text-center font-mono font-black text-amber-800">{formatScore(studentCategoryTotal(student.student_code, 'final_exam'))}</td>
                                    <td className={`border-b border-r border-slate-200 px-3 py-3 text-center font-mono text-base font-black ${total > scorebook.maximum_score ? 'text-rose-700' : 'text-brand-800'}`}>{formatScore(total)}</td>
                                    <td className="border-b border-slate-200 p-2"><input aria-label={`หมายเหตุของ ${student.full_name}`} value={studentValues[student.student_code]?.note ?? ''} onChange={(event) => updateNote(student.student_code, event.target.value)} maxLength={1000} placeholder="เพิ่มหมายเหตุ" disabled={!canEdit} className="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none placeholder:text-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-100 disabled:bg-slate-100 disabled:text-slate-500" /></td>
                                </tr>;
                            })}
                        </tbody>
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
    courses: Array<{ id: string; subject_code: string; subject_name: string; score_ratio: string | null; assignment_score: number | null; exam_score: number | null; total_score: number | null; maximum_score: number; status: string }>;
    disclaimer: string;
};

function StudentScoreSummary() {
    const scores = useQuery({
        queryKey: ['learning', 'scores', 'student'],
        queryFn: ({ signal }) => getFeatureDataWithDemo<StudentScorePayload>('/api/v1/learning/scores', { term: null, summary: { score: 0, maximum_score: 0, items: 0 }, courses: [], disclaimer: '' }, signal),
    });
    if (scores.isPending) return <QuerySkeleton rows={6} />;
    if (scores.isError) return <QueryError onRetry={() => scores.refetch()} />;
    const exportStudentScores = () => downloadExcel('คะแนนของฉัน', [{
        name: 'คะแนน',
        columns: ['รายวิชา', 'รหัสวิชา', 'สัดส่วน', 'คะแนนเก็บ', 'สอบปลายภาค', 'รวม', 'คะแนนเต็ม', 'สถานะ'],
        rows: scores.data.data.courses.map((course) => [course.subject_name, course.subject_code, course.score_ratio ?? 'โครงสร้างเดิม', course.assignment_score, course.exam_score, course.total_score, course.maximum_score, course.status === 'studying' ? 'กำลังเรียน' : course.status]),
    }]);

    return <div>
        <PageHeader category="learning" title="คะแนนของฉัน" description="คะแนนเก็บ คะแนนสอบปลายภาค และคะแนนรวมที่ครูบันทึกในระบบ" icon={ChartBar} actions={scores.data.data.courses.length > 0 ? <Button type="button" appearance="outline" icon={<FileXls size={18} weight="bold" />} onClick={exportStudentScores}>ส่งออก Excel</Button> : undefined} />
        <Panel title="รายการคะแนน" description={scores.data.data.disclaimer}>
            {scores.data.data.courses.length === 0 ? <div className="grid min-h-48 place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-center text-sm text-slate-600">ครูยังไม่ได้บันทึกคะแนน</div> : <div className="overflow-x-auto rounded-xl border border-slate-200">
                <table className="min-w-[900px] w-full text-sm"><thead className="bg-slate-50"><tr><th className="px-4 py-3 text-left">รายวิชา</th><th className="px-4 py-3 text-left">รหัสวิชา</th><th className="px-4 py-3 text-center">สัดส่วน</th><th className="px-4 py-3 text-center">คะแนนเก็บ</th><th className="px-4 py-3 text-center">สอบปลายภาค</th><th className="px-4 py-3 text-center">คะแนนรวม</th><th className="px-4 py-3 text-center">สถานะ</th></tr></thead><tbody>{scores.data.data.courses.map((course) => <tr key={course.id} className="border-t border-slate-200"><td className="px-4 py-3 font-bold text-slate-950">{course.subject_name}</td><td className="px-4 py-3 font-mono text-slate-600">{course.subject_code}</td><td className="px-4 py-3 text-center font-mono text-slate-700">{course.score_ratio ?? 'เดิม'}</td><td className="px-4 py-3 text-center font-mono font-bold text-sky-800">{course.assignment_score == null ? '-' : formatScore(course.assignment_score)}</td><td className="px-4 py-3 text-center font-mono font-bold text-amber-800">{course.exam_score == null ? '-' : formatScore(course.exam_score)}</td><td className="px-4 py-3 text-center font-mono font-black text-brand-800">{course.total_score == null ? '-' : formatScore(course.total_score)}</td><td className="px-4 py-3 text-center text-slate-600">{course.status === 'studying' ? 'กำลังเรียน' : course.status}</td></tr>)}</tbody></table>
            </div>}
        </Panel>
    </div>;
}
