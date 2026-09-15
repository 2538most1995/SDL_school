import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    ArrowSquareOut,
    CheckCircle,
    Eye,
    EyeSlash,
    FloppyDisk,
    Megaphone,
    PencilSimple,
    Plus,
    Trash,
    X,
} from '@phosphor-icons/react';
import { useState, type FormEvent } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatTile } from '../../components/StatTile';
import { StatusBadge } from '../../components/StatusBadge';
import { getFeatureData, sendFeatureData } from '../api';

type Announcement = {
    id: number;
    title: string;
    message: string;
    button_label: string | null;
    button_url: string | null;
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
    is_active: boolean;
};

const blankDraft: AnnouncementDraft = {
    title: '',
    message: '',
    button_label: 'ดูรายละเอียด',
    button_url: '',
    is_active: false,
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

export function AdminAnnouncementsPage() {
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState<Announcement | 'new' | null>(null);
    const [draft, setDraft] = useState<AnnouncementDraft>(blankDraft);
    const announcements = useQuery({
        queryKey: ['admin', 'announcements'],
        queryFn: ({ signal }) => getFeatureData<Announcement[]>('/api/v1/admin/announcements', signal),
    });
    const save = useMutation({
        meta: { notification: { success: editing === 'new' ? 'สร้างประกาศเรียบร้อยแล้ว' : 'แก้ไขประกาศเรียบร้อยแล้ว' } },
        mutationFn: () => editing === 'new'
            ? sendFeatureData<Announcement>('/api/v1/admin/announcements', 'POST', draft)
            : sendFeatureData<Announcement>(`/api/v1/admin/announcements/${editing?.id}`, 'PATCH', draft),
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
        if (window.confirm(`ยืนยันลบประกาศ “${announcement.title}” ใช่หรือไม่?\n\nหากลบแล้ว นักศึกษาจะไม่เห็นประกาศนี้อีกต่อไป`)) {
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
            is_active: announcement.is_active,
        });
        setEditing(announcement);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        save.mutate();
    }

    return (
        <div>
            <PageHeader
                category="จัดการระบบ"
                title="ประกาศป๊อปอัปสำหรับนักศึกษา"
                description="สร้างข้อความสำคัญพร้อมปุ่มลิงก์ นักศึกษาของอำเภอนี้จะเห็นประกาศที่เปิดใช้งานหลังเข้าสู่ระบบ"
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
                                                <span className="text-xs text-slate-500">แก้ไขล่าสุด {formatDate(announcement.updated_at)}</span>
                                            </div>
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
                                <p className="mt-1 text-sm leading-6 text-slate-500">ข้อความจะแสดงเป็นตัวอักษรธรรมดา และลิงก์อนุญาตเฉพาะ http หรือ https</p>
                            </div>
                            <button type="button" onClick={() => setEditing(null)} className="grid size-9 shrink-0 place-items-center rounded-full bg-white text-slate-500 shadow-sm hover:text-slate-950" aria-label="ปิด"><X size={18} weight="bold" /></button>
                        </header>
                        <form onSubmit={submit} className="max-h-[78vh] overflow-y-auto p-5 sm:p-6">
                            <div className="grid gap-4">
                                <Field label="หัวข้อประกาศ"><input required maxLength={160} value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} className={inputClass} placeholder="เช่น แจ้งกำหนดการสอบปลายภาค" autoFocus /></Field>
                                <Field label="ข้อความประกาศ" hint={`${draft.message.length.toLocaleString('th-TH')} / 4,000 ตัวอักษร`}><textarea required maxLength={4000} rows={6} value={draft.message} onChange={(event) => setDraft({ ...draft, message: event.target.value })} className="w-full resize-y rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-100" placeholder="ระบุรายละเอียดที่ต้องการให้นักศึกษาทราบ" /></Field>
                                <div className="grid gap-4 sm:grid-cols-[0.65fr_1.35fr]">
                                    <Field label="ข้อความบนปุ่ม"><input maxLength={60} value={draft.button_label} onChange={(event) => setDraft({ ...draft, button_label: event.target.value })} className={inputClass} placeholder="ดูรายละเอียด" disabled={draft.button_url === ''} /></Field>
                                    <Field label="ลิงก์ของปุ่ม (ไม่บังคับ)" hint="ต้องขึ้นต้นด้วย https:// หรือ http://"><input type="url" maxLength={2048} value={draft.button_url} onChange={(event) => setDraft({ ...draft, button_url: event.target.value, button_label: draft.button_label || 'ดูรายละเอียด' })} className={inputClass} placeholder="https://example.com/details" /></Field>
                                </div>
                                <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <input type="checkbox" checked={draft.is_active} onChange={(event) => setDraft({ ...draft, is_active: event.target.checked })} className="mt-0.5 size-5 accent-brand-700" />
                                    <span><strong className="block text-sm text-slate-900">เปิดแสดงให้นักศึกษาทันที</strong><span className="mt-0.5 block text-xs leading-5 text-slate-500">ถ้ามีประกาศอื่นกำลังแสดง ระบบจะปิดรายการเดิมให้อัตโนมัติ</span></span>
                                </label>
                                <div className="rounded-2xl border border-brand-100 bg-brand-50/50 p-4">
                                    <p className="text-xs font-bold text-brand-700">ตัวอย่างป๊อปอัป</p>
                                    <h3 className="mt-2 text-lg font-black text-slate-950">{draft.title || 'หัวข้อประกาศ'}</h3>
                                    <p className="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{draft.message || 'รายละเอียดประกาศจะแสดงตรงนี้'}</p>
                                    {draft.button_url && <span className="mt-3 inline-flex items-center gap-1.5 rounded-full bg-brand-700 px-4 py-2 text-sm font-bold text-white"><ArrowSquareOut size={15} /> {draft.button_label || 'ดูรายละเอียด'}</span>}
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
