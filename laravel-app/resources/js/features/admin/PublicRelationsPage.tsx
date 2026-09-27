import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Eye,
    EyeSlash,
    FloppyDisk,
    Image as ImageIcon,
    Newspaper,
    PencilSimple,
    Plus,
    Trash,
    UploadSimple,
    X,
} from '@phosphor-icons/react';
import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatTile } from '../../components/StatTile';
import { StatusBadge } from '../../components/StatusBadge';
import { withAppBasePath } from '../../lib/urls';
import { getFeatureData, sendFeatureData } from '../api';

export type PublicRelationsPost = {
    id: number;
    title: string;
    description: string;
    image_url: string | null;
    is_published: boolean;
    published_at: string | null;
    created_by_name: string | null;
    created_at: string | null;
    updated_at: string | null;
};

type Draft = {
    title: string;
    description: string;
    image: File | null;
    existingImageUrl: string | null;
    removeImage: boolean;
    isPublished: boolean;
};

const blankDraft: Draft = {
    title: '',
    description: '',
    image: null,
    existingImageUrl: null,
    removeImage: false,
    isPublished: false,
};

const primaryButton = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full bg-brand-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-300 active:scale-[0.98]';
const secondaryButton = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:border-brand-300 hover:text-brand-800 disabled:cursor-not-allowed disabled:opacity-50 active:scale-[0.98]';
const inputClass = 'h-11 w-full rounded-xl border border-slate-300 bg-white px-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-100';

function formatDate(value: string | null): string {
    if (!value) return '-';

    return new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-bold text-slate-700">{label}</span>
            {children}
            {hint && <span className="mt-1.5 block text-xs leading-5 text-slate-500">{hint}</span>}
        </label>
    );
}

function createPayload(draft: Draft, isEditing: boolean): FormData {
    const form = new FormData();
    form.append('title', draft.title);
    form.append('description', draft.description);
    form.append('is_published', draft.isPublished ? '1' : '0');
    if (isEditing) form.append('_method', 'PATCH');
    if (draft.image) form.append('image', draft.image);
    if (draft.removeImage) form.append('remove_image', '1');

    return form;
}

export function AdminPublicRelationsPage() {
    const queryClient = useQueryClient();
    const imageInputRef = useRef<HTMLInputElement>(null);
    const [editing, setEditing] = useState<PublicRelationsPost | 'new' | null>(null);
    const [draft, setDraft] = useState<Draft>(blankDraft);
    const posts = useQuery({
        queryKey: ['admin', 'public-relations'],
        queryFn: ({ signal }) => getFeatureData<PublicRelationsPost[]>('/api/v1/admin/public-relations', signal),
    });
    const save = useMutation({
        meta: { notification: { success: editing === 'new' ? 'สร้างข่าวประชาสัมพันธ์เรียบร้อยแล้ว' : 'แก้ไขข่าวประชาสัมพันธ์เรียบร้อยแล้ว' } },
        mutationFn: () => {
            const isEditing = editing !== 'new';
            const path = isEditing ? `/api/v1/admin/public-relations/${(editing as PublicRelationsPost).id}` : '/api/v1/admin/public-relations';
            return sendFeatureData<PublicRelationsPost>(path, 'POST', createPayload(draft, isEditing));
        },
        onSuccess: async () => {
            setEditing(null);
            setDraft(blankDraft);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['admin', 'public-relations'] }),
                queryClient.invalidateQueries({ queryKey: ['public-relations'] }),
            ]);
        },
    });
    const changeStatus = useMutation({
        meta: { notification: { success: 'เปลี่ยนสถานะข่าวประชาสัมพันธ์เรียบร้อยแล้ว' } },
        mutationFn: ({ post, published }: { post: PublicRelationsPost; published: boolean }) => sendFeatureData<PublicRelationsPost>(
            `/api/v1/admin/public-relations/${post.id}/status`,
            'PATCH',
            { is_published: published },
        ),
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['admin', 'public-relations'] }),
                queryClient.invalidateQueries({ queryKey: ['public-relations'] }),
            ]);
        },
    });
    const remove = useMutation({
        meta: { notification: { success: 'ลบข่าวประชาสัมพันธ์เรียบร้อยแล้ว' } },
        mutationFn: (id: number) => sendFeatureData<{ id: number }>(`/api/v1/admin/public-relations/${id}`, 'DELETE'),
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['admin', 'public-relations'] }),
                queryClient.invalidateQueries({ queryKey: ['public-relations'] }),
            ]);
        },
    });

    const localPreview = useMemo(() => draft.image ? URL.createObjectURL(draft.image) : null, [draft.image]);
    useEffect(() => () => { if (localPreview) URL.revokeObjectURL(localPreview); }, [localPreview]);
    const previewUrl = localPreview ?? (draft.existingImageUrl ? withAppBasePath(draft.existingImageUrl) : null);
    const items = posts.data?.data ?? [];
    const publishedCount = items.filter((post) => post.is_published).length;

    function openCreate() {
        save.reset();
        setDraft(blankDraft);
        setEditing('new');
    }

    function openEdit(post: PublicRelationsPost) {
        save.reset();
        setDraft({
            title: post.title,
            description: post.description,
            image: null,
            existingImageUrl: post.image_url,
            removeImage: false,
            isPublished: post.is_published,
        });
        setEditing(post);
    }

    function selectImage(files: FileList | null) {
        if (!files?.[0]) return;
        setDraft((current) => ({ ...current, image: files[0], removeImage: false }));
    }

    function clearImage() {
        setDraft((current) => ({ ...current, image: null, existingImageUrl: null, removeImage: true }));
        if (imageInputRef.current) imageInputRef.current.value = '';
    }

    function confirmDelete(post: PublicRelationsPost) {
        if (window.confirm(`ยืนยันลบข่าว “${post.title}” ใช่หรือไม่?`)) remove.mutate(post.id);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        save.mutate();
    }

    return (
        <div>
            <PageHeader
                category="จัดการระบบ"
                title="ข่าวประชาสัมพันธ์"
                description="อัปโหลดรูป เขียนคำอธิบาย และเลือกเผยแพร่ข่าวให้นักศึกษาในอำเภอของคุณ"
                icon={Newspaper}
                actions={<button type="button" onClick={openCreate} className={primaryButton}><Plus size={17} weight="bold" /> เพิ่มข่าว</button>}
            />

            <div className="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="ข่าวทั้งหมด" value={items.length} detail="รวมฉบับร่างและข่าวที่เผยแพร่" icon={Newspaper} tone="sky" />
                <StatTile label="กำลังเผยแพร่" value={`${publishedCount} ข่าว`} detail="นักศึกษาในอำเภอมองเห็นได้" icon={Eye} tone="emerald" />
                <StatTile label="มีรูปประกอบ" value={items.filter((post) => post.image_url).length} detail="รูปเก็บในพื้นที่ private" icon={ImageIcon} tone="amber" />
            </div>

            <Panel title="รายการข่าว" description="เผยแพร่ได้หลายข่าวพร้อมกัน ข่าวล่าสุดจะแสดงก่อนบนหน้าแรกของนักศึกษา">
                {posts.isPending && <QuerySkeleton />}
                {posts.isError && <QueryError onRetry={() => posts.refetch()} />}
                {posts.data && items.length === 0 && (
                    <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-12 text-center">
                        <span className="mx-auto grid size-14 place-items-center rounded-full bg-sky-100 text-sky-700"><Newspaper size={27} weight="duotone" /></span>
                        <h2 className="mt-4 font-black text-slate-900">ยังไม่มีข่าวประชาสัมพันธ์</h2>
                        <p className="mt-1 text-sm text-slate-500">เพิ่มข่าวแรกพร้อมรูปและคำอธิบายได้ทันที</p>
                        <button type="button" onClick={openCreate} className={`${primaryButton} mt-5`}><Plus size={17} weight="bold" /> เพิ่มข่าวแรก</button>
                    </div>
                )}
                {posts.data && items.length > 0 && (
                    <div className="grid gap-4 md:grid-cols-2">
                        {items.map((post) => (
                            <article key={post.id} className={`overflow-hidden rounded-2xl border ${post.is_published ? 'border-sky-200 bg-sky-50/40' : 'border-slate-200 bg-white'}`}>
                                {post.image_url ? (
                                    <img src={withAppBasePath(post.image_url)} alt={`ภาพข่าว ${post.title}`} className="aspect-[16/9] w-full border-b border-slate-200 object-cover" />
                                ) : (
                                    <div className="grid aspect-[16/7] place-items-center border-b border-slate-200 bg-slate-100 text-slate-400"><ImageIcon size={34} weight="duotone" /></div>
                                )}
                                <div className="p-4 sm:p-5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge tone={post.is_published ? 'success' : 'neutral'}>{post.is_published ? 'เผยแพร่แล้ว' : 'ฉบับร่าง'}</StatusBadge>
                                        <span className="text-xs text-slate-500">แก้ไข {formatDate(post.updated_at)}</span>
                                    </div>
                                    <h3 className="mt-3 text-lg font-black leading-7 text-slate-950">{post.title}</h3>
                                    <p className="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{post.description}</p>
                                    <p className="mt-3 text-xs text-slate-400">สร้างโดย {post.created_by_name ?? 'ผู้ดูแลอำเภอ'}</p>
                                    <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-200/80 pt-4">
                                        <button type="button" onClick={() => openEdit(post)} className={secondaryButton}><PencilSimple size={16} weight="bold" /> แก้ไข</button>
                                        <button type="button" disabled={changeStatus.isPending} onClick={() => changeStatus.mutate({ post, published: !post.is_published })} className={post.is_published ? secondaryButton : primaryButton}>
                                            {post.is_published ? <EyeSlash size={16} weight="bold" /> : <Eye size={16} weight="bold" />}
                                            {post.is_published ? 'หยุดเผยแพร่' : 'เผยแพร่'}
                                        </button>
                                        <button type="button" disabled={remove.isPending} onClick={() => confirmDelete(post)} className="inline-flex items-center justify-center gap-1.5 rounded-full border border-rose-200 bg-white px-3.5 py-2.5 text-sm font-bold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50 active:scale-[0.98]" aria-label={`ลบข่าว ${post.title}`}><Trash size={16} weight="bold" /> ลบ</button>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </Panel>

            {editing && (
                <div className="fixed inset-0 z-[70] grid place-items-center overflow-y-auto bg-slate-950/55 p-3 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="public-relations-editor-title" onMouseDown={(event) => { if (event.target === event.currentTarget) setEditing(null); }}>
                    <section className="my-auto w-full max-w-3xl overflow-hidden rounded-3xl border border-white/70 bg-white shadow-2xl">
                        <header className="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-sky-50 to-emerald-50 px-5 py-4 sm:px-6">
                            <div>
                                <h2 id="public-relations-editor-title" className="text-xl font-black text-slate-950">{editing === 'new' ? 'เพิ่มข่าวประชาสัมพันธ์' : 'แก้ไขข่าวประชาสัมพันธ์'}</h2>
                                <p className="mt-1 text-sm leading-6 text-slate-500">คำอธิบายจะแสดงแบบข้อความธรรมดาเพื่อความปลอดภัย</p>
                            </div>
                            <button type="button" onClick={() => setEditing(null)} className="grid size-9 shrink-0 place-items-center rounded-full bg-white text-slate-500 shadow-sm hover:text-slate-950" aria-label="ปิด"><X size={18} weight="bold" /></button>
                        </header>
                        <form onSubmit={submit} className="max-h-[80vh] overflow-y-auto p-5 sm:p-6">
                            <div className="grid gap-4">
                                <Field label="หัวข้อข่าว"><input required maxLength={180} value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} className={inputClass} placeholder="เช่น เปิดรับสมัครนักศึกษาใหม่" autoFocus /></Field>
                                <Field label="คำอธิบาย" hint={`${draft.description.length.toLocaleString('th-TH')} / 8,000 ตัวอักษร`}><textarea required maxLength={8000} rows={7} value={draft.description} onChange={(event) => setDraft({ ...draft, description: event.target.value })} className="w-full resize-y rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-100" placeholder="ระบุรายละเอียดข่าวที่ต้องการประชาสัมพันธ์" /></Field>

                                <div>
                                    <span className="mb-1.5 block text-sm font-bold text-slate-700">รูปประกอบข่าว</span>
                                    {previewUrl ? (
                                        <div className="group relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                                            <img src={previewUrl} alt="ตัวอย่างรูปประกอบข่าว" className="aspect-[16/9] w-full object-cover" />
                                            <button type="button" onClick={clearImage} className="absolute right-2 top-2 grid size-9 place-items-center rounded-full bg-slate-950/70 text-white" aria-label="ลบรูปภาพ"><X size={15} weight="bold" /></button>
                                        </div>
                                    ) : (
                                        <button type="button" onClick={() => imageInputRef.current?.click()} className="flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-sm font-bold text-slate-500 transition hover:border-sky-400 hover:text-sky-700"><UploadSimple size={21} weight="bold" /> อัปโหลดรูปประกอบ</button>
                                    )}
                                    <input ref={imageInputRef} type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => selectImage(event.target.files)} className="hidden" />
                                    <span className="mt-1.5 block text-xs leading-5 text-slate-500">JPG, PNG หรือ WebP ขนาด 320×180 px ขึ้นไป และไม่เกิน 5 MB</span>
                                </div>

                                <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                                    <input type="checkbox" checked={draft.isPublished} onChange={(event) => setDraft({ ...draft, isPublished: event.target.checked })} className="mt-0.5 size-5 accent-emerald-700" />
                                    <span><strong className="block text-sm text-slate-900">เผยแพร่ให้นักศึกษาเห็นทันที</strong><span className="mt-0.5 block text-xs leading-5 text-slate-600">หากยังเตรียมเนื้อหาไม่เสร็จ ให้ปิดตัวเลือกนี้เพื่อบันทึกเป็นฉบับร่าง</span></span>
                                </label>
                                {save.error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">{save.error.message}</p>}
                            </div>
                            <div className="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-5">
                                <button type="button" onClick={() => setEditing(null)} className={secondaryButton}>ยกเลิก</button>
                                <button type="submit" disabled={save.isPending} className={primaryButton}><FloppyDisk size={17} weight="bold" /> {save.isPending ? 'กำลังบันทึก' : 'บันทึกข่าว'}</button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </div>
    );
}
