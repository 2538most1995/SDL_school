import { Alert, Dialog, DialogActions, DialogContent, DialogTitle, MenuItem, TextField } from '@mui/material';
import { Books, Plus, PencilSimple, Trash, ArrowSquareOut, UploadSimple, CheckCircle } from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Button } from '../../components/MaterialUI';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { EmptyState, QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { ApiError } from '../../lib/api';
import { getFeatureBlob, getFeatureData, sendFeatureData } from '../api';
import { kindLabels, questionLabels, safeClassroomUrl, youtubeEmbed, type Activity, type ActivityKind, type PageInfo, type Question, type QuestionType, type Subject, type Submission, type Workspace } from './classroom/types';

const base = '/api/v1/learning/classroom';
const errorText = (error: unknown) => error instanceof Error ? error.message : 'ไม่สามารถดำเนินการได้';
const dateLabel = (date: string | null) => date ? new Date(date).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : 'ไม่กำหนดวันส่ง';

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
    return failed ? <Alert severity="warning">โหลดภาพไม่ได้ กรุณารีเฟรชหน้าอีกครั้ง</Alert> : src ? <img src={src} alt="ภาพประกอบโจทย์" className="max-h-80 max-w-full rounded-xl object-contain" /> : <p role="status">กำลังโหลดภาพ…</p>;
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
    const district = window.localStorage.getItem('sena-district-id') ?? '';
    return <Classroom key={`${role}:${district}`} role={role} district={district} />;
}

function Classroom({ role, district }: { role: string; district: string }) {
    const client = useQueryClient();
    const manage = role !== 'student';
    const [course, setCourse] = useState('');
    const [activityId, setActivityId] = useState('');
    const [activityPage, setActivityPage] = useState(1);
    const [submissionPage, setSubmissionPage] = useState(1);
    const [editor, setEditor] = useState<Activity | 'new' | null>(null);
    const [review, setReview] = useState<Submission | null>(null);
    const [deleteId, setDeleteId] = useState('');
    const [dirty, setDirty] = useState(false);
    const [kind, setKind] = useState('all');
    const params = new URLSearchParams({ activity_page: String(activityPage), page: String(submissionPage) });
    if (course) { const [level, code] = course.split('|'); params.set('subject_code', code); params.set('education_level', level); }
    if (activityId) params.set('activity_id', activityId);
    const query = useQuery({ queryKey: ['online-classroom', role, district, course, activityId, activityPage, submissionPage],
        queryFn: ({ signal }) => getFeatureData<Workspace>(`${base}?${params}`, signal) });
    const data = query.data?.data;
    const readOnly = Boolean(query.data?.meta?.read_only);
    const refresh = () => client.invalidateQueries({ queryKey: ['online-classroom'] });
    const save = useMutation({ mutationFn: ({ id, values }: { id?: string; values: unknown }) => sendFeatureData<{ id: string }>(`${base}/activities${id ? `/${id}` : ''}`, 'POST', values),
        onSuccess: async (result, variables) => { setEditor(null); setDirty(false); if (!variables.id) setActivityPage(1); setActivityId(result.data.id); setKind('all'); await refresh(); } });
    const status = useMutation({ mutationFn: (activity: Activity) => sendFeatureData(`${base}/activities/${activity.id}`, 'POST', { status: activity.status === 'published' ? 'draft' : 'published' }), onSuccess: refresh });
    const remove = useMutation({ mutationFn: () => sendFeatureData(`${base}/activities/${deleteId}`, 'DELETE'), onSuccess: async () => { setDeleteId(''); setActivityId(''); await refresh(); } });

    useEffect(() => {
        if (!dirty) return;
        const listener = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', listener);
        return () => window.removeEventListener('beforeunload', listener);
    }, [dirty]);
    const leave = (action: () => void) => { if (!dirty || window.confirm('มีข้อมูลที่ยังไม่ได้บันทึก ต้องการออกจากกิจกรรมนี้หรือไม่?')) { setDirty(false); action(); } };
    if (query.isPending) return <QuerySkeleton />;
    if (query.isError) return <QueryError message={errorText(query.error)} onRetry={() => void query.refetch()} />;
    if (!data) return null;
    const subject = data.selected_subject;
    const selected = data.selected_activity;
    const select = (id: string) => leave(() => { setActivityId(id); setSubmissionPage(1); });
    return <div className="online-classroom space-y-4 pb-8" style={{ color: 'var(--ui-text)' }}>
        <PageHeader title="ห้องเรียนออนไลน์" description="เรียนรู้ ทำใบงาน และทดสอบความเข้าใจ แยกตามวิชาที่ลงทะเบียน" icon={Books} category={data.term ? `ภาคเรียน ${data.term}` : 'พื้นที่การเรียนรู้'}
            actions={manage && subject ? <Button appearance="primary" icon={<Plus />} disabled={readOnly} onClick={() => leave(() => { save.reset(); setEditor('new'); })}>เพิ่มกิจกรรม</Button> : undefined} />
        {readOnly && <Alert severity="info">ระบบอยู่ในโหมดอ่านอย่างเดียว ผู้ดูแลต้องเปิดการเขียนข้อมูลก่อนสร้างกิจกรรมหรือส่งคำตอบ</Alert>}
        {data.subjects.length === 0 ? <EmptyState title="ยังไม่มีรายวิชาที่ลงทะเบียน" description="ห้องเรียนจะแสดงรายวิชาจากทะเบียนของภาคเรียนปัจจุบัน เมื่อมีการลงทะเบียนแล้วให้กลับเข้าหน้านี้อีกครั้ง" /> : <>
            <Panel><TextField select fullWidth label="รายวิชา" value={subject ? `${subject.level}|${subject.code}` : ''} onChange={event => leave(() => { setCourse(event.target.value); setActivityId(''); setActivityPage(1); setSubmissionPage(1); setKind('all'); })}>
                {data.subjects.map(item => <MenuItem key={`${item.level}|${item.code}`} value={`${item.level}|${item.code}`}>{item.code} · {item.name} · {item.level_label}</MenuItem>)}
            </TextField><p className="mt-2 text-sm" style={{ color: 'var(--ui-text-muted)' }}>{manage ? `${subject?.student_count ?? 0} คนลงทะเบียนในขอบเขตของคุณ` : 'แสดงเฉพาะกิจกรรมในรายวิชาและกลุ่มเรียนของคุณ'}</p></Panel>
            <div className="grid items-start gap-4 xl:grid-cols-[300px_minmax(0,1fr)]">
                <Panel title="กิจกรรมในรายวิชา"><div className="mb-3 flex flex-wrap gap-1" role="group" aria-label="ประเภทกิจกรรม">
                    {['all', ...Object.keys(kindLabels)].map(value => <Button key={value} appearance={kind === value ? 'primary' : 'subtle'} size="small" aria-pressed={kind === value} onClick={() => setKind(value)}>{value === 'all' ? 'ทั้งหมด' : kindLabels[value as ActivityKind]}</Button>)}
                </div><div className="space-y-2">
                    {data.activities.filter(item => kind === 'all' || item.type === kind).map(item => <button type="button" key={item.id} aria-current={selected?.id === item.id ? 'true' : undefined} onClick={() => select(item.id)}
                        className="w-full rounded-xl border p-3 text-left transition-colors hover:border-brand-500 active:scale-[0.99]" style={{ borderColor: selected?.id === item.id ? 'var(--ui-navigation)' : 'var(--ui-border)', background: selected?.id === item.id ? 'color-mix(in srgb, var(--ui-navigation) 8%, var(--ui-surface))' : 'var(--ui-surface)' }}>
                        <span className="text-xs font-bold text-brand-700">{kindLabels[item.type]} {item.status === 'draft' ? '· ฉบับร่าง' : ''}</span><span className="mt-1 block break-words font-semibold">{item.title}</span>
                        <span className="mt-2 block text-xs" style={{ color: 'var(--ui-text-muted)' }}>{manage ? `${item.submission_count} คนส่งแล้ว` : item.submission ? (item.submission.status === 'reviewed' ? 'ตรวจแล้ว' : 'ส่งแล้ว · รอครูตรวจ') : item.type === 'knowledge' ? 'เปิดอ่านบทเรียน' : 'ยังไม่ส่งคำตอบ'}</span>
                    </button>)}
                    {!data.activities.some(item => kind === 'all' || item.type === kind) && <p className="py-5 text-sm">ยังไม่มีกิจกรรมในหมวดนี้{manage ? ' กดเพิ่มกิจกรรมเพื่อเริ่มต้น' : ' กรุณารอครูเผยแพร่กิจกรรม'}</p>}
                </div><Pager page={data.activity_pagination} change={page => leave(() => { setActivityPage(page); setActivityId(''); })} /></Panel>
                <div className="min-w-0 space-y-4">{selected ? <>
                    <Panel title={selected.title} description={`${kindLabels[selected.type]} · ${selected.target_group || 'ทุกกลุ่มในขอบเขต'} · ${selected.status === 'draft' ? 'ฉบับร่าง' : 'เผยแพร่แล้ว'}`}
                        action={manage && selected.can_edit ? <><Button icon={<PencilSimple />} disabled={readOnly || selected.has_submissions} onClick={() => { save.reset(); setEditor(selected); }}>แก้ไข</Button><Button disabled={readOnly || status.isPending} onClick={() => status.mutate(selected)}>{selected.status === 'published' ? 'ซ่อนกิจกรรม' : 'เผยแพร่'}</Button><Button aria-label="ลบกิจกรรม" icon={<Trash />} disabled={readOnly || selected.has_submissions} onClick={() => setDeleteId(selected.id)} /></> : undefined}>
                        {selected.has_submissions && manage && <Alert severity="info" className="mb-4">มีผู้ส่งคำตอบแล้ว เนื้อหาและข้อคำถามถูกล็อก คุณยังซ่อนกิจกรรมหรือตรวจคำตอบได้</Alert>}
                        <p className="mb-4 text-sm" style={{ color: 'var(--ui-text-muted)' }}>{selected.type !== 'knowledge' ? `${dateLabel(selected.due_at)} · ${selected.max_score} คะแนน · ส่งได้ 1 ครั้ง` : 'ใบความรู้และสื่อประกอบการเรียน'}</p>
                        {selected.content && <p className="mb-5 whitespace-pre-wrap break-words leading-7">{selected.content}</p>}
                        <div className="space-y-4">{selected.links.map((link, i) => <LearningLink key={`${i}:${link.url}`} title={link.title} url={link.url} />)}</div>
                        {selected.type !== 'knowledge' && <AnswerForm key={`${role}:${district}:${selected.id}`} activity={selected} manage={manage} readOnly={readOnly} onDirty={setDirty} refresh={refresh} />}
                    </Panel>
                    {manage && selected.type !== 'knowledge' && <Panel title="คำตอบที่ส่งแล้ว" description={`${selected.submission_count} รายการ`}>
                        {data.submissions.length === 0 && <p className="text-sm">ยังไม่มีผู้ส่งคำตอบ</p>}
                        <div className="divide-y" style={{ borderColor: 'var(--ui-border)' }}>{data.submissions.map(submission => <div key={submission.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><div><p className="font-semibold">{submission.student_name}</p><p className="text-sm">{submission.student_code} · {submission.status === 'submitted' ? 'รอตรวจ' : `${submission.score} / ${submission.max_score} คะแนน`}</p></div><Button appearance="outline" disabled={!selected.can_edit} onClick={() => setReview(submission)}>ตรวจคำตอบ</Button></div>)}</div>
                        <Pager page={data.submission_pagination} change={setSubmissionPage} />
                    </Panel>}
                </> : <EmptyState title="พร้อมเริ่มต้นการเรียนรู้" description={manage ? 'เพิ่มใบความรู้ ใบงาน หรือแบบทดสอบในรายวิชาที่เลือก' : 'ครูยังไม่ได้เผยแพร่กิจกรรมสำหรับรายวิชานี้'} />}</div>
            </div>
        </>}
        {editor && subject && <ActivityEditor key={editor === 'new' ? 'new' : editor.id} activity={editor === 'new' ? null : editor} subject={subject} pending={save.isPending} error={save.error} onClose={() => leave(() => setEditor(null))} onDirty={() => setDirty(true)} onSave={values => save.mutate({ id: editor === 'new' ? undefined : editor.id, values })} />}
        {review && selected && <ReviewDialog submission={review} activity={selected} readOnly={readOnly} close={() => setReview(null)} refresh={refresh} />}
        <Dialog open={Boolean(deleteId)} onClose={() => { if (!remove.isPending) setDeleteId(''); }}><DialogTitle>ลบกิจกรรมนี้?</DialogTitle><DialogContent>กิจกรรมที่ยังไม่มีผู้ส่งคำตอบจะถูกลบออกจากห้องเรียน</DialogContent><DialogActions><Button disabled={remove.isPending} onClick={() => setDeleteId('')}>ยกเลิก</Button><Button appearance="primary" disabled={remove.isPending} onClick={() => remove.mutate()}>ยืนยันลบ</Button></DialogActions></Dialog>
    </div>;
}

function LearningLink({ title, url }: { title: string; url: string }) {
    const safe = safeClassroomUrl(url);
    const embed = youtubeEmbed(url);
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

function ActivityEditor({ activity, subject, pending, error, onClose, onDirty, onSave }: { activity: Activity | null; subject: Subject; pending: boolean; error: unknown; onClose: () => void; onDirty: () => void; onSave: (values: unknown) => void }) {
    const [title, setTitle] = useState(activity?.title ?? '');
    const [type, setType] = useState<ActivityKind>(activity?.type ?? 'knowledge');
    const [content, setContent] = useState(activity?.content ?? '');
    const [group, setGroup] = useState(activity?.target_group ?? '');
    const [status, setStatus] = useState(activity?.status ?? 'draft');
    const [due, setDue] = useState(() => { if (!activity?.due_at) return ''; const date = new Date(activity.due_at); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); });
    const [links, setLinks] = useState(activity?.links ?? []);
    const [questions, setQuestions] = useState<Question[]>(() => activity?.questions.length ? activity.questions : [newQuestion()]);
    const [uploading, setUploading] = useState(false);
    const submit = (event: FormEvent) => { event.preventDefault(); if (uploading) return; onSave({ title, type, content, subject_code: subject.code, education_level: subject.level, target_group: group, status,
        due_at: due ? new Date(due).toISOString() : null, links, questions: type === 'knowledge' ? [] : questions.map(({ image_url: _imageUrl, ...question }) => question) }); };
    return <Dialog open onClose={() => { if (!pending && !uploading) onClose(); }} fullWidth maxWidth="md"><form onSubmit={submit} onChange={onDirty}>
        <DialogTitle>{activity ? 'แก้ไขกิจกรรม' : 'เพิ่มกิจกรรม'} · {subject.name}</DialogTitle><DialogContent><div className="space-y-5 pt-2">
            <div className="grid gap-4 sm:grid-cols-2"><TextField select fullWidth label="ประเภทกิจกรรม" value={type} onChange={event => setType(event.target.value as ActivityKind)}>{Object.entries(kindLabels).map(([value, label]) => <MenuItem value={value} key={value}>{label}</MenuItem>)}</TextField><TextField select fullWidth label="กลุ่มเป้าหมาย" value={group} slotProps={{ inputLabel: { shrink: true }, select: { displayEmpty: true } }} onChange={event => setGroup(event.target.value)}><MenuItem value="">ทุกกลุ่มในขอบเขตของคุณ</MenuItem>{subject.groups.map(item => <MenuItem value={item.code} key={item.code}>{item.name || item.code}</MenuItem>)}</TextField></div>
            <TextField fullWidth required label="ชื่อกิจกรรม" value={title} slotProps={{ htmlInput: { maxLength: 220 } }} onChange={event => setTitle(event.target.value)} />
            <TextField fullWidth multiline minRows={5} label={type === 'knowledge' ? 'เนื้อหาใบความรู้' : 'คำชี้แจง'} value={content} slotProps={{ htmlInput: { maxLength: 30000 } }} onChange={event => setContent(event.target.value)} />
            <section className="space-y-3"><h3 className="font-bold">สื่อและลิงก์ประกอบ</h3><p className="text-sm">เพิ่ม YouTube, Google Drive, OneDrive หรือเว็บไซต์อื่น โดยใช้ลิงก์แชร์ที่ผู้เรียนเปิดดูได้ (สูงสุด 20 ลิงก์)</p>
                {links.map((link, i) => <div key={i} className="grid items-start gap-2 sm:grid-cols-[1fr_2fr_auto]"><TextField required label={`ชื่อสื่อ ${i + 1}`} value={link.title} onChange={event => setLinks(current => current.map((item, index) => index === i ? { ...item, title: event.target.value } : item))} /><TextField required type="url" label="ลิงก์ HTTP / HTTPS" value={link.url} onChange={event => setLinks(current => current.map((item, index) => index === i ? { ...item, url: event.target.value } : item))} /><Button aria-label={`ลบลิงก์ ${i + 1}`} icon={<Trash />} onClick={() => { setLinks(current => current.filter((_, index) => i !== index)); onDirty(); }} /></div>)}
                <Button appearance="outline" icon={<Plus />} disabled={links.length >= 20} onClick={() => { setLinks(current => [...current, { title: '', url: '' }]); onDirty(); }}>เพิ่มลิงก์สื่อ</Button>
            </section>
            {type !== 'knowledge' && <section className="space-y-4"><h3 className="font-bold">คำถาม · รวม {questions.reduce((sum, question) => sum + Number(question.points || 0), 0)} คะแนน</h3><p className="text-sm">เลือกหลายคำตอบได้คะแนนเมื่อตอบถูกครบชุด เติมคำเทียบข้อความโดยไม่สนตัวพิมพ์ใหญ่/เล็กและช่องว่างหัวท้าย ส่วนอัตนัยรอครูตรวจ</p>
                {questions.map((question, index) => <QuestionEditor key={question.id} question={question} index={index} disabled={pending || uploading} onUpload={setUploading} change={next => { setQuestions(current => current.map(item => item.id === question.id ? next : item)); onDirty(); }} remove={() => { setQuestions(current => current.filter(item => item.id !== question.id)); onDirty(); }} />)}
                <Button appearance="outline" icon={<Plus />} disabled={questions.length >= 100} onClick={() => { setQuestions(current => [...current, { ...newQuestion(), ...(type === 'worksheet' ? { type: 'essay' as QuestionType, options: [], answer: undefined } : {}) }]); onDirty(); }}>เพิ่มคำถาม</Button>
            </section>}
            <div className="grid gap-4 sm:grid-cols-2"><TextField select fullWidth label="สถานะ" value={status} onChange={event => setStatus(event.target.value as Activity['status'])}><MenuItem value="draft">ฉบับร่าง</MenuItem><MenuItem value="published">เผยแพร่</MenuItem></TextField>{type !== 'knowledge' && <TextField type="datetime-local" label="กำหนดส่ง (เว้นว่างได้)" value={due} slotProps={{ inputLabel: { shrink: true } }} onChange={event => setDue(event.target.value)} />}</div>
            {error != null && <Alert severity="error">{errorText(error)}</Alert>}
        </div></DialogContent><DialogActions><Button disabled={pending || uploading} onClick={onClose}>ยกเลิก</Button><Button type="submit" appearance="primary" disabled={pending || uploading || (type !== 'knowledge' && questions.length === 0)}>{uploading ? 'กำลังอัปโหลดภาพ…' : pending ? 'กำลังบันทึก…' : 'บันทึกกิจกรรม'}</Button></DialogActions>
    </form></Dialog>;
}

function QuestionEditor({ question, index, disabled, change, remove, onUpload }: { question: Question; index: number; disabled: boolean; change: (question: Question) => void; remove: () => void; onUpload: (uploading: boolean) => void }) {
    const upload = useMutation({ mutationFn: (file: File) => { const form = new FormData(); form.set('image', file); return sendFeatureData<{ id: string; url: string }>(`${base}/images`, 'POST', form); }, onMutate: () => onUpload(true), onSuccess: result => change({ ...question, image_id: result.data.id, image_url: result.data.url }), onSettled: () => onUpload(false) });
    const options = ['single_choice', 'multiple_choice'].includes(question.type);
    return <div className="space-y-3 rounded-xl border p-4" style={{ borderColor: 'var(--ui-border)' }}><div className="flex items-center justify-between"><h4 className="font-bold">ข้อ {index + 1}</h4><Button aria-label={`ลบคำถาม ${index + 1}`} icon={<Trash />} disabled={disabled || upload.isPending} onClick={remove} /></div>
        <div className="grid gap-3 sm:grid-cols-[2fr_1fr]"><TextField select label="ชนิดคำถาม" value={question.type} disabled={disabled || upload.isPending} onChange={event => { const type = event.target.value as QuestionType; change({ ...question, type, options: ['single_choice', 'multiple_choice'].includes(type) ? ['ตัวเลือก 1', 'ตัวเลือก 2'] : [], answer: type === 'multiple_choice' ? [] : type === 'true_false' ? 'true' : type === 'single_choice' ? 'ตัวเลือก 1' : undefined }); }}>{Object.entries(questionLabels).map(([type, label]) => <MenuItem value={type} key={type}>{label}</MenuItem>)}</TextField><TextField required label="คะแนน" type="number" value={question.points} disabled={disabled} slotProps={{ htmlInput: { min: 0.01, max: 1000, step: 0.01 } }} onChange={event => change({ ...question, points: Number(event.target.value) })} /></div>
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
