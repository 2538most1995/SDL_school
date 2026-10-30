import { Alert, Dialog, DialogActions, DialogContent, DialogTitle, TextField } from '@mui/material';
import { ArrowRight, CheckCircle, ClipboardText, Clock, Plus } from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '../../../components/MaterialUI';
import { QueryError, QuerySkeleton } from '../../../components/QueryState';
import { getFeatureData, sendFeatureData } from '../../api';
import { kindLabels, type Activity, type ActivityKind, type PageInfo, type Subject } from './types';
import './tasks.css';

const base = '/api/v1/learning/classroom/tasks';
const errorText = (error: unknown) => error instanceof Error ? error.message : 'ดำเนินการไม่สำเร็จ กรุณาลองอีกครั้ง';
const dueLabel = (date: string) => new Date(date).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
const statusLabels = { pending: 'ยังไม่ทำ', in_progress: 'ทำบางส่วน', completed: 'ทำครบแล้ว' };
type Progress = { overdue?: boolean; late?: boolean; completed: number; total: number; status: keyof typeof statusLabels };
export type TaskItem = { id: string; unit_id?: string | null; kind: 'activity' | 'assignment'; title: string; type: ActivityKind; target_group?: string; completed?: boolean };
export type ClassroomTask = { id: string; unit_id?: string | null; title: string; instructions: string; due_at: string; subject_name?: string; subject_code: string; education_level: number; target_group: string; item_count: number; completed_count: number; student_count: number; progress: Progress | null };
type TaskDetail = ClassroomTask & { items: TaskItem[]; students: (Progress & { student_code: string; student_name: string; group_code: string; group_name: string })[]; pagination: PageInfo | null };
type Options = { items: TaskItem[]; groups: { code: string; name: string }[] };
type Props = { unitId?: string; unitTargetGroup?: string; subject?: Subject; manage: boolean; role: string; district: string; readOnly: boolean; onDirty?: (dirty: boolean) => void; onBusy?: (busy: boolean) => void; onOpen: (item: TaskItem, task: ClassroomTask) => void };

export function ClassroomTasks({ subject, manage, role, district, readOnly, unitId, unitTargetGroup, onDirty, onBusy, onOpen }: Props) {
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState('');
    const [creating, setCreating] = useState(false);
    const params = new URLSearchParams({ page: String(page) });
    if (unitId) params.set('unit_id', unitId);
    if (subject) { params.set('subject_code', subject.code); params.set('education_level', String(subject.level)); }
    const query = useQuery({ queryKey: ['classroom-tasks', role, district, subject?.level, subject?.code, unitId, page], queryFn: ({ signal }) => getFeatureData<{ tasks: ClassroomTask[]; pagination: PageInfo }>(`${base}?${params}`, signal) });
    return <section className="classroom-tasks" aria-label={manage ? 'งานที่มอบหมาย' : 'งานที่ครูสั่ง'}>
        <div className="classroom-tasks-heading"><div><span className="classroom-step"><ClipboardText size={19} /> {manage ? 'มอบหมายและติดตาม' : 'งานของฉัน'}</span><h3>{manage ? 'งานที่มอบหมาย' : 'งานที่ครูสั่ง'}</h3><p>{manage ? 'เลือกกิจกรรม กำหนดส่ง แล้วติดตามผู้เรียนรายคน' : 'เปิดงาน ดูสิ่งที่ต้องทำ แล้วทำกิจกรรมให้ครบตามที่ครูกำหนด'}</p></div>{manage && subject && <Button appearance="primary" icon={<Plus size={19} />} disabled={readOnly} onClick={() => setCreating(true)}>สั่งงาน</Button>}</div>
        {query.isPending ? <QuerySkeleton rows={2} /> : query.isError ? <QueryError message={errorText(query.error)} onRetry={() => void query.refetch()} /> : <>
            {query.data.data.tasks.length === 0 && <p className="classroom-muted">{manage ? 'ยังไม่มีงานที่มอบหมายในห้องเรียนนี้' : 'ยังไม่มีงานที่ครูสั่งในขณะนี้'}</p>}
            <div className="classroom-task-list">{query.data.data.tasks.map(task => {
                const progress = task.progress;
                const overdue = new Date(task.due_at).getTime() < Date.now() && progress?.status !== 'completed';
                return <button type="button" key={task.id} className="classroom-task-card" onClick={() => setSelected(task.id)}>
                    <span className="classroom-task-card-top"><span className="classroom-task-subject">{subject?.name ?? task.subject_name ?? task.subject_code}</span><span className="classroom-task-state" data-state={progress?.status ?? 'pending'}>{progress ? statusLabels[progress.status] : `${task.completed_count}/${task.student_count} คนทำครบ`}</span></span>
                    <strong>{task.title}</strong><span className="classroom-task-deadline" data-overdue={overdue}><Clock size={16} /> กำหนดส่ง {dueLabel(task.due_at)}{progress?.overdue ? ' · เลยกำหนดส่ง' : progress?.late ? ' · ส่งช้า' : ''}</span>
                    <span className="classroom-task-card-bottom"><span>{progress ? `ทำแล้ว ${progress.completed}/${progress.total} กิจกรรม` : `${task.item_count} กิจกรรม · ${task.target_group || (role === 'teacher' ? 'กลุ่มที่รับผิดชอบ' : 'ทุกกลุ่มที่รับผิดชอบ')}`}</span><span>{manage ? 'ดูรายชื่อผู้เรียน' : 'ดูงาน'} <ArrowRight size={17} /></span></span>
                </button>;
            })}</div>
            <TaskPager page={query.data.data.pagination} change={setPage} />
        </>}
        {creating && subject && <TaskEditor unitId={unitId} unitTargetGroup={unitTargetGroup} onDirty={onDirty} onBusy={onBusy} subject={subject} role={role} district={district} close={() => setCreating(false)} saved={(id) => { setCreating(false); setSelected(id); }} />}
        {selected && <TaskDialog key={`${selected}:${role}:${district}`} id={selected} manage={manage} role={role} district={district} close={() => setSelected('')} openItem={onOpen} />}
    </section>;
}

function TaskPager({ page, change }: { page: PageInfo | null | undefined; change: (page: number) => void }) {
    return page && page.last_page > 1 ? <nav aria-label="แบ่งหน้างาน" className="classroom-task-pagination"><Button disabled={page.current_page <= 1} onClick={() => change(page.current_page - 1)}>ก่อนหน้า</Button><span>หน้า {page.current_page}/{page.last_page} · {page.total} รายการ</span><Button disabled={page.current_page >= page.last_page} onClick={() => change(page.current_page + 1)}>ถัดไป</Button></nav> : null;
}

function TaskEditor({ subject, role, district, close, saved, onDirty, onBusy, unitId, unitTargetGroup }: { unitId?: string; unitTargetGroup?: string; onDirty?: (dirty: boolean) => void; onBusy?: (busy: boolean) => void; subject: Subject; role: string; district: string; close: () => void; saved: (id: string) => void }) {
    const client = useQueryClient();
    const [title, setTitle] = useState('');
    const [instructions, setInstructions] = useState('');
    const [due, setDue] = useState('');
    const [group, setGroup] = useState(unitTargetGroup || (role === 'teacher' ? subject.groups[0]?.code ?? '' : ''));
    const [mode, setMode] = useState<'all' | 'selected'>('all');
    const [checked, setChecked] = useState<string[]>([]);
    const [filter, setFilter] = useState<ActivityKind | 'all'>('all');
    const [changed, setChanged] = useState(false);
    const params = new URLSearchParams({ subject_code: subject.code, education_level: String(subject.level), target_group: group });
    if (unitId) params.set('unit_id', unitId);
    const options = useQuery({ queryKey: ['classroom-task-options', role, district, subject.level, subject.code, unitId, group], queryFn: ({ signal }) => getFeatureData<Options>(`${base}/options?${params}`, signal) });
    const available = options.data?.data.items ?? [];
    const save = useMutation({ mutationFn: () => sendFeatureData<{ id: string }>(base, 'POST', { ...(unitId ? { unit_id: unitId } : {}), subject_code: subject.code, education_level: subject.level, title: title.trim(), instructions: instructions.trim(), due_at: new Date(due).toISOString(), target_group: group, selection_mode: mode, activity_ids: available.filter(item => item.kind === 'activity' && checked.includes(`activity:${item.id}`)).map(item => item.id), assignment_ids: available.filter(item => item.kind === 'assignment' && checked.includes(`assignment:${item.id}`)).map(item => item.id) }), onSuccess: async (result) => { await Promise.all([client.invalidateQueries({ queryKey: ['classroom-tasks'] }), client.invalidateQueries({ queryKey: ['classroom-units'] }), client.invalidateQueries({ queryKey: ['online-classroom'] })]); saved(result.data.id); } });
    useEffect(() => { onDirty?.(changed); return () => onDirty?.(false); }, [changed, onDirty]);
    useEffect(() => { onBusy?.(save.isPending); return () => onBusy?.(false); }, [save.isPending, onBusy]);
    const requestClose = () => { if (!save.isPending && (!changed || window.confirm('ยกเลิกงานที่ยังไม่ได้บันทึกหรือไม่?'))) close(); };
    const selectedCount = available.filter(item => checked.includes(`${item.kind}:${item.id}`)).length;
    const submit = (event: FormEvent) => { event.preventDefault(); if (due && !options.isFetching) save.mutate(); };
    return <Dialog className="classroom-dialog classroom-task-dialog" open fullWidth maxWidth="md" onClose={requestClose}><DialogTitle>สั่งงาน · {subject.name}</DialogTitle><form className="classroom-task-editor-body" onSubmit={submit} onChange={() => setChanged(true)}><DialogContent className="classroom-task-form">
        <TextField required fullWidth label="ชื่องาน" value={title} onChange={event => setTitle(event.target.value)} disabled={save.isPending} slotProps={{ htmlInput: { maxLength: 220 } }} />
        <TextField fullWidth multiline minRows={2} label="คำชี้แจงสำหรับผู้เรียน" value={instructions} onChange={event => setInstructions(event.target.value)} disabled={save.isPending} slotProps={{ htmlInput: { maxLength: 5000 } }} />
        <TextField required fullWidth type="datetime-local" label="กำหนดส่ง" value={due} onChange={event => setDue(event.target.value)} disabled={save.isPending} slotProps={{ inputLabel: { shrink: true } }} helperText="วันและเวลาตามอุปกรณ์ของคุณ" />
        <fieldset disabled={save.isPending}><legend>มอบหมายให้กลุ่มเรียน</legend><div className="classroom-choice-buttons">{role !== 'teacher' && !unitTargetGroup && <button type="button" aria-pressed={!group} onClick={() => { setGroup(''); setChecked([]); setChanged(true); }}>ทุกกลุ่มที่รับผิดชอบ</button>}{subject.groups.map(item => <button key={item.code} type="button" aria-pressed={group === item.code} onClick={() => { setGroup(item.code); setChecked([]); setChanged(true); }}>{item.name || item.code}</button>)}</div></fieldset>
        <fieldset disabled={save.isPending}><legend>สิ่งที่ต้องทำ</legend><div className="classroom-choice-buttons"><button type="button" aria-pressed={mode === 'all'} onClick={() => { setMode('all'); setChanged(true); }}>ทำครบทุกรายการ</button><button type="button" aria-pressed={mode === 'selected'} onClick={() => { setMode('selected'); setChanged(true); }}>เลือกเฉพาะรายการ</button></div><p className="classroom-muted">{mode === 'all' ? 'รวมกิจกรรมที่เผยแพร่และใบงานส่งไฟล์ที่เปิดอยู่ขณะสั่งงาน กิจกรรมที่เพิ่มภายหลังจะไม่ถูกเพิ่มในงานนี้' : 'เลือกใบงาน แบบทดสอบ หรือกิจกรรมที่ต้องการให้ผู้เรียนทำ'}</p></fieldset>
        {options.isPending ? <QuerySkeleton rows={2} /> : options.isError ? <Alert severity="error">{errorText(options.error)} <Button onClick={() => void options.refetch()}>ลองใหม่</Button></Alert> : <>
            {mode === 'selected' && <div className="classroom-choice-buttons" role="group" aria-label="กรองกิจกรรมสำหรับสั่งงาน">{(['all', 'video', 'knowledge', 'worksheet', 'quiz'] as const).map(type => <button type="button" key={type} aria-pressed={filter === type} onClick={() => setFilter(type)}>{type === 'all' ? 'ทั้งหมด' : kindLabels[type]}</button>)}</div>}
            <div className="classroom-task-options">{available.filter(item => filter === 'all' || mode === 'all' || item.type === filter).map(item => <label className="classroom-task-option" key={`${item.kind}:${item.id}`}><input type="checkbox" checked={mode === 'all' || checked.includes(`${item.kind}:${item.id}`)} disabled={mode === 'all' || save.isPending || options.isFetching} onChange={event => { const key = `${item.kind}:${item.id}`; setChecked(old => event.target.checked ? [...old, key] : old.filter(value => value !== key)); }} /><span><strong>{item.title}</strong><small>{kindLabels[item.type]}{item.kind === 'assignment' ? ' · ส่งไฟล์ / ลิงก์' : ''}{item.target_group ? ` · ${item.target_group}` : ''}</small></span></label>)}</div>
            {!available.length && <Alert severity="info">ยังไม่มีกิจกรรมที่มอบหมายให้กลุ่มนี้ได้ กรุณาสร้างและเผยแพร่กิจกรรมก่อน</Alert>}
            <p className="classroom-muted">มอบหมาย {mode === 'all' ? available.length : selectedCount} กิจกรรม</p>
        </>}
        {save.isError && <Alert severity="error">{errorText(save.error)}</Alert>}
    </DialogContent><DialogActions><Button disabled={save.isPending} onClick={requestClose}>ยกเลิก</Button><Button type="submit" appearance="primary" disabled={save.isPending || options.isFetching || options.isError || (role === 'teacher' && !group) || !title.trim() || !due || !available.length || (mode === 'selected' && !selectedCount)}>{save.isPending ? 'กำลังสั่งงาน…' : 'ยืนยันสั่งงาน'}</Button></DialogActions></form></Dialog>;
}

function TaskDialog({ id, manage, role, district, close, openItem }: { id: string; manage: boolean; role: string; district: string; close: () => void; openItem: Props['onOpen'] }) {
    const [page, setPage] = useState(1);
    const [filter, setFilter] = useState('all');
    const params = new URLSearchParams({ page: String(page), status: filter });
    const query = useQuery({ queryKey: ['classroom-tasks', 'detail', role, district, id, page, filter], queryFn: ({ signal }) => getFeatureData<TaskDetail>(`${base}/${id}?${params}`, signal) });
    const data = query.data?.data;
    return <Dialog className="classroom-dialog classroom-task-dialog" open fullWidth maxWidth="md" onClose={close}><DialogTitle>{data?.title ?? 'รายละเอียดงาน'}</DialogTitle><DialogContent>
        {query.isPending ? <QuerySkeleton rows={3} /> : query.isError ? <QueryError message={errorText(query.error)} onRetry={() => void query.refetch()} /> : data && <div className="classroom-task-detail">
            <p className="classroom-task-deadline"><Clock size={18} /> กำหนดส่ง {dueLabel(data.due_at)}</p>
            {data.instructions && <p className="whitespace-pre-wrap break-words">{data.instructions}</p>}
            {data.progress && <Alert severity={data.progress.status === 'completed' ? 'success' : 'info'}>{statusLabels[data.progress.status]} · {data.progress.completed}/{data.progress.total} กิจกรรม</Alert>}
            <h3 className="font-bold">กิจกรรมที่ต้องทำ</h3><div className="classroom-task-options">{data.items.map(item => <button type="button" key={`${item.kind}:${item.id}`} className="classroom-task-option" onClick={() => { close(); openItem(item, data); }}><CheckCircle size={22} weight={item.completed ? 'fill' : 'regular'} /><span><strong>{item.title}</strong><small>{kindLabels[item.type]}{item.kind === 'assignment' ? ' · ส่งไฟล์ / ลิงก์' : ''}{item.completed ? ' · ทำแล้ว' : ''}</small></span><ArrowRight size={18} /></button>)}</div>
            {manage && <><h3 className="font-bold">ติดตามผู้เรียน · ทำครบ {data.completed_count}/{data.student_count} คน</h3><div className="classroom-choice-buttons" role="group" aria-label="กรองสถานะผู้เรียน">{['all', 'pending', 'in_progress', 'completed'].map(status => <button type="button" key={status} aria-pressed={filter === status} onClick={() => { setFilter(status); setPage(1); }}>{status === 'all' ? 'ทุกคน' : statusLabels[status as keyof typeof statusLabels]}</button>)}</div><div className="classroom-task-roster">{data.students.map(student => <div className="classroom-task-student" key={student.student_code}><div><strong>{student.student_name}</strong><small>{student.student_code} · {student.group_name || student.group_code}</small></div><div><span className="classroom-task-state" data-state={student.status}>{statusLabels[student.status]}</span><small>{student.completed}/{student.total} กิจกรรม{student.overdue ? ' · เลยกำหนด' : student.late ? ' · ส่งช้า' : ''}</small></div></div>)}{!data.students.length && <p className="classroom-muted">ไม่มีผู้เรียนในสถานะนี้</p>}</div><TaskPager page={data.pagination} change={setPage} /></>}
        </div>}
    </DialogContent><DialogActions><Button onClick={close}>ปิด</Button></DialogActions></Dialog>;
}

export function TaskCompletion({ activity, taskId, role, district, readOnly }: { activity: Activity; taskId: string; role: string; district: string; readOnly: boolean }) {
    const client = useQueryClient();
    const task = useQuery({ queryKey: ['classroom-tasks', 'completion', role, district, taskId], enabled: Boolean(taskId), queryFn: ({ signal }) => getFeatureData<TaskDetail>(`${base}/${taskId}`, signal) });
    const item = task.data?.data.items.find(item => item.kind === 'activity' && item.id === activity.id);
    const complete = useMutation({ mutationFn: () => sendFeatureData(`${base}/${taskId}/activities/${activity.id}/complete`, 'POST'), onSuccess: () => client.invalidateQueries({ queryKey: ['classroom-tasks'] }) });
    if (!taskId || (!item && !task.isError)) return null;
    return <div className="classroom-task-completion">{task.isError ? <Alert severity="warning">โหลดสถานะงานไม่สำเร็จ <Button onClick={() => void task.refetch()}>ลองใหม่</Button></Alert> : <><p>งานที่ครูสั่ง: {task.data?.data.title}</p><Button appearance="primary" icon={<CheckCircle />} disabled={readOnly || complete.isPending || item?.completed} onClick={() => complete.mutate()}>{item?.completed ? 'บันทึกว่าทำแล้ว' : activity.type === 'video' ? 'ยืนยันว่าดูคลิปแล้ว' : 'ยืนยันว่าอ่านแล้ว'}</Button><p className="classroom-muted">สถานะนี้เป็นการยืนยันจากผู้เรียน</p></>}{complete.isError && <Alert severity="error">{errorText(complete.error)}</Alert>}</div>;
}
