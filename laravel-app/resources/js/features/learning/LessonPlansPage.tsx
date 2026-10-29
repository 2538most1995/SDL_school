import { Alert, Dialog, DialogActions, DialogContent, DialogTitle, TextField } from '@mui/material';
import { ArrowSquareOut, BookOpen, BookOpenText, CalendarBlank, CheckCircle, DownloadSimple, FilePdf, LinkSimple, MagnifyingGlass, Notebook, PencilSimple, Plus, Trash, UploadSimple, User } from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useDeferredValue, useEffect, useRef, useState, type FormEvent } from 'react';
import { Button } from '../../components/MaterialUI';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { getFeatureBlob, getFeatureData, sendFeatureData } from '../api';
import { documentPreview } from './lesson-plans/documentLinks';
import { filterLessonPlans, planScope, planScopeLabels, type PlanScope } from './lesson-plans/planFilters';
import './lesson-plans.css';

type LessonPlan = {
    id: string; title: string; description: string; course: string; timing: string; status: string; can_edit: boolean;
    teacher_name?: string; external_url?: string | null; file_url?: string | null; cover_url?: string | null;
    page_count?: number | null; plan_scope?: PlanScope; raw: Record<string, string>;
};
type Draft = { title: string; subject: string; level: string; semester: string; objectives: string; activities: string; assessment: string; external_url: string };
const base = '/api/v1/learning/lesson-plans';
const levels = [{ value: '1', label: 'ประถม' }, { value: '2', label: 'ม.ต้น' }, { value: '3', label: 'ม.ปลาย' }];
const errorText = (error: unknown) => error instanceof Error ? error.message : 'ดำเนินการไม่สำเร็จ กรุณาลองอีกครั้ง';
const sourceUrl = (plan: LessonPlan) => plan.external_url || plan.raw?.external_url || '';

function PlanCover({ plan }: { plan: LessonPlan }) {
    const element = useRef<HTMLDivElement>(null);
    const [visible, setVisible] = useState(false);
    const [src, setSrc] = useState('');
    const [failed, setFailed] = useState(false);
    const external = documentPreview(sourceUrl(plan));
    useEffect(() => {
        if (!element.current) return;
        if (typeof IntersectionObserver === 'undefined') { setVisible(true); return; }
        const observer = new IntersectionObserver(entries => { if (entries.some(entry => entry.isIntersecting)) { setVisible(true); observer.disconnect(); } }, { rootMargin: '240px' });
        observer.observe(element.current);
        return () => observer.disconnect();
    }, []);
    useEffect(() => {
        if (!visible || !plan.cover_url) return;
        let active = true;
        let url = '';
        setFailed(false); setSrc('');
        getFeatureBlob(`${base}/${encodeURIComponent(plan.id)}/cover`).then(blob => {
            if (active) { url = URL.createObjectURL(blob); setSrc(url); setFailed(false); }
        }).catch(() => { if (active) setFailed(true); });
        return () => { active = false; if (url) URL.revokeObjectURL(url); };
    }, [plan.id, plan.cover_url, visible]);
    const image = src || (!plan.cover_url && !failed ? external?.thumbnailUrl : '') || '';
    return <div ref={element} className={`lesson-plan-cover ${image && !failed ? 'has-image' : ''}`}>
        {image && !failed ? <img src={image} loading="lazy" referrerPolicy="no-referrer" alt={`หน้าปก ${plan.title}`} onError={() => setFailed(true)} /> : <div className="lesson-plan-cover-fallback"><span><BookOpen size={39} weight="duotone" /></span><strong>{plan.title}</strong>{plan.teacher_name && <small>{plan.teacher_name}</small>}</div>}
        <span className="lesson-plan-source">{plan.file_url && !sourceUrl(plan) ? <><FilePdf size={15} /> PDF</> : sourceUrl(plan) ? <><LinkSimple size={15} /> {external?.provider || 'ลิงก์'}</> : <><Notebook size={15} /> แผนการสอน</>}</span>
    </div>;
}

export function LessonPlansPage() {
    const { role } = useDemoRole();
    const district = window.localStorage.getItem('sena-district-id') ?? '';
    return <LessonPlanLibrary key={`${role}:${district}`} role={role} district={district} />;
}

function LessonPlanLibrary({ role, district }: { role: string; district: string }) {
    const client = useQueryClient();
    const [search, setSearch] = useState('');
    const deferredSearch = useDeferredValue(search);
    const [level, setLevel] = useState('');
    const [scope, setScope] = useState<PlanScope | ''>('');
    const [editing, setEditing] = useState<LessonPlan | 'new' | null>(null);
    const [reading, setReading] = useState<LessonPlan | null>(null);
    const [details, setDetails] = useState<LessonPlan | null>(null);
    const [deleting, setDeleting] = useState<LessonPlan | null>(null);
    const params = new URLSearchParams();
    if (level) params.set('education_level', level);
    if (scope) params.set('plan_scope', scope);
    const query = useQuery({ queryKey: ['lesson-plan-library', role, district, level, scope], queryFn: ({ signal }) => getFeatureData<LessonPlan[]>(`${base}?${params}`, signal) });
    const data = query.data?.data;
    const canManage = role !== 'student' && query.data !== undefined && query.data.meta.read_only !== true;
    const refresh = () => client.invalidateQueries({ queryKey: ['lesson-plan-library'] });
    const remove = useMutation({ mutationFn: (plan: LessonPlan) => sendFeatureData(`${base}/${plan.id}`, 'DELETE'), onSuccess: async () => { setDeleting(null); await refresh(); } });
    if (query.isPending) return <QuerySkeleton rows={6} />;
    if (query.isError && !data) return <QueryError onRetry={() => void query.refetch()} />;
    const rows = data ?? [];
    const needle = deferredSearch.trim().toLocaleLowerCase('th');
    const visible = filterLessonPlans(rows, { level, scope, search: deferredSearch });
    return <div className="lesson-plan-library">
        <header className="lesson-plan-hero"><div className="lesson-plan-hero-icon"><Notebook size={33} weight="duotone" /></div><div><span className="lesson-plan-eyebrow">พื้นที่เตรียมการสอน</span><h1>แผนการสอน</h1><p>เก็บแผนดี ๆ ไว้ใกล้มือ เปิดอ่าน เตรียมสอน และต่อยอดไอเดีย</p></div>{canManage && <Button appearance="primary" icon={<Plus size={20} />} onClick={() => setEditing('new')}>เพิ่มแผนการสอน</Button>}</header>
        {query.data?.meta.read_only === true && <Alert severity="info">ระบบอยู่ในโหมดอ่านอย่างเดียว ยังเพิ่มหรือแก้ไขแผนการสอนไม่ได้</Alert>}
        {query.isError && <Alert severity="warning" action={<Button onClick={() => void query.refetch()}>ลองใหม่</Button>}>โหลดข้อมูลล่าสุดไม่สำเร็จ ข้อมูลและแบบร่างที่เปิดไว้ยังอยู่</Alert>}
        <section className="lesson-plan-toolbar" aria-label="ค้นหาและเลือกระดับ">
            <div className="lesson-plan-search"><TextField fullWidth size="small" label="ค้นหาแผนการสอน" placeholder="ชื่อแผน รหัสวิชา หรือผู้จัดทำ" value={search} onChange={event => setSearch(event.target.value)} slotProps={{ input: { startAdornment: <MagnifyingGlass size={20} className="mr-2" /> } }} /></div>
            <div className="lesson-plan-levels" role="group" aria-label="ระดับการศึกษา">{[{ value: '', label: 'ทั้งหมด' }, ...levels].map(item => <button type="button" key={item.value} aria-pressed={level === item.value} onClick={() => setLevel(item.value)}>{item.label}</button>)}</div>
        </section>
        <div className="lesson-plan-scope-filter"><span>ประเภทแผน</span><div className="lesson-plan-choice-buttons" role="group" aria-label="ประเภทแผนที่แสดง">{[{ value: '', label: 'ทุกประเภท' }, ...Object.entries(planScopeLabels).map(([value, label]) => ({ value, label }))].map(item => <button type="button" key={item.value} aria-pressed={scope === item.value} onClick={() => setScope(item.value as PlanScope | '')}>{item.label}</button>)}</div></div>
        <div className="lesson-plan-shelf-heading"><h2>{needle || level || scope ? 'แผนที่ค้นพบ' : 'ชั้นหนังสือของคุณ'}</h2><span>{visible.length} แผน{needle ? ` จาก ${rows.length} แผน` : ''}</span></div>
        {visible.length === 0 ? <section className="lesson-plan-empty"><BookOpenText size={52} weight="duotone" /><h3>{rows.length ? 'ยังไม่พบแผนที่ตรงกับคำค้น' : 'แผนการสอนเล่มแรก เริ่มได้ที่นี่'}</h3><p>{rows.length ? 'ลองเปลี่ยนคำค้นหรือระดับการศึกษา' : 'อัปโหลด PDF เพื่อใช้หน้าแรกเป็นปก หรือแนบลิงก์ Google Drive, OneDrive และเว็บไซต์เอกสาร'}</p>{canManage && rows.length === 0 && <Button appearance="primary" icon={<Plus />} onClick={() => setEditing('new')}>เพิ่มแผนแรก</Button>}</section> : <div className="lesson-plan-grid">{visible.map(plan => <article key={plan.id} className="lesson-plan-card">
            <button type="button" className="lesson-plan-cover-button" aria-label={`เปิดอ่าน ${plan.title}`} onClick={() => plan.file_url || sourceUrl(plan) ? setReading(plan) : setDetails(plan)}><PlanCover plan={plan} /><span className="lesson-plan-cover-hover"><BookOpen size={24} /> เปิดอ่านทันที</span></button>
            <div className="lesson-plan-card-body">{planScope(plan) === 'all_subjects' && <span className="lesson-plan-scope-badge">แผนรวมทุกวิชา</span>}<h3>{plan.title}</h3>{plan.teacher_name && <p className="lesson-plan-author"><User size={15} />{plan.teacher_name}</p>}<p className="lesson-plan-card-meta">{plan.course || 'ไม่ระบุรายวิชา'}{plan.raw?.level && <> · {levels.find(item => item.value === String(plan.raw.level))?.label || plan.raw.level}</>}</p><div className="lesson-plan-card-bottom"><span><CalendarBlank size={15} /> {plan.timing || 'ไม่ระบุภาคเรียน'}</span>{plan.page_count ? <span><BookOpen size={15} /> {plan.page_count} หน้า</span> : <span>{plan.status}</span>}</div>
                <div className="lesson-plan-card-actions"><Button appearance="primary" icon={<BookOpen size={17} />} onClick={() => plan.file_url || sourceUrl(plan) ? setReading(plan) : setDetails(plan)}>เปิดอ่าน</Button><Button appearance="outline" onClick={() => setDetails(plan)}>รายละเอียด</Button></div>
                {canManage && plan.can_edit && <div className="lesson-plan-card-manage"><Button size="small" icon={<PencilSimple size={16} />} onClick={() => setEditing(plan)}>แก้ไข<span className="sr-only"> {plan.title}</span></Button><Button size="small" icon={<Trash size={16} />} onClick={() => { remove.reset(); setDeleting(plan); }}>ลบ<span className="sr-only"> {plan.title}</span></Button></div>}
            </div>
        </article>)}</div>}
        {rows.length >= 250 && <p className="lesson-plan-muted">แสดงแผนล่าสุดสูงสุด 250 รายการ</p>}
        {editing && <PlanEditor key={editing === 'new' ? 'new' : editing.id} plan={editing === 'new' ? null : editing} initialScope={scope || 'subject'} initialLevel={level || '1'} close={() => setEditing(null)} saved={async () => { setEditing(null); await refresh(); }} />}
        {reading && <PlanReader key={reading.id} plan={reading} close={() => setReading(null)} />}
        {details && <Dialog className="lesson-plan-dialog" open onClose={() => setDetails(null)} fullWidth maxWidth="md"><DialogTitle>{details.title}</DialogTitle><DialogContent><div className="lesson-plan-details-meta"><span>{planScopeLabels[planScope(details)]}</span>{planScope(details) === 'subject' && <span>{details.course}</span>}<span>{levels.find(item => item.value === details.raw?.level)?.label}</span><span>ภาคเรียน {details.timing}</span>{details.teacher_name && <span>{details.teacher_name}</span>}</div>{[['วัตถุประสงค์', details.raw?.objectives], ['กิจกรรมการเรียนรู้', details.raw?.activities], ['การวัดและประเมินผล', details.raw?.assessment]].map(([label, value]) => <section className="lesson-plan-detail-section" key={label}><h3>{label}</h3><p>{value || 'ยังไม่ได้ระบุ'}</p></section>)}</DialogContent><DialogActions><Button onClick={() => setDetails(null)}>ปิด</Button>{(details.file_url || sourceUrl(details)) && <Button appearance="primary" icon={<BookOpen />} onClick={() => { setReading(details); setDetails(null); }}>เปิดเอกสาร</Button>}</DialogActions></Dialog>}
        <Dialog open={Boolean(deleting)} onClose={() => { if (!remove.isPending) setDeleting(null); }} fullWidth maxWidth="xs"><DialogTitle>ลบแผนการสอนนี้?</DialogTitle><DialogContent>ลบ “{deleting?.title}” พร้อมไฟล์แนบและหน้าปก{remove.isError && <Alert className="mt-3" severity="error">{errorText(remove.error)}</Alert>}</DialogContent><DialogActions><Button disabled={remove.isPending} onClick={() => setDeleting(null)}>ยกเลิก</Button><Button appearance="primary" disabled={remove.isPending} onClick={() => { if (deleting) remove.mutate(deleting); }}>{remove.isPending ? 'กำลังลบ…' : 'ยืนยันลบ'}</Button></DialogActions></Dialog>
    </div>;
}

function PlanReader({ plan, close }: { plan: LessonPlan; close: () => void }) {
    const external = documentPreview(sourceUrl(plan));
    const [useCopy, setUseCopy] = useState(false);
    const activeExternal = useCopy ? null : external;
    const [url, setUrl] = useState('');
    const [error, setError] = useState('');
    useEffect(() => {
        if (activeExternal || !plan.file_url) return;
        let active = true;
        let objectUrl = '';
        getFeatureBlob(`${base}/${encodeURIComponent(plan.id)}/file`).then(blob => { if (active) { objectUrl = URL.createObjectURL(blob); setUrl(objectUrl); } }).catch(reason => { if (active) setError(errorText(reason)); });
        return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl); };
    }, [plan.id, plan.file_url, Boolean(activeExternal)]);
    return <Dialog className="lesson-plan-dialog lesson-plan-reader" open onClose={close} fullWidth maxWidth="lg"><DialogTitle>{plan.title}</DialogTitle><DialogContent>
        {activeExternal ? <><p className="lesson-plan-reader-note">เอกสารจาก {activeExternal.provider} · หากเปิดไม่ได้ ให้ตรวจสิทธิ์แชร์หรือเปิดในแท็บใหม่</p>{activeExternal.embedUrl ? <iframe className="lesson-plan-document" src={activeExternal.embedUrl} title={`แผนการสอน ${plan.title}`} referrerPolicy="no-referrer" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-downloads" allowFullScreen /> : <div className="lesson-plan-reader-link"><LinkSimple size={43} /><h3>พร้อมเปิดเอกสารของคุณ</h3><p>ลิงก์นี้เปิดผ่านเว็บไซต์ต้นทาง</p></div>}</> : !plan.file_url ? <Alert severity="warning">ลิงก์เอกสารนี้ไม่ถูกต้อง กรุณาแก้ไขลิงก์หรือแนบ PDF</Alert> : error ? <Alert severity="error">{error}</Alert> : url ? <iframe className="lesson-plan-document" src={url} title={`แผนการสอน ${plan.title}`} /> : <p role="status">กำลังเปิดเอกสาร…</p>}
    </DialogContent><DialogActions><Button onClick={close}>ปิด</Button>{external && plan.file_url && !useCopy && <Button appearance="outline" onClick={() => setUseCopy(true)}>อ่าน PDF สำเนาที่แนบ</Button>}{external && <Button as="a" appearance="primary" icon={<ArrowSquareOut />} href={external.url} target="_blank" rel="noopener noreferrer">เปิดในแท็บใหม่</Button>}{url && <Button as="a" appearance="primary" icon={<DownloadSimple />} href={url} download={`${plan.title}.pdf`}>ดาวน์โหลด PDF</Button>}</DialogActions></Dialog>;
}

function PlanEditor({ plan, initialScope, initialLevel, close, saved }: { plan: LessonPlan | null; initialScope: PlanScope; initialLevel: string; close: () => void; saved: () => Promise<void> }) {
    const [draft, setDraft] = useState<Draft>(() => ({ title: plan?.title ?? '', subject: plan?.raw?.subject ?? '', level: plan?.raw?.level || initialLevel, semester: plan?.raw?.semester ?? '', objectives: plan?.raw?.objectives ?? '', activities: plan?.raw?.activities ?? '', assessment: plan?.raw?.assessment ?? '', external_url: plan ? sourceUrl(plan) : '' }));
    const [scope, setScope] = useState<PlanScope>(() => plan ? planScope(plan) : initialScope);
    const [source, setSource] = useState<'link' | 'pdf' | ''>(() => plan ? sourceUrl(plan) ? 'link' : plan.file_url ? 'pdf' : '' : 'link');
    const [file, setFile] = useState<File | null>(null);
    const [fileInputKey, setFileInputKey] = useState(0);
    const [cover, setCover] = useState<Blob | null>(null);
    const [coverUrl, setCoverUrl] = useState('');
    const [pages, setPages] = useState<number | null>(null);
    const [rendering, setRendering] = useState(false);
    const [fileError, setFileError] = useState('');
    const [dirty, setDirty] = useState(false);
    const generation = useRef(0);
    const preview = documentPreview(draft.external_url);
    useEffect(() => () => { generation.current++; }, []);
    useEffect(() => {
        if (!cover) { setCoverUrl(''); return; }
        const url = URL.createObjectURL(cover); setCoverUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [cover]);
    useEffect(() => {
        if (!dirty) return;
        const listener = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', listener);
        return () => window.removeEventListener('beforeunload', listener);
    }, [dirty]);
    const save = useMutation({ mutationFn: () => {
        const form = new FormData();
        Object.entries(draft).forEach(([key, value]) => { if (key !== 'external_url' || source === 'link') form.append(key, value); });
        form.set('plan_scope', scope);
        if (scope === 'all_subjects') form.set('subject', '');
        if (source) form.append('source_type', source);
        if (file) form.append('pdf', file);
        if (cover) form.append('cover_image', cover, 'first-page.jpg');
        if (pages) form.append('page_count', String(pages));
        if (plan) form.append('_method', 'PATCH');
        return sendFeatureData(`${base}${plan ? `/${plan.id}` : ''}`, 'POST', form);
    }, onSuccess: saved });
    const update = (values: Partial<Draft>) => { setDirty(true); setDraft(current => ({ ...current, ...values })); };
    const busy = save.isPending || rendering;
    const closeEditor = () => { if (!busy && (!dirty || window.confirm('มีแผนการสอนที่ยังไม่ได้บันทึก ต้องการปิดแบบร่างนี้หรือไม่?'))) close(); };
    const selectPdf = async (next: File | null) => {
        const id = ++generation.current;
        setDirty(true); setFile(next); setCover(null); setPages(null); setFileError('');
        if (!next) { setRendering(false); return; }
        if (next.size > 20 * 1024 * 1024 || !/\.pdf$/i.test(next.name)) { setRendering(false); setFileError('กรุณาเลือกไฟล์ PDF ขนาดไม่เกิน 20 MB'); return; }
        setRendering(true);
        try {
            const { renderPdfCover } = await import('./lesson-plans/pdfCover');
            const result = await renderPdfCover(next);
            if (generation.current === id) { setCover(result.blob); setPages(result.pages); }
        } catch (reason) { if (generation.current === id) setFileError(errorText(reason)); }
        finally { if (generation.current === id) setRendering(false); }
    };
    const submit = (event: FormEvent) => { event.preventDefault(); if (!busy && !fileError && (!file || cover) && (source !== 'link' || preview)) save.mutate(); };
    return <Dialog className="lesson-plan-dialog" open onClose={closeEditor} fullWidth maxWidth="md"><form onSubmit={submit}><DialogTitle>{plan ? 'แก้ไขแผนการสอน' : 'เพิ่มแผนการสอน'}</DialogTitle><DialogContent><div className="lesson-plan-editor-grid">
        <TextField className="lesson-plan-form-wide" fullWidth required label="ชื่อแผนการสอน" value={draft.title} disabled={busy} slotProps={{ htmlInput: { maxLength: 220 } }} onChange={event => update({ title: event.target.value })} />
        <fieldset className="lesson-plan-form-wide"><legend>ขอบเขตของแผน</legend><div className="lesson-plan-choice-buttons" role="group" aria-label="ขอบเขตของแผน">{(Object.entries(planScopeLabels) as [PlanScope, string][]).map(([value, label]) => <button type="button" key={value} aria-pressed={scope === value} disabled={busy} onClick={() => { setScope(value); setDirty(true); }}>{label}{scope === value && <CheckCircle size={16} weight="fill" />}</button>)}</div></fieldset>
        {scope === 'subject' ? <TextField fullWidth required label="รายวิชา / รหัสวิชา" value={draft.subject} disabled={busy} slotProps={{ htmlInput: { maxLength: 32 } }} onChange={event => update({ subject: event.target.value })} /> : <Alert severity="info" className="lesson-plan-form-wide">แผนนี้รวมทุกวิชาของระดับ{levels.find(item => item.value === draft.level)?.label} เลือกระดับและแนบเอกสารได้โดยไม่ต้องกรอกรหัสวิชา</Alert>}
        <TextField className={scope === 'all_subjects' ? 'lesson-plan-form-wide' : undefined} fullWidth required label="ภาคเรียน" placeholder="เช่น 2/2569" value={draft.semester} disabled={busy} slotProps={{ htmlInput: { maxLength: 16 } }} onChange={event => update({ semester: event.target.value })} />
        <fieldset className="lesson-plan-form-wide"><legend>ระดับการศึกษา</legend><div className="lesson-plan-choice-buttons" role="group" aria-label="ระดับแผนการสอน">{levels.map(item => <button type="button" key={item.value} aria-pressed={draft.level === item.value} disabled={busy} onClick={() => update({ level: item.value })}>{item.label}{draft.level === item.value && <CheckCircle size={16} weight="fill" />}</button>)}</div></fieldset>
        <section className="lesson-plan-source-editor lesson-plan-form-wide"><h3>เอกสารแผนการสอน</h3><div className="lesson-plan-choice-buttons" role="group" aria-label="วิธีแนบเอกสาร">{[{ value: 'link', label: 'แนบลิงก์', Icon: LinkSimple }, { value: 'pdf', label: 'อัปโหลด PDF', Icon: UploadSimple }, ...(plan && !plan.file_url && !sourceUrl(plan) ? [{ value: '', label: 'เฉพาะรายละเอียดเดิม', Icon: Notebook }] : [])].map(({ value, label, Icon }) => <button type="button" key={value} aria-pressed={source === value} disabled={busy} onClick={() => { if (value === source) return; setSource(value as typeof source); setDirty(true); setFile(null); setCover(null); setPages(null); setFileError(''); }}><Icon size={19} />{label}</button>)}</div>
            {source === 'link' && <><TextField fullWidth required type="url" label="ลิงก์เอกสาร HTTP / HTTPS" placeholder="Google Drive, OneDrive หรือเว็บไซต์เอกสาร" value={draft.external_url} disabled={busy} slotProps={{ htmlInput: { maxLength: 2048 } }} onChange={event => update({ external_url: event.target.value })} /><p className="lesson-plan-muted">ใช้ลิงก์แชร์ที่ผู้เปิดมีสิทธิ์เข้าถึงได้ รองรับ Google Drive, OneDrive และลิงก์เว็บทั่วไป</p>{draft.external_url && !preview && <Alert severity="error">ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https:// และไม่มีข้อมูลเข้าสู่ระบบในลิงก์</Alert>}</>}
            {source && <label className="lesson-plan-upload"><FilePdf size={30} weight="duotone" /><span><strong>{file ? file.name : source === 'link' ? 'แนบ PDF เพิ่มเพื่อสร้างปก (ไม่บังคับ)' : plan?.file_url ? 'เลือก PDF ใหม่เมื่อต้องการเปลี่ยนไฟล์' : 'เลือกไฟล์ PDF'}</strong><small>{source === 'link' ? 'กรณีลิงก์ไม่เปิดเผยหน้าปก แนบสำเนา PDF เพื่อใช้หน้าแรกเป็นปก ลิงก์เดิมยังเป็นทางเข้าเอกสาร' : 'สูงสุด 20 MB · ใช้หน้าแรกเป็นปกให้อัตโนมัติ'}</small></span><input key={`${source}:${fileInputKey}`} type="file" accept="application/pdf,.pdf" disabled={busy} required={source === 'pdf' && !plan?.file_url} onChange={event => void selectPdf(event.target.files?.[0] ?? null)} /></label>}
            {file && <Button appearance="outline" size="small" disabled={busy} icon={<Trash size={16} />} onClick={() => { generation.current++; setFile(null); setCover(null); setPages(null); setFileError(''); setFileInputKey(value => value + 1); }}>นำไฟล์ที่เลือกออก</Button>}
            {rendering && <p role="status" className="lesson-plan-muted">กำลังอ่าน PDF และสร้างหน้าปก…</p>}{fileError && <Alert severity="error">{fileError}</Alert>}{coverUrl && <div className="lesson-plan-generated-cover"><img src={coverUrl} alt="ตัวอย่างหน้าปกจากหน้าแรกของ PDF" /><div><CheckCircle size={22} weight="fill" /><strong>ใช้หน้าแรกเป็นปกเรียบร้อย</strong><span>{pages} หน้า · {file?.name}</span></div></div>}
        </section>
        <TextField className="lesson-plan-form-wide" fullWidth multiline minRows={3} label="วัตถุประสงค์" value={draft.objectives} disabled={busy} slotProps={{ htmlInput: { maxLength: 10000 } }} onChange={event => update({ objectives: event.target.value })} />
        <TextField className="lesson-plan-form-wide" fullWidth multiline minRows={3} label="กิจกรรมการเรียนรู้" value={draft.activities} disabled={busy} slotProps={{ htmlInput: { maxLength: 10000 } }} onChange={event => update({ activities: event.target.value })} />
        <TextField className="lesson-plan-form-wide" fullWidth multiline minRows={3} label="การวัดและประเมินผล" value={draft.assessment} disabled={busy} slotProps={{ htmlInput: { maxLength: 10000 } }} onChange={event => update({ assessment: event.target.value })} />
        {save.isError && <Alert className="lesson-plan-form-wide" severity="error">{errorText(save.error)}</Alert>}
    </div></DialogContent><DialogActions><Button disabled={busy} onClick={closeEditor}>ยกเลิก</Button><Button appearance="primary" type="submit" disabled={busy || Boolean(fileError) || Boolean(file && !cover) || (source === 'link' && !preview)}>{rendering ? 'กำลังสร้างปก…' : save.isPending ? 'กำลังบันทึก…' : 'บันทึกแผนการสอน'}</Button></DialogActions></form></Dialog>;
}
