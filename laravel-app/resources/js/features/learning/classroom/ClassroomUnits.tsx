import { Alert, Dialog, DialogActions, DialogContent, DialogTitle, TextField } from '@mui/material';
import { ArrowLeft, BookOpenText, CaretDown, CaretUp, CheckCircle, FolderOpen, PencilSimple, Plus, Trash } from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Button } from '../../../components/MaterialUI';
import { QueryError, QuerySkeleton } from '../../../components/QueryState';
import { getFeatureData, sendFeatureData } from '../../api';
import type { ActivityKind, PageInfo, Subject } from './types';
import './units.css';

export type ClassroomUnit = { id: string; title: string; description: string; status: 'draft' | 'published'; target_group: string; subject_code: string; education_level: number; can_edit: boolean; can_delete: boolean; content_count: number; activity_counts: Record<ActivityKind | 'all', number>; is_legacy?: boolean };
const base = '/api/v1/learning/classroom/units';
const message = (error: unknown) => error instanceof Error ? error.message : 'ดำเนินการไม่สำเร็จ กรุณาลองอีกครั้ง';

type Props = { subject: Subject; role: string; district: string; readOnly: boolean; unitId: string; selected?: ClassroomUnit | null; children?: ReactNode; onOpen: (id: string) => void; onCommitOpen?: (id: string) => void; onDirty: (dirty: boolean) => void; onBusy: (busy: boolean) => void };

export function ClassroomUnits({ subject, role, district, readOnly, unitId, selected, children, onOpen, onCommitOpen = onOpen, onDirty, onBusy }: Props) {
    const client = useQueryClient();
    const manage = role !== 'student';
    const [page, setPage] = useState(1);
    const [editor, setEditor] = useState<ClassroomUnit | 'new' | null>(null);
    const [deleting, setDeleting] = useState<ClassroomUnit | null>(null);
    const params = new URLSearchParams({ subject_code: subject.code, education_level: String(subject.level), page: String(page) });
    const query = useQuery({ queryKey: ['classroom-units', role, district, subject.level, subject.code, page], enabled: !unitId, queryFn: ({ signal }) => getFeatureData<{ units: ClassroomUnit[]; pagination: PageInfo }>(`${base}?${params}`, signal) });
    const refresh = async () => { await Promise.all([client.invalidateQueries({ queryKey: ['classroom-units'] }), client.invalidateQueries({ queryKey: ['online-classroom'] })]); };
    const remove = useMutation({ mutationFn: () => sendFeatureData(`${base}/${deleting!.id}`, 'DELETE'), onSuccess: async () => { setDeleting(null); if (unitId) onCommitOpen(''); await refresh(); } });
    useEffect(() => { onBusy(remove.isPending); return () => onBusy(false); }, [remove.isPending, onBusy]);
    const tools = (unit: ClassroomUnit) => manage && unit.can_edit && !unit.is_legacy ? <div className="classroom-unit-tools"><Button size="small" appearance="outline" icon={<PencilSimple size={17} />} disabled={readOnly} onClick={() => setEditor(unit)}>แก้ไขบทเรียน</Button><Button size="small" aria-label={`ลบบทเรียน ${unit.title}`} icon={<Trash size={17} />} disabled={readOnly || !unit.can_delete} title={!unit.can_delete ? 'บทเรียนที่มีเนื้อหาหรืองานที่สั่งแล้วจะยังลบไม่ได้' : 'ลบบทเรียนว่าง'} onClick={() => { remove.reset(); setDeleting(unit); }} /></div> : null;
    const unitStatus = (unit: ClassroomUnit) => <span className="classroom-unit-status" data-draft={unit.status === 'draft'}>{unit.is_legacy ? 'เนื้อหาเดิม' : unit.status === 'draft' ? 'ฉบับร่าง · ครูเท่านั้น' : 'เผยแพร่แล้ว'}</span>;
    return <section className="classroom-units" aria-label={unitId ? 'บทเรียนที่เลือก' : 'บทเรียนในรายวิชา'}>
        {unitId ? selected && <div className="classroom-unit-expanded">
            <div className="classroom-chapter-heading">
                <button type="button" className="classroom-chapter-toggle" onClick={() => onOpen('')} aria-expanded="true" aria-controls={`classroom-unit-body-${selected.id}`}>
                    <span className="classroom-chapter-symbol"><BookOpenText size={23} weight="duotone" /></span>
                    <span className="classroom-chapter-copy"><strong>{selected.title}</strong>{unitStatus(selected)}</span><CaretUp size={20} aria-hidden="true" />
                </button>
                {tools(selected)}
            </div>
            {selected.description && <p className="classroom-chapter-description">{selected.description}</p>}
            <div id={`classroom-unit-body-${selected.id}`} className="classroom-chapter-content">{children}</div>
            <button type="button" className="classroom-chapter-all" onClick={() => onOpen('')}><ArrowLeft size={18} /> ทุกบทเรียน</button>
        </div> : <>
            <div className="classroom-unit-heading"><div><h2>บทเรียน</h2><p>เลือกบทหรือหัวข้อ แล้วเปิดคลิป ใบความรู้ และงานด้านใน</p></div>{manage && <Button appearance="primary" icon={<Plus size={20} />} disabled={readOnly} onClick={() => setEditor('new')}>เพิ่มบทเรียน</Button>}</div>
            {query.isPending ? <QuerySkeleton rows={4} /> : query.isError ? <QueryError message={message(query.error)} onRetry={() => void query.refetch()} /> : query.data && <>
                {!query.data.data.units.length ? <div className="classroom-unit-empty"><span><FolderOpen size={36} weight="duotone" /></span><h3>{manage ? 'เพิ่มบทเรียนแรก' : 'ยังไม่มีบทเรียนที่เผยแพร่'}</h3><p>{manage ? 'ตั้งชื่อบทหรือหัวข้อ แล้วเพิ่มเนื้อหาด้านใน' : 'เมื่อครูเผยแพร่บทเรียน คุณจะเปิดเรียนได้จากที่นี่'}</p>{manage && <Button appearance="primary" icon={<Plus />} disabled={readOnly} onClick={() => setEditor('new')}>เพิ่มบทเรียน</Button>}</div> : <div className="classroom-unit-list">{query.data.data.units.map((unit, index) => <article key={unit.id} className="classroom-chapter-row">
                    <button type="button" className="classroom-chapter-open" onClick={() => onOpen(unit.id)} aria-label={`เปิดบทเรียน ${unit.title}`} aria-expanded="false">
                        <span className="classroom-chapter-symbol">{unit.is_legacy ? <FolderOpen size={23} weight="duotone" /> : String((page - 1) * 50 + index + 1 - (query.data.data.units[0]?.is_legacy ? 1 : 0)).padStart(2, '0')}</span>
                        <span className="classroom-chapter-copy"><strong>{unit.title}</strong><span className="classroom-chapter-meta">{unit.content_count} รายการเรียนรู้ · {unitStatus(unit)}</span></span>
                        <CaretDown size={20} aria-hidden="true" />
                    </button>{tools(unit)}
                </article>)}</div>}
                {query.data.data.pagination.last_page > 1 && <nav aria-label="แบ่งหน้าบทเรียน" className="classroom-unit-pagination"><Button disabled={page <= 1} onClick={() => setPage(page - 1)}>ก่อนหน้า</Button><span>หน้า {page}/{query.data.data.pagination.last_page}</span><Button disabled={page >= query.data.data.pagination.last_page} onClick={() => setPage(page + 1)}>ถัดไป</Button></nav>}
            </>}
        </>}
        {editor && <UnitEditor key={editor === 'new' ? 'new' : editor.id} unit={editor === 'new' ? null : editor} subject={subject} role={role} close={() => setEditor(null)} onDirty={onDirty} onBusy={onBusy} saved={async id => { const creating = editor === 'new'; setEditor(null); await refresh(); if (creating) onCommitOpen(id); }} />}
        <Dialog className="classroom-dialog" open={Boolean(deleting)} onClose={() => { if (!remove.isPending) setDeleting(null); }}><DialogTitle>ลบบทเรียนนี้?</DialogTitle><DialogContent><p>บทเรียน “{deleting?.title}” ที่ยังไม่มีเนื้อหาจะถูกลบออกจากรายวิชา</p>{remove.isError && <Alert severity="error" className="mt-4">{message(remove.error)}</Alert>}</DialogContent><DialogActions><Button disabled={remove.isPending} onClick={() => setDeleting(null)}>ยกเลิก</Button><Button appearance="primary" disabled={remove.isPending} onClick={() => remove.mutate()}>ยืนยันลบบทเรียน</Button></DialogActions></Dialog>
    </section>;
}

function UnitEditor({ unit, subject, role, close, saved, onDirty, onBusy }: { unit: ClassroomUnit | null; subject: Subject; role: string; close: () => void; saved: (id: string) => Promise<void>; onDirty: (dirty: boolean) => void; onBusy: (busy: boolean) => void }) {
    const [title, setTitle] = useState(unit?.title ?? '');
    const [description, setDescription] = useState(unit?.description ?? '');
    const [group, setGroup] = useState(unit?.target_group ?? (role === 'teacher' ? subject.groups[0]?.code ?? '' : ''));
    const [status, setStatus] = useState<'draft' | 'published'>(unit?.status ?? 'draft');
    const [changed, setChanged] = useState(false);
    const save = useMutation({ mutationFn: () => sendFeatureData<{ id: string }>(`${base}${unit ? `/${unit.id}` : ''}`, unit ? 'PATCH' : 'POST', { title: title.trim(), description: description.trim(), subject_code: subject.code, education_level: subject.level, target_group: group, status }), onSuccess: result => saved(result.data.id) });
    useEffect(() => { onDirty(changed); return () => onDirty(false); }, [changed, onDirty]);
    useEffect(() => { onBusy(save.isPending); return () => onBusy(false); }, [save.isPending, onBusy]);
    const requestClose = () => { if (!save.isPending && (!changed || window.confirm('มีบทเรียนที่ยังไม่ได้บันทึก ต้องการปิดหรือไม่?'))) close(); };
    const submit = (event: FormEvent) => { event.preventDefault(); if (title.trim() && (role !== 'teacher' || group)) save.mutate(); };
    return <Dialog className="classroom-dialog classroom-unit-dialog" open fullWidth maxWidth="sm" onClose={requestClose}><DialogTitle>{unit ? 'แก้ไขบทเรียน' : 'เพิ่มบทเรียนในรายวิชา'}</DialogTitle><form onSubmit={submit} onChange={() => setChanged(true)}><DialogContent><div className="classroom-unit-form"><p className="classroom-unit-form-course"><BookOpenText size={22} /> {subject.code} · {subject.name}</p><TextField required fullWidth label="ชื่อบทเรียน" placeholder="เช่น รู้จักระบบนิเวศรอบตัวเรา" value={title} disabled={save.isPending} slotProps={{ htmlInput: { maxLength: 220 } }} onChange={event => setTitle(event.target.value)} /><TextField fullWidth multiline minRows={3} label="คำชวนเรียน / คำอธิบายบทเรียน" placeholder="บอกผู้เรียนว่าจะได้เรียนรู้อะไรจากบทเรียนนี้" value={description} disabled={save.isPending} slotProps={{ htmlInput: { maxLength: 5000 } }} onChange={event => setDescription(event.target.value)} />
        <fieldset disabled={save.isPending}><legend className="classroom-field-label">กลุ่มเรียนที่ร่วมบทเรียน</legend><div className="classroom-choice-buttons">{role !== 'teacher' && <button type="button" aria-pressed={!group} onClick={() => { setGroup(''); setChanged(true); }}>ทุกกลุ่มในรายวิชา</button>}{subject.groups.map(item => <button type="button" key={item.code} aria-pressed={group === item.code || group === item.name} onClick={() => { setGroup(item.code); setChanged(true); }}>{item.name || item.code}</button>)}</div></fieldset>
        <fieldset disabled={save.isPending}><legend className="classroom-field-label">การมองเห็น</legend><div className="classroom-unit-publish"><button type="button" aria-pressed={status === 'draft'} onClick={() => { setStatus('draft'); setChanged(true); }}><PencilSimple size={21} /><span><strong>เก็บเป็นฉบับร่าง</strong><small>เตรียมเนื้อหาก่อนเปิดให้เรียน</small></span></button><button type="button" aria-pressed={status === 'published'} onClick={() => { setStatus('published'); setChanged(true); }}><CheckCircle size={21} /><span><strong>เผยแพร่บทเรียน</strong><small>ผู้เรียนในกลุ่มเริ่มเรียนได้</small></span></button></div></fieldset>
        {save.isError && <Alert severity="error">{message(save.error)}</Alert>}
        </div></DialogContent><DialogActions><Button disabled={save.isPending} onClick={requestClose}>ยกเลิก</Button><Button appearance="primary" type="submit" disabled={save.isPending || !title.trim() || (role === 'teacher' && !group)}>{save.isPending ? 'กำลังบันทึก…' : 'บันทึกบทเรียน'}</Button></DialogActions></form></Dialog>;
}
