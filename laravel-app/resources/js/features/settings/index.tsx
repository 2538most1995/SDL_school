import {
    ArrowCounterClockwise,
    Camera,
    Check,
    Eye,
    LockKey,
    Monitor,
    Moon,
    PaintBrush,
    ShieldCheck,
    Sun,
    Trash,
    UploadSimple,
    UserCircle,
} from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatusBadge } from '../../components/StatusBadge';
import { applyAppearance, DEFAULT_APPEARANCE, type AppearanceSettings, type ColorScheme } from '../../lib/appearance';
import { getFeatureDataWithDemo, sendFeatureData } from '../api';

type ProfileSettings = {
    displayName: string;
    avatarUrl: string | null;
    email: string;
    phoneMasked: string;
    studentCode?: string;
    roleLabel: string;
    districtName: string;
    canChangePassword?: boolean;
};

const demoProfile: ProfileSettings = {
    displayName: 'ณัฐชา ศรีสวัสดิ์', avatarUrl: null, email: 'natthacha@example.ac.th', phoneMasked: '08x-xxx-2841', studentCode: 'SENA-670142', roleLabel: 'นักศึกษา', districtName: 'อำเภอเสนา',
};

export function ProfileSettingsPage() {
    const queryClient = useQueryClient();
    const profile = useQuery({ queryKey: ['settings', 'profile'], queryFn: ({ signal }) => getFeatureDataWithDemo<ProfileSettings>('/api/v1/settings/profile', demoProfile, signal) });
    const [draft, setDraft] = useState<ProfileSettings | null>(null);
    const [avatarFile, setAvatarFile] = useState<File | null>(null);
    const [avatarPreview, setAvatarPreview] = useState<string | null>(null);
    const [passwords, setPasswords] = useState({ current: '', password: '', confirmation: '' });
    const values = draft ?? profile.data?.data ?? demoProfile;
    const saveProfile = useMutation({
        meta: { notification: { success: 'บันทึกโปรไฟล์สำเร็จ' } },
        mutationFn: () => sendFeatureData<ProfileSettings>('/api/v1/settings/profile', 'PATCH', { display_name: values.displayName, email: values.email }),
        onSuccess: async (response) => { queryClient.setQueryData(['settings', 'profile'], response); setDraft(null); await queryClient.invalidateQueries({ queryKey: ['auth', 'me'] }); },
    });
    const uploadAvatar = useMutation({
        meta: { notification: { success: 'อัปโหลดรูปโปรไฟล์สำเร็จ' } },
        mutationFn: () => {
            const form = new FormData();
            if (avatarFile) form.append('avatar', avatarFile);
            return sendFeatureData<ProfileSettings>('/api/v1/settings/profile/avatar', 'POST', form);
        },
        onSuccess: async (response) => {
            queryClient.setQueryData(['settings', 'profile'], response);
            setAvatarFile(null);
            await queryClient.invalidateQueries({ queryKey: ['auth', 'me'] });
        },
    });
    const removeAvatar = useMutation({
        meta: { notification: { success: 'ลบรูปโปรไฟล์เรียบร้อยแล้ว' } },
        mutationFn: () => sendFeatureData<ProfileSettings>('/api/v1/settings/profile/avatar', 'DELETE'),
        onSuccess: async (response) => {
            queryClient.setQueryData(['settings', 'profile'], response);
            setAvatarFile(null);
            await queryClient.invalidateQueries({ queryKey: ['auth', 'me'] });
        },
    });
    const savePassword = useMutation({
        meta: { notification: { success: 'เปลี่ยนรหัสผ่านสำเร็จ' } },
        mutationFn: () => sendFeatureData<{ updated: boolean }>('/api/v1/settings/password', 'PATCH', { current_password: passwords.current, password: passwords.password, password_confirmation: passwords.confirmation }),
        onSuccess: () => setPasswords({ current: '', password: '', confirmation: '' }),
    });
    function update(key: 'displayName' | 'email', value: string) { setDraft({ ...values, [key]: value }); }
    function submitPassword(event: FormEvent) { event.preventDefault(); savePassword.mutate(); }
    useEffect(() => {
        if (!avatarFile) {
            setAvatarPreview(null);
            return;
        }
        const preview = URL.createObjectURL(avatarFile);
        setAvatarPreview(preview);
        return () => URL.revokeObjectURL(preview);
    }, [avatarFile]);

    return (
        <div>
            <PageHeader category="การตั้งค่า" title="โปรไฟล์และความปลอดภัย" description="ดูข้อมูลบัญชี แก้ไขช่องทางติดต่อ และเปลี่ยนรหัสผ่านอย่างปลอดภัย" icon={UserCircle} actions={<StatusBadge tone="success">{values.roleLabel}</StatusBadge>} />
            {profile.isPending && <QuerySkeleton rows={6} />}
            {profile.isError && <QueryError onRetry={() => profile.refetch()} />}
            {profile.data && (
                <div className={`grid gap-5 ${values.canChangePassword ? 'xl:grid-cols-[1fr_0.9fr]' : ''}`}>
                    <Panel title="ข้อมูลบัญชี" description="รหัสนักศึกษา บทบาท และพื้นที่แก้ไขโดยผู้ดูแลเท่านั้น">
                        <div className="mb-6 flex flex-col gap-5 rounded-2xl border border-brand-100 bg-gradient-to-br from-brand-50 to-sky-50 p-4 sm:flex-row sm:items-center sm:p-5">
                            <div className="relative mx-auto shrink-0 sm:mx-0">
                                {avatarPreview || values.avatarUrl
                                    ? <img src={avatarPreview ?? values.avatarUrl ?? ''} alt={`รูปโปรไฟล์ของ ${values.displayName}`} className="size-28 rounded-2xl object-cover shadow-sm ring-4 ring-white" />
                                    : <span className="grid size-28 place-items-center rounded-2xl bg-white text-brand-700 shadow-sm ring-1 ring-brand-100"><UserCircle size={58} weight="duotone" /></span>}
                                <span className="absolute -bottom-2 -right-2 grid size-9 place-items-center rounded-xl bg-brand-700 text-white shadow-md"><Camera size={18} weight="fill" aria-hidden="true" /></span>
                            </div>
                            <div className="min-w-0 flex-1 text-center sm:text-left">
                                <h3 className="font-black text-slate-950">รูปโปรไฟล์</h3>
                                <p className="mt-1 text-xs leading-5 text-slate-600">รองรับ JPEG, PNG และ WebP ขนาดไม่เกิน 2 MB ภาพอย่างน้อย 128 × 128 พิกเซล</p>
                                <div className="mt-3 flex flex-wrap justify-center gap-2 sm:justify-start">
                                    <label className="inline-flex cursor-pointer items-center gap-2 rounded-full border border-brand-200 bg-white px-4 py-2 text-xs font-bold text-brand-800 hover:bg-brand-50">
                                        <Camera size={16} />เลือกรูป
                                        <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(event) => setAvatarFile(event.target.files?.[0] ?? null)} />
                                    </label>
                                    {avatarFile && <button type="button" onClick={() => uploadAvatar.mutate()} disabled={uploadAvatar.isPending} className="inline-flex items-center gap-2 rounded-full bg-brand-700 px-4 py-2 text-xs font-bold text-white hover:bg-brand-800 disabled:bg-slate-300"><UploadSimple size={16} />{uploadAvatar.isPending ? 'กำลังอัปโหลด' : 'อัปโหลดรูป'}</button>}
                                    {values.avatarUrl && !avatarFile && <button type="button" onClick={() => removeAvatar.mutate()} disabled={removeAvatar.isPending} className="inline-flex items-center gap-2 rounded-full border border-rose-200 bg-white px-4 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-50"><Trash size={16} />{removeAvatar.isPending ? 'กำลังลบ' : 'ลบรูป'}</button>}
                                </div>
                                {avatarFile && <p className="mt-2 truncate text-xs font-semibold text-slate-500">ไฟล์ที่เลือก: {avatarFile.name}</p>}
                                {(uploadAvatar.isError || removeAvatar.isError) && <p role="alert" className="mt-3 text-xs font-bold text-rose-700">{uploadAvatar.error?.message ?? removeAvatar.error?.message}</p>}
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="block sm:col-span-2"><span className="mb-2 block text-sm font-bold text-slate-700">ชื่อที่แสดง</span><input value={values.displayName} onChange={(event) => update('displayName', event.target.value)} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm" /></label>
                            <label className="block sm:col-span-2"><span className="mb-2 block text-sm font-bold text-slate-700">อีเมล</span><input type="email" value={values.email} onChange={(event) => update('email', event.target.value)} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-4 text-sm" /></label>
                            <label className="block"><span className="mb-2 block text-sm font-bold text-slate-700">รหัสนักศึกษา</span><input value={values.studentCode ?? '-'} readOnly className="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-4 text-sm text-slate-600" /></label>
                            <label className="block"><span className="mb-2 block text-sm font-bold text-slate-700">พื้นที่</span><input value={values.districtName} readOnly className="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-4 text-sm text-slate-600" /></label>
                            <label className="block sm:col-span-2"><span className="mb-2 block text-sm font-bold text-slate-700">โทรศัพท์</span><input value={values.phoneMasked} readOnly className="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-4 text-sm text-slate-600" /><span className="mt-1.5 block text-xs text-slate-500">ระบบปกปิดข้อมูลส่วนบุคคลตามสิทธิ์ผู้ใช้งาน</span></label>
                        </div>
                        {saveProfile.isError && <p role="alert" className="mt-4 rounded-xl bg-rose-50 p-3 text-sm font-bold text-rose-800">{saveProfile.error.message}</p>}
                        <button type="button" onClick={() => saveProfile.mutate()} disabled={!draft || saveProfile.isPending} className="mt-5 whitespace-nowrap rounded-full bg-brand-700 px-6 py-2.5 text-sm font-bold text-white hover:bg-brand-800 disabled:bg-slate-300 active:scale-[0.98]">{saveProfile.isPending ? 'กำลังบันทึก' : 'บันทึกโปรไฟล์'}</button>
                    </Panel>

                    {values.canChangePassword ? <Panel title="เปลี่ยนรหัสผ่าน" description="ใช้รหัสผ่านอย่างน้อย 8 ตัวอักษรและไม่ซ้ำกับบัญชีอื่น">
                        <form onSubmit={submitPassword} className="space-y-4">
                            {[['current', 'รหัสผ่านปัจจุบัน'], ['password', 'รหัสผ่านใหม่'], ['confirmation', 'ยืนยันรหัสผ่านใหม่']].map(([key, label]) => (
                                <label key={key} className="block"><span className="mb-2 block text-sm font-bold text-slate-700">{label}</span><span className="relative block"><LockKey size={18} className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" /><input type="password" value={passwords[key as keyof typeof passwords]} onChange={(event) => setPasswords((current) => ({ ...current, [key]: event.target.value }))} className="h-11 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-sm" /></span></label>
                            ))}
                            {savePassword.isError && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm font-bold text-rose-800">{savePassword.error.message}</p>}
                            <button type="submit" disabled={!passwords.current || passwords.password.length < 8 || passwords.password !== passwords.confirmation || savePassword.isPending} className="inline-flex w-full items-center justify-center gap-2 whitespace-nowrap rounded-full bg-slate-900 px-5 py-3 text-sm font-bold text-white hover:bg-slate-800 disabled:bg-slate-300 active:scale-[0.98]"><ShieldCheck size={18} weight="fill" />{savePassword.isPending ? 'กำลังตรวจสอบ' : 'เปลี่ยนรหัสผ่าน'}</button>
                        </form>
                    </Panel> : <Panel title="ความปลอดภัยของบัญชี" description="บัญชีนี้ยืนยันตัวตนจากระบบสถานศึกษาเดิม"><div className="flex items-start gap-3 rounded-2xl bg-brand-50 p-5 text-brand-950"><ShieldCheck size={24} weight="fill" className="shrink-0" /><div><p className="font-bold">เชื่อมบัญชีจริงแล้ว</p><p className="mt-1 text-sm leading-6 text-brand-800">หากต้องการเปลี่ยนรหัสผ่าน กรุณาดำเนินการผ่านผู้ดูแลระบบต้นทาง เพื่อให้สิทธิ์ยังตรงกันทุกระบบ</p></div></div></Panel>}
                </div>
            )}
        </div>
    );
}

const demoAppearance: AppearanceSettings = DEFAULT_APPEARANCE;
const themeOptions = [
    { value: 'light' as const, label: 'สว่าง', description: 'พื้นหลังสว่าง อ่านง่ายในห้องเรียน', icon: Sun },
    { value: 'dark' as const, label: 'มืด', description: 'ลดแสงหน้าจอเมื่อใช้ตอนกลางคืน', icon: Moon },
    { value: 'system' as const, label: 'ตามอุปกรณ์', description: 'เปลี่ยนตามการตั้งค่าของอุปกรณ์', icon: Monitor },
];
const colorOptions: Array<{ value: ColorScheme; label: string; color: string }> = [
    { value: 'blue', label: 'น้ำเงิน', color: '#2563eb' },
    { value: 'teal', label: 'เขียวอมฟ้า', color: '#0d9488' },
    { value: 'violet', label: 'ม่วง', color: '#7c3aed' },
    { value: 'rose', label: 'ชมพูเข้ม', color: '#e11d48' },
    { value: 'amber', label: 'อำพัน', color: '#d97706' },
];

export function AppearanceSettingsPage() {
    const queryClient = useQueryClient();
    const appearance = useQuery({ queryKey: ['settings', 'appearance'], queryFn: ({ signal }) => getFeatureDataWithDemo<AppearanceSettings>('/api/v1/settings/appearance', demoAppearance, signal) });
    const [draft, setDraft] = useState<AppearanceSettings | null>(null);
    const values = draft ?? appearance.data?.data ?? demoAppearance;
    const save = useMutation({
        meta: { notification: { success: 'บันทึกรูปแบบการแสดงผลสำเร็จ' } },
        mutationFn: () => sendFeatureData<AppearanceSettings>('/api/v1/settings/appearance', 'PATCH', values),
        onSuccess: (response) => {
            queryClient.setQueryData(['settings', 'appearance'], response);
            applyAppearance(response.data);
            setDraft(null);
            void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] });
        },
    });
    function update<K extends keyof AppearanceSettings>(key: K, value: AppearanceSettings[K]) { setDraft({ ...values, [key]: value }); }
    useEffect(() => {
        const saved = appearance.data?.data;
        if (draft) applyAppearance(draft, false);
        else if (saved) applyAppearance(saved);
        return () => { if (saved) applyAppearance(saved); };
    }, [appearance.data?.data, draft]);

    return (
        <div>
            <PageHeader category="การตั้งค่า" title="รูปแบบการแสดงผล" description="เลือกธีม ขนาดตัวอักษร และระยะห่างที่อ่านสบายสำหรับคุณ" icon={PaintBrush} />
            {appearance.isPending && <QuerySkeleton rows={5} />}
            {appearance.isError && <QueryError onRetry={() => appearance.refetch()} />}
            {appearance.data && (
                <div className="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
                    <div className="space-y-5">
                        <Panel title="โหมดหน้าจอ" description="เลือกความสว่างให้เหมาะกับอุปกรณ์และสภาพแวดล้อม">
                            <div className="grid gap-3 sm:grid-cols-3">
                                {themeOptions.map((option) => <button key={option.value} type="button" onClick={() => update('theme', option.value)} aria-pressed={values.theme === option.value} className={`relative rounded-2xl border p-4 text-left transition active:scale-[0.98] ${values.theme === option.value ? 'border-brand-600 bg-brand-50 text-brand-900' : 'border-slate-200 bg-white text-slate-700 hover:border-brand-300'}`}><option.icon size={24} weight="duotone" /><p className="mt-3 font-bold">{option.label}</p><p className="mt-1 text-xs leading-5 opacity-75">{option.description}</p>{values.theme === option.value && <span className="absolute right-3 top-3 grid size-6 place-items-center rounded-full bg-brand-700 text-white"><Check size={14} weight="bold" /></span>}</button>)}
                            </div>
                        </Panel>
                        <Panel title="สีหลักของระบบ" description="สีที่เลือกจะใช้กับเมนู ปุ่ม ลิงก์ ตัวกรอง และจุดเน้นทุกหน้า">
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                {colorOptions.map((option) => (
                                    <button key={option.value} type="button" onClick={() => update('colorScheme', option.value)} aria-pressed={values.colorScheme === option.value} className={`relative flex min-h-24 flex-col items-center justify-center gap-2 rounded-2xl border bg-white p-3 text-sm font-bold transition active:scale-[0.98] ${values.colorScheme === option.value ? 'border-brand-600 ring-2 ring-brand-100' : 'border-slate-200 hover:border-slate-400'}`}>
                                        <span className="size-9 rounded-full shadow-inner ring-4 ring-white" style={{ backgroundColor: option.color }} aria-hidden="true" />
                                        <span>{option.label}</span>
                                        {values.colorScheme === option.value && <span className="absolute right-2 top-2 grid size-5 place-items-center rounded-full bg-brand-700 text-white"><Check size={12} weight="bold" /></span>}
                                    </button>
                                ))}
                            </div>
                        </Panel>
                        <Panel title="การอ่านข้อมูล">
                            <div className="grid gap-5 sm:grid-cols-2">
                                <fieldset><legend className="mb-2 text-sm font-bold text-slate-700">ขนาดตัวอักษร</legend><div className="grid grid-cols-2 gap-2">{([['normal', 'ปกติ'], ['large', 'ใหญ่']] as const).map(([value, label]) => <button key={value} type="button" onClick={() => update('fontSize', value)} className={`rounded-xl border px-4 py-3 text-sm font-bold ${values.fontSize === value ? 'border-brand-600 bg-brand-50 text-brand-900' : 'border-slate-200 text-slate-700'}`}>{label}</button>)}</div></fieldset>
                                <fieldset><legend className="mb-2 text-sm font-bold text-slate-700">ระยะห่าง</legend><div className="grid grid-cols-2 gap-2">{([['comfortable', 'สบายตา'], ['compact', 'กระชับ']] as const).map(([value, label]) => <button key={value} type="button" onClick={() => update('density', value)} className={`rounded-xl border px-4 py-3 text-sm font-bold ${values.density === value ? 'border-brand-600 bg-brand-50 text-brand-900' : 'border-slate-200 text-slate-700'}`}>{label}</button>)}</div></fieldset>
                            </div>
                        </Panel>
                    </div>
                    <Panel title="ตัวอย่างแบบทันที" description="ทุกตัวเลือกจะแสดงผลกับทั้งระบบก่อนบันทึก">
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-slate-950">
                            <span className="grid size-10 place-items-center rounded-xl bg-brand-700 text-white"><Eye size={21} weight="fill" /></span>
                            <h2 className="mt-4 text-xl font-bold">ตัวอย่างข้อมูล</h2>
                            <label className="mt-4 block"><span className="mb-2 block text-sm font-bold">ค้นหารายการ</span><input placeholder="พิมพ์คำค้นหา" className="w-full rounded-xl border px-3" /></label>
                            <div className="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
                                <div className="grid grid-cols-[1fr_auto] bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600"><span>ชื่อรายการ</span><span>สถานะ</span></div>
                                <div className="grid grid-cols-[1fr_auto] items-center px-3 py-3 text-sm"><span>งานวิทยาศาสตร์</span><span className="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-bold text-brand-800">พร้อมใช้งาน</span></div>
                            </div>
                        </div>
                        {save.isError && <p role="alert" className="mt-4 rounded-xl bg-rose-50 p-3 text-sm font-bold text-rose-800">{save.error.message}</p>}
                        <div className="mt-5 grid gap-2 sm:grid-cols-2">
                            <button type="button" onClick={() => setDraft(DEFAULT_APPEARANCE)} className="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 hover:border-brand-300"><ArrowCounterClockwise size={17} weight="bold" />ค่าเริ่มต้นสีน้ำเงิน</button>
                            <button type="button" onClick={() => save.mutate()} disabled={!draft || save.isPending} className="whitespace-nowrap rounded-full bg-brand-700 px-5 py-3 text-sm font-bold text-white hover:bg-brand-800 disabled:bg-slate-300 active:scale-[0.98]">{save.isPending ? 'กำลังบันทึก' : 'บันทึกรูปแบบ'}</button>
                            {draft && <button type="button" onClick={() => setDraft(null)} className="text-sm font-bold text-slate-600 hover:text-brand-700 sm:col-span-2">ยกเลิกการเปลี่ยนแปลง</button>}
                        </div>
                    </Panel>
                </div>
            )}
        </div>
    );
}
