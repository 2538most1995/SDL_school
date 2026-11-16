import { CenteredDataLoading } from '../../components/DataLoading';
import { Alert, Dialog, DialogActions, DialogContent, DialogTitle, TextField } from '@mui/material';
import { Books, Plus, PencilSimple, Trash, ArrowSquareOut, UploadSimple, CheckCircle, Play, BookOpenText, NotePencil, Lightning, ArrowRight, ArrowLeft, GraduationCap, RocketLaunch, Student, SquaresFour, Clock, MagnifyingGlass, ClipboardText, Files, CaretRight, Archive, DotsSixVertical, ArrowUp, ArrowDown } from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AssignmentWorkspacePage } from './AssignmentWorkspacePage';
import { ClassroomResources } from './classroom/ClassroomResources';
import { classroomUnitParams, classroomTabParams, classroomKindParams, classroomAssignmentParams, classroomContentPage, classroomTaskItemParams, type ClassroomTab } from './classroom/navigation';
import { previewContentOrder, type ContentMove } from './classroom/contentOrder';
import { ClassroomLessonLibrary } from './classroom/ClassroomLessonLibrary';
import { ClassroomUnits } from './classroom/ClassroomUnits';
import { ClassroomTasks, TaskCompletion, type TaskItem, type ClassroomTask } from './classroom/ClassroomTasks';
import { Button } from '../../components/MaterialUI';
import { Panel } from '../../components/Panel';
import { EmptyState, QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { ApiError } from '../../lib/api';
import { getFeatureBlob, getFeatureData, sendFeatureData } from '../api';
import { kindLabels, questionLabels, safeClassroomUrl, youtubeEmbed, type Activity, type ActivityKind, type PageInfo, type Question, type QuestionType, type Subject, type Submission, type Workspace } from './classroom/types';
import { QuizImportPanel, QuizTemplateActions } from './classroom/QuizImportPanel';
import './classroom/classroom.css';
import './classroom/compact.css';
import { useSelectedDistrictId } from '../../lib/districtSelection';

const base = '/api/v1/learning/classroom';
const errorText = (error: unknown) => error instanceof Error ? error.message : 'ไม่สามารถดำเนินการได้';
const dateLabel = (date: string | null) => date ? new Date(date).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : 'ไม่กำหนดวันส่ง';
const isAssessment = (type: ActivityKind) => type === 'quiz' || type === 'worksheet';
const kindIcons = { video: Play, knowledge: BookOpenText, worksheet: NotePencil, quiz: Lightning };
const levelChoices = [{ id: 1, label: 'ประถม', detail: 'ประถมศึกษา', Icon: Student }, { id: 2, label: 'ม.ต้น', detail: 'มัธยมศึกษาตอนต้น', Icon: GraduationCap }, { id: 3, label: 'ม.ปลาย', detail: 'มัธยมศึกษาตอนปลาย', Icon: RocketLaunch }];

function PrivateImage({ url }: { url: string }) {
    const [src, setSrc] = useState('');
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        let active = true;
        let objectUrl = '';
        setSrc(''); setFailed(false);
        getFeatureBlob(url).then(blob => {
            if (!active) return;
            objectUrl = URL.createObjectURL(blob); setSrc(objectUrl);
        }).catch(() => { if (active) setFailed(true); });
        return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl); };
    }, [url]);
    return failed ? <Alert severity="warning">โหลดภาพไม่ได้ กรุณารีเฟรชหน้าอีกครั้ง</Alert> : src ? <img src={src} alt="ภาพประกอบโจทย์" className="max-h-80 max-w-full rounded-xl object-contain" /> : <CenteredDataLoading label="กำลังโหลดภาพ" />;
}

function Pager({ page, change }: { page: PageInfo | null; change: (page: number) => void }) {
    return page && page.last_page > 1 ? <nav aria-label="แบ่งหน้า" className="mt-4 flex flex-wrap items-center justify-between gap-2">
        <Button appearance="outline" disabled={page.current_page <= 1} onClick={() => change(page.current_page - 1)}>ก่อนหน้า</Button>
        <span className="text-sm">หน้า {page.current_page} / {page.last_page} · {page.total} รายการ</span>
        <Button appearance="outline" disabled={page.current_page >= page.last_page} onClick={() => change(page.current_page + 1)}>ถัดไป</Button>
    </nav> : null;
}

export function OnlineClassroomPage() {
    const { role } = useDemoRole();
    const district = useSelectedDistrictId() ?? '';
    return <Classroom key={`${role}:${district}`} role={role} district={district} />;
}

function Classroom({ role, district }: { role: string; district: string }) {
    const client = useQueryClient();
    const manage = role !== 'student';
    const [routeParams, setRouteParams] = useSearchParams();
    const initialSubject = routeParams.get('subject_code') ?? '';
    const initialLevel = routeParams.get('education_level') ?? '';
    const unitId = /^(legacy|[1-9]\d*)$/.test(routeParams.get('unit_id') ?? '') ? routeParams.get('unit_id')! : '';
    const initialAssignmentId = /^[1-9]\d{0,18}$/.test(routeParams.get('assignment_id') ?? '') ? routeParams.get('assignment_id')! : '';
    const course = /^[123]$/.test(initialLevel) && initialSubject ? `${initialLevel}|${initialSubject}` : '';
    const setCourse = (value: string, replace = false) => {
        const next = new URLSearchParams(routeParams);
        if (value) { for (const key of ['activity_id', 'unit_id', 'assignment_id', 'task_id', 'section', 'mode', 'materials', 'tab']) next.delete(key); const [selectedLevel, code] = value.split('|'); next.set('education_level', selectedLevel); next.set('subject_code', code); }
        else { for (const key of ['education_level', 'subject_code', 'assignment_id', 'task_id', 'activity_id', 'unit_id', 'section', 'mode', 'materials', 'tab']) next.delete(key); }
        setRouteParams(next, { replace });
    };
    const materials = routeParams.get('materials') === 'all';
    const tab: ClassroomTab = materials ? 'library' : manage && routeParams.get('tab') === 'templates' ? 'templates' : routeParams.get('tab') === 'tasks' ? 'tasks' : routeParams.get('tab') === 'documents' ? 'documents' : 'lessons';
    const worksheetMode = routeParams.get('mode') === 'files' ? 'files' : 'online';
    const [childBusy, setChildBusy] = useState(false);
    const routedAssignment = useQuery({
        queryKey: ['classroom-assignment-link', role, district, initialAssignmentId],
        enabled: Boolean(initialAssignmentId),
        queryFn: ({ signal }) => getFeatureData<{ selected_assignment: { subject_code: string; education_level: number | null; unit_id?: string | null } | null }>(`/api/v1/learning/assignments?assignment_id=${initialAssignmentId}`, signal),
    });
    const resolvedAssignmentLink = useRef('');
    const [level, setLevel] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [coursePage, setCoursePage] = useState(1);
    const [toolsOpen, setToolsOpen] = useState(false);
    const [sorting, setSorting] = useState(false);
    const [pendingMoves, setPendingMoves] = useState<ContentMove[]>([]);
    const [draggedId, setDraggedId] = useState('');
    useEffect(() => { setCoursePage(1); }, [search, level]);
    const activityId = routeParams.get('activity_id') ?? '';
    useEffect(() => { setActivityPage(1); setSubmissionPage(1); }, [initialSubject, initialLevel, initialAssignmentId, unitId, routeParams.get('section'), routeParams.get('mode')]);
    useEffect(() => { setSubmissionPage(1); }, [activityId]);
    const [activityPage, setActivityPage] = useState(1);
    const [submissionPage, setSubmissionPage] = useState(1);
    const [editor, setEditor] = useState<Activity | 'new' | null>(null);
    const [initialKind, setInitialKind] = useState<ActivityKind>('knowledge');
    const [review, setReview] = useState<Submission | null>(null);
    const [deleteId, setDeleteId] = useState('');
    const [activityDirty, setDirty] = useState(false);
    const [resourceDirty, setResourceDirty] = useState(false);
    const [assignmentDirty, setAssignmentDirty] = useState(false);
    const [taskDirty, setTaskDirty] = useState(false);
    const dirty = activityDirty || resourceDirty || assignmentDirty || taskDirty || pendingMoves.length > 0;
    const kind: ActivityKind | 'all' = ['video', 'knowledge', 'worksheet', 'quiz'].includes(routeParams.get('section') ?? '') ? routeParams.get('section') as ActivityKind : 'all';
    const detailRef = useRef<HTMLDivElement>(null);
    const params = new URLSearchParams({ activity_page: String(classroomContentPage(activityPage).serverPage), page: String(submissionPage) });
    if (course) { const [selectedLevel, code] = course.split('|'); params.set('subject_code', code); params.set('education_level', selectedLevel); }
    if (activityId) params.set('activity_id', activityId);
    if (unitId) params.set('unit_id', unitId);
    if (kind !== 'all') params.set('activity_type', kind);
    const catalogOnly = !activityId && (!course || !unitId);
    if (catalogOnly) params.set('catalog_only', '1');
    else if (!activityId) params.set('summary_only', '1');
    const query = useQuery({ queryKey: ['online-classroom', role, district, course, unitId, activityId, kind, catalogOnly, classroomContentPage(activityPage).serverPage, submissionPage],
        queryFn: ({ signal }) => getFeatureData<Workspace>(`${base}?${params}`, signal), staleTime: 15_000, placeholderData: previous => previous });
    const data = query.data?.data;
    useEffect(() => {
        if (resolvedAssignmentLink.current === initialAssignmentId || !initialAssignmentId || !data?.subjects.length) return;
        const assignment = routedAssignment.data?.data.selected_assignment;
        if (!assignment) return;
        const match = data.subjects.find(item => item.code === assignment.subject_code && (!assignment.education_level || item.level === assignment.education_level));
        if (match) {
            resolvedAssignmentLink.current = initialAssignmentId;
            const next = new URLSearchParams(routeParams); next.set('subject_code', match.code); next.set('education_level', String(match.level)); next.set('unit_id', assignment.unit_id ?? 'legacy'); setRouteParams(next, { replace: true });
            setLevel(match.level);
        }
    }, [data?.subjects, routedAssignment.data, initialAssignmentId]);
    const readOnly = Boolean(query.data?.meta?.read_only);
    const busy = query.isPlaceholderData;
    const refresh = async () => { await Promise.all([client.invalidateQueries({ queryKey: ['online-classroom'] }), client.invalidateQueries({ queryKey: ['classroom-tasks'] }), client.invalidateQueries({ queryKey: ['classroom-units'] })]); };
    const save = useMutation({ mutationFn: ({ id, values }: { id?: string; values: unknown }) => sendFeatureData<{ id: string }>(`${base}/activities${id ? `/${id}` : ''}`, 'POST', { ...(values as Record<string, unknown>), unit_id: unitId || 'legacy' }),
        onSuccess: async (result, variables) => { setEditor(null); setDirty(false); setActivityPage(1); const next = classroomKindParams(routeParams.toString(), 'all'); if (!variables.id) next.set('activity_id', result.data.id); setRouteParams(next, { replace: true }); await refresh(); } });
    const reorder = useMutation({ mutationFn: () => sendFeatureData(`${base}/activities/order`, 'POST', { moves: pendingMoves }), onSuccess: async () => { await refresh(); setPendingMoves([]); setSorting(false); setActivityPage(1); } });
    const queueMove = (move: ContentMove) => { if (!readOnly && !reorder.isPending && pendingMoves.length < 500) setPendingMoves(previous => [...previous, move]); };
    const finishSorting = () => { if (pendingMoves.length) reorder.mutate(); else setSorting(false); };

    const status = useMutation({ mutationFn: (activity: Activity) => sendFeatureData(`${base}/activities/${activity.id}`, 'POST', { status: activity.status === 'published' ? 'draft' : 'published' }), onSuccess: refresh });
    const remove = useMutation({ mutationFn: () => sendFeatureData(`${base}/activities/${deleteId}`, 'DELETE'), onSuccess: async () => { setDeleteId(''); selectActivity('', true); setActivityPage(1); await refresh(); } });
    useEffect(() => {
        if (!dirty) return;
        const listener = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', listener);
        return () => window.removeEventListener('beforeunload', listener);
    }, [dirty]);
    useEffect(() => {
        if (activityId && data?.selected_activity?.id === activityId && !busy) {
            detailRef.current?.focus({ preventScroll: true });
            detailRef.current?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
        }
    }, [activityId, data?.selected_activity?.id, busy]);
    const leave = (action: () => void) => { if (childBusy || save.isPending || remove.isPending || reorder.isPending) return; if (!dirty || window.confirm('มีข้อมูลที่ยังไม่ได้บันทึก ต้องการออกจากกิจกรรมนี้หรือไม่?')) { setDirty(false); setResourceDirty(false); setAssignmentDirty(false); setTaskDirty(false); setPendingMoves([]); setSorting(false); setDraggedId(''); action(); } };
    const selectActivity = (id: string, replace = false) => { const next = new URLSearchParams(routeParams); next.delete('tab'); next.delete('assignment_id'); if (kind !== 'all') next.set('section', kind); else next.delete('section'); if (id) next.set('activity_id', id); else next.delete('activity_id'); setRouteParams(next, { replace }); };
    const resetActivity = () => { selectActivity('', true); setActivityPage(1); setSubmissionPage(1); };
    const add = (type: ActivityKind) => leave(() => { save.reset(); setInitialKind(type); setEditor('new'); });
    useEffect(() => {
        if (course || !initialSubject || !data?.subjects.length || initialAssignmentId) return;
        const match = data.subjects.find(item => item.code === initialSubject);
        if (match) setCourse(`${match.level}|${match.code}`, true);
    }, [course, initialSubject, data?.subjects, initialAssignmentId]);
    useEffect(() => {
        if (unitId || !activityId || !data?.selected_activity || data.selected_activity.id !== activityId) return;
        const next = new URLSearchParams(routeParams); next.set('unit_id', data.selected_activity.unit_id ?? 'legacy'); setRouteParams(next, { replace: true });
    }, [unitId, activityId, data?.selected_activity]);
    const openUnit = (id: string) => { const next = classroomUnitParams(routeParams.toString(), id); setActivityPage(1); setSubmissionPage(1); setRouteParams(next); };
    const openTaskItem = (item: TaskItem, task: ClassroomTask) => leave(() => setRouteParams(classroomTaskItemParams(item, task)));
    if (query.isPending || (initialAssignmentId && routedAssignment.isPending)) return <QuerySkeleton />;
    if (query.isError && !data) return <QueryError message={errorText(query.error)} onRetry={() => void query.refetch()} />;
    if (!data) return null;
    const subject = course && data.selected_subject && `${data.selected_subject.level}|${data.selected_subject.code}` === course ? data.selected_subject : null;
    const selected = data.selected_activity;
    const fileMode = kind === 'worksheet' && worksheetMode === 'files';
    const focusedFile = !materials && tab === 'lessons' && fileMode && Boolean(initialAssignmentId) && Boolean(subject) && !routedAssignment.isError;
    const focused = !materials && tab === 'lessons' && (Boolean(activityId) || focusedFile);
    const focusPending = focused && !focusedFile && (busy || selected?.id !== activityId);
    const selectAssignment = (id: string) => setRouteParams(classroomAssignmentParams(routeParams.toString(), id));
    const changeTab = (value: ClassroomTab) => leave(() => { setActivityPage(1); setSubmissionPage(1); setToolsOpen(false); setRouteParams(classroomTabParams(routeParams.toString(), value)); });
    const changeKind = (value: ActivityKind | 'all', mode: 'online' | 'files' = 'online') => leave(() => { setActivityPage(1); setRouteParams(classroomKindParams(routeParams.toString(), value, mode)); });
    const selectedUnit = data.selected_unit?.id === unitId ? data.selected_unit : null;
    const insideUnit = Boolean(unitId && selectedUnit);
    const contentSubject = subject && selectedUnit?.target_group ? { ...subject, groups: subject.groups.filter(group => group.code === selectedUnit.target_group || group.name === selectedUnit.target_group) } : subject;
    const activeLevel = level ?? subject?.level ?? data.subjects[0]?.level ?? 1;
    const levelSubjects = data.subjects.filter(item => item.level === activeLevel);
    const visibleSubjects = levelSubjects.filter(item => `${item.code} ${item.name}`.toLocaleLowerCase('th').includes(search.trim().toLocaleLowerCase('th')));
    const courseLastPage = Math.max(1, Math.ceil(visibleSubjects.length / 8));
    const visibleCoursePage = Math.min(coursePage, courseLastPage);
    const pagedSubjects = visibleSubjects.slice((visibleCoursePage - 1) * 8, visibleCoursePage * 8);
    return <div className="online-classroom classroom-v2 classroom-v3">
        {course && !materials && <nav className="classroom-trail" aria-label="เส้นทางห้องเรียน">
            <button type="button" disabled={childBusy} onClick={() => leave(() => { setCourse(''); })}><GraduationCap size={17} /> ห้องเรียน</button>
            <CaretRight size={14} aria-hidden="true" />
            <button type="button" onClick={() => leave(() => openUnit(''))}>{subject?.name ?? initialSubject}</button>
            {insideUnit && <><CaretRight size={14} aria-hidden="true" /><button type="button" onClick={() => leave(() => selectActivity(''))}>{selectedUnit?.title}</button></>}
        </nav>}
        {!focused && <header className="classroom-heading">
            <div className="classroom-heading-copy"><span className="classroom-heading-icon"><GraduationCap size={26} weight="duotone" /></span><div><h1>{tab === 'templates' ? 'คลังบทเรียน' : materials ? 'สื่อเพิ่มเติม' : subject?.name ?? (manage ? 'รายวิชาที่ดูแล' : 'ห้องเรียนของฉัน')}</h1><p>{data.term ? `ภาคเรียน ${data.term}` : 'ห้องเรียนออนไลน์'}{subject ? ` · ${subject.code} · ${subject.level_label}` : ` · ${manage ? 'เฉพาะรายวิชาที่รับผิดชอบ' : 'เฉพาะวิชาที่ลงทะเบียน'}`}</p></div></div>
            {manage && insideUnit && tab === 'lessons' && !materials && <button type="button" className="classroom-action classroom-action-primary" aria-expanded={toolsOpen} aria-controls="classroom-add-tools" disabled={readOnly || busy} onClick={() => setToolsOpen(!toolsOpen)}><Plus size={19} /> เพิ่มเนื้อหา</button>}
        </header>}
        {readOnly && <Alert severity="info">ระบบอยู่ในโหมดอ่านอย่างเดียว ผู้ดูแลต้องเปิดการเขียนข้อมูลก่อนสร้างกิจกรรมหรือส่งคำตอบ</Alert>}
        {query.isError && <Alert severity="warning" action={<Button onClick={() => void query.refetch()}>ลองโหลดใหม่</Button>}>โหลดข้อมูลล่าสุดไม่สำเร็จ ข้อมูลที่กำลังกรอกยังอยู่ กรุณาลองโหลดใหม่เมื่อเชื่อมต่อได้</Alert>}
        {status.isError && <Alert severity="error">{errorText(status.error)}</Alert>}
        {reorder.isError && <Alert severity="error">{errorText(reorder.error)}</Alert>}
        {remove.isError && <Alert severity="error">{errorText(remove.error)}</Alert>}
        {routedAssignment.isError && <Alert severity="warning">เปิดงานจากลิงก์เดิมไม่ได้ งานอาจถูกลบหรืออยู่นอกขอบเขตของคุณ กรุณาเลือกวิชาและงานด้านล่าง</Alert>}
        {!focused && <nav className="classroom-tabs" aria-label="ส่วนของห้องเรียน">
            <button type="button" aria-current={tab === 'lessons' ? 'page' : undefined} onClick={() => changeTab('lessons')}><Books size={19} />{course ? 'บทเรียน' : 'รายวิชา'}</button>
            <button type="button" aria-current={tab === 'tasks' ? 'page' : undefined} onClick={() => changeTab('tasks')}><ClipboardText size={19} />{manage ? 'งานที่มอบหมาย' : 'งานที่ต้องส่ง'}</button>
            {course && <button type="button" aria-current={tab === 'documents' ? 'page' : undefined} onClick={() => changeTab('documents')}><Files size={19} />เอกสาร / สื่อ</button>}
            {manage && <button type="button" aria-current={tab === 'templates' ? 'page' : undefined} onClick={() => leave(() => { setRouteParams(new URLSearchParams({ tab: 'templates' })); })}><Archive size={19} />คลังบทเรียน</button>}
            <button type="button" aria-current={tab === 'library' ? 'page' : undefined} onClick={() => changeTab('library')}><BookOpenText size={19} />สื่อเพิ่มเติม</button>
        </nav>}
        {manage && tab === 'templates' && <ClassroomLessonLibrary key={`templates:${role}:${district}:${routeParams.get('source_unit_id') ?? ''}`} subjects={data.subjects} role={role} district={district} readOnly={readOnly} initialUnitId={routeParams.get('source_unit_id') ?? undefined} onBusy={setChildBusy} onRestored={(id, target) => { setRouteParams(new URLSearchParams({ subject_code: target.code, education_level: String(target.level), unit_id: id })); void refresh(); }} />}
        {materials && <ClassroomResources key={`library:${role}:${district}`} subject={null} kind="all" onDirty={setResourceDirty} onBusy={setChildBusy} />}
        {!materials && tab === 'tasks' && (!course || subject) && <ClassroomTasks key={`tasks:${course}:${unitId}:${role}:${district}`} unitId={unitId || undefined} unitTargetGroup={selectedUnit?.target_group} subject={contentSubject ?? undefined} manage={manage} role={role} district={district} readOnly={readOnly || selectedUnit?.status === 'draft'} onDirty={setTaskDirty} onBusy={setChildBusy} onOpen={openTaskItem} />}
        {!materials && tab === 'documents' && contentSubject && !busy && <ClassroomResources key={`documents:${course}:${unitId}:${role}:${district}`} unitId={unitId || undefined} unitTargetGroup={selectedUnit?.target_group} subject={contentSubject} kind="all" onDirty={setResourceDirty} onBusy={setChildBusy} />}
        {!materials && tab === 'lessons' && !course && !manage && <ClassroomTasks compact onViewAll={() => changeTab('tasks')} manage={false} role={role} district={district} readOnly={readOnly} onOpen={openTaskItem} />}
        {!materials && tab === 'lessons' && !course && <section className="classroom-course-section" aria-label="เลือกรายวิชา">
            <div className="classroom-course-toolbar">
                <div className="classroom-levels" role="group" aria-label="ระดับการศึกษา">{levelChoices.filter(item => manage || data.subjects.some(subject => subject.level === item.id)).map(({ id, label, Icon }) => {
                    const total = data.subjects.filter(item => item.level === id).length;
                    return <button type="button" key={id} aria-pressed={activeLevel === id} disabled={total === 0 || busy} onClick={() => leave(() => { setLevel(id); setSearch(''); resetActivity(); })}><Icon size={19} />{label}<span>{total}</span></button>;
                })}</div>
                <TextField size="small" label="ค้นหารายวิชา" placeholder="ชื่อหรือรหัสวิชา" value={search} onChange={event => setSearch(event.target.value)} slotProps={{ input: { startAdornment: <MagnifyingGlass className="mr-2" size={18} /> } }} />
            </div>
            {data.subjects.length === 0 ? <EmptyState title="ยังไม่มีรายวิชา" description="รายวิชาที่ลงทะเบียนในภาคเรียนนี้จะแสดงที่นี่" /> : <div className="classroom-course-list">{pagedSubjects.map(item => <button type="button" key={`${item.level}|${item.code}`} className="classroom-course-row" disabled={busy} onClick={() => leave(() => { setCourse(`${item.level}|${item.code}`); setActivityPage(1); setSubmissionPage(1); })}>
                <span className="classroom-course-symbol"><Books size={24} weight="duotone" /></span><span className="classroom-course-copy"><strong>{item.name}</strong><small>{item.code} · {item.level_label}{manage ? ` · ${item.student_count} คน` : ''}</small></span><span className="classroom-course-enter">เข้าเรียน <ArrowRight size={18} /></span>
            </button>)}</div>}
            {visibleSubjects.length === 0 && data.subjects.length > 0 && <EmptyState title="ไม่พบรายวิชา" description="ลองค้นหาด้วยชื่อหรือรหัสวิชาอื่น" />}
            {courseLastPage > 1 && <nav className="classroom-course-pager" aria-label="แบ่งหน้ารายวิชา"><Button appearance="outline" disabled={visibleCoursePage <= 1} onClick={() => setCoursePage(visibleCoursePage - 1)}>ก่อนหน้า</Button><span>หน้า {visibleCoursePage} / {courseLastPage} · {visibleSubjects.length} วิชา</span><Button appearance="outline" disabled={visibleCoursePage >= courseLastPage} onClick={() => setCoursePage(visibleCoursePage + 1)}>ถัดไป</Button></nav>}
        </section>}
        {course && busy && !materials && !focused && <QuerySkeleton rows={3} />}
        {subject && !materials && !focused && tab === 'lessons' && !busy && <ClassroomUnits key={`${course}:${role}:${district}`} subject={subject} role={role} district={district} readOnly={readOnly} unitId={unitId} selected={selectedUnit} onOpen={id => leave(() => openUnit(id))} onCommitOpen={openUnit} onDirty={setTaskDirty} onBusy={setChildBusy}>
            {contentSubject && insideUnit && <section className="classroom-activity-section" aria-label="เนื้อหาในบทเรียน">
                {manage && selectedUnit?.can_edit && !selectedUnit.is_legacy && <Button appearance="outline" icon={<Archive size={18} />} disabled={readOnly || childBusy} onClick={() => leave(() => setRouteParams(new URLSearchParams({ tab: 'templates', source_unit_id: unitId })))}>เก็บบทเรียนเข้าคลัง</Button>}
                {manage && selectedUnit?.status === 'draft' && <Alert severity="info">บทเรียนนี้เป็นฉบับร่าง เมื่อเตรียมเนื้อหาครบแล้วให้เผยแพร่ก่อนสั่งงาน</Alert>}
                {manage && toolsOpen && <div id="classroom-add-tools" className="classroom-add-tools"><div className="classroom-add-actions">{(Object.keys(kindLabels) as ActivityKind[]).map(type => { const Icon = kindIcons[type]; return <button type="button" key={type} data-kind={type} className="classroom-action" disabled={readOnly || busy} onClick={() => add(type)}><Icon size={19} />{kindLabels[type]}</button>; })}<button type="button" className="classroom-action" disabled={readOnly || busy} onClick={() => add('quiz')}><UploadSimple size={19} />นำเข้าข้อสอบ</button></div><QuizTemplateActions compact /></div>}
                <div className="classroom-content-filters" role="group" aria-label="ประเภทเนื้อหา"><button type="button" aria-pressed={kind === 'all'} onClick={() => changeKind('all')}><SquaresFour size={18} />ทั้งหมด</button>{(Object.keys(kindLabels) as ActivityKind[]).map(type => { const Icon = kindIcons[type]; return <button type="button" key={type} data-kind={type} aria-pressed={kind === type} disabled={busy} onClick={() => changeKind(type)}><Icon size={18} />{kindLabels[type]}</button>; })}</div>
                {kind === 'worksheet' && <div className="classroom-work-options"><div className="classroom-choice-buttons" role="group" aria-label="รูปแบบใบงาน"><button type="button" aria-pressed={!fileMode} disabled={childBusy} onClick={() => changeKind('worksheet')}><NotePencil size={19} />ตอบออนไลน์</button><button type="button" aria-pressed={fileMode} disabled={childBusy} onClick={() => changeKind('worksheet', 'files')}><UploadSimple size={19} />ส่งไฟล์ / ลิงก์</button></div></div>}
                {fileMode && <AssignmentWorkspacePage key={`${role}:${district}:${contentSubject.level}:${contentSubject.code}:${unitId}:${initialAssignmentId}`} embedded unitId={unitId} unitTargetGroup={selectedUnit?.target_group} subject={contentSubject} initialAssignmentId={initialAssignmentId} onSelectionChange={selectAssignment} onDirty={setAssignmentDirty} onBusy={setChildBusy} />}
                {!fileMode && <>
                    <div className="classroom-list-heading"><h3>{kind === 'all' ? 'เนื้อหาในบทเรียน' : kindLabels[kind]}</h3><span>{data.activity_pagination?.total ?? data.activities.length} รายการ</span>{manage && <Button size="small" appearance="outline" disabled={readOnly || reorder.isPending} onClick={() => { if (sorting) finishSorting(); else { reorder.reset(); setPendingMoves([]); setSorting(true); } }}>{reorder.isPending ? 'กำลังบันทึก…' : sorting ? 'เสร็จสิ้นการเรียง' : 'จัดลำดับเนื้อหา'}</Button>}</div>
                    {manage && sorting && <Button size="small" disabled={reorder.isPending} onClick={() => { setPendingMoves([]); setSorting(false); reorder.reset(); }}>ยกเลิกการเรียง</Button>}
                    {pendingMoves.length >= 500 && <Alert severity="info">กรุณากดเสร็จสิ้นการเรียงเพื่อบันทึกก่อนจัดลำดับต่อ</Alert>}
                    {manage && sorting && <p className="text-sm classroom-muted" role="status">{reorder.isPending ? 'กำลังบันทึกลำดับ…' : 'ลากที่จุดด้านซ้ายเพื่อย้ายตำแหน่ง หรือใช้ปุ่มขึ้น / ลง และย้ายไปแรกสุด กดเสร็จสิ้นการเรียงเพื่อบันทึกลำดับ'}</p>}
                    {kind === 'all' && <button type="button" className="classroom-file-shortcut" onClick={() => changeKind('worksheet', 'files')}><UploadSimple size={20} /><span>ใบงานแบบส่งไฟล์ / ลิงก์</span><ArrowRight size={18} /></button>}
                    {data.activities.length === 0 ? <div className="classroom-empty"><Books size={30} /><h3>{manage ? 'ยังไม่มีเนื้อหาในส่วนนี้' : 'ยังไม่มีเนื้อหาที่เผยแพร่'}</h3>{manage && <Button appearance="primary" icon={<Plus />} disabled={readOnly} onClick={() => add(kind === 'all' ? 'knowledge' : kind)}>เพิ่มเนื้อหาแรก</Button>}</div> : <div className="classroom-content-list">{previewContentOrder(data.activities, pendingMoves).slice(classroomContentPage(activityPage).offset, classroomContentPage(activityPage).offset + 10).map((item, index, visibleItems) => {
                        const Icon = kindIcons[item.type];
                        const state = item.status === 'draft' ? 'ฉบับร่าง' : manage ? 'เผยแพร่แล้ว' : item.submission ? item.submission.status === 'reviewed' ? `คะแนน ${item.submission.score}/${item.submission.max_score}` : 'ส่งแล้ว · รอตรวจ' : isAssessment(item.type) ? 'ยังไม่ส่ง' : 'เปิดเรียน';
                        return <article key={item.id} className="classroom-sort-row" data-dragging={draggedId === item.id} onDragOver={event => { if (sorting && draggedId && !reorder.isPending && item.can_edit) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; } }} onDrop={event => { event.preventDefault(); const source = draggedId; setDraggedId(''); if (source && source !== item.id && !reorder.isPending) { const sourceIndex = visibleItems.findIndex(row => row.id === source); queueMove({ id: source, anchor_id: item.id, placement: sourceIndex < index ? 'after' : 'before' }); } }}>
                            {manage && sorting && <div className="classroom-sort-tools"><button type="button" className="classroom-drag-handle" draggable={!readOnly && !reorder.isPending && item.can_edit} disabled={readOnly || reorder.isPending || !item.can_edit} aria-label={`ลากเรียง ${item.title}`} onDragStart={event => { setDraggedId(item.id); event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', item.id); }} onDragEnd={() => setDraggedId('')}><DotsSixVertical size={24} /></button><Button size="small" aria-label={`เลื่อน ${item.title} ขึ้น`} disabled={readOnly || index === 0 || reorder.isPending || !item.can_edit} icon={<ArrowUp size={17} />} onClick={() => queueMove({ id: item.id, placement: 'before', anchor_id: visibleItems[index - 1].id })} /><Button size="small" aria-label={`เลื่อน ${item.title} ลง`} disabled={readOnly || index === visibleItems.length - 1 || reorder.isPending || !item.can_edit} icon={<ArrowDown size={17} />} onClick={() => queueMove({ id: item.id, placement: 'after', anchor_id: visibleItems[index + 1].id })} /><Button size="small" disabled={readOnly || reorder.isPending || !item.can_edit} onClick={() => { queueMove({ id: item.id, placement: 'first' }); setActivityPage(Math.floor((activityPage - 1) / 5) * 5 + 1); }}>แรกสุด</Button></div>}
                            <button type="button" className="classroom-content-row" data-kind={item.type} onClick={() => leave(() => { selectActivity(item.id); setSubmissionPage(1); })}>
                            <span className="classroom-content-icon"><Icon size={24} weight="duotone" /></span><span className="classroom-content-copy"><strong>{item.title}</strong><small>{kindLabels[item.type]}{isAssessment(item.type) ? ` · ${item.max_score} คะแนน` : ''}{manage && isAssessment(item.type) ? ` · ส่งแล้ว ${item.submission_count} คน` : ''}</small></span><span className="classroom-content-state" data-submitted={Boolean(item.submission)}>{state}</span><span className="classroom-content-open">{isAssessment(item.type) ? item.submission ? 'ดูผล' : manage ? 'เปิดดู' : 'ทำกิจกรรม' : 'เปิดเรียน'} <ArrowRight size={16} /></span>
                        </button></article>;
                    })}</div>}
                    <Pager page={classroomContentPage(activityPage, data.activity_pagination?.total ?? data.activities.length).pagination} change={page => leave(() => { setActivityPage(page); selectActivity('', true); })} />
                </>}
            </section>}
        </ClassroomUnits>}
        {focusedFile && contentSubject && insideUnit && !busy && <AssignmentWorkspacePage key={`file-detail:${role}:${district}:${course}:${unitId}:${initialAssignmentId}`} embedded unitId={unitId} unitTargetGroup={selectedUnit?.target_group} subject={contentSubject} initialAssignmentId={initialAssignmentId} onSelectionChange={selectAssignment} onDirty={setAssignmentDirty} onBusy={setChildBusy} />}
        {focusPending && (query.isError ? <QueryError onRetry={() => void query.refetch()} /> : <QuerySkeleton rows={4} />)}
        {insideUnit && focused && !fileMode && !busy && selected?.id === activityId && <div ref={detailRef} tabIndex={-1} role="region" aria-label={`กิจกรรม ${selected.title}`} className="classroom-detail" data-kind={selected.type}>
            <div className="classroom-detail-heading"><button type="button" className="classroom-action" onClick={() => leave(() => selectActivity(''))}><ArrowLeft size={20} /> กลับไปบทเรียน</button><span className="classroom-muted">{kindLabels[selected.type]} · {subject?.name}</span></div>
            <Panel title={selected.title} description={`${kindLabels[selected.type]} · ${selected.target_group || (role === 'teacher' ? 'กลุ่มที่รับผิดชอบ' : 'ทุกกลุ่มในขอบเขต')} · ${selected.status === 'draft' ? 'ฉบับร่าง' : 'เผยแพร่แล้ว'}`}
                action={manage && selected.can_edit ? <><Button icon={<PencilSimple />} disabled={readOnly || selected.has_submissions || selected.content_locked} onClick={() => { save.reset(); setEditor(selected); }}>แก้ไข</Button><Button disabled={readOnly || status.isPending || selected.content_locked} onClick={() => status.mutate(selected)}>{selected.status === 'published' ? 'ซ่อนกิจกรรม' : 'เผยแพร่'}</Button><Button aria-label="ลบกิจกรรม" icon={<Trash />} disabled={readOnly || selected.has_submissions || selected.content_locked} onClick={() => setDeleteId(selected.id)} /></> : undefined}>
                {selected.content_locked && manage && <Alert severity="info" className="mb-4">กิจกรรมนี้ถูกสั่งงานแล้ว เนื้อหาและการเผยแพร่ถูกล็อกเพื่อให้ผู้เรียนทำงานเดิมได้ครบ คุณยังตรวจคำตอบได้</Alert>}
                {selected.has_submissions && manage && <Alert severity="info" className="mb-4">มีผู้ส่งคำตอบแล้ว เนื้อหาและข้อคำถามถูกล็อก คุณยังซ่อนกิจกรรมหรือตรวจคำตอบได้</Alert>}
                {isAssessment(selected.type) && <p className="mb-4 flex flex-wrap items-center gap-2 text-sm classroom-muted"><Clock size={17} />{dateLabel(selected.due_at)} · {selected.max_score} คะแนน · ส่งได้ 1 ครั้ง</p>}
                {selected.content && <p className="mb-5 whitespace-pre-wrap break-words leading-7">{selected.content}</p>}
                <div className="space-y-4">{selected.links.map((link, i) => <LearningLink key={`${i}:${link.url}`} title={link.title} url={link.url} embedVideo={selected.type === 'video'} />)}</div>
                {!manage && !isAssessment(selected.type) && <TaskCompletion activity={selected} taskId={routeParams.get('task_id') ?? ''} role={role} district={district} readOnly={readOnly} />}
                {isAssessment(selected.type) && <AnswerForm key={`${role}:${district}:${selected.id}`} activity={selected} manage={manage} readOnly={readOnly} onDirty={setDirty} refresh={refresh} />}
            </Panel>
            {manage && isAssessment(selected.type) && <Panel title="คำตอบที่ส่งแล้ว" description={`${selected.submission_count} รายการ`}>
                {data.submissions.length === 0 && <p className="text-sm">ยังไม่มีผู้ส่งคำตอบ</p>}
                <div className="divide-y" style={{ borderColor: 'var(--ui-border)' }}>{data.submissions.map(submission => <div key={submission.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><div><p className="font-semibold">{submission.student_name}</p><p className="text-sm">{submission.student_code} · {submission.status === 'submitted' ? 'รอตรวจ' : `${submission.score} / ${submission.max_score} คะแนน`}</p></div><Button appearance="outline" disabled={!selected.can_edit} onClick={() => setReview(submission)}>ตรวจคำตอบ</Button></div>)}</div>
                <Pager page={data.submission_pagination} change={setSubmissionPage} />
            </Panel>}
        </div>}
        {editor && contentSubject && insideUnit && <ActivityEditor key={editor === 'new' ? `new:${initialKind}` : editor.id} activity={editor === 'new' ? null : editor} initialKind={initialKind} unitTargetGroup={selectedUnit?.target_group} subject={contentSubject} pending={save.isPending} error={save.error} onClose={() => leave(() => setEditor(null))} onDirty={() => setDirty(true)} onSave={values => save.mutate({ id: editor === 'new' ? undefined : editor.id, values })} />}
        {review && selected && <ReviewDialog submission={review} activity={selected} readOnly={readOnly} close={() => setReview(null)} refresh={refresh} />}
        <Dialog open={Boolean(deleteId)} onClose={() => { if (!remove.isPending) setDeleteId(''); }}><DialogTitle>ลบกิจกรรมนี้?</DialogTitle><DialogContent>กิจกรรมที่ยังไม่มีผู้ส่งคำตอบจะถูกลบออกจากห้องเรียน</DialogContent><DialogActions><Button disabled={remove.isPending} onClick={() => setDeleteId('')}>ยกเลิก</Button><Button appearance="primary" disabled={remove.isPending} onClick={() => remove.mutate()}>ยืนยันลบ</Button></DialogActions></Dialog>
    </div>;
}

function LearningLink({ title, url, embedVideo = true }: { title: string; url: string; embedVideo?: boolean }) {
    const safe = safeClassroomUrl(url);
    const embed = embedVideo ? youtubeEmbed(url) : null;
    if (!safe) return null;
    return <div className="overflow-hidden rounded-xl border" style={{ borderColor: 'var(--ui-border)' }}>
        {embed && <iframe className="aspect-video w-full" src={embed} title={title} loading="lazy" referrerPolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen />}
        <a className="flex items-center gap-2 break-all p-3 font-semibold text-brand-700 hover:underline" href={safe} target="_blank" rel="noopener noreferrer"><ArrowSquareOut className="shrink-0" />{title}</a>
    </div>;
}

function AnswerControl({ question, value, change, disabled }: { question: Question; value: string | string[]; change: (value: string | string[]) => void; disabled: boolean }) {
    if (['single_choice', 'multiple_choice', 'true_false'].includes(question.type)) {
        const choices = question.type === 'true_false' ? [{ value: 'true', label: 'ถูก' }, { value: 'false', label: 'ผิด' }] : question.options.map(option => ({ value: option, label: option }));
        return <div className="space-y-2">{choices.map(option => <label key={option.value} className="flex cursor-pointer items-start gap-3 rounded-lg border p-3" style={{ borderColor: 'var(--ui-border)' }}>
            <input type={question.type === 'multiple_choice' ? 'checkbox' : 'radio'} name={question.id} value={option.value} className="mt-1 size-4 shrink-0 accent-brand-600" disabled={disabled}
                checked={Array.isArray(value) ? value.includes(option.value) : value === option.value} onChange={event => change(question.type === 'multiple_choice' ? event.target.checked ? [...(Array.isArray(value) ? value : []), option.value] : (Array.isArray(value) ? value : []).filter(item => item !== option.value) : option.value)} /><span className="break-words">{option.label}</span>
        </label>)}</div>;
    }
    return <TextField fullWidth label="คำตอบของคุณ" multiline minRows={question.type === 'essay' ? 4 : 1} value={value} disabled={disabled} slotProps={{ htmlInput: { maxLength: 10000 } }} onChange={event => change(event.target.value)} />;
}

function QuestionBlock({ question, index, children }: { question: Question; index: number; children: ReactNode }) {
    return <fieldset className="min-w-0 space-y-3 rounded-xl border p-4" style={{ borderColor: 'var(--ui-border)' }}><legend className="px-1 text-sm font-bold">ข้อ {index + 1} · {questionLabels[question.type]} · {question.points} คะแนน</legend>
        <p className="whitespace-pre-wrap break-words font-semibold leading-7">{question.prompt}</p>
        {question.image_url && <PrivateImage url={question.image_url} />}{children}
    </fieldset>;
}

function AnswerForm({ activity, manage, readOnly, onDirty, refresh }: { activity: Activity; manage: boolean; readOnly: boolean; onDirty: (dirty: boolean) => void; refresh: () => Promise<unknown> }) {
    const [answers, setAnswers] = useState<Record<string, string | string[]>>({});
    const [confirm, setConfirm] = useState(false);
    const [sent, setSent] = useState<Submission | null>(null);
    const submit = useMutation({ mutationFn: () => sendFeatureData<Submission>(`${base}/activities/${activity.id}/submit`, 'POST', { answers }), onSuccess: async result => { setSent(result.data); setConfirm(false); onDirty(false); await refresh(); },
        onError: error => { if (error instanceof ApiError && error.status === 409) { setConfirm(false); onDirty(false); void refresh(); } } });
    const submission = activity.submission ?? sent;
    const expired = activity.due_at !== null && new Date(activity.due_at).getTime() < Date.now();
    const disabled = manage || readOnly || submission !== null || expired;
    const complete = activity.questions.every(question => { const answer = answers[question.id]; return Array.isArray(answer) ? answer.length > 0 : Boolean(answer?.trim()); });
    return <div className="mt-6 space-y-5">
        {submission && <Alert severity={submission.status === 'reviewed' ? 'success' : 'info'} icon={<CheckCircle />}><strong>{submission.status === 'reviewed' ? `คะแนน ${submission.score} / ${submission.max_score}` : `ส่งคำตอบแล้ว · รอครูตรวจ (คะแนนอัตโนมัติ ${submission.auto_score})`}</strong>{submission.feedback && <p className="mt-2 whitespace-pre-wrap">ความเห็นครู: {submission.feedback}</p>}</Alert>}
        {expired && !submission && !manage && <Alert severity="warning">พ้นกำหนดส่งแล้ว</Alert>}
        {manage && <Alert severity="info">ตัวอย่างข้อคำถามสำหรับผู้เรียน คำตอบอัตนัยต้องตรวจและให้คะแนนรวมด้วยตนเอง</Alert>}
        {activity.questions.map((question, index) => <QuestionBlock key={question.id} question={question} index={index}>
            <AnswerControl question={question} value={submission?.answers[question.id] ?? answers[question.id] ?? (question.type === 'multiple_choice' ? [] : '')} disabled={disabled} change={value => { setAnswers(current => ({ ...current, [question.id]: value })); onDirty(true); }} />
            {manage && question.answer !== undefined && <p className="text-sm text-brand-700">เฉลย: {Array.isArray(question.answer) ? question.answer.join(', ') : question.type === 'true_false' ? question.answer === 'true' ? 'ถูก' : 'ผิด' : question.answer}</p>}
        </QuestionBlock>)}
        {!manage && !submission && <><p className="text-sm">ตอบแล้ว {activity.questions.filter(question => { const value = answers[question.id]; return Array.isArray(value) ? value.length > 0 : Boolean(value?.trim()); }).length} / {activity.questions.length} ข้อ · คำตอบยังไม่ถูกบันทึกจนกว่าจะกดส่ง</p><Button appearance="primary" disabled={disabled || !complete || submit.isPending} onClick={() => setConfirm(true)}>ตรวจทานและส่งคำตอบ</Button></>}
        <Dialog open={confirm} onClose={() => { if (!submit.isPending) setConfirm(false); }} fullWidth maxWidth="sm"><DialogTitle>ตรวจทานก่อนส่ง</DialogTitle><DialogContent><Alert severity="info">ส่งได้ 1 ครั้ง และแก้คำตอบหลังส่งไม่ได้</Alert><div className="mt-4 space-y-3">{activity.questions.map((question, index) => <div key={question.id}><strong>ข้อ {index + 1}</strong><p className="whitespace-pre-wrap break-words">{Array.isArray(answers[question.id]) ? (answers[question.id] as string[]).join(', ') : question.type === 'true_false' ? answers[question.id] === 'true' ? 'ถูก' : 'ผิด' : answers[question.id]}</p></div>)}</div>{submit.isError && <Alert severity="error">{errorText(submit.error)}</Alert>}</DialogContent><DialogActions><Button disabled={submit.isPending} onClick={() => setConfirm(false)}>กลับไปแก้ไข</Button><Button appearance="primary" disabled={submit.isPending} onClick={() => submit.mutate()}>{submit.isPending ? 'กำลังส่ง…' : 'ยืนยันส่งคำตอบ'}</Button></DialogActions></Dialog>
    </div>;
}

function newQuestion(): Question { return { id: crypto.randomUUID(), type: 'single_choice', prompt: '', options: ['ตัวเลือก 1', 'ตัวเลือก 2'], answer: 'ตัวเลือก 1', image_id: null, points: 1 }; }

function ActivityEditor({ unitTargetGroup, activity, initialKind, subject, pending, error, onClose, onDirty, onSave }: { unitTargetGroup?: string; activity: Activity | null; initialKind: ActivityKind; subject: Subject; pending: boolean; error: unknown; onClose: () => void; onDirty: () => void; onSave: (values: unknown) => void }) {
    const { role } = useDemoRole();
    const [title, setTitle] = useState(activity?.title ?? '');
    const [type, setType] = useState<ActivityKind>(activity?.type ?? initialKind);
    const [content, setContent] = useState(activity?.content ?? '');
    const [group, setGroup] = useState(activity?.target_group || unitTargetGroup || (role === 'teacher' ? subject.groups[0]?.code ?? '' : ''));
    const [status, setStatus] = useState(activity?.status ?? 'draft');
    const [due, setDue] = useState(() => { if (!activity?.due_at) return ''; const date = new Date(activity.due_at); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); });
    const [links, setLinks] = useState(activity?.links ?? (initialKind === 'video' ? [{ title: '', url: '' }] : []));
    const [questions, setQuestions] = useState<Question[]>(() => activity?.questions.length ? activity.questions : [{ ...newQuestion(), ...(initialKind === 'worksheet' ? { type: 'essay' as QuestionType, options: [], answer: undefined } : {}) }]);
    const [uploading, setUploading] = useState(false);
    const submit = (event: FormEvent) => { event.preventDefault(); if (uploading || (role === 'teacher' && !group)) return; onSave({ title, type, content, subject_code: subject.code, education_level: subject.level, target_group: group, status,
        due_at: isAssessment(type) && due ? new Date(due).toISOString() : null, links, questions: isAssessment(type) ? questions.map(({ image_url: _imageUrl, ...question }) => question) : [] }); };
    return <Dialog className="classroom-dialog" open onClose={() => { if (!pending && !uploading) onClose(); }} fullWidth maxWidth="md"><form onSubmit={submit} onChange={onDirty}>
        <DialogTitle><span className="classroom-step">{subject.code} · {subject.name}</span><span className="block mt-1">{activity ? 'แก้ไขเนื้อหาการเรียนรู้' : 'เพิ่มเนื้อหาในกิจกรรม'}</span></DialogTitle><DialogContent><div className="space-y-5 pt-2">
            <fieldset><legend className="classroom-field-label">วันนี้จะสร้างอะไรดี?</legend><div className="classroom-editor-kinds" role="group" aria-label="ประเภทกิจกรรม">{(Object.keys(kindLabels) as ActivityKind[]).map(value => { const Icon = kindIcons[value]; return <button type="button" data-kind={value} className="classroom-editor-kind" key={value} aria-pressed={type === value} disabled={pending || uploading} onClick={() => { setType(value); if (value === 'video' && links.length === 0) setLinks([{ title: '', url: '' }]); onDirty(); }}><Icon size={25} weight="duotone" /><strong>{kindLabels[value]}</strong></button>; })}</div></fieldset>
            <TextField fullWidth required label="ชื่อกิจกรรม" placeholder={type === 'video' ? 'เช่น ดูคลิป รู้จักระบบสุริยะ' : 'ตั้งชื่อที่ทำให้ผู้เรียนอยากเปิดดู'} value={title} disabled={pending || uploading} slotProps={{ htmlInput: { maxLength: 220 } }} onChange={event => setTitle(event.target.value)} />
            <fieldset><legend className="classroom-field-label">ให้กลุ่มไหนเรียนบ้าง?</legend><div className="classroom-choice-buttons" role="group" aria-label="กลุ่มเป้าหมาย">{[...(role === 'teacher' || unitTargetGroup ? [] : [{ code: '', name: 'ทุกกลุ่มในขอบเขตของคุณ' }]), ...subject.groups].map(item => <button type="button" key={item.code} aria-pressed={group === item.code} disabled={pending || uploading} onClick={() => { setGroup(item.code); onDirty(); }}>{item.name || item.code}{group === item.code && <CheckCircle size={16} weight="fill" />}</button>)}</div></fieldset>
            <TextField fullWidth multiline minRows={4} label={type === 'knowledge' ? 'เนื้อหาใบความรู้' : type === 'video' ? 'คำอธิบายคลิป' : 'คำชี้แจง'} value={content} disabled={pending || uploading} slotProps={{ htmlInput: { maxLength: 30000 } }} onChange={event => setContent(event.target.value)} />
            <section className="space-y-3"><h3 className="font-bold">{type === 'video' ? 'ลิงก์คลิปวิดีโอ' : 'เอกสารและลิงก์ประกอบ'}</h3><p className="text-sm classroom-muted">{type === 'video' ? 'YouTube เล่นในห้องเรียนได้ทันที หรือแนบวิดีโอจาก Drive / OneDrive ผ่านลิงก์แชร์' : 'แนบเอกสาร Google Drive, OneDrive หรือเว็บไซต์ที่ผู้เรียนเปิดดูได้'} · สูงสุด 20 ลิงก์</p>
                {links.map((link, i) => <div key={i} className="grid items-start gap-2 sm:grid-cols-[1fr_2fr_auto]"><TextField required label={`ชื่อสื่อ ${i + 1}`} value={link.title} disabled={pending || uploading} onChange={event => setLinks(current => current.map((item, index) => index === i ? { ...item, title: event.target.value } : item))} /><TextField required type="url" label="ลิงก์ HTTP / HTTPS" value={link.url} disabled={pending || uploading} onChange={event => setLinks(current => current.map((item, index) => index === i ? { ...item, url: event.target.value } : item))} /><Button aria-label={`ลบลิงก์ ${i + 1}`} icon={<Trash />} disabled={pending || uploading || (type === 'video' && links.length === 1)} onClick={() => { setLinks(current => current.filter((_, index) => i !== index)); onDirty(); }} /></div>)}
                <Button appearance="outline" icon={<Plus />} disabled={links.length >= 20 || pending || uploading} onClick={() => { setLinks(current => [...current, { title: '', url: '' }]); onDirty(); }}>{type === 'video' ? 'เพิ่มลิงก์คลิป' : 'เพิ่มลิงก์สื่อ'}</Button>
            </section>
            {isAssessment(type) && <section className="space-y-4">
                <QuizImportPanel disabled={pending || uploading} existingCount={questions.filter(question => question.prompt.trim() !== '').length} apply={value => { setQuestions(value.questions); if (value.title && !title.trim()) setTitle(value.title); onDirty(); }} />
                <div className="classroom-section-heading"><h3 className="font-bold">คำถามของกิจกรรม</h3><span className="classroom-total-points">{questions.length} ข้อ · รวม {Number(questions.reduce((sum, question) => sum + Number(question.points || 0), 0).toFixed(2))} คะแนน</span></div><p className="text-sm classroom-muted">เลือกหลายคำตอบต้องถูกครบชุด เติมคำไม่สนตัวพิมพ์ใหญ่/เล็กและช่องว่างหัวท้าย ส่วนอัตนัยรอครูตรวจ</p>
                {questions.map((question, index) => <QuestionEditor key={question.id} question={question} index={index} disabled={pending || uploading} onUpload={setUploading} change={next => { setQuestions(current => current.map(item => item.id === question.id ? next : item)); onDirty(); }} remove={() => { setQuestions(current => current.filter(item => item.id !== question.id)); onDirty(); }} />)}
                <Button appearance="outline" icon={<Plus />} disabled={questions.length >= 100 || pending || uploading} onClick={() => { setQuestions(current => [...current, { ...newQuestion(), ...(type === 'worksheet' ? { type: 'essay' as QuestionType, options: [], answer: undefined } : {}) }]); onDirty(); }}>เพิ่มคำถาม</Button>
            </section>}
            <div className="grid gap-5 sm:grid-cols-2"><fieldset><legend className="classroom-field-label">พร้อมให้ผู้เรียนเห็นหรือยัง?</legend><div className="classroom-choice-buttons" role="group" aria-label="สถานะกิจกรรม">{[{ value: 'draft', label: 'เก็บเป็นฉบับร่าง' }, { value: 'published', label: 'เผยแพร่เลย' }].map(item => <button type="button" key={item.value} aria-pressed={status === item.value} disabled={pending || uploading} onClick={() => { setStatus(item.value as Activity['status']); onDirty(); }}>{item.label}{status === item.value && <CheckCircle size={16} weight="fill" />}</button>)}</div></fieldset>{isAssessment(type) && <TextField type="datetime-local" label="กำหนดส่ง (เว้นว่างได้)" value={due} disabled={pending || uploading} slotProps={{ inputLabel: { shrink: true } }} onChange={event => setDue(event.target.value)} />}</div>
            {error != null && <Alert severity="error">{errorText(error)}</Alert>}
        </div></DialogContent><DialogActions><Button disabled={pending || uploading} onClick={onClose}>ยกเลิก</Button><Button type="submit" appearance="primary" disabled={pending || uploading || (isAssessment(type) && questions.length === 0)}>{uploading ? 'กำลังอัปโหลดภาพ…' : pending ? 'กำลังบันทึก…' : 'บันทึกกิจกรรม'}</Button></DialogActions>
    </form></Dialog>;
}

function QuestionEditor({ question, index, disabled, change, remove, onUpload }: { question: Question; index: number; disabled: boolean; change: (question: Question) => void; remove: () => void; onUpload: (uploading: boolean) => void }) {
    const upload = useMutation({ mutationFn: (file: File) => { const form = new FormData(); form.set('image', file); return sendFeatureData<{ id: string; url: string }>(`${base}/images`, 'POST', form); }, onMutate: () => onUpload(true), onSuccess: result => change({ ...question, image_id: result.data.id, image_url: result.data.url }), onSettled: () => onUpload(false) });
    const options = ['single_choice', 'multiple_choice'].includes(question.type);
    return <div className="space-y-3 rounded-xl border p-4" style={{ borderColor: 'var(--ui-border)' }}><div className="flex items-center justify-between"><h4 className="font-bold">ข้อ {index + 1}</h4><Button aria-label={`ลบคำถาม ${index + 1}`} icon={<Trash />} disabled={disabled || upload.isPending} onClick={remove} /></div>
        <fieldset><legend className="classroom-field-label">ชนิดคำถาม</legend><div className="classroom-choice-buttons" role="group" aria-label={`ชนิดคำถามข้อ ${index + 1}`}>{Object.entries(questionLabels).map(([value, label]) => <button type="button" key={value} aria-pressed={question.type === value} disabled={disabled || upload.isPending} onClick={() => { if (value === question.type) return; const type = value as QuestionType; change({ ...question, type, options: ['single_choice', 'multiple_choice'].includes(type) ? ['ตัวเลือก 1', 'ตัวเลือก 2'] : [], answer: type === 'multiple_choice' ? [] : type === 'true_false' ? 'true' : type === 'single_choice' ? 'ตัวเลือก 1' : undefined }); }}>{label}</button>)}</div></fieldset><TextField required className="max-w-44" label="คะแนน" type="number" value={question.points} disabled={disabled} slotProps={{ htmlInput: { min: 0.01, max: 1000, step: 0.01 } }} onChange={event => change({ ...question, points: Number(event.target.value) })} />
        <TextField fullWidth required multiline minRows={2} label="โจทย์ / คำถาม" value={question.prompt} disabled={disabled} slotProps={{ htmlInput: { maxLength: 5000 } }} onChange={event => change({ ...question, prompt: event.target.value })} />
        {question.image_url && <><PrivateImage url={question.image_url} /><Button disabled={disabled || upload.isPending} onClick={() => change({ ...question, image_id: null, image_url: null })}>นำภาพออกจากโจทย์</Button></>}
        <Button as="label" appearance="outline" icon={<UploadSimple />} disabled={disabled || upload.isPending}>{upload.isPending ? 'กำลังอัปโหลด…' : 'อัปโหลดภาพโจทย์'}<input type="file" hidden accept="image/jpeg,image/png,image/webp" disabled={disabled || upload.isPending} onChange={event => { const file = event.target.files?.[0]; if (file) upload.mutate(file); event.target.value = ''; }} /></Button><p className="text-xs">JPG, PNG, WebP ไม่เกิน 5 MB</p>
        {upload.isError && <Alert severity="error">{errorText(upload.error)}</Alert>}
        {options && <div className="space-y-2">{question.options.map((option, i) => <div className="flex gap-2" key={i}><TextField fullWidth required label={`ตัวเลือก ${i + 1}`} value={option} disabled={disabled} onChange={event => { const value = event.target.value; change({ ...question, options: question.options.map((text, index) => index === i ? value : text), answer: Array.isArray(question.answer) ? question.answer.map(text => text === option ? value : text) : question.answer === option ? value : question.answer }); }} /><Button disabled={disabled || question.options.length <= 2} aria-label={`ลบตัวเลือก ${i + 1}`} icon={<Trash />} onClick={() => change({ ...question, options: question.options.filter((_, index) => i !== index), answer: Array.isArray(question.answer) ? question.answer.filter(text => text !== option) : question.answer === option ? '' : question.answer })} /></div>)}<Button disabled={disabled || question.options.length >= 10} onClick={() => change({ ...question, options: [...question.options, `ตัวเลือก ${question.options.length + 1}`] })}>เพิ่มตัวเลือก</Button></div>}
        {question.type !== 'essay' && <fieldset className="space-y-2"><legend className="mb-2 text-sm font-bold">เฉลย (แสดงเฉพาะครู)</legend><AnswerControl question={question} value={question.answer ?? (question.type === 'multiple_choice' ? [] : '')} disabled={disabled} change={answer => change({ ...question, answer })} /></fieldset>}
    </div>;
}

function ReviewDialog({ submission, activity, readOnly, close, refresh }: { submission: Submission; activity: Activity; readOnly: boolean; close: () => void; refresh: () => Promise<unknown> }) {
    const [score, setScore] = useState(String(submission.score ?? submission.auto_score));
    const [feedback, setFeedback] = useState(submission.feedback ?? '');
    const save = useMutation({ mutationFn: () => sendFeatureData(`${base}/activities/${activity.id}/submissions/${submission.id}/review`, 'PATCH', { score: Number(score), feedback }), onSuccess: async () => { close(); await refresh(); } });
    return <Dialog open onClose={() => { if (!save.isPending) close(); }} fullWidth maxWidth="md"><form onSubmit={event => { event.preventDefault(); save.mutate(); }}><DialogTitle>ตรวจคำตอบ · {submission.student_name}</DialogTitle><DialogContent><div className="space-y-4 pt-2"><p>{submission.student_code} · ส่งเมื่อ {dateLabel(submission.submitted_at)} · คะแนนอัตโนมัติ {submission.auto_score}</p>
        {activity.questions.map((question, index) => <QuestionBlock key={question.id} question={question} index={index}><p className="whitespace-pre-wrap break-words">{Array.isArray(submission.answers[question.id]) ? (submission.answers[question.id] as string[]).join(', ') : question.type === 'true_false' ? submission.answers[question.id] === 'true' ? 'ถูก' : 'ผิด' : submission.answers[question.id]}</p></QuestionBlock>)}
        <TextField required fullWidth type="number" label={`คะแนนรวมทั้งหมด (เต็ม ${submission.max_score})`} value={score} disabled={readOnly || save.isPending} slotProps={{ htmlInput: { min: 0, max: submission.max_score, step: 0.01 } }} onChange={event => setScore(event.target.value)} />
        <TextField fullWidth multiline minRows={3} label="ความเห็นครู" value={feedback} disabled={readOnly || save.isPending} slotProps={{ htmlInput: { maxLength: 5000 } }} onChange={event => setFeedback(event.target.value)} />{save.isError && <Alert severity="error">{errorText(save.error)}</Alert>}
    </div></DialogContent><DialogActions><Button disabled={save.isPending} onClick={close}>ปิด</Button><Button appearance="primary" type="submit" disabled={readOnly || save.isPending || score === ''}>{save.isPending ? 'กำลังบันทึก…' : 'บันทึกผลตรวจ'}</Button></DialogActions></form></Dialog>;
}
