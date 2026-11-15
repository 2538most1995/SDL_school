import { Alert, Dialog, DialogActions, DialogContent, DialogTitle, MenuItem, TextField } from '@mui/material';
import { Archive, ArrowRight, Books, MagnifyingGlass, Plus, Trash } from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '../../../components/MaterialUI';
import { QueryError, QuerySkeleton } from '../../../components/QueryState';
import { getFeatureData, sendFeatureData } from '../../api';
import type { PageInfo, Subject } from './types';
import './lesson-library.css';

type Template = { id: string; title: string; academic_term: string; subject_code: string; subject_name: string; education_level: number; content_count: number; created_at: string };
type SourceUnit = Omit<Template, 'content_count' | 'created_at'> & { target_group: string };
type Props = { subjects: Subject[]; role: string; district: string; readOnly: boolean; initialUnitId?: string; onRestored: (unitId: string, subject: Subject) => void; onBusy?: (busy: boolean) => void };
const base = '/api/v1/learning/classroom/library';
const message = (error: unknown) => error instanceof Error ? error.message : 'ดำเนินการไม่สำเร็จ กรุณาลองอีกครั้ง';
const sourceTermLabel = (term: string) => (term === 'library' || term === 'ไม่ระบุ') ? 'สื่อเดิมที่ไม่ผูกภาคเรียน' : `ภาคเรียน ${term}`;
const courseKey = (subject: Subject) => `${subject.level}|${subject.code}`;
const levelLabel = (level: number) => ({ 1: 'ประถมศึกษา', 2: 'มัธยมศึกษาตอนต้น', 3: 'มัธยมศึกษาตอนปลาย' })[level] ?? '';

export function ClassroomLessonLibrary({ subjects, role, district, readOnly, initialUnitId, onRestored, onBusy }: Props) {
    const client = useQueryClient();
    const manage = role !== 'student';
    const [searchText, setSearchText] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [sourcesOpen, setSourcesOpen] = useState(Boolean(initialUnitId && initialUnitId !== 'legacy'));
    const [restoring, setRestoring] = useState<Template | null>(null);
    const [deleting, setDeleting] = useState<Template | null>(null);
    const params = new URLSearchParams({ search, page: String(page) });
    const query = useQuery({ queryKey: ['classroom-lesson-library', role, district, search, page], enabled: manage, queryFn: ({ signal }) => getFeatureData<{ templates: Template[]; pagination: PageInfo }>(`${base}?${params}`, signal) });
    const remove = useMutation({ mutationFn: () => sendFeatureData(`${base}/${deleting!.id}`, 'DELETE'), onSuccess: async () => { setDeleting(null); await client.invalidateQueries({ queryKey: ['classroom-lesson-library'] }); } });
    useEffect(() => { onBusy?.(remove.isPending); return () => onBusy?.(false); }, [remove.isPending, onBusy]);
    if (!manage) return null;
    const submitSearch = (event: FormEvent) => { event.preventDefault(); setPage(1); setSearch(searchText.trim()); };
    return <section className="classroom-lesson-library" aria-label="คลังบทเรียน">
        <div className="classroom-library-heading"><div><h2><Archive size={24} /> คลังบทเรียน</h2><p>เก็บบทเรียนพร้อมสื่อไว้ใช้ซ้ำ แล้วนำไปใช้กับรายวิชาและกลุ่มเรียนของภาคเรียนใหม่</p></div><Button appearance="primary" icon={<Plus size={19} />} disabled={readOnly} onClick={() => setSourcesOpen(true)}>เก็บบทเรียนเข้าคลัง</Button></div>
        <Alert severity="info">บทเรียนในคลังเก็บแยกจากต้นฉบับ การนำกลับมาใช้จะสร้างบทเรียนฉบับร่างใหม่ พร้อมสื่อและข้อคำถาม โดยเริ่มคำตอบ คะแนน และกำหนดส่งใหม่</Alert>
        <form className="classroom-library-search" onSubmit={submitSearch}><TextField fullWidth size="small" label="ค้นหาบทเรียนในคลัง" value={searchText} onChange={event => setSearchText(event.target.value)} /><Button type="submit" appearance="outline" icon={<MagnifyingGlass size={18} />}>ค้นหา</Button></form>
        {query.isPending ? <QuerySkeleton rows={3} /> : query.isError ? <QueryError message={message(query.error)} onRetry={() => void query.refetch()} /> : query.data && <>
            {query.data.data.templates.length === 0 ? <div className="classroom-library-empty"><Books size={36} /><h3>{search ? 'ไม่พบบทเรียนที่ค้นหา' : 'ยังไม่มีบทเรียนในคลัง'}</h3><p>{search ? 'ลองค้นหาด้วยชื่อหรือรหัสวิชาอื่น' : 'เลือกเก็บบทเรียนปัจจุบันหรือบทเรียนจากภาคเรียนก่อน เพื่อเตรียมนำกลับมาใช้'}</p></div> : <div className="classroom-library-list">{query.data.data.templates.map(template => <article key={template.id} className="classroom-library-row"><div className="classroom-library-copy"><h3>{template.title}</h3><p>{template.subject_code} · {template.subject_name} · {levelLabel(template.education_level)}</p><small>ต้นทาง: {sourceTermLabel(template.academic_term)} · {template.content_count} รายการ</small></div><div className="classroom-library-actions"><Button appearance="outline" icon={<ArrowRight size={18} />} disabled={readOnly || subjects.length === 0} onClick={() => setRestoring(template)}>นำมาใช้</Button><Button aria-label={`ลบ ${template.title} ออกจากคลัง`} icon={<Trash size={18} />} disabled={readOnly} onClick={() => { remove.reset(); setDeleting(template); }} /></div></article>)}</div>}
            <LibraryPager page={query.data.data.pagination} change={setPage} />
        </>}
        {subjects.length === 0 && <Alert severity="warning">ยังไม่มีรายวิชาที่ลงทะเบียนในภาคเรียนปัจจุบัน คุณยังเก็บบทเรียนเข้าคลังได้ และนำกลับมาใช้ได้หลังมีรายวิชา</Alert>}
        {sourcesOpen && <SourceDialog role={role} district={district} initialUnitId={initialUnitId} readOnly={readOnly} onBusy={onBusy} close={() => setSourcesOpen(false)} saved={async () => { setSourcesOpen(false); setPage(1); await client.invalidateQueries({ queryKey: ['classroom-lesson-library'] }); }} />}
        {restoring && <RestoreDialog template={restoring} subjects={subjects} role={role} readOnly={readOnly} onBusy={onBusy} close={() => setRestoring(null)} restored={async (id, subject) => { setRestoring(null); await Promise.all([client.invalidateQueries({ queryKey: ['classroom-units'] }), client.invalidateQueries({ queryKey: ['online-classroom'] })]); onRestored(id, subject); }} />}
        <Dialog className="classroom-dialog" open={Boolean(deleting)} onClose={() => { if (!remove.isPending) setDeleting(null); }}><DialogTitle>ลบบทเรียนออกจากคลัง?</DialogTitle><DialogContent><p>ลบ “{deleting?.title}” และสื่อสำเนาในคลัง บทเรียนที่นำไปใช้แล้วและบทเรียนต้นฉบับยังอยู่ตามเดิม</p>{remove.isError && <Alert severity="error">{message(remove.error)}</Alert>}</DialogContent><DialogActions><Button disabled={remove.isPending} onClick={() => setDeleting(null)}>ยกเลิก</Button><Button appearance="primary" disabled={remove.isPending || readOnly} onClick={() => remove.mutate()}>ยืนยันลบจากคลัง</Button></DialogActions></Dialog>
    </section>;
}

function LibraryPager({ page, change }: { page: PageInfo; change: (page: number) => void }) {
    return page.last_page > 1 ? <nav className="classroom-library-pagination" aria-label="แบ่งหน้าคลังบทเรียน"><Button disabled={page.current_page <= 1} onClick={() => change(page.current_page - 1)}>ก่อนหน้า</Button><span>หน้า {page.current_page}/{page.last_page} · {page.total} รายการ</span><Button disabled={page.current_page >= page.last_page} onClick={() => change(page.current_page + 1)}>ถัดไป</Button></nav> : null;
}

function SourceDialog({ role, district, initialUnitId, readOnly, close, saved, onBusy }: { role: string; district: string; initialUnitId?: string; readOnly: boolean; close: () => void; saved: () => Promise<void>; onBusy?: (busy: boolean) => void }) {
    const [searchText, setSearchText] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [sourceId, setSourceId] = useState(initialUnitId && initialUnitId !== 'legacy' ? initialUnitId : '');
    const [title, setTitle] = useState('');
    const params = new URLSearchParams({ search, page: String(page) });
    const query = useQuery({ queryKey: ['classroom-library-sources', role, district, search, page], queryFn: ({ signal }) => getFeatureData<{ units: SourceUnit[]; pagination: PageInfo }>(`${base}/sources?${params}`, signal) });
    const save = useMutation({ mutationFn: () => sendFeatureData<{ id: string }>(base, 'POST', { unit_id: sourceId, ...(title.trim() ? { title: title.trim() } : {}) }), onSuccess: saved });
    useEffect(() => { onBusy?.(save.isPending); return () => onBusy?.(false); }, [save.isPending, onBusy]);
    return <Dialog className="classroom-dialog" open fullWidth maxWidth="md" onClose={() => { if (!save.isPending) close(); }}><DialogTitle>เก็บบทเรียนเข้าคลัง</DialogTitle><DialogContent><div className="classroom-library-dialog-body"><p>เลือกบทเรียนปัจจุบันหรือภาคเรียนก่อนที่คุณดูแล ระบบจะเก็บสำเนาเนื้อหาและสื่อไว้ในคลัง</p><form className="classroom-library-search" onSubmit={event => { event.preventDefault(); setSearch(searchText.trim()); setPage(1); }}><TextField fullWidth size="small" label="ค้นหาชื่อบทเรียนหรือรหัสวิชา" value={searchText} disabled={save.isPending} onChange={event => setSearchText(event.target.value)} /><Button type="submit" disabled={save.isPending} icon={<MagnifyingGlass size={18} />}>ค้นหา</Button></form>
        {query.isPending ? <QuerySkeleton rows={3} /> : query.isError ? <QueryError message={message(query.error)} onRetry={() => void query.refetch()} /> : query.data && <>
            {!query.data.data.units.length && <p>ไม่มีบทเรียนในขอบเขตที่รับผิดชอบตามคำค้นนี้</p>}
            <div className="classroom-library-sources" role="group" aria-label="เลือกบทเรียนต้นทาง">{query.data.data.units.map(unit => <label key={unit.id} className="classroom-library-source" data-selected={sourceId === unit.id}><input type="radio" name="library-source-unit" value={unit.id} checked={sourceId === unit.id} disabled={save.isPending} onChange={() => setSourceId(unit.id)} /><span><strong>{unit.title}</strong><small>{unit.subject_code} · {unit.subject_name} · {sourceTermLabel(unit.academic_term)} · {levelLabel(unit.education_level)}{unit.target_group ? ` · กลุ่ม ${unit.target_group}` : ''}</small></span></label>)}</div>
            <LibraryPager page={query.data.data.pagination} change={setPage} />
        </>}
        {sourceId && <p className="classroom-library-selected" role="status">เลือกบทเรียนแล้ว{!query.data?.data.units.some(unit => unit.id === sourceId) ? ' (รายการที่เลือกอยู่นอกหน้านี้)' : `: ${query.data.data.units.find(unit => unit.id === sourceId)?.title}`}</p>}
        <TextField label="ชื่อในคลัง (เว้นว่างเพื่อใช้ชื่อเดิม)" fullWidth value={title} disabled={save.isPending} slotProps={{ htmlInput: { maxLength: 220 } }} onChange={event => setTitle(event.target.value)} />
        {save.isError && <Alert severity="error">{message(save.error)}</Alert>}
    </div></DialogContent><DialogActions><Button disabled={save.isPending} onClick={close}>ยกเลิก</Button><Button appearance="primary" disabled={!sourceId || save.isPending || readOnly} onClick={() => save.mutate()}>{save.isPending ? 'กำลังเก็บบทเรียนและสื่อ…' : 'เก็บเข้าคลัง'}</Button></DialogActions></Dialog>;
}

function RestoreDialog({ template, subjects, role, readOnly, close, restored, onBusy }: { template: Template; subjects: Subject[]; role: string; readOnly: boolean; close: () => void; restored: (id: string, subject: Subject) => Promise<void>; onBusy?: (busy: boolean) => void }) {
    const initialSubject = subjects.find(subject => subject.code === template.subject_code && subject.level === template.education_level) ?? subjects[0];
    const [selectedCourse, setSelectedCourse] = useState(initialSubject ? courseKey(initialSubject) : '');
    const subject = subjects.find(item => courseKey(item) === selectedCourse);
    const [group, setGroup] = useState(role === 'teacher' ? initialSubject?.groups[0]?.code ?? '' : '');
    const [title, setTitle] = useState(template.title);
    const restore = useMutation({ mutationFn: () => sendFeatureData<{ unit_id: string }>(`${base}/${template.id}/restore`, 'POST', { subject_code: subject!.code, education_level: subject!.level, target_group: group, title: title.trim() }), onSuccess: result => restored(result.data.unit_id, subject!) });
    useEffect(() => { onBusy?.(restore.isPending); return () => onBusy?.(false); }, [restore.isPending, onBusy]);
    return <Dialog className="classroom-dialog" open fullWidth maxWidth="sm" onClose={() => { if (!restore.isPending) close(); }}><DialogTitle>นำบทเรียนจากคลังมาใช้</DialogTitle><DialogContent><div className="classroom-library-dialog-body"><p>ต้นฉบับในคลัง: <strong>{template.title}</strong></p><TextField select fullWidth label="รายวิชาในภาคเรียนปัจจุบัน" value={selectedCourse} disabled={restore.isPending} onChange={event => { const value = event.target.value; setSelectedCourse(value); const next = subjects.find(item => courseKey(item) === value); setGroup(role === 'teacher' ? next?.groups[0]?.code ?? '' : ''); }}>{subjects.map(item => <MenuItem key={courseKey(item)} value={courseKey(item)}>{item.code} · {item.name} · {item.level_label}</MenuItem>)}</TextField><TextField select fullWidth required={role === 'teacher'} label="กลุ่มเรียนปลายทาง" value={group} disabled={restore.isPending || !subject} onChange={event => setGroup(event.target.value)}>{role !== 'teacher' && <MenuItem value="">ทุกกลุ่มในรายวิชา</MenuItem>}{subject?.groups.map(item => <MenuItem key={item.code} value={item.code}>{item.name || item.code}</MenuItem>)}</TextField><TextField required fullWidth label="ชื่อบทเรียนใหม่" value={title} disabled={restore.isPending} slotProps={{ htmlInput: { maxLength: 220 } }} onChange={event => setTitle(event.target.value)} /><Alert severity="info">สร้างเป็นฉบับร่างเพื่อให้ตรวจสื่อ ข้อคำถาม และกำหนดวันส่งก่อนเผยแพร่ให้ผู้เรียนชุดใหม่</Alert>{restore.isError && <Alert severity="error">{message(restore.error)}</Alert>}</div></DialogContent><DialogActions><Button disabled={restore.isPending} onClick={close}>ยกเลิก</Button><Button appearance="primary" disabled={readOnly || restore.isPending || !subject || !title.trim() || (role === 'teacher' && !group)} onClick={() => restore.mutate()}>{restore.isPending ? 'กำลังสร้างบทเรียน…' : 'สร้างบทเรียนจากคลัง'}</Button></DialogActions></Dialog>;
}
