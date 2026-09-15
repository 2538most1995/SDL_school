import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    ArrowSquareOut,
    CalendarCheck,
    CheckCircle,
    Eye,
    EyeSlash,
    FloppyDisk,
    Image as ImageIcon,
    Megaphone,
    PencilSimple,
    Plus,
    Trash,
    UploadSimple,
    X,
} from '@phosphor-icons/react';
import { useRef, useState, type FormEvent } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatTile } from '../../components/StatTile';
import { StatusBadge } from '../../components/StatusBadge';
import { getFeatureData, sendFeatureData } from '../api';
import { withAppBasePath } from '../../lib/urls';

type Announcement = {
    id: number;
    title: string;
    message: string;
    button_label: string | null;
    button_url: string | null;
    image_url: string | null;
    show_exam_link: boolean;
    is_active: boolean;
    created_by_name: string | null;
    created_at: string | null;
    updated_at: string | null;
};

type AnnouncementDraft = {
    title: string;
    message: string;
    button_label: string;
    button_url: string;
    show_exam_link: boolean;
    is_active: boolean;
    image: File | null;
    remove_image: boolean;
    existing_image_url: string | null;
};

const blankDraft: AnnouncementDraft = {
    title: '',
    message: '',
    button_label: 'ดูรายละเอียด',
    button_url: '',
    show_exam_link: false,
    is_active: false,
    image: null,
    remove_image: false,
    existing_image_url: null,
};

const primaryButton = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full bg-brand-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-300 active:scale-[0.98]';
const secondaryButton = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:border-brand-300 hover:text-brand-800 disabled:cursor-not-allowed disabled:opacity-50 active:scale-[0.98]';
const inputClass = 'h-11 w-full rounded-xl border border-slate-300 bg-white px-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-100';

function formatDate(value: string | null): string {
    if (!value) return '-';

    return new Intl.DateTimeFormat('th-TH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function Field({ label, children, hint }: { label: string; children: React.ReactNode; hint?: string }) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-bold text-slate-700">{label}</span>
            {children}
            {hint && <span className="mt-1.5 block text-xs leading-5 text-slate-500">{hint}</span>}
        </label>
    );
}

function buildFormData(draft: AnnouncementDraft): FormData {
    const form = new FormData();
    form.append('title', draft.title);
    form.append('message', draft.message);
    form.append('button_label', draft.button_label);
    form.append('button_url', draft.button_url);
    form.append('show_exam_link', draft.show_exam_link ? '1' : '0');
    form.append('is_active', draft.is_active ? '1' : '0');

    if (draft.image) {
        form.append('image', draft.image);
    }

    if (draft.remove_image) {
        form.append('remove_image', '1');
    }

    return form;
}

function ImagePreview({ src, onRemove }: { src: string; onRemove: () => void }) {
    return (
        <div className="group relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
            <img src={src} alt="ภาพประกอบประกาศ" className="block h-40 w-full object-cover" />
            <button
                type="button"
                onClick={onRemove}
                className="absolute right-2 top-2 grid size-8 place-items-center rounded-full bg-slate-950/60 text-white opacity-0 transition group-hover:opacity-100"
                aria-label="ลบรูปภาพ"
            >
                <X size={14} weight="bold" />
            </button>
        </div>
    );
}

export function AdminAnnouncementsPage() {
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState<Announcement | 'new' | null>(null);
    const [draft, setDraft] = useState<AnnouncementDraft>(blankDraft);
    const imageInputRef = useRef<HTMLInputElement>(null);
    const announcements = useQuery({
        queryKey: ['admin', 'announcements'],
        queryFn: ({ signal }) => getFeatureData<Announcement[]>('/api/v1/admin/announcements', signal),
    });
    const save = useMutation({
        meta: { notification: { success: editing === 'new' ? 'สร้างประกาศเรียบร้อยแล้ว' : 'แก้ไขประกาศเรียบร้อยแล้ว' } },
        mutationFn: () => {
            const formData = buildFormData(draft);
            return editing === 'new'
                ? sendFeatureData<Announcement>('/api/v1/admin/announcements', 'POST', formData)
                : sendFeatureData<Announcement>(`/api/v1/admin/announcements/${(editing as Announcement).id}`, 'PATCH', formData);
        },
        onSuccess: async () => {
            setEditing(null);
            setDraft(blankDraft);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['admin', 'announcements'] }),
                queryClient.invalidateQueries({ queryKey: ['student', 'active-announcement'] }),
            ]);
        },
    });
    const changeStatus = useMutation({
        meta: { notification: { success: 'เปลี่ยนสถานะประกาศเรียบร้อยแล้ว' } },
        mutationFn: ({ announcement, isActive }: { announcement: Announcement; isActive: boolean }) => sendFeatureData<Announcement>(
            `/api/v1/admin/announcements/${announcement.id}/status`,
            'PATCH',
            { is_active: isActive },
        ),
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['admin', 'announcements'] }),
                queryClient.invalidateQueries({ queryKey: ['student', 'active-announcement'] }),
            ]);
        },
    });
    const deleteAnnouncement = useMutation({
        meta: { notification: { success: 'ลบประกาศเรียบร้อยแล้ว' } },
        mutationFn: (announcementId: number) => sendFeatureData<{ id: number }>(
            `/api/v1/admin/announcements/${announcementId}`,
            'DELETE',
        ),
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['admin', 'announcements'] }),
                queryClient.invalidateQueries({ queryKey: ['student', 'active-announcement'] }),
            ]);
        },
    });

    const items = announcements.data?.data ?? [];
    const active = items.find((announcement) => announcement.is_active) ?? null;

    function confirmDelete(announcement: Announcement) {
        if (window.confirm(`ยืนยันลบประกาศ "${announcement.title}" ใช่หรือไม่?\n\nหากลบแล้ว นักศึกษาจะไม่เห็นประกาศนี้อีกต่อไป`)) {
            deleteAnnouncement.mutate(announcement.id);
        }
    }

    function openCreate() {
        save.reset();
        setDraft({ ...blankDraft, is_active: active === null });
        setEditing('new');
    }

    function openEdit(announcement: Announcement) {
        save.reset();
        setDraft({
            title: announcement.title,
            message: announcement.message,
            button_label: announcement.button_label ?? 'ดูรายละเอียด',
            button_url: announcement.button_url ?? '',
            show_exam_link: announcement.show_exam_link,
            is_active: announcement.is_active,
            image: null,
            remove_image: false,
            existing_image_url: announcement.image_url,
        });
        setEditing(announcement);
    }

    function handleImageSelect(files: FileList | null) {
        if (!files || files.length === 0) return;
        setDraft({ ...draft, image: files[0], remove_image: false });
    }

    function removeImage() {
        setDraft({ ...draft, image: null, remove_image: true, existing_image_url: null });
        if (imageInputRef.current) imageInputRef.current.value = '';
    }

    const previewImageUrl = draft.image ? URL.createObjectURL(draft.image) : draft.existing_image_url ? withAppBasePath(draft.existing_image_url) : null;

    function submit(event: FormEvent) {
        event.preventDefault();
        save.mutate();
    }

    return (
        <div>
            <PageHeader
                category="จัดการระบบ"
                title="ประกาศป๊อปอัปสำหรับนักศึกษา"
                description="สร้างข้อความสำคัญพร้อมปุ่มลิงก์ รูปภาพ และลิงก์ตารางสอบ นักศึกษาของอำเภอนี้จะเห็นประกาศที่เปิดใช้งานหลังเข้าสู่ระบบ"
                icon={Megaphone}
                actions={<button type="button" onClick={openCreate} className={primaryButton}><Plus size={17} weight="bold" /> สร้างประกาศ</button>}
            />

            <div className="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="ประกาศทั้งหมด" value={items.length} detail="เก็บไว้แก้ไขและนำกลับมาใช้ได้" icon={Megaphone} tone="sky" />
                <StatTile label="กำลังแสดง" value={active ? '1 รายการ' : 'ปิดอยู่'} detail={active?.title ?? 'นักศึกษาจะยังไม่เห็นป๊อปอัป'} icon={active ? CheckCircle : EyeSlash} tone={active ? 'emerald' : 'amber'} />
                <StatTile label="ผู้ชม" value="นักศึกษา" detail="เฉพาะบัญชีในอำเภอนี้" icon={Eye} tone="sky" />
            </div>

            <Panel title="รายการประกาศ" description="เปิดใช้งานได้ครั้งละหนึ่งรายการ เมื่อเปิดประกาศใหม่ ระบบจะปิดรายการเดิมให้อัตโนมัติ">
                {announcements.isPending && <QuerySkeleton />}
                {announcements.isError && <QueryError onRetry={() => announcements.refetch()} />}
                {announcements.data && items.length === 0 && (
                    <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-12 text-center">
                        <span className="mx-auto grid size-12 place-items-center rounded-2xl bg-white text-brand-700 shadow-sm"><Megaphone size={25} weight="duotone" /></span>
                        <h2 className="mt-4 font-black text-slate-900">ยังไม่มีประกาศ</h2>
                        <p className="mt-1 text-sm text-slate-500">สร้างประกาศแรก แล้วเลือกเปิดแสดงให้นักศึกษาได้ทันที</p>
                        <button type="button" onClick={openCreate} className={`${primaryButton} mt-5`}><Plus size={17} weight="bold" /> สร้างประกาศแรก</button>
                    </div>
                )}
                {announcements.data && items.length > 0 && (
                    <div className="grid gap-3">
                        {items.map((announcement) => {
                            const changingThis = changeStatus.isPending && changeStatus.variables?.announcement.id === announcement.id;

                            return (
                                <article key={announcement.id} className={`rounded-2xl border p-4 transition sm:p-5 ${announcement.is_active ? 'border-brand-200 bg-brand-50/55 shadow-[0_12px_34px_rgb(30_64_175_/_0.08)]' : 'border-slate-200 bg-white'}`}>
                                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <StatusBadge tone={announcement.is_active ? 'success' : 'neutral'}>{announcement.is_active ? 'กำลังแสดง' : 'ปิดอยู่'}</StatusBadge>
                                                {announcement.image_url && <StatusBadge tone="info"><ImageIcon size={13} weight="bold" className="mr-0.5" /> มีรูป</StatusBadge>}
                                                {announcement.show_exam_link && <StatusBadge tone="info"><CalendarCheck size={13} weight="bold" className="mr-0.5" /> ตารางสอบ</StatusBadge>}
                                                <span className="text-xs text-slate-500">แก้ไขล่าสุด {formatDate(announcement.updated_at)}</span>
                                            </div>
                                            {announcement.image_url && (
                                                <img src={withAppBasePath(announcement.image_url)} alt="" className="mt-3 h-28 w-full rounded-xl border border-slate-200 object-cover" />
                                            )}
                                            <h3 className="mt-3 text-lg font-black leading-7 text-slate-950">{announcement.title}</h3>
                                            <p className="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{announcement.message}</p>
                                            {announcement.button_url && (
                                                <a href={announcement.button_url} target="_blank" rel="noreferrer" className="mt-3 inline-flex max-w-full items-center gap-1.5 text-sm font-bold text-brand-700 hover:text-brand-900">
                                                    <ArrowSquareOut size={16} weight="bold" />
                                                    <span className="truncate">{announcement.button_label}</span>
                                                </a>
                                            )}
                                            <p className="mt-3 text-xs text-slate-400">สร้างโดย {announcement.created_by_name ?? 'ผู้ดูแลอำเภอ'}</p>
                                        </div>
                                        <div className="flex shrink-0 flex-wrap gap-2">
                                            <button type="button" onClick={() => openEdit(announcement)} className={secondaryButton}><PencilSimple size={16} weight="bold" /> แก้ไข</button>
                                            <button
                                                type="button"
                                                disabled={changeStatus.isPending}
                                                onClick={() => changeStatus.mutate({ announcement, isActive: !announcement.is_active })}
                                                className={announcement.is_active ? secondaryButton : primaryButton}
                                            >
                                                {announcement.is_active ? <EyeSlash size={16} weight="bold" /> : <Eye size={16} weight="bold" />}
                                                {changingThis ? 'กำลังเปลี่ยน' : announcement.is_active ? 'ปิดประกาศ' : 'เปิดประกาศ'}
                                            </button>
                                            <button
                                                type="button"
                                                disabled={deleteAnnouncement.isPending}
                                                onClick={() => confirmDelete(announcement)}
                                                className="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-full border border-rose-200 bg-white px-3.5 py-2.5 text-sm font-bold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50 active:scale-[0.98]"
                                                title="ลบประกาศ"
                                                aria-label={`ลบประกาศ ${announcement.title}`}
                                            >
                                                <Trash size={16} weight="bold" />
                                                <span className="hidden sm:inline">ลบ</span>
                                            </button>
                                        </div>
                                    </div>
                                </article>
                            );
                        })}
                    </div>
                )}
                {changeStatus.error && <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">{changeStatus.error.message}</p>}
            </Panel>

            {editing && (
                <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/55 p-3 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="announcement-form-title" onMouseDown={(event) => { if (event.target === event.currentTarget) setEditing(null); }}>
                    <section className="my-auto w-full max-w-3xl overflow-hidden rounded-3xl border border-white/70 bg-white shadow-2xl">
                        <header className="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-brand-50 to-sky-50 px-5 py-4 sm:px-6">
                            <div>
                                <h2 id="announcement-form-title" className="text-xl font-black text-slate-950">{editing === 'new' ? 'สร้างประกาศใหม่' : 'แก้ไขประกาศ'}</h2>
                                <p className="mt-1 text-sm leading-6 text-slate-500">ข้อความจะแสดงเป็นตัวอักษรธรรมดา สามารถแนบรูปภาพและลิงก์ตารางสอบได้</p>
                            </div>
                            <button type="button" onClick={() => setEditing(null)} className="grid size-9 shrink-0 place-items-center rounded-full bg-white text-slate-500 shadow-sm hover:text-slate-950" aria-label="ปิด"><X size={18} weight="bold" /></button>
                        </header>
                        <form onSubmit={submit} className="max-h-[78vh] overflow-y-auto p-5 sm:p-6">
                            <div className="grid gap-4">
                                <Field label="หัวข้อประกาศ"><input required maxLength={160} value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} className={inputClass} placeholder="เช่น แจ้งกำหนดการสอบปลายภาค" autoFocus /></Field>
                                <Field label="ข้อความประกาศ" hint={`${draft.message.length.toLocaleString('th-TH')} / 4,000 ตัวอักษร`}><textarea required maxLength={4000} rows={5} value={draft.message} onChange={(event) => setDraft({ ...draft, message: event.target.value })} className="w-full resize-y rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-100" placeholder="ระบุรายละเอียดที่ต้องการให้นักศึกษาทราบ" /></Field>

                                {/* Image upload */}
                                <div>
                                    <span className="mb-1.5 block text-sm font-bold text-slate-700">รูปภาพประกอบ (ไม่บังคับ)</span>
                                    {previewImageUrl ? (
                                        <ImagePreview src={previewImageUrl} onRemove={removeImage} />
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => imageInputRef.current?.click()}
                                            className="flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-sm font-bold text-slate-500 transition hover:border-brand-400 hover:text-brand-700"
                                        >
                                            <UploadSimple size={20} weight="bold" />
                                            คลิกเพื่ออัปโหลดรูปภาพ
                                        </button>
                                    )}
                                    <input ref={imageInputRef} type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => handleImageSelect(event.target.files)} className="hidden" />
                                    <span className="mt-1.5 block text-xs leading-5 text-slate-500">JPG, PNG หรือ WebP ขนาดไม่เกิน 4 MB</span>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-[0.65fr_1.35fr]">
                                    <Field label="ข้อความบนปุ่ม"><input maxLength={60} value={draft.button_label} onChange={(event) => setDraft({ ...draft, button_label: event.target.value })} className={inputClass} placeholder="ดูรายละเอียด" disabled={draft.button_url === ''} /></Field>
                                    <Field label="ลิงก์ของปุ่ม (ไม่บังคับ)" hint="ต้องขึ้นต้นด้วย https:// หรือ http://"><input type="url" maxLength={2048} value={draft.button_url} onChange={(event) => setDraft({ ...draft, button_url: event.target.value, button_label: draft.button_label || 'ดูรายละเอียด' })} className={inputClass} placeholder="https://example.com/details" /></Field>
                                </div>

                                {/* Exam link toggle */}
                                <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4">
                                    <input type="checkbox" checked={draft.show_exam_link} onChange={(event) => setDraft({ ...draft, show_exam_link: event.target.checked })} className="mt-0.5 size-5 accent-sky-700" />
                                    <span>
                                        <strong className="flex items-center gap-1.5 text-sm text-slate-900"><CalendarCheck size={17} weight="bold" className="text-sky-700" /> แสดงปุ่มลิงก์ตารางสอบ</strong>
                                        <span className="mt-0.5 block text-xs leading-5 text-slate-500">นักศึกษาจะเห็นปุ่ม "ดูตารางสอบ" ที่ลิงก์ไปตารางสอบของตัวเองโดยอัตโนมัติ</span>
                                    </span>
                                </label>

                                <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <input type="checkbox" checked={draft.is_active} onChange={(event) => setDraft({ ...draft, is_active: event.target.checked })} className="mt-0.5 size-5 accent-brand-700" />
                                    <span><strong className="block text-sm text-slate-900">เปิดแสดงให้นักศึกษาทันที</strong><span className="mt-0.5 block text-xs leading-5 text-slate-500">ถ้ามีประกาศอื่นกำลังแสดง ระบบจะปิดรายการเดิมให้อัตโนมัติ</span></span>
                                </label>

                                {/* Preview */}
                                <div className="rounded-2xl border border-brand-100 bg-brand-50/50 p-4">
                                    <p className="text-xs font-bold text-brand-700">ตัวอย่างป๊อปอัป</p>
                                    {previewImageUrl && <img src={previewImageUrl} alt="" className="mt-2 h-32 w-full rounded-xl object-cover" />}
                                    
                                    <div className="mt-3 flex flex-col gap-2">
                                        {draft.button_url && (
                                            <span className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl bg-brand-700 px-3 py-2 text-xs font-bold text-white">
                                                <ArrowSquareOut size={15} /> {draft.button_label || 'ดูรายละเอียด'}
                                            </span>
                                        )}
                                        <div className={`grid gap-2 ${draft.show_exam_link ? 'grid-cols-2' : 'grid-cols-1'}`}>
                                            {draft.show_exam_link && (
                                                <span className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl border border-sky-300 bg-sky-100 px-3 py-2 text-xs font-bold text-sky-800">
                                                    <CalendarCheck size={15} weight="bold" /> ดูตารางสอบ
                                                </span>
                                            )}
                                            <span className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-sm">
                                                รับทราบ
                                            </span>
                                        </div>
                                    </div>

                                    <h3 className="mt-3 text-lg font-black text-slate-950">{draft.title || 'หัวข้อประกาศ'}</h3>
                                    <p className="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{draft.message || 'รายละเอียดประกาศจะแสดงตรงนี้'}</p>
                                </div>
                                {save.error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">{save.error.message}</p>}
                            </div>
                            <div className="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-5">
                                <button type="button" onClick={() => setEditing(null)} className={secondaryButton}>ยกเลิก</button>
                                <button type="submit" disabled={save.isPending} className={primaryButton}><FloppyDisk size={17} weight="bold" /> {save.isPending ? 'กำลังบันทึก' : 'บันทึกประกาศ'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </div>
    );
}
