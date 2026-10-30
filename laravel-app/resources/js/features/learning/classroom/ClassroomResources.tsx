import { Alert, Dialog, DialogActions, DialogContent, DialogTitle, TextField } from '@mui/material';
import { ArrowSquareOut, BookOpenText, CheckCircle, DownloadSimple, FileArrowUp, FileText, MagnifyingGlass, PencilSimple, Play, Trash } from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useDeferredValue, useEffect, useState, type FormEvent } from 'react';
import { Button } from '../../../components/MaterialUI';
import { QuerySkeleton } from '../../../components/QueryState';
import { useDemoRole } from '../../../context/DemoRoleContext';
import { getFeatureBlob, getFeatureData, sendFeatureData } from '../../api';
import { safeClassroomUrl, youtubeEmbed, type Subject } from './types';
import './classroom-resources.css';

type Resource = {
    id: string;
    title: string;
    description?: string;
    type?: string;
    subject_code?: string;
    file_url?: string | null;
    can_edit?: boolean;
    is_downloadable?: boolean;
    raw?: Record<string, string>;
};
type Group = { code: string; name?: string; label?: string };
type ResourceDraft = { title: string; subject: string; description: string; resource_type: string; url: string; level: string; target_group: string };
const resourceBase = '/api/v1/learning/resources';
const typeLabels: Record<string, string> = { file: 'ไฟล์เอกสาร', pdf: 'PDF', exercise: 'แบบฝึกหัด', link: 'ลิงก์', video: 'วิดีโอ', youtube: 'YouTube' };
const isExternal = (type: string) => ['link', 'video', 'youtube'].includes(type);
const errorText = (reason: unknown) => reason instanceof Error ? reason.message : 'ไม่สามารถดำเนินการได้ กรุณาลองอีกครั้ง';

/** Existing private library files appear beside classroom lessons, without copying records. */
export function ClassroomResources({ subject, kind, sharedOnly = false, onDirty, onBusy }: {
    subject: Subject | null;
    kind: 'video' | 'knowledge' | 'all';
    sharedOnly?: boolean;
    onDirty?: (dirty: boolean) => void;
    onBusy?: (busy: boolean) => void;
}) {
    const { role } = useDemoRole();
    const district = window.localStorage.getItem('sena-district-id') ?? '';
    const client = useQueryClient();
    const [search, setSearch] = useState('');
    const deferredSearch = useDeferredValue(search);
    const [editing, setEditing] = useState<Resource | 'new' | null>(null);
    const [selected, setSelected] = useState<Resource | null>(null);
    const [deleting, setDeleting] = useState<Resource | null>(null);
    const params = new URLSearchParams();
    if (subject && !sharedOnly) { params.set('subject_code', subject.code); params.set('education_level', String(subject.level)); }
    if (sharedOnly) params.set('shared_only', '1');
    params.set('classroom_kind', kind);
    if (deferredSearch.trim()) params.set('search', deferredSearch.trim());
    const query = useQuery({
        queryKey: ['classroom-resources', role, district, subject?.code, subject?.level, kind, sharedOnly, deferredSearch],
        queryFn: ({ signal }) => getFeatureData<Resource[]>(`${resourceBase}?${params}`, signal),
    });
    const rows = query.data?.data ?? [];
    const canManage = role !== 'student' && query.data !== undefined && query.data.meta.read_only !== true;
    const availableGroups = (Array.isArray(query.data?.meta.available_groups) ? query.data.meta.available_groups : []) as Group[];
    const refresh = async () => {
        await Promise.all([
            client.invalidateQueries({ queryKey: ['classroom-resources'] }),
            client.invalidateQueries({ queryKey: ['learning', 'resources'] }),
            client.invalidateQueries({ queryKey: ['learning', 'overview'] }),
        ]);
    };
    const remove = useMutation({
        mutationFn: (item: Resource) => sendFeatureData(`${resourceBase}/${item.id}`, 'DELETE'),
        onSuccess: async () => { setDeleting(null); setSelected(null); await refresh(); },
    });
    useEffect(() => { onBusy?.(remove.isPending); return () => onBusy?.(false); }, [remove.isPending, onBusy]);
    const create = () => { setEditing('new'); };
    return <section className="classroom-resource-section" aria-label={kind === 'video' ? 'คลิปเพิ่มเติม' : 'เอกสารประกอบการเรียน'}>
        <div className="classroom-resource-heading">
            <div><span className="classroom-resource-eyebrow">{sharedOnly ? 'อ่านร่วมกันได้ทุกวิชา' : 'เปิดอ่าน เก็บไอเดีย แล้วเรียนต่อ'}</span><h3>{kind === 'video' ? 'คลิปเพิ่มเติม' : sharedOnly ? 'สื่อส่วนกลาง' : !subject ? 'สื่อเพิ่มเติม' : 'เอกสารประกอบการเรียน'}</h3><p>{kind === 'video' ? 'คลิปจากสื่อการเรียนเดิม เปิดดูต่อได้จากห้องเรียนนี้' : 'ไฟล์และสื่อเดิมอยู่ครบ เปิดอ่านหรือดาวน์โหลดได้จากที่เดียว'}</p></div>
            {canManage && subject && !sharedOnly && kind !== 'video' && <Button appearance="outline" icon={<FileArrowUp size={20} />} onClick={create}>แนบไฟล์เอกสาร</Button>}
        </div>
        <TextField fullWidth size="small" label="ค้นหาเอกสารและสื่อ" value={search} onChange={event => setSearch(event.target.value)} slotProps={{ htmlInput: { maxLength: 120 }, input: { startAdornment: <MagnifyingGlass size={20} className="mr-2" /> } }} />
        {query.isPending && <QuerySkeleton rows={3} />}
        {query.isError && <Alert severity="error" action={<Button onClick={() => void query.refetch()}>ลองใหม่</Button>}>{errorText(query.error)}</Alert>}
        {query.isSuccess && rows.length === 0 && <div className="classroom-resource-empty"><FileText size={31} weight="duotone" /><div><strong>{search.trim() ? 'ไม่พบสื่อที่ตรงกับคำค้น' : kind === 'video' ? 'ยังไม่มีคลิปเพิ่มเติม' : 'ยังไม่มีไฟล์ประกอบในส่วนนี้'}</strong><p>{search.trim() ? 'ลองใช้คำค้นอื่น' : kind === 'video' ? 'คลิปที่ครูสร้างใหม่จะแสดงในกิจกรรมด้านบน' : 'บทเรียนและลิงก์อยู่ในกิจกรรมด้านบน ส่วนไฟล์เอกสารจะแสดงตรงนี้'}</p></div></div>}
        <div className="classroom-resource-grid">{rows.map(item => {
            const type = item.raw?.resource_type ?? item.type ?? 'file';
            const video = ['video', 'youtube'].includes(type);
            const Icon = video ? Play : FileText;
            return <article key={item.id} className="classroom-resource-card" data-video={video}>
                <button type="button" className="classroom-resource-open" onClick={() => setSelected(item)}>
                    <span className="classroom-resource-icon"><Icon size={30} weight="duotone" /></span><span className="classroom-resource-label">{typeLabels[type] ?? 'สื่อการเรียน'}{!item.subject_code && <span>ส่วนกลาง</span>}</span>
                    <strong>{item.title}</strong><span className="classroom-resource-description">{item.description || 'เปิดสื่อประกอบบทเรียน'}</span><span className="classroom-resource-go">{video ? 'เปิดคลิป' : item.file_url ? 'เปิดไฟล์ / ดาวน์โหลด' : 'เปิดอ่าน'}<ArrowSquareOut size={17} /></span>
                </button>
                {canManage && item.can_edit !== false && <div className="classroom-resource-manage"><Button size="small" icon={<PencilSimple size={16} />} onClick={() => setEditing(item)}>แก้ไข<span className="sr-only"> {item.title}</span></Button><Button size="small" icon={<Trash size={16} />} onClick={() => { remove.reset(); setDeleting(item); }}>ลบ<span className="sr-only"> {item.title}</span></Button></div>}
            </article>;
        })}</div>
        {rows.length >= 250 && <p className="classroom-muted">แสดงสูงสุด 250 รายการ ใช้ช่องค้นหาเพื่อหาไฟล์อื่นในหมวดนี้</p>}
        {selected && <ResourceViewer key={selected.id} resource={selected} close={() => setSelected(null)} />}
        {editing && <ResourceEditor key={editing === 'new' ? 'new' : editing.id} resource={editing === 'new' ? null : editing} subject={subject} groups={availableGroups} onDirty={onDirty} onBusy={onBusy} close={() => setEditing(null)} saved={async () => { setEditing(null); onDirty?.(false); await refresh(); }} />}
        <Dialog open={Boolean(deleting)} onClose={() => { if (!remove.isPending) setDeleting(null); }} fullWidth maxWidth="xs"><DialogTitle>ลบสื่อนี้?</DialogTitle><DialogContent><p>ลบ “{deleting?.title}” และไฟล์แนบออกจากห้องเรียนทั้งหมดที่ใช้สื่อนี้</p>{remove.isError && <Alert className="mt-3" severity="error">{errorText(remove.error)}</Alert>}</DialogContent><DialogActions><Button disabled={remove.isPending} onClick={() => setDeleting(null)}>ยกเลิก</Button><Button appearance="primary" disabled={remove.isPending} onClick={() => { if (deleting) remove.mutate(deleting); }}>{remove.isPending ? 'กำลังลบ…' : 'ยืนยันลบ'}</Button></DialogActions></Dialog>
    </section>;
}

function ResourceViewer({ resource, close }: { resource: Resource; close: () => void }) {
    const [blobUrl, setBlobUrl] = useState('');
    const [mime, setMime] = useState('');
    const [error, setError] = useState('');
    const downloadable = Boolean(resource.file_url || resource.is_downloadable);
    const type = resource.raw?.resource_type ?? resource.type ?? '';
    const safe = safeClassroomUrl(resource.raw?.url ?? '');
    const embed = ['video', 'youtube'].includes(type) ? youtubeEmbed(resource.raw?.url ?? '') : null;
    useEffect(() => {
        if (!downloadable) return;
        let active = true;
        let url = '';
        // Construct the known authorized endpoint; never fetch a stored external URL with credentials.
        getFeatureBlob(`${resourceBase}/${encodeURIComponent(resource.id)}/file`).then(blob => {
            if (!active) return;
            url = URL.createObjectURL(blob); setBlobUrl(url); setMime(blob.type);
        }).catch(reason => { if (active) setError(errorText(reason)); });
        return () => { active = false; if (url) URL.revokeObjectURL(url); };
    }, [resource.id, downloadable]);
    const extension = resource.raw?.file_extension || (type === 'pdf' ? 'pdf' : '');
    const filename = `${resource.title}${extension ? `.${extension}` : ''}`;
    return <Dialog className="classroom-resource-dialog" open onClose={close} fullWidth maxWidth="md"><DialogTitle>{resource.title}</DialogTitle><DialogContent>
        {resource.description && <p className="mb-4 whitespace-pre-wrap break-words">{resource.description}</p>}
        {downloadable ? error ? <Alert severity="error">{error}</Alert> : !blobUrl ? <p role="status">กำลังเปิดไฟล์…</p> : <>
            {mime.split(';')[0] === 'application/pdf' ? <iframe className="classroom-resource-preview" title={`เอกสาร ${resource.title}`} src={blobUrl} /> : <div className="classroom-resource-empty"><DownloadSimple size={35} /><p>ดาวน์โหลดไฟล์นี้แล้วเปิดด้วยแอปที่รองรับบนอุปกรณ์ของคุณ</p></div>}
            <Button as="a" appearance="primary" icon={<DownloadSimple />} href={blobUrl} download={filename}>ดาวน์โหลดไฟล์</Button>
        </> : safe ? <>{embed && <iframe className="mb-4 aspect-video w-full rounded-xl" src={embed} title={resource.title} referrerPolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen />}<Button as="a" appearance="outline" icon={<ArrowSquareOut />} href={safe} target="_blank" rel="noopener noreferrer">เปิดสื่อในแท็บใหม่</Button></> : <Alert severity="warning">สื่อนี้ยังไม่มีไฟล์หรือลิงก์ที่เปิดได้ กรุณาแจ้งครูเจ้าของสื่อ</Alert>}
    </DialogContent><DialogActions><Button onClick={close}>ปิด</Button></DialogActions></Dialog>;
}

function ResourceEditor({ resource, subject, groups, onDirty, onBusy, close, saved }: {
    resource: Resource | null; subject: Subject | null; groups: Group[]; onDirty?: (dirty: boolean) => void; onBusy?: (busy: boolean) => void; close: () => void; saved: () => Promise<void>;
}) {
    const { role } = useDemoRole();
    const [draft, setDraft] = useState<ResourceDraft>(() => ({ title: resource?.title ?? '', subject: resource?.raw?.subject ?? subject?.code ?? '', description: resource?.description ?? '', resource_type: resource?.raw?.resource_type ?? resource?.type ?? 'file', url: resource?.raw?.url ?? '', level: resource?.raw?.level ?? (subject ? String(subject.level) : ''), target_group: resource?.raw?.target_group || (role === 'teacher' ? subject?.groups[0]?.code ?? groups[0]?.code ?? '' : '') }));
    const [file, setFile] = useState<File | null>(null);
    const [dirty, setDirty] = useState(false);
    const [fileError, setFileError] = useState('');
    const external = isExternal(draft.resource_type);
    const initialType = resource?.raw?.resource_type ?? resource?.type;
    const fileRequired = !external && (!resource?.file_url || initialType !== draft.resource_type);
    const save = useMutation({ mutationFn: () => {
        const payload = new FormData();
        Object.entries(draft).forEach(([key, value]) => { if (key !== 'url' || external) payload.append(key, value); });
        if (!external && file) payload.append('file', file);
        if (resource) payload.append('_method', 'PATCH');
        return sendFeatureData(`${resourceBase}${resource ? `/${resource.id}` : ''}`, 'POST', payload);
    }, onSuccess: saved });
    useEffect(() => { onBusy?.(save.isPending); return () => onBusy?.(false); }, [save.isPending, onBusy]);
    const update = (values: Partial<ResourceDraft>) => { setDraft(current => ({ ...current, ...values })); setDirty(true); onDirty?.(true); };
    const closeEditor = () => { if (!save.isPending && (!dirty || window.confirm('มีเอกสารที่ยังไม่ได้บันทึก ต้องการปิดหรือไม่?'))) { onDirty?.(false); close(); } };
    useEffect(() => {
        if (!dirty) return;
        const listener = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', listener);
        return () => window.removeEventListener('beforeunload', listener);
    }, [dirty]);
    const submit = (event: FormEvent) => { event.preventDefault(); if (!fileError && (role !== 'teacher' || draft.target_group)) save.mutate(); };
    const choices = resource ? Object.keys(typeLabels) : ['file', 'pdf'];
    const availableGroups: Group[] = subject && !resource ? subject.groups : groups;
    const allGroups: Group[] = role !== 'teacher' && draft.target_group && !availableGroups.some(group => group.code === draft.target_group) ? [...availableGroups, { code: draft.target_group, name: draft.target_group }] : availableGroups;
    return <Dialog className="classroom-resource-dialog" open onClose={closeEditor} fullWidth maxWidth="sm"><form onSubmit={submit}><DialogTitle>{resource ? 'แก้ไขสื่อประกอบ' : 'แนบไฟล์ประกอบบทเรียน'}</DialogTitle><DialogContent><div className="space-y-5 pt-2">
        {subject && !resource ? <div className="classroom-resource-course"><BookOpenText size={23} /><div><strong>{subject.name}</strong><p>{subject.code} · {subject.level_label}</p></div></div> : <TextField fullWidth label="รหัสวิชา (เว้นว่าง = ใช้ร่วมกัน)" value={draft.subject} slotProps={{ htmlInput: { maxLength: 32 } }} disabled={save.isPending} onChange={event => update({ subject: event.target.value })} />}
        <TextField fullWidth required label="ชื่อเอกสารหรือสื่อ" value={draft.title} slotProps={{ htmlInput: { maxLength: 220 } }} disabled={save.isPending} onChange={event => update({ title: event.target.value })} />
        <fieldset><legend className="classroom-field-label">ประเภทสื่อ</legend><div className="classroom-resource-choices">{choices.map(type => <button type="button" key={type} aria-pressed={draft.resource_type === type} disabled={save.isPending} onClick={() => { update({ resource_type: type }); setFile(null); setFileError(''); }}>{typeLabels[type]}{draft.resource_type === type && <CheckCircle size={16} weight="fill" />}</button>)}</div></fieldset>
        {external ? <TextField fullWidth required type="url" label="ลิงก์ HTTP / HTTPS" value={draft.url} disabled={save.isPending} slotProps={{ htmlInput: { maxLength: 2000 } }} onChange={event => update({ url: event.target.value })} /> : <label className="classroom-resource-upload"><FileArrowUp size={30} /><strong>{file ? file.name : resource?.file_url && !fileRequired ? 'เลือกไฟล์ใหม่เมื่อจะเปลี่ยนเอกสาร' : 'เลือกไฟล์เอกสาร'}</strong><span>PDF, Word, PowerPoint, Excel หรือ ZIP · ไม่เกิน 20 MB</span><input required={fileRequired} disabled={save.isPending} type="file" accept={draft.resource_type === 'pdf' ? '.pdf' : '.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip'} onChange={event => { const next = event.target.files?.[0] ?? null; setFile(next); setFileError(next && next.size > 20 * 1024 * 1024 ? 'ไฟล์ต้องมีขนาดไม่เกิน 20 MB' : ''); setDirty(true); onDirty?.(true); }} /></label>}
        {fileError && <Alert severity="error">{fileError}</Alert>}
        <TextField fullWidth multiline minRows={3} label="คำอธิบาย" value={draft.description} disabled={save.isPending} slotProps={{ htmlInput: { maxLength: 5000 } }} onChange={event => update({ description: event.target.value })} />
        <fieldset><legend className="classroom-field-label">กลุ่มเป้าหมาย</legend><div className="classroom-resource-choices">{[...(role === 'teacher' ? [] : [{ code: '', name: 'ทุกกลุ่มในขอบเขตของคุณ' }]), ...allGroups].map(group => <button type="button" key={group.code} aria-pressed={draft.target_group === group.code} disabled={save.isPending} onClick={() => update({ target_group: group.code })}>{group.name || group.label || group.code}{draft.target_group === group.code && <CheckCircle size={16} weight="fill" />}</button>)}</div></fieldset>
        {resource && <fieldset><legend className="classroom-field-label">ระดับการศึกษา</legend><div className="classroom-resource-choices">{[['', 'ทุกระดับ'], ['1', 'ประถม'], ['2', 'ม.ต้น'], ['3', 'ม.ปลาย']].map(([value, label]) => <button type="button" key={value} aria-pressed={draft.level === value} disabled={save.isPending} onClick={() => update({ level: value })}>{label}</button>)}</div></fieldset>}
        <Alert severity="info">ไฟล์ที่บันทึกจะเปิดให้ผู้เรียนตามระดับและกลุ่มเป้าหมายทันที</Alert>
        {save.isError && <Alert severity="error">{errorText(save.error)}</Alert>}
    </div></DialogContent><DialogActions><Button disabled={save.isPending} onClick={closeEditor}>ยกเลิก</Button><Button appearance="primary" disabled={save.isPending || Boolean(fileError) || (role === 'teacher' && !draft.target_group)} type="submit">{save.isPending ? 'กำลังบันทึก…' : 'บันทึกสื่อ'}</Button></DialogActions></form></Dialog>;
}
