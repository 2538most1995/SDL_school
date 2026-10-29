import { Alert, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, TextField, Tooltip } from '@mui/material';
import { ArrowSquareOut, BookOpen, BookOpenText, CalendarBlank, CheckCircle, DownloadSimple, FileImage, FilePdf, LinkSimple, MagnifyingGlass, Notebook, PencilSimple, Plus, Sparkle, Trash, UploadSimple, User } from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useDeferredValue, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Button } from '../../components/MaterialUI';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { withAppBasePath } from '../../lib/urls';
import { getFeatureBlob, getFeatureData, sendFeatureData } from '../api';
import { documentOpenTarget, documentPreview } from './lesson-plans/documentLinks';
import { loadPlanCover } from './lesson-plans/coverSource';
import { lessonPlanFilePath } from './lesson-plans/fileUrls';
import { filterLessonPlans, planScope, planScopeLabels, type PlanScope } from './lesson-plans/planFilters';
import { generateSmartCoverBlob } from './lesson-plans/smartCover';
import './lesson-plans.css';

type LessonPlan = {
    id: string; title: string; description: string; course: string; timing: string; status: string; can_edit: boolean;
    teacher_name?: string; external_url?: string | null; file_url?: string | null; cover_url?: string | null;
    filename?: string | null; file_size?: number | null;
    page_count?: number | null; plan_scope?: PlanScope; raw: Record<string, string>;
};
type Draft = { title: string; subject: string; level: string; semester: string; external_url: string };
const base = '/api/v1/learning/lesson-plans';
const levels = [{ value: '1', label: 'ประถม' }, { value: '2', label: 'ม.ต้น' }, { value: '3', label: 'ม.ปลาย' }];
const errorText = (error: unknown) => error instanceof Error ? error.message : 'ดำเนินการไม่สำเร็จ กรุณาลองอีกครั้ง';
const sourceUrl = (plan: LessonPlan) => plan.external_url || plan.raw?.external_url || '';

function PlanCover({ plan }: { plan: LessonPlan }) {
    const element = useRef<HTMLDivElement>(null);
    const [visible, setVisible] = useState(false);
    const [src, setSrc] = useState('');
    const [failed, setFailed] = useState(false);
    const [thumbnailFailed, setThumbnailFailed] = useState(false);
    const external = documentPreview(sourceUrl(plan));
    useEffect(() => { setThumbnailFailed(false); setFailed(false); }, [external?.thumbnailUrl]);
    useEffect(() => {
        if (!element.current) return;
        if (typeof IntersectionObserver === 'undefined') { setVisible(true); return; }
        const observer = new IntersectionObserver(entries => { if (entries.some(entry => entry.isIntersecting)) { setVisible(true); observer.disconnect(); } }, { rootMargin: '240px' });
        observer.observe(element.current);
        return () => observer.disconnect();
    }, []);
    useEffect(() => {
        if (!visible || (!plan.cover_url && !plan.file_url)) return;
        if (!plan.cover_url && external?.thumbnailUrl && !thumbnailFailed) return;
        let active = true;
        let url = '';
        setFailed(false); setSrc('');
        const path = `${base}/${encodeURIComponent(plan.id)}`;
        loadPlanCover({ coverPath: plan.cover_url ? `${path}/cover` : null, pdfPath: plan.file_url ? `${path}/file` : null }, getFeatureBlob, async pdf => {
            const { renderPdfCover } = await import('./lesson-plans/pdfCover');
            return renderPdfCover(pdf);
        }).then(blob => {
            if (active && blob) { url = URL.createObjectURL(blob); setSrc(url); setFailed(false); }
        }).catch(() => { if (active) setFailed(true); });
        return () => { active = false; if (url) URL.revokeObjectURL(url); };
    }, [plan.id, plan.cover_url, plan.file_url, visible, external?.thumbnailUrl, thumbnailFailed]);
    const thumbnail = visible && !plan.cover_url && !thumbnailFailed ? external?.thumbnailUrl : '';
    const image = src || thumbnail || '';
    const levelVal = String(plan.raw?.level || '');
    return <div ref={element} data-level={levelVal} className={`lesson-plan-cover ${image && !failed ? 'has-image' : ''}`}>
        {image && !failed ? <img src={image} loading="lazy" referrerPolicy="no-referrer" alt={`หน้าปก ${plan.title}`} onError={() => { if (!src && thumbnail) setThumbnailFailed(true); else setFailed(true); }} /> : <div className="lesson-plan-cover-fallback"><span><BookOpen size={39} weight="duotone" /></span><strong>{plan.title}</strong>{plan.teacher_name && <small>{plan.teacher_name}</small>}</div>}
        <span className="lesson-plan-source">{plan.file_url && !sourceUrl(plan) ? <><FilePdf size={15} /> PDF</> : sourceUrl(plan) ? <><LinkSimple size={15} /> {external?.provider || 'ลิงก์'}</> : <><Notebook size={15} /> แผนการสอน</>}</span>
    </div>;
}

function PlanOpen({ plan, cover = false, children, read }: {
    plan: LessonPlan; cover?: boolean; children?: ReactNode;
    read: (plan: LessonPlan) => void;
}) {
    const target = documentOpenTarget(sourceUrl(plan), Boolean(plan.file_url));
    const label = `เปิดอ่าน ${plan.title}`;
    if (target.kind === 'external') {
        return cover
            ? <a className="lesson-plan-cover-button" href={target.url} target="_blank" rel="noopener noreferrer" aria-label={label}>{children}</a>
            : <Button as="a" appearance="primary" size="small" className="lesson-plan-open-btn" icon={<BookOpen size={16} />} href={target.url} target="_blank" rel="noopener noreferrer">เปิดอ่าน</Button>;
    }
    const open = () => read(plan);
    return cover
        ? <button type="button" className="lesson-plan-cover-button" aria-label={label} onClick={open}>{children}</button>
        : <Button appearance="primary" size="small" className="lesson-plan-open-btn" icon={<BookOpen size={16} />} onClick={open}>เปิดอ่าน</Button>;
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
            <PlanOpen plan={plan} cover read={setReading}><PlanCover plan={plan} /><span className="lesson-plan-cover-hover"><BookOpen size={22} /> เปิดอ่านทันที</span></PlanOpen>
            <div className="lesson-plan-card-body">{planScope(plan) === 'all_subjects' && <span className="lesson-plan-scope-badge">แผนรวมทุกวิชา</span>}<h3>{plan.title}</h3>{plan.teacher_name && <p className="lesson-plan-author"><User size={14} />{plan.teacher_name}</p>}<p className="lesson-plan-card-meta">{plan.course || 'ไม่ระบุรายวิชา'}{plan.raw?.level && <> · {levels.find(item => item.value === String(plan.raw.level))?.label || plan.raw.level}</>}</p><div className="lesson-plan-card-bottom"><span><CalendarBlank size={14} /> {plan.timing || 'ไม่ระบุภาคเรียน'}</span>{plan.page_count ? <span><BookOpen size={14} /> {plan.page_count} หน้า</span> : <span>{plan.status}</span>}</div>
                <div className="lesson-plan-card-actions">
                    <PlanOpen plan={plan} read={setReading} />
                    {canManage && plan.can_edit && (
                        <div className="lesson-plan-card-icons">
                            <Tooltip title="แก้ไขแผนการสอน" arrow>
                                <IconButton
                                    size="small"
                                    className="lesson-plan-icon-btn lesson-plan-icon-btn--edit"
                                    aria-label={`แก้ไข ${plan.title}`}
                                    onClick={() => setEditing(plan)}
                                >
                                    <PencilSimple size={17} />
                                </IconButton>
                            </Tooltip>
                            <Tooltip title="ลบแผนการสอน" arrow>
                                <IconButton
                                    size="small"
                                    className="lesson-plan-icon-btn lesson-plan-icon-btn--delete"
                                    aria-label={`ลบ ${plan.title}`}
                                    onClick={() => { remove.reset(); setDeleting(plan); }}
                                >
                                    <Trash size={17} />
                                </IconButton>
                            </Tooltip>
                        </div>
                    )}
                </div>
            </div>
        </article>)}</div>}
        {rows.length >= 250 && <p className="lesson-plan-muted">แสดงแผนล่าสุดสูงสุด 250 รายการ</p>}
        {editing && <PlanEditor key={editing === 'new' ? 'new' : editing.id} plan={editing === 'new' ? null : editing} initialScope={scope || 'subject'} initialLevel={level || '1'} close={() => setEditing(null)} saved={async () => { setEditing(null); await refresh(); }} />}
        {reading && <PlanReader key={reading.id} plan={reading} close={() => setReading(null)} />}
        <Dialog open={Boolean(deleting)} onClose={() => { if (!remove.isPending) setDeleting(null); }} fullWidth maxWidth="xs"><DialogTitle>ลบแผนการสอนนี้?</DialogTitle><DialogContent>ลบ “{deleting?.title}” พร้อมไฟล์แนบและหน้าปก{remove.isError && <Alert className="mt-3" severity="error">{errorText(remove.error)}</Alert>}</DialogContent><DialogActions><Button disabled={remove.isPending} onClick={() => setDeleting(null)}>ยกเลิก</Button><Button appearance="primary" disabled={remove.isPending} onClick={() => { if (deleting) remove.mutate(deleting); }}>{remove.isPending ? 'กำลังลบ…' : 'ยืนยันลบ'}</Button></DialogActions></Dialog>
    </div>;
}

function PlanReader({ plan, close }: { plan: LessonPlan; close: () => void }) {
    const external = documentPreview(sourceUrl(plan));
    const activeExternal = external;
    const district = window.localStorage.getItem('sena-district-id') ?? '';
    const url = plan.file_url ? withAppBasePath(lessonPlanFilePath(plan.id, plan.file_url, district)) : '';
    const download = plan.file_url ? withAppBasePath(lessonPlanFilePath(plan.id, plan.file_url, district, true)) : '';
    return <Dialog className="lesson-plan-dialog lesson-plan-reader" open onClose={close} fullWidth maxWidth="lg"><DialogTitle>{plan.title}</DialogTitle><DialogContent>
        <div className="lesson-plan-reader-header-bar">
            {activeExternal ? (
                <>
                    <p className="lesson-plan-reader-note">เอกสารจาก {activeExternal.provider} · แนะนำเปิดในแท็บใหม่เพื่อความรวดเร็วและฟังก์ชันครบถ้วน</p>
                    <Button as="a" appearance="primary" size="small" icon={<ArrowSquareOut size={16} />} href={activeExternal.url} target="_blank" rel="noopener noreferrer">
                        เปิดใน {activeExternal.provider} (เร็วที่สุด)
                    </Button>
                </>
            ) : url ? (
                <>
                    <p className="lesson-plan-reader-note">เอกสาร PDF ในระบบ · สามารถเปิดเต็มจอในแท็บใหม่เพื่อการอ่านที่รวดเร็ว</p>
                    <Button as="a" appearance="primary" size="small" icon={<ArrowSquareOut size={16} />} href={url} target="_blank" rel="noopener noreferrer">
                        เปิดอ่านเต็มจอในแท็บใหม่ (เร็วที่สุด)
                    </Button>
                </>
            ) : null}
        </div>
        {activeExternal ? <>{activeExternal.embedUrl ? <iframe className="lesson-plan-document" src={activeExternal.embedUrl} title={`แผนการสอน ${plan.title}`} referrerPolicy="no-referrer" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-downloads" allowFullScreen /> : <div className="lesson-plan-reader-link"><LinkSimple size={43} /><h3>พร้อมเปิดเอกสารของคุณ</h3><p>ลิงก์นี้เปิดผ่านเว็บไซต์ต้นทางเพื่อความรวดเร็ว</p><Button as="a" appearance="primary" icon={<ArrowSquareOut size={18} />} href={activeExternal.url} target="_blank" rel="noopener noreferrer">เปิดเอกสารต้นทางทันที</Button></div>}</> : !url ? <Alert severity="warning">ลิงก์เอกสารนี้ไม่ถูกต้อง กรุณาแก้ไขลิงก์หรือแนบ PDF</Alert> : <iframe className="lesson-plan-document" src={url} title={`แผนการสอน ${plan.title}`} referrerPolicy="same-origin" />}
    </DialogContent><DialogActions><Button onClick={close}>ปิด</Button>{external && <Button as="a" appearance="primary" icon={<ArrowSquareOut />} href={external.url} target="_blank" rel="noopener noreferrer">เปิดในแท็บใหม่</Button>}{download && <Button as="a" appearance="outline" icon={<DownloadSimple />} href={download} download={`${plan.title}.pdf`}>ดาวน์โหลด PDF</Button>}</DialogActions></Dialog>;
}

function PlanEditor({ plan, initialScope, initialLevel, close, saved }: { plan: LessonPlan | null; initialScope: PlanScope; initialLevel: string; close: () => void; saved: () => Promise<void> }) {
    const [draft, setDraft] = useState<Draft>(() => ({
        title: plan?.title ?? '',
        subject: plan?.raw?.subject ?? '',
        level: plan?.raw?.level || initialLevel,
        semester: plan?.raw?.semester ?? '',
        external_url: plan ? sourceUrl(plan) : '',
    }));
    const [scope, setScope] = useState<PlanScope>(() => plan ? planScope(plan) : initialScope);
    const [source, setSource] = useState<'link' | 'pdf' | ''>(() => plan ? sourceUrl(plan) ? 'link' : plan.file_url ? 'pdf' : '' : 'link');
    const [file, setFile] = useState<File | null>(null);
    const [fileInputKey, setFileInputKey] = useState(0);
    const [cover, setCover] = useState<Blob | null>(null);
    const [coverUrl, setCoverUrl] = useState('');
    const [coverDescription, setCoverDescription] = useState('');
    const [pages, setPages] = useState<number | null>(() => plan?.page_count ?? null);
    const [customPageCount, setCustomPageCount] = useState<string>(() => plan?.page_count ? String(plan.page_count) : '');
    const [clearPdf, setClearPdf] = useState(false);
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

    const handleCreateSmartCover = async () => {
        setDirty(true);
        setRendering(true);
        setFileError('');
        try {
            const levelObj = levels.find(item => item.value === draft.level);
            const districtText = window.localStorage.getItem('sena-district-name') || undefined;
            const blob = await generateSmartCoverBlob({
                title: draft.title.trim() || 'แผนการสอน',
                level: draft.level,
                levelLabel: levelObj?.label ? `ระดับ${levelObj.label}` : undefined,
                scopeLabel: scope === 'all_subjects' ? 'แผนรวมทุกวิชา' : (draft.subject ? `รายวิชา ${draft.subject}` : undefined),
                subject: draft.subject,
                semester: draft.semester,
                districtName: districtText,
                teacherName: plan?.teacher_name || undefined,
            });
            setCover(blob);
            setCoverDescription('ปกสำเร็จรูปอัตโนมัติ (ภาพขนาดเล็ก ~35 KB)');
        } catch (reason) {
            setFileError(errorText(reason));
        } finally {
            setRendering(false);
        }
    };

    const handleSelectCoverImage = (event: React.ChangeEvent<HTMLInputElement>) => {
        const next = event.target.files?.[0] ?? null;
        if (!next) return;
        setDirty(true);
        if (next.size > 2 * 1024 * 1024) {
            setFileError('กรุณาเลือกไฟล์ภาพขนาดไม่เกิน 2 MB');
            return;
        }
        if (!/^image\/(jpe?g|png|webp)$/i.test(next.type)) {
            setFileError('รองรับไฟล์ภาพ JPG, PNG หรือ WebP เท่านั้น');
            return;
        }
        setFileError('');
        setCover(next);
        setCoverDescription(`ไฟล์ภาพ ${next.name} (${(next.size / 1024).toFixed(0)} KB)`);
    };

    const selectPdf = async (next: File | null) => {
        const id = ++generation.current;
        setDirty(true); setFile(next); setCover(null); setPages(null); setFileError('');
        if (!next) { setRendering(false); return; }
        if (next.size > 20 * 1024 * 1024 || !/\.pdf$/i.test(next.name)) { setRendering(false); setFileError('กรุณาเลือกไฟล์ PDF ขนาดไม่เกิน 20 MB'); return; }
        setRendering(true);
        try {
            const { renderPdfCover } = await import('./lesson-plans/pdfCover');
            const result = await renderPdfCover(next);
            if (generation.current === id) {
                setCover(result.blob);
                setPages(result.pages);
                setCoverDescription(`หน้าแรกจาก PDF · ${result.pages} หน้า`);
            }
        } catch (reason) { if (generation.current === id) setFileError(errorText(reason)); }
        finally { if (generation.current === id) setRendering(false); }
    };

    const save = useMutation({
        mutationFn: () => {
            const form = new FormData();
            Object.entries(draft).forEach(([key, value]) => { if (key !== 'external_url' || source === 'link') form.append(key, value); });
            form.set('plan_scope', scope);
            if (scope === 'all_subjects') form.set('subject', '');
            if (source) form.append('source_type', source);
            if (source === 'pdf' && file) form.append('pdf', file);
            if (clearPdf) form.append('clear_pdf', '1');
            if (cover) form.append('cover_image', cover, 'cover.jpg');
            const parsedPages = source === 'link'
                ? (customPageCount.trim() ? parseInt(customPageCount.trim(), 10) : null)
                : pages;
            if (parsedPages && !Number.isNaN(parsedPages) && parsedPages > 0) form.append('page_count', String(parsedPages));
            if (plan) form.append('_method', 'PATCH');
            return sendFeatureData(`${base}${plan ? `/${plan.id}` : ''}`, 'POST', form);
        },
        onSuccess: saved,
    });

    const update = (values: Partial<Draft>) => { setDirty(true); setDraft(current => ({ ...current, ...values })); };
    const busy = save.isPending || rendering;
    const closeEditor = () => { if (!busy && (!dirty || window.confirm('มีแผนการสอนที่ยังไม่ได้บันทึก ต้องการปิดแบบร่างนี้หรือไม่?'))) close(); };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!busy && !fileError && (source !== 'pdf' || file || plan?.file_url) && (source !== 'link' || preview)) {
            save.mutate();
        }
    };

    return <Dialog className="lesson-plan-dialog" open onClose={closeEditor} fullWidth maxWidth="md"><form onSubmit={submit}><DialogTitle>{plan ? 'แก้ไขแผนการสอน' : 'เพิ่มแผนการสอน'}</DialogTitle><DialogContent><div className="lesson-plan-editor-grid">
        <TextField className="lesson-plan-form-wide" fullWidth required label="ชื่อแผนการสอน" value={draft.title} disabled={busy} slotProps={{ htmlInput: { maxLength: 220 } }} onChange={event => update({ title: event.target.value })} />
        <fieldset className="lesson-plan-form-wide"><legend>ขอบเขตของแผน</legend><div className="lesson-plan-choice-buttons" role="group" aria-label="ขอบเขตของแผน">{(Object.entries(planScopeLabels) as [PlanScope, string][]).map(([value, label]) => <button type="button" key={value} aria-pressed={scope === value} disabled={busy} onClick={() => { setScope(value); setDirty(true); }}>{label}{scope === value && <CheckCircle size={16} weight="fill" />}</button>)}</div></fieldset>
        {scope === 'subject' ? <TextField fullWidth required label="รายวิชา / รหัสวิชา" value={draft.subject} disabled={busy} slotProps={{ htmlInput: { maxLength: 32 } }} onChange={event => update({ subject: event.target.value })} /> : <Alert severity="info" className="lesson-plan-form-wide">แผนนี้รวมทุกวิชาของระดับ{levels.find(item => item.value === draft.level)?.label} เลือกระดับและแนบเอกสารได้โดยไม่ต้องกรอกรหัสวิชา</Alert>}
        <TextField className={scope === 'all_subjects' ? 'lesson-plan-form-wide' : undefined} fullWidth required label="ภาคเรียน" placeholder="เช่น 2/2569" value={draft.semester} disabled={busy} slotProps={{ htmlInput: { maxLength: 16 } }} onChange={event => update({ semester: event.target.value })} />
        <fieldset className="lesson-plan-form-wide"><legend>ระดับการศึกษา</legend><div className="lesson-plan-choice-buttons" role="group" aria-label="ระดับแผนการสอน">{levels.map(item => <button type="button" key={item.value} aria-pressed={draft.level === item.value} disabled={busy} onClick={() => update({ level: item.value })}>{item.label}{draft.level === item.value && <CheckCircle size={16} weight="fill" />}</button>)}</div></fieldset>
        <section className="lesson-plan-source-editor lesson-plan-form-wide"><h3>เอกสารแผนการสอน</h3><div className="lesson-plan-choice-buttons" role="group" aria-label="วิธีแนบเอกสาร">{[{ value: 'link', label: 'แนบลิงก์ (แนะนำ)', Icon: LinkSimple }, { value: 'pdf', label: 'อัปโหลด PDF ในระบบ', Icon: UploadSimple }, ...(plan && !plan.file_url && !sourceUrl(plan) ? [{ value: '', label: 'เฉพาะรายละเอียดเดิม', Icon: Notebook }] : [])].map(({ value, label, Icon }) => <button type="button" key={value} aria-pressed={source === value} disabled={busy} onClick={() => { if (value === source) return; setSource(value as typeof source); setDirty(true); setFile(null); setCover(null); setPages(null); setCoverDescription(''); setFileError(''); }}><Icon size={19} />{label}</button>)}</div>
            {source === 'link' && <>
                <TextField fullWidth required type="url" label="ลิงก์เอกสาร HTTP / HTTPS" placeholder="Google Drive, OneDrive หรือเว็บไซต์เอกสาร" value={draft.external_url} disabled={busy} slotProps={{ htmlInput: { maxLength: 2048 } }} onChange={event => update({ external_url: event.target.value })} />
                <p className="lesson-plan-muted">ใช้ลิงก์แชร์ที่ผู้เปิดมีสิทธิ์เข้าถึงได้ รองรับ Google Drive, OneDrive และลิงก์เว็บทั่วไป</p>
                {draft.external_url && !preview && <Alert severity="error">ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https:// และไม่มีข้อมูลเข้าสู่ระบบในลิงก์</Alert>}

                {preview?.thumbnailUrl && !cover && (
                    <div className="lesson-plan-generated-cover">
                        <img src={preview.thumbnailUrl} alt="ภาพปกจาก Google Drive" referrerPolicy="no-referrer" />
                        <div>
                            <CheckCircle size={22} weight="fill" />
                            <strong>ดึงภาพปกจาก Google Drive อัตโนมัติ</strong>
                            <span>พร้อมแสดงภาพปกโดยตรงจากลิงก์ ไม่ต้องดาวน์โหลดไฟล์ PDF</span>
                        </div>
                    </div>
                )}

                <div className="lesson-plan-cover-section">
                    <label className="text-sm font-bold text-slate-700 dark:text-slate-300">ภาพหน้าปกของแผนการสอน</label>
                    <div className="lesson-plan-cover-actions">
                        <Button type="button" appearance="primary" size="small" icon={<Sparkle size={18} weight="fill" />} disabled={busy} onClick={handleCreateSmartCover}>
                            สร้างปกสำเร็จรูปอัตโนมัติ
                        </Button>
                        <label className="lesson-plan-cover-upload-btn">
                            <FileImage size={18} />
                            <span>เลือกไฟล์ภาพหน้าปก (JPG / PNG / WebP)</span>
                            <input type="file" accept="image/jpeg,image/png,image/webp" disabled={busy} onChange={handleSelectCoverImage} />
                        </label>
                    </div>
                    {coverUrl && (
                        <div className="lesson-plan-generated-cover mt-3">
                            <img src={coverUrl} alt="ตัวอย่างหน้าปกที่เลือก" />
                            <div>
                                <CheckCircle size={22} weight="fill" />
                                <strong>ตั้งค่าภาพหน้าปกเรียบร้อย</strong>
                                <span>{coverDescription || 'พร้อมบันทึก'}</span>
                                <Button appearance="outline" size="small" disabled={busy} icon={<Trash size={15} />} onClick={() => { setCover(null); setCoverDescription(''); }}>
                                    นำภาพปกออก
                                </Button>
                            </div>
                        </div>
                    )}
                </div>

                <TextField fullWidth type="number" size="small" label="จำนวนหน้า (ไม่บังคับ)" placeholder="เช่น 222" value={customPageCount} disabled={busy} slotProps={{ htmlInput: { min: 1, max: 10000 } }} onChange={event => { setDirty(true); setCustomPageCount(event.target.value); }} helperText="ระบุจำนวนหน้าเพื่อแสดงบนการ์ดแผนการสอน โดยไม่ต้องอัปโหลดไฟล์ PDF ขนาดใหญ่" />

                {plan?.file_url && (
                    <Alert severity={clearPdf ? "warning" : "info"} action={<Button size="small" onClick={() => { setDirty(true); setClearPdf(val => !val); }}>{clearPdf ? 'ยกเลิกการลบ' : 'ลบไฟล์ PDF ออก'}</Button>}>
                        {clearPdf ? 'จะลบไฟล์ PDF สำเนาออกจากเซิร์ฟเวอร์เมื่อกดบันทึก (ช่วยประหยัดพื้นที่)' : `แผนนี้มีไฟล์ PDF เดิมขนาด ${plan.file_size ? `${(plan.file_size / (1024 * 1024)).toFixed(1)} MB` : ''} บันทึกอยู่ในระบบ สามารถกดลบออกเพื่อประหยัดพื้นที่เซิร์ฟเวอร์`}
                    </Alert>
                )}
            </>}
            {source === 'pdf' && <>
                <label className="lesson-plan-upload"><FilePdf size={30} weight="duotone" /><span><strong>{file ? file.name : plan?.file_url ? 'เลือก PDF ใหม่เมื่อต้องการเปลี่ยนไฟล์' : 'เลือกไฟล์ PDF'}</strong><small>สูงสุด 20 MB · ใช้หน้าแรกเป็นปกให้อัตโนมัติ</small></span><input key={`${source}:${fileInputKey}`} type="file" accept="application/pdf,.pdf" disabled={busy} required={source === 'pdf' && !plan?.file_url} onChange={event => void selectPdf(event.target.files?.[0] ?? null)} /></label>
                {file && <Button appearance="outline" size="small" disabled={busy} icon={<Trash size={16} />} onClick={() => { generation.current++; setFile(null); setCover(null); setPages(null); setCoverDescription(''); setFileError(''); setFileInputKey(value => value + 1); }}>นำไฟล์ที่เลือกออก</Button>}
                {rendering && <p role="status" className="lesson-plan-muted">กำลังอ่าน PDF และสร้างหน้าปก…</p>}
                {coverUrl && <div className="lesson-plan-generated-cover"><img src={coverUrl} alt="ตัวอย่างหน้าปกจากหน้าแรกของ PDF" /><div><CheckCircle size={22} weight="fill" /><strong>ใช้หน้าแรกเป็นปกเรียบร้อย</strong><span>{pages} หน้า · {file?.name || coverDescription}</span></div></div>}
            </>}
            {fileError && <Alert severity="error">{fileError}</Alert>}
        </section>
        {save.isError && <Alert className="lesson-plan-form-wide" severity="error">{errorText(save.error)}</Alert>}
    </div></DialogContent><DialogActions><Button disabled={busy} onClick={closeEditor}>ยกเลิก</Button><Button appearance="primary" type="submit" disabled={busy || Boolean(fileError) || (source === 'pdf' && !file && !plan?.file_url) || (source === 'link' && !preview)}>{rendering ? 'กำลังสร้างปก…' : save.isPending ? 'กำลังบันทึก…' : 'บันทึกแผนการสอน'}</Button></DialogActions></form></Dialog>;
}
