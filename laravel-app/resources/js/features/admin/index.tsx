import {
    Archive,
    Buildings,
    CheckCircle,
    CircleNotch,
    Database,
    DoorOpen,
    FileZip,
    FloppyDisk,
    GraduationCap,
    HardDrives,
    ImageSquare,
    LockKey,
    MagnifyingGlass,
    PaintBrushBroad,
    PencilSimple,
    Plus,
    ShieldCheck,
    Trash,
    UploadSimple,
    UserCircleGear,
    UsersThree,
    Warning,
    X,
} from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { DataTable } from '../../components/DataTable';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatTile } from '../../components/StatTile';
import { StatusBadge, type StatusTone } from '../../components/StatusBadge';
import { showErrorAlert, showSuccessAlert } from '../../lib/feedback';
import { getFeatureData, getFeatureDataWithDemo, sendFeatureData, uploadFeatureData } from '../api';

const primaryButton = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full bg-brand-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-300 active:scale-[0.98]';
const secondaryButton = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:border-brand-300 hover:text-brand-800 disabled:opacity-50 active:scale-[0.98]';
const inputClass = 'h-10 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-brand-600 focus:ring-2 focus:ring-brand-100 disabled:bg-slate-100';

function Modal({ title, description, children, onClose }: { title: string; description?: string; children: ReactNode; onClose: () => void }) {
    return (
        <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-3 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label={title} onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
            <section className="my-auto w-full max-w-2xl overflow-hidden rounded-2xl border border-white/70 bg-white shadow-2xl">
                <header className="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-brand-50 to-sky-50 px-5 py-4 sm:px-6">
                    <div><h2 className="text-xl font-bold text-slate-950">{title}</h2>{description && <p className="mt-1 text-sm leading-6 text-slate-500">{description}</p>}</div>
                    <button type="button" onClick={onClose} className="grid size-9 shrink-0 place-items-center rounded-full bg-white text-slate-500 shadow-sm hover:text-slate-950" aria-label="ปิด"><X size={18} weight="bold" /></button>
                </header>
                <div className="max-h-[75vh] overflow-y-auto p-5 sm:p-6">{children}</div>
            </section>
        </div>
    );
}

function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
    return <label className="block"><span className="mb-1.5 block text-sm font-bold text-slate-700">{label}</span>{children}{hint && <span className="mt-1 block text-xs leading-5 text-slate-500">{hint}</span>}</label>;
}

function MutationError({ error }: { error: Error | null }) {
    return error ? <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">{error.message}</p> : null;
}

type AdminRole = 'student' | 'teacher' | 'admin' | 'super_admin';
type AdminUser = {
    id: number;
    display_name: string;
    first_name: string;
    last_name: string;
    username: string;
    role: AdminRole;
    district_id: number | null;
    district_name: string;
    assigned_groups: string[];
    assigned_group_names: string[];
    group: string | null;
    status: string;
    can_edit: boolean;
};
type AvailableGroup = { code: string; name: string; label: string; level: string | null; advisor: string | null; meeting_place: string | null };
type UserDraft = { username: string; password: string; first_name: string; last_name: string; role: AdminRole; assigned_groups: string[] };

const roleLabels: Record<AdminRole, string> = { student: 'นักศึกษา', teacher: 'ครู', admin: 'ผู้ดูแลอำเภอ', super_admin: 'ผู้ดูแลสูงสุด' };
const blankUser: UserDraft = { username: '', password: '', first_name: '', last_name: '', role: 'teacher', assigned_groups: [] };

type DistrictRegistryItem = {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    users_count: number;
    created_at: string | null;
};
type DistrictDraft = { name: string; code: string };
const blankDistrict: DistrictDraft = { name: '', code: '' };

export function SuperAdminDistrictsPage() {
    const queryClient = useQueryClient();
    const [creating, setCreating] = useState(false);
    const [draft, setDraft] = useState<DistrictDraft>(blankDistrict);
    const districts = useQuery({
        queryKey: ['super-admin', 'districts'],
        queryFn: ({ signal }) => getFeatureData<DistrictRegistryItem[]>('/api/v1/super-admin/districts', signal),
    });
    const save = useMutation({
        meta: { notification: { success: 'เพิ่มอำเภอใหม่เรียบร้อยแล้ว' } },
        mutationFn: () => sendFeatureData<DistrictRegistryItem>('/api/v1/super-admin/districts', 'POST', draft),
        onSuccess: async () => {
            setCreating(false);
            setDraft(blankDistrict);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['super-admin', 'districts'] }),
                queryClient.invalidateQueries({ queryKey: ['auth', 'me'] }),
                queryClient.invalidateQueries({ queryKey: ['auth', 'districts'] }),
            ]);
        },
    });
    const columns = useMemo<ColumnDef<DistrictRegistryItem>[]>(() => [
        { accessorKey: 'name', header: 'ชื่อพื้นที่', size: 260, meta: { compactSize: 145 }, cell: ({ row }) => <div><p className="font-bold text-slate-950">{row.original.name}</p><p className="mt-0.5 text-xs text-slate-500">รหัส {row.original.code}</p></div> },
        { accessorKey: 'users_count', header: 'ผู้ใช้งาน', size: 120, meta: { compactSize: 70, compactTextAlign: 'center' }, cell: ({ getValue }) => Number(getValue()).toLocaleString('th-TH') },
        { accessorKey: 'is_active', header: 'สถานะ', size: 120, meta: { compactSize: 72, compactTextAlign: 'center' }, cell: ({ getValue }) => <StatusBadge tone={getValue<boolean>() ? 'success' : 'neutral'}>{getValue<boolean>() ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}</StatusBadge> },
        { accessorKey: 'created_at', header: 'วันที่เพิ่ม', size: 150, meta: { compactSize: 90 }, cell: ({ getValue }) => { const value = getValue<string | null>(); return value ? new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium' }).format(new Date(value)) : '-'; } },
    ], []);
    const readOnly = districts.data?.meta.read_only === true;

    return (
        <div>
            <PageHeader category="จัดการพื้นที่" title="ทะเบียนอำเภอ" description="เพิ่มอำเภอใหม่เพื่อแยกผู้ใช้งาน การนำเข้า และข้อมูลนักศึกษาออกจากกันอย่างชัดเจน" icon={Buildings} actions={<button type="button" onClick={() => { setDraft(blankDistrict); save.reset(); setCreating(true); }} disabled={readOnly} className={primaryButton}><Plus size={17} weight="bold" /> เพิ่มอำเภอ</button>} />
            <div className="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="อำเภอทั้งหมด" value={districts.data?.data.length ?? 0} detail="ในทะเบียนระบบ" icon={Buildings} tone="sky" />
                <StatTile label="เปิดใช้งาน" value={districts.data?.data.filter((district) => district.is_active).length ?? 0} detail="เลือกใช้งานได้จากเมนูด้านบน" icon={CheckCircle} />
                <StatTile label="ขั้นตอนถัดไป" value="2 ขั้นตอน" detail="สร้างผู้ดูแล แล้วนำเข้า ZIP/DBF" icon={UploadSimple} tone="amber" />
            </div>
            <Panel title="พื้นที่ที่เปิดให้บริการ" description="หลังเพิ่มอำเภอ ให้เลือกอำเภอจากเมนูด้านบน จากนั้นเพิ่มผู้ดูแลประจำอำเภอและนำเข้าข้อมูลของพื้นที่นั้น">
                {districts.isPending && <QuerySkeleton />}
                {districts.isError && <QueryError onRetry={() => districts.refetch()} />}
                {districts.data && <DataTable data={districts.data.data} columns={columns} />}
            </Panel>
            {creating && <Modal title="เพิ่มอำเภอใหม่" description="ระบบจะสร้างพื้นที่ว่างที่ยังไม่มีข้อมูลนักศึกษา การนำเข้าครั้งแรกจะผูกกับอำเภอนี้เท่านั้น" onClose={() => setCreating(false)}><form onSubmit={(event) => { event.preventDefault(); save.mutate(); }} className="grid gap-4 sm:grid-cols-2">
                <Field label="ชื่ออำเภอ" hint="ตัวอย่าง: อำเภอบางปะอิน"><input required maxLength={255} value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} className={inputClass} placeholder="อำเภอบางปะอิน" /></Field>
                <Field label="รหัสอำเภอ" hint="ใช้ a-z, 0-9, - หรือ _ เช่น bang-pa-in"><input required minLength={2} maxLength={40} pattern="[a-z0-9]+(?:[-_][a-z0-9]+)*" value={draft.code} onChange={(event) => setDraft({ ...draft, code: event.target.value.toLocaleLowerCase('en-US') })} className={inputClass} placeholder="bang-pa-in" /></Field>
                <div className="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-950 sm:col-span-2"><strong className="block">หลังบันทึก</strong>เลือกอำเภอใหม่นี้จากเมนูด้านบน → ไปที่ “ผู้ใช้งาน” เพื่อสร้าง admin ประจำอำเภอ → ไปที่ “นำเข้าข้อมูล” เพื่ออัปโหลด ZIP/DBF</div>
                <div className="sm:col-span-2"><MutationError error={save.error} /></div>
                <div className="flex justify-end gap-2 sm:col-span-2"><button type="button" onClick={() => setCreating(false)} className={secondaryButton}>ยกเลิก</button><button type="submit" disabled={save.isPending} className={primaryButton}><FloppyDisk size={17} weight="bold" />{save.isPending ? 'กำลังเพิ่มอำเภอ' : 'บันทึกอำเภอ'}</button></div>
            </form></Modal>}
        </div>
    );
}

export function AdminUsersPage() {
    const queryClient = useQueryClient();
    const [role, setRole] = useState('all');
    const [search, setSearch] = useState('');
    const [groupSearch, setGroupSearch] = useState('');
    const [editing, setEditing] = useState<AdminUser | 'new' | null>(null);
    const [draft, setDraft] = useState<UserDraft>(blankUser);
    const users = useQuery({
        queryKey: ['admin', 'users'],
        queryFn: ({ signal }) => getFeatureData<AdminUser[]>('/api/v1/admin/users', signal),
    });
    const save = useMutation({
        meta: { notification: { success: editing === 'new' ? 'เพิ่มผู้ใช้งานสำเร็จ' : 'บันทึกข้อมูลผู้ใช้งานสำเร็จ' } },
        mutationFn: () => {
            const payload = { ...draft, assigned_groups: draft.role === 'super_admin' ? [] : draft.assigned_groups };
            return editing === 'new'
                ? sendFeatureData<AdminUser>('/api/v1/admin/users', 'POST', payload)
                : sendFeatureData<AdminUser>(`/api/v1/admin/users/${editing?.id}`, 'PATCH', payload);
        },
        onSuccess: () => {
            setEditing(null);
            setDraft(blankUser);
            void queryClient.invalidateQueries({ queryKey: ['admin', 'users'] });
        },
    });
    const allowedRoles = (users.data?.meta.allowed_roles as AdminRole[] | undefined) ?? ['teacher', 'admin'];
    const availableGroups = (users.data?.meta.available_groups as AvailableGroup[] | undefined) ?? [];
    const groupOptions = [
        ...availableGroups,
        ...draft.assigned_groups
            .filter((code) => !availableGroups.some((group) => group.code === code))
            .map((code) => ({ code, name: code, label: code, level: null, advisor: null, meeting_place: null })),
    ].filter((group) => `${group.label} ${group.code} ${group.advisor ?? ''}`.toLocaleLowerCase('th-TH').includes(groupSearch.trim().toLocaleLowerCase('th-TH')));
    const filtered = (users.data?.data ?? []).filter((user) => {
        const matchesRole = role === 'all' || user.role === role;
        const needle = search.trim().toLocaleLowerCase('th-TH');
        return matchesRole && (needle === '' || `${user.display_name} ${user.username} ${user.group ?? ''}`.toLocaleLowerCase('th-TH').includes(needle));
    });
    function openCreate() {
        setDraft({ ...blankUser, role: allowedRoles[0] ?? 'teacher' });
        setGroupSearch('');
        save.reset();
        setEditing('new');
    }
    function openEdit(user: AdminUser) {
        setDraft({ username: user.username, password: '', first_name: user.first_name, last_name: user.last_name, role: user.role, assigned_groups: user.assigned_groups });
        setGroupSearch('');
        save.reset();
        setEditing(user);
    }
    function toggleGroup(code: string) {
        setDraft({ ...draft, assigned_groups: draft.assigned_groups.includes(code) ? draft.assigned_groups.filter((item) => item !== code) : [...draft.assigned_groups, code] });
    }
    function submit(event: FormEvent) { event.preventDefault(); save.mutate(); }
    const columns = useMemo<ColumnDef<AdminUser>[]>(() => [
        { accessorKey: 'display_name', header: 'ชื่อผู้ใช้งาน', size: 240, meta: { compactSize: 130 }, cell: ({ row }) => <div><p className="font-bold text-slate-950">{row.original.display_name}</p><p className="mt-0.5 text-xs text-slate-500">{row.original.username}</p></div> },
        { accessorKey: 'role', header: 'สิทธิ์', size: 125, meta: { compactSize: 70, compactTextAlign: 'center' }, cell: ({ getValue }) => <StatusBadge tone={getValue<AdminRole>() === 'admin' ? 'warning' : getValue<AdminRole>() === 'super_admin' ? 'danger' : 'info'}>{roleLabels[getValue<AdminRole>()]}</StatusBadge> },
        { accessorKey: 'district_name', header: 'พื้นที่', size: 160, meta: { compactSize: 90 } },
        { accessorKey: 'group', header: 'กลุ่มที่รับผิดชอบ', size: 220, meta: { compactSize: 112 }, cell: ({ getValue }) => <span className="text-xs">{getValue<string | null>() || 'ทุกกลุ่ม'}</span> },
        { accessorKey: 'status', header: 'สถานะ', size: 115, meta: { compactSize: 70, compactTextAlign: 'center' }, cell: () => <StatusBadge tone="success">ใช้งาน</StatusBadge> },
        { id: 'actions', header: 'จัดการ', size: 120, meta: { compactSize: 46, compactTextAlign: 'center' }, enableSorting: false, cell: ({ row }) => row.original.can_edit ? <button type="button" onClick={() => openEdit(row.original)} className={`${secondaryButton} responsive-table-action`} aria-label={`แก้ไข ${row.original.display_name}`}><PencilSimple size={15} weight="bold" /> <span>แก้ไข</span></button> : <span className="text-xs text-slate-400">สงวนสิทธิ์</span> },
    ], []);

    return (
        <div>
            <PageHeader category="จัดการระบบ" title="ผู้ใช้งานและสิทธิ์" description="ข้อมูลผู้ใช้อ่านและบันทึกในฐานข้อมูลของระบบโดยตรง ผู้ดูแลอำเภอจัดการได้เฉพาะพื้นที่ตนเอง" icon={UsersThree} actions={<button type="button" onClick={openCreate} disabled={users.data?.meta.read_only === true} className={primaryButton}><Plus size={17} weight="bold" /> เพิ่มผู้ใช้</button>} />
            <div className="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="ผู้ใช้งาน" value={users.data?.data.length ?? 0} detail="ในพื้นที่ที่เลือก" icon={UsersThree} tone="sky" />
                <StatTile label="ครู" value={(users.data?.data ?? []).filter((item) => item.role === 'teacher').length} detail="บัญชีผู้สอน" icon={UserCircleGear} />
                <StatTile label="ผู้ดูแล" value={(users.data?.data ?? []).filter((item) => item.role.includes('admin')).length} detail="บัญชีสิทธิ์สูง" icon={ShieldCheck} tone="amber" />
            </div>
            <Panel title="บัญชีผู้ใช้งาน" description="ทุกการเพิ่มและแก้ไขจะบันทึกประวัติ พร้อมบังคับขอบเขตอำเภอจากบัญชีที่เข้าสู่ระบบ" action={<div className="flex flex-wrap gap-2"><label className="relative"><MagnifyingGlass size={16} className="absolute left-3 top-3 text-slate-400" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="ค้นหาชื่อหรือบัญชี" className={`${inputClass} w-52 pl-9`} /></label><select value={role} onChange={(event) => setRole(event.target.value)} className={`${inputClass} w-auto`}><option value="all">ทุกบทบาท</option>{allowedRoles.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></div>}>
                {users.isPending && <QuerySkeleton />}
                {users.isError && <QueryError onRetry={() => users.refetch()} />}
                {users.data && <DataTable data={filtered} columns={columns} minWidth="wide" />}
            </Panel>
            {editing && <Modal title={editing === 'new' ? 'เพิ่มผู้ใช้งาน' : 'แก้ไขผู้ใช้งาน'} description="บัญชีนี้จะถูกผูกกับอำเภอที่เลือกอยู่ในระบบ" onClose={() => setEditing(null)}><form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="ชื่อ"><input required maxLength={100} value={draft.first_name} onChange={(event) => setDraft({ ...draft, first_name: event.target.value })} className={inputClass} /></Field>
                <Field label="นามสกุล"><input required maxLength={100} value={draft.last_name} onChange={(event) => setDraft({ ...draft, last_name: event.target.value })} className={inputClass} /></Field>
                <Field label="ชื่อผู้ใช้"><input required minLength={3} maxLength={50} autoComplete="off" value={draft.username} onChange={(event) => setDraft({ ...draft, username: event.target.value })} className={inputClass} /></Field>
                <Field label={editing === 'new' ? 'รหัสผ่าน' : 'รหัสผ่านใหม่ (ถ้าต้องการเปลี่ยน)'} hint="อย่างน้อย 8 ตัวอักษร"><input required={editing === 'new'} minLength={8} maxLength={72} type="password" autoComplete="new-password" value={draft.password} onChange={(event) => setDraft({ ...draft, password: event.target.value })} className={inputClass} /></Field>
                <Field label="บทบาท"><select value={draft.role} onChange={(event) => setDraft({ ...draft, role: event.target.value as AdminRole })} className={inputClass}>{allowedRoles.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></Field>
                {draft.role !== 'super_admin' && <div className="sm:col-span-2">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2"><div><h3 className="text-sm font-bold text-slate-700">กลุ่มที่รับผิดชอบ</h3><p className="mt-0.5 text-xs text-slate-500">เลือกได้มากกว่าหนึ่งกลุ่ม รายการเป็นชื่อภาษาไทยจากข้อมูลอำเภอปัจจุบัน</p></div><StatusBadge tone={draft.assigned_groups.length > 0 ? 'info' : 'warning'}>เลือกแล้ว {draft.assigned_groups.length} กลุ่ม</StatusBadge></div>
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/70">
                        <div className="flex flex-col gap-2 border-b border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                            <label className="relative min-w-0 flex-1"><MagnifyingGlass size={16} className="absolute left-3 top-3 text-slate-400" /><input value={groupSearch} onChange={(event) => setGroupSearch(event.target.value)} placeholder="ค้นหาชื่อกลุ่ม ตำบล หรือชื่อครู" className={`${inputClass} pl-9`} /></label>
                            <div className="flex gap-2"><button type="button" onClick={() => setDraft({ ...draft, assigned_groups: availableGroups.map((group) => group.code) })} disabled={availableGroups.length === 0} className={secondaryButton}>เลือกทั้งหมด</button><button type="button" onClick={() => setDraft({ ...draft, assigned_groups: [] })} disabled={draft.assigned_groups.length === 0} className={secondaryButton}>ล้าง</button></div>
                        </div>
                        <div className="grid max-h-64 gap-2 overflow-y-auto p-3 sm:grid-cols-2">
                            {groupOptions.map((group) => <label key={group.code} className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition ${draft.assigned_groups.includes(group.code) ? 'border-brand-400 bg-brand-50 shadow-sm' : 'border-slate-200 bg-white hover:border-brand-200'}`}><input type="checkbox" checked={draft.assigned_groups.includes(group.code)} onChange={() => toggleGroup(group.code)} className="mt-1 size-4 rounded border-slate-300 accent-brand-700" /><span className="min-w-0"><span className="block font-bold leading-5 text-slate-950">{group.name}</span><span className="mt-0.5 block text-xs font-bold text-brand-700">{group.level ?? 'ไม่ระบุระดับ'} · รหัส {group.code}</span>{group.advisor && <span className="mt-0.5 block text-xs text-slate-500">ครูที่ปรึกษา: {group.advisor}</span>}{group.meeting_place && <span className="mt-0.5 block text-xs text-slate-500">สถานที่พบกลุ่ม: {group.meeting_place}</span>}</span></label>)}
                            {groupOptions.length === 0 && <p className="py-8 text-center text-sm text-slate-500 sm:col-span-2">{availableGroups.length === 0 ? 'ยังไม่พบข้อมูลกลุ่มใน batch ปัจจุบันของอำเภอนี้' : 'ไม่พบกลุ่มที่ตรงกับคำค้น'}</p>}
                        </div>
                    </div>
                    {draft.role === 'teacher' && draft.assigned_groups.length === 0 && <p className="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900">ครูที่ยังไม่ได้เลือกกลุ่มจะไม่เห็นข้อมูลนักศึกษา จนกว่าจะกำหนดกลุ่มรับผิดชอบ</p>}
                </div>}
                <div className="sm:col-span-2"><MutationError error={save.error} /></div>
                <div className="flex justify-end gap-2 sm:col-span-2"><button type="button" onClick={() => setEditing(null)} className={secondaryButton}>ยกเลิก</button><button type="submit" disabled={save.isPending} className={primaryButton}><FloppyDisk size={17} weight="bold" />{save.isPending ? 'กำลังบันทึก' : 'บันทึกผู้ใช้'}</button></div>
            </form></Modal>}
        </div>
    );
}

type ImportBatch = { id: number; batch_key: string; district_name: string; academic_term: string; status: string; row_count: number; table_count: number; warning_count: number; replaced_batch_count?: number; is_active: boolean; exam_schedule_ready?: boolean; missing_exam_data?: string[] };
type ImportStart = { job_id: string; status: 'queued'; message: string };
type ImportJob = { job_id: string; status: 'queued' | 'processing' | 'completed' | 'failed'; message: string; progress?: number; processed_rows?: number; total_rows?: number; current_table?: string; result?: ImportBatch };
type ImportDeleteResult = { deleted: boolean; batch_key: string; removed_table_count: number; removed_zip: boolean; removed_extract_directory: boolean };
function importTone(status: string): StatusTone { return status === 'พร้อมใช้งาน' ? 'success' : status.includes('ตรวจ') || status.includes('รอ') ? 'warning' : 'neutral'; }

export function AdminImportsPage() {
    const queryClient = useQueryClient();
    const [file, setFile] = useState<File | null>(null);
    const [term, setTerm] = useState('1/2569');
    const [jobId, setJobId] = useState<string | null>(null);
    const [handledJobId, setHandledJobId] = useState<string | null>(null);
    const [uploadProgress, setUploadProgress] = useState(0);
    const imports = useQuery({
        queryKey: ['admin', 'imports'],
        queryFn: async ({ signal }) => {
            const response = await getFeatureData<ImportBatch[]>('/api/v1/admin/imports', signal);
            const labels: Record<string, string> = { active: 'พร้อมใช้งาน', validated: 'ตรวจแล้ว', archived: 'เก็บถาวร', failed: 'ไม่สำเร็จ' };
            return { ...response, data: response.data.map((batch) => ({ ...batch, status: labels[batch.status] ?? batch.status })) };
        },
    });
    const upload = useMutation({
        mutationFn: () => {
            if (!file) throw new Error('กรุณาเลือกไฟล์ ZIP ก่อนนำเข้า');
            const payload = new FormData();
            payload.append('archive', file);
            payload.append('academic_term', term);
            return uploadFeatureData<ImportStart>('/api/v1/admin/imports', payload, setUploadProgress);
        },
        onMutate: () => setUploadProgress(0),
        onSuccess: (response) => {
            setFile(null);
            setJobId(response.data.job_id);
            setHandledJobId(null);
        },
        onError: () => setUploadProgress(0),
    });
    const importJob = useQuery({
        queryKey: ['admin', 'imports', 'job', jobId],
        queryFn: ({ signal }) => getFeatureData<ImportJob>(`/api/v1/admin/imports/jobs/${jobId}`, signal),
        enabled: jobId !== null,
        retry: 4,
        retryDelay: (attempt) => Math.min(5_000, 1_000 * (attempt + 1)),
        refetchInterval: (query) => ['queued', 'processing'].includes(query.state.data?.data.status ?? '') ? 2_500 : false,
    });
    const jobStatus = importJob.data?.data.status;
    const jobRunning = jobId !== null && jobStatus !== 'completed' && jobStatus !== 'failed';
    const loading = upload.isPending || jobRunning;
    const currentProgress = upload.isPending ? uploadProgress : Math.max(0, Math.min(100, importJob.data?.data.progress ?? 0));
    const remove = useMutation({
        meta: { notification: { success: 'ลบชุดข้อมูลและไฟล์เดิมเรียบร้อยแล้ว' } },
        mutationFn: (batch: ImportBatch) => sendFeatureData<ImportDeleteResult>(`/api/v1/admin/imports/${encodeURIComponent(batch.batch_key)}`, 'DELETE'),
        onSuccess: () => {
            setJobId(null);
            setHandledJobId(null);
            void queryClient.invalidateQueries({ queryKey: ['admin', 'imports'] });
        },
    });
    useEffect(() => {
        if (!jobId || handledJobId === jobId || (jobStatus !== 'completed' && jobStatus !== 'failed')) return;
        setHandledJobId(jobId);
        if (jobStatus === 'completed') {
            void (async () => {
                await queryClient.invalidateQueries({
                    predicate: (query) => {
                        const key = query.queryKey;
                        return !['auth', 'system'].includes(String(key[0])) && !(key[0] === 'admin' && key[1] === 'imports' && key[2] === 'job');
                    },
                    refetchType: 'none',
                });
                await queryClient.refetchQueries({ queryKey: ['admin', 'imports'], type: 'active' });
                setJobId(null);
                setUploadProgress(0);
                upload.reset();
                showSuccessAlert('นำเข้าและเปิดใช้ชุดข้อมูลใหม่เรียบร้อยแล้ว ข้อมูลบนหน้าจออัปเดตเป็นชุดล่าสุดแล้ว');
            })();
        } else {
            showErrorAlert(importJob.data?.data.message ?? 'นำเข้าข้อมูลไม่สำเร็จ กรุณาตรวจไฟล์แล้วลองใหม่');
        }
    }, [handledJobId, importJob.data?.data.message, jobId, jobStatus, queryClient]);
    function confirmDelete(batch: ImportBatch) {
        if (loading || remove.isPending) return;
        if (window.confirm(`ยืนยันลบชุดข้อมูล ${batch.batch_key} ของ${batch.district_name}หรือไม่?\n\nระบบจะลบตาราง ฐานทะเบียน ZIP และไฟล์ DBF/FPT ของชุดนี้ทั้งหมด หลังลบแล้วเมนูข้อมูลนักศึกษาจะไม่มีข้อมูลจนกว่าจะนำเข้าชุดใหม่`)) {
            remove.mutate(batch);
        }
    }
    const columns = useMemo<ColumnDef<ImportBatch>[]>(() => [
        { accessorKey: 'batch_key', header: 'Batch', size: 245, meta: { compactSize: 130 }, cell: ({ row }) => <div><p className="font-bold text-slate-950">{row.original.batch_key}</p><p className="mt-0.5 text-xs text-slate-500">{row.original.district_name}</p></div> },
        { accessorKey: 'academic_term', header: 'ภาคเรียน', size: 110, meta: { compactSize: 64, compactTextAlign: 'center' } },
        { accessorKey: 'row_count', header: 'จำนวนแถว', size: 125, meta: { compactSize: 68, compactTextAlign: 'center' }, cell: ({ getValue }) => Number(getValue()).toLocaleString('th-TH') },
        { accessorKey: 'table_count', header: 'ตาราง', size: 92, meta: { compactSize: 52, compactTextAlign: 'center' } },
        { id: 'exam_data', header: 'ข้อมูลตารางสอบ', size: 205, meta: { compactSize: 112 }, cell: ({ row }) => row.original.exam_schedule_ready ? <StatusBadge tone="success">ครบ</StatusBadge> : <div><StatusBadge tone="warning">ไม่ครบ</StatusBadge><p className="mt-1 text-xs leading-5 text-amber-800">ขาด {(row.original.missing_exam_data ?? []).join(', ') || 'schedule / field'}</p></div> },
        { accessorKey: 'warning_count', header: 'คำเตือน', size: 105, meta: { compactSize: 62, compactTextAlign: 'center' }, cell: ({ getValue }) => <StatusBadge tone={Number(getValue()) > 0 ? 'warning' : 'success'}>{getValue<number>()}</StatusBadge> },
        { accessorKey: 'status', header: 'สถานะ', size: 135, meta: { compactSize: 76, compactTextAlign: 'center' }, cell: ({ getValue }) => <StatusBadge tone={importTone(getValue<string>())}>{getValue<string>()}</StatusBadge> },
        { id: 'actions', header: 'จัดการ', size: 112, meta: { compactSize: 72, compactTextAlign: 'center' }, enableSorting: false, cell: ({ row }) => <button type="button" onClick={() => confirmDelete(row.original)} disabled={loading || remove.isPending} className="responsive-table-action inline-flex items-center justify-center gap-1.5 rounded-full border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50" aria-label={`ลบชุดข้อมูล ${row.original.batch_key}`}>{remove.isPending ? <CircleNotch size={16} className="animate-spin" /> : <Trash size={16} weight="bold" />}<span>ลบข้อมูล</span></button> },
    ], [loading, remove.isPending]);
    const readOnly = imports.data ? imports.data.meta.read_only === true : true;
    function submit(event: FormEvent) { event.preventDefault(); upload.mutate(); }
    return (
        <div>
            <PageHeader category="จัดการข้อมูล" title="นำเข้าข้อมูล ZIP และ DBF" description="ตรวจชุดใหม่ให้สมบูรณ์ก่อนแทนที่ชุดเดิมอัตโนมัติ โดยจำกัดเฉพาะอำเภอที่เลือก" icon={UploadSimple} actions={<StatusBadge tone={readOnly ? 'warning' : 'success'}>{readOnly ? 'ปิดการนำเข้า' : 'พร้อมนำเข้า'}</StatusBadge>} />
            <div className="grid gap-5 xl:grid-cols-[0.72fr_1.28fr]">
                <Panel title="อัปโหลดชุดข้อมูลใหม่" description="เมื่อสำเร็จ ระบบจะใช้ชุดนี้แทนชุดเดิมของอำเภอทันที">
                    <form onSubmit={submit} className="space-y-4">
                        <label className="block"><span className="mb-2 block text-sm font-bold text-slate-700">ไฟล์ข้อมูล</span><span className="grid min-h-32 place-items-center rounded-2xl border border-dashed border-brand-300 bg-brand-50 px-4 py-6 text-center"><FileZip size={32} weight="duotone" className="text-brand-700" /><span className="mt-2 max-w-full truncate text-sm font-bold text-slate-800">{file?.name ?? 'เลือกไฟล์ ZIP'}</span><input key={jobId ?? 'ready'} type="file" accept=".zip,application/zip" disabled={readOnly || upload.isPending || jobRunning} onChange={(event) => { setFile(event.target.files?.[0] ?? null); setJobId(null); setHandledJobId(null); setUploadProgress(0); upload.reset(); }} className="mt-3 block max-w-full text-xs text-slate-600 file:mr-3 file:rounded-full file:border-0 file:bg-brand-700 file:px-4 file:py-2 file:font-bold file:text-white" /></span></label>
                        <Field label="ภาคเรียน"><input required pattern="(?:[12]/25[0-9]{2}|25[0-9]{2}/[12])" value={term} onChange={(event) => setTerm(event.target.value)} disabled={readOnly} className={inputClass} placeholder="1/2569" /></Field>
                        <MutationError error={upload.error ?? remove.error} />
                        {importJob.isError && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">อ่านสถานะการนำเข้าไม่สำเร็จ ระบบอาจยังทำงานอยู่ กรุณารอสักครู่แล้วรีเฟรชหน้า</p>}
                        {loading && <div role="status" aria-live="polite" className="overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 to-sky-50 p-4 shadow-sm"><div className="flex items-start gap-3"><span className="grid size-11 shrink-0 place-items-center rounded-full bg-white text-brand-700 shadow-sm"><CircleNotch size={25} weight="bold" className="animate-spin" /></span><div className="min-w-0 flex-1"><div className="flex items-center justify-between gap-3"><p className="font-bold text-slate-950">{upload.isPending ? 'กำลังอัปโหลดไฟล์ ZIP' : jobStatus === 'queued' ? 'ไฟล์อยู่ในคิวนำเข้า' : 'กำลังตรวจสอบและนำเข้าข้อมูล'}</p><strong className="text-sm text-brand-800">{currentProgress}%</strong></div><p className="mt-1 text-sm leading-6 text-slate-600">{upload.isPending ? `กำลังส่ง ${file?.name ?? 'ไฟล์ข้อมูล'} ไปยังเซิร์ฟเวอร์ กรุณาอย่าปิดหน้านี้` : importJob.data?.data.message ?? 'กำลังเริ่มงานเบื้องหลังอัตโนมัติ กรุณารอสักครู่'}</p>{!upload.isPending && (importJob.data?.data.total_rows ?? 0) > 0 && <p className="mt-1 text-xs font-semibold text-slate-500">ประมวลผล {Number(importJob.data?.data.processed_rows ?? 0).toLocaleString('th-TH')} / {Number(importJob.data?.data.total_rows ?? 0).toLocaleString('th-TH')} แถว{importJob.data?.data.current_table ? ` · ${importJob.data.data.current_table}` : ''}</p>}</div></div><div className="mt-3 h-2 overflow-hidden rounded-full bg-white"><div className="h-full rounded-full bg-brand-600 transition-[width] duration-500" style={{ width: `${currentProgress}%` }} /></div></div>}
                        {jobStatus === 'failed' && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">{importJob.data?.data.message}</p>}
                        {jobStatus === 'completed' && importJob.data?.data.result && <p role="status" className="rounded-xl border border-brand-200 bg-brand-50 p-3 text-sm font-bold text-brand-800">นำเข้าสำเร็จ {importJob.data.data.result.table_count.toLocaleString('th-TH')} ตาราง รวม {importJob.data.data.result.row_count.toLocaleString('th-TH')} แถว เปิดใช้งานแล้ว และลบชุดเก่า {importJob.data.data.result.replaced_batch_count ?? 0} ชุด</p>}
                        <button type="submit" disabled={readOnly || !file || loading || remove.isPending} className={`${primaryButton} w-full py-3`}>{loading ? <CircleNotch size={18} weight="bold" className="animate-spin" /> : <UploadSimple size={18} weight="bold" />}{upload.isPending ? 'กำลังอัปโหลด กรุณารอ' : jobRunning ? 'กำลังนำเข้า กรุณารอ' : 'ตรวจสอบและเริ่มนำเข้า'}</button>
                        <p className="text-xs leading-5 text-slate-500">รองรับ ZIP ไม่เกิน 90 MB ระบบปฏิเสธ path อันตราย, symbolic link และ ZIP bomb เมื่อชุดใหม่ผ่านครบทุกขั้น ระบบจะเปิดใช้ชุดใหม่และลบตาราง ZIP และไฟล์ DBF/FPT ชุดเดิมโดยอัตโนมัติ หากชุดใหม่ไม่สมบูรณ์ ชุดเดิมจะยังใช้งานต่อ</p>
                    </form>
                </Panel>
                <Panel title="ชุดข้อมูลปัจจุบัน" description="แต่ละอำเภอมีชุดข้อมูลใช้งานหนึ่งชุด ชุดก่อนหน้าจะถูกลบเมื่อชุดใหม่ผ่านการนำเข้า">
                    {imports.data?.data.some((batch) => batch.is_active && batch.exam_schedule_ready === false) && <div role="alert" className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950"><p className="font-black">ข้อมูลนักศึกษาพร้อมใช้ แต่ข้อมูลตารางสอบยังไม่ครบ</p><p className="mt-1">นำเข้า ZIP จาก ITW51 ที่มี SCHEDULE.DBF ในโฟลเดอร์ระดับ 1, 2, 3 และ FIELD.DBF ระบบจะเก็บเป็นตารางในฐานข้อมูลของระบบเอง</p></div>}
                    {imports.isPending && <QuerySkeleton />}{imports.isError && <QueryError onRetry={() => imports.refetch()} />}{imports.data && <DataTable data={imports.data.data} columns={columns} minWidth="wide" />}
                </Panel>
            </div>
        </div>
    );
}

type ExamRoom = { id: number; district_id: number; term: string; subject_code: string; assignment_type: 'group_range' | 'student_range'; start_val: string; end_val: string; room_name: string; capacity: number | null; status: string };
type RoomDraft = Pick<ExamRoom, 'term' | 'subject_code' | 'assignment_type' | 'start_val' | 'end_val' | 'room_name'>;
const blankRoom: RoomDraft = { term: '1/2569', subject_code: '', assignment_type: 'group_range', start_val: '', end_val: '', room_name: '' };

export function AdminExamRoomsPage() {
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState<ExamRoom | 'new' | null>(null);
    const [draft, setDraft] = useState<RoomDraft>(blankRoom);
    const rooms = useQuery({ queryKey: ['admin', 'exam-rooms'], queryFn: ({ signal }) => getFeatureData<ExamRoom[]>('/api/v1/admin/exam-rooms', signal) });
    const save = useMutation({
        meta: { notification: { success: editing === 'new' ? 'เพิ่มห้องสอบสำเร็จ' : 'บันทึกห้องสอบสำเร็จ' } },
        mutationFn: () => editing === 'new' ? sendFeatureData<ExamRoom>('/api/v1/admin/exam-rooms', 'POST', draft) : sendFeatureData<ExamRoom>(`/api/v1/admin/exam-rooms/${editing?.id}`, 'PATCH', draft),
        onSuccess: () => { setEditing(null); void queryClient.invalidateQueries({ queryKey: ['admin', 'exam-rooms'] }); },
    });
    const remove = useMutation({
        meta: { notification: { success: 'ลบห้องสอบเรียบร้อยแล้ว' } },
        mutationFn: (id: number) => sendFeatureData<{ deleted: boolean; id: number }>(`/api/v1/admin/exam-rooms/${id}`, 'DELETE'),
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['admin', 'exam-rooms'] }),
    });
    function openCreate() { setDraft(blankRoom); save.reset(); setEditing('new'); }
    function openEdit(room: ExamRoom) { setDraft({ term: room.term, subject_code: room.subject_code, assignment_type: room.assignment_type, start_val: room.start_val, end_val: room.end_val, room_name: room.room_name }); save.reset(); setEditing(room); }
    function confirmDelete(room: ExamRoom) { if (window.confirm(`ยืนยันลบห้องสอบ “${room.room_name}” เฉพาะอำเภอนี้หรือไม่`)) remove.mutate(room.id); }
    const columns = useMemo<ColumnDef<ExamRoom>[]>(() => [
        { accessorKey: 'term', header: 'ภาคเรียน', size: 105, meta: { compactSize: 62, compactTextAlign: 'center' } },
        { accessorKey: 'subject_code', header: 'รหัสวิชา', size: 120, meta: { compactSize: 74 }, cell: ({ getValue }) => <span className="font-bold text-slate-950">{getValue<string>()}</span> },
        { accessorKey: 'assignment_type', header: 'จัดตาม', size: 150, meta: { compactSize: 82, compactTextAlign: 'center' }, cell: ({ getValue }) => getValue<string>() === 'group_range' ? 'ช่วงกลุ่มเรียน' : 'ช่วงรหัสนักศึกษา' },
        { id: 'range', header: 'ช่วง', size: 170, meta: { compactSize: 94, compactTextAlign: 'center' }, accessorFn: (row) => `${row.start_val} - ${row.end_val}` },
        { accessorKey: 'room_name', header: 'ห้องสอบ', size: 180, meta: { compactSize: 110 }, cell: ({ getValue }) => <span className="font-bold text-slate-950">{getValue<string>()}</span> },
        { accessorKey: 'capacity', header: 'จำนวน', size: 105, meta: { compactSize: 62, compactTextAlign: 'center' }, cell: ({ getValue }) => getValue<number | null>() === null ? '-' : `${getValue<number>()?.toLocaleString('th-TH')} คน` },
        { id: 'actions', header: 'จัดการ', size: 145, meta: { compactSize: 86, compactTextAlign: 'center' }, enableSorting: false, cell: ({ row }) => <div className="flex justify-center gap-1.5"><button type="button" onClick={() => openEdit(row.original)} className={`${secondaryButton} responsive-table-action`} aria-label={`แก้ไขห้องสอบ ${row.original.room_name}`}><PencilSimple size={15} /> <span>แก้ไข</span></button><button type="button" onClick={() => confirmDelete(row.original)} disabled={remove.isPending} className="responsive-table-action grid size-10 place-items-center rounded-full border border-rose-200 bg-white text-rose-700 hover:bg-rose-50" aria-label="ลบห้องสอบ"><Trash size={16} weight="bold" /></button></div> },
    ], [remove.isPending]);
    return (
        <div>
            <PageHeader category="จัดการการสอบ" title="ห้องสอบและช่วงผู้เข้าสอบ" description="เพิ่มและแก้ไขการจัดห้องตามช่วงกลุ่มเรียนหรือช่วงรหัสนักศึกษา โดยจำกัดเฉพาะอำเภอที่เลือก" icon={DoorOpen} actions={<button type="button" onClick={openCreate} disabled={rooms.data?.meta.read_only === true} className={primaryButton}><Plus size={17} weight="bold" /> เพิ่มห้องสอบ</button>} />
            <div className="mb-5 grid gap-3 sm:grid-cols-3"><StatTile label="รายการห้องสอบ" value={rooms.data?.data.length ?? 0} detail="ในอำเภอที่เลือก" icon={DoorOpen} tone="sky" /><StatTile label="จำนวนรวม" value={(rooms.data?.data ?? []).reduce((sum, room) => sum + (room.capacity ?? 0), 0)} detail="คำนวณจากช่วงตัวเลข" icon={Buildings} /><StatTile label="ภาคเรียน" value={rooms.data?.data[0]?.term ?? '-'} detail="รายการล่าสุด" icon={Archive} tone="amber" /></div>
            {remove.isError && <div className="mb-4"><MutationError error={remove.error} /></div>}
            <Panel title="รายการจัดห้องสอบ" description="การลบต้องยืนยันก่อนและบันทึก audit log">{rooms.isPending && <QuerySkeleton />}{rooms.isError && <QueryError onRetry={() => rooms.refetch()} />}{rooms.data && <DataTable data={rooms.data.data} columns={columns} minWidth="wide" />}</Panel>
            {editing && <Modal title={editing === 'new' ? 'เพิ่มห้องสอบ' : 'แก้ไขห้องสอบ'} description="ข้อมูลจะถูกบันทึกเฉพาะอำเภอที่เลือก" onClose={() => setEditing(null)}><form onSubmit={(event) => { event.preventDefault(); save.mutate(); }} className="grid gap-4 sm:grid-cols-2">
                <Field label="ภาคเรียน"><input required pattern="(?:[12]/(?:25)?[0-9]{2}|(?:25)?[0-9]{2}/[12])" value={draft.term} onChange={(event) => setDraft({ ...draft, term: event.target.value })} className={inputClass} placeholder="1/2569" /></Field>
                <Field label="รหัสวิชา"><input required maxLength={100} value={draft.subject_code} onChange={(event) => setDraft({ ...draft, subject_code: event.target.value })} className={inputClass} /></Field>
                <Field label="วิธีจัดช่วง"><select value={draft.assignment_type} onChange={(event) => setDraft({ ...draft, assignment_type: event.target.value as RoomDraft['assignment_type'] })} className={inputClass}><option value="group_range">ช่วงกลุ่มเรียน</option><option value="student_range">ช่วงรหัสนักศึกษา</option></select></Field>
                <Field label="ชื่อห้องสอบ"><input required maxLength={100} value={draft.room_name} onChange={(event) => setDraft({ ...draft, room_name: event.target.value })} className={inputClass} /></Field>
                <Field label="ค่าเริ่มต้น"><input required maxLength={100} value={draft.start_val} onChange={(event) => setDraft({ ...draft, start_val: event.target.value })} className={inputClass} /></Field>
                <Field label="ค่าสิ้นสุด"><input required maxLength={100} value={draft.end_val} onChange={(event) => setDraft({ ...draft, end_val: event.target.value })} className={inputClass} /></Field>
                <div className="sm:col-span-2"><MutationError error={save.error} /></div>
                <div className="flex justify-end gap-2 sm:col-span-2"><button type="button" onClick={() => setEditing(null)} className={secondaryButton}>ยกเลิก</button><button type="submit" disabled={save.isPending} className={primaryButton}><FloppyDisk size={17} weight="bold" />{save.isPending ? 'กำลังบันทึก' : 'บันทึกห้องสอบ'}</button></div>
            </form></Modal>}
        </div>
    );
}

type SafetyCheck = { key: string; label: string; description: string; status: 'ผ่าน' | 'คำเตือน' };
export function AdminDataMaintenancePage() {
    const safety = useQuery({
        queryKey: ['admin', 'imports', 'safety'],
        queryFn: async ({ signal }) => {
            type SafetyApi = { operations?: Array<{ key: string; label: string; reason: string; state: string }>; required_controls?: Array<{ key: string; label: string; state: string }> };
            const response = await getFeatureData<SafetyApi>('/api/v1/admin/imports/safety', signal);
            const data: SafetyCheck[] = [
                ...(response.data.operations ?? []).map((item) => ({ key: item.key, label: item.label, description: item.reason, status: item.state === 'enabled' ? 'ผ่าน' as const : 'คำเตือน' as const })),
                ...(response.data.required_controls ?? []).map((item) => ({ key: item.key, label: item.label, description: item.state === 'ready' ? 'มาตรการนี้เปิดใช้งานแล้ว' : 'ต้องตรวจสอบก่อนใช้งานจริง', status: item.state === 'ready' ? 'ผ่าน' as const : 'คำเตือน' as const })),
            ];
            return { ...response, data };
        },
    });
    return (
        <div>
            <PageHeader category="ดูแลข้อมูล" title="สถานะความปลอดภัยของข้อมูล" description="ตรวจขอบเขตอำเภอ การตรวจ ZIP โครงสร้าง DBF และ audit log ของระบบนำเข้า" icon={Database} actions={<StatusBadge tone="success">ควบคุมการลบตามอำเภอ</StatusBadge>} />
            <div className="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
                <Panel title="ผลตรวจระบบ" description="สถานะจริงของมาตรการควบคุมในระบบ"><div className="grid gap-3">{safety.isPending && <QuerySkeleton rows={5} />}{safety.isError && <QueryError onRetry={() => safety.refetch()} />}{safety.data?.data.map((item) => <article key={item.key} className="flex items-start gap-3 rounded-2xl bg-slate-50 p-4"><span className={`grid size-10 shrink-0 place-items-center rounded-xl ${item.status === 'ผ่าน' ? 'bg-brand-100 text-brand-800' : 'bg-amber-100 text-amber-900'}`}>{item.status === 'ผ่าน' ? <CheckCircle size={21} weight="fill" /> : <Warning size={21} weight="fill" />}</span><div><div className="flex flex-wrap items-center gap-2"><h2 className="font-bold text-slate-950">{item.label}</h2><StatusBadge tone={item.status === 'ผ่าน' ? 'success' : 'warning'}>{item.status}</StatusBadge></div><p className="mt-1 text-sm leading-6 text-slate-500">{item.description}</p></div></article>)}</div></Panel>
                <Panel title="ขอบเขตควบคุม" description="ป้องกันข้อมูลข้ามอำเภอและการลบโดยไม่ตั้งใจ" className="border-rose-200 bg-rose-50"><div className="space-y-3 text-sm leading-6 text-rose-950"><p className="flex items-start gap-2"><LockKey size={19} weight="fill" className="mt-0.5 shrink-0" />admin ใช้งานได้เฉพาะอำเภอของบัญชี ส่วน super admin ต้องเลือกอำเภอเป้าหมาย</p><p className="flex items-start gap-2"><Archive size={19} weight="fill" className="mt-0.5 shrink-0" />ชุดข้อมูลเดิมยังใช้งานต่อจนกว่า batch ใหม่จะนำเข้าสำเร็จครบทุกตาราง</p><p className="flex items-start gap-2"><HardDrives size={19} weight="fill" className="mt-0.5 shrink-0" />เมื่อผิดพลาด ระบบลบเฉพาะตารางและไฟล์ staging ของ batch ใหม่</p></div></Panel>
            </div>
        </div>
    );
}

type BrandingSettings = {
    schoolName: string;
    portalName: string;
    welcomeMessage: string;
    primaryColor: string;
    districtName: string;
    logoImageUrl: string | null;
    hasCustomLogo: boolean;
    heroImageUrl: string;
    hasCustomHero: boolean;
    dashboardHeroImageUrl: string;
    hasCustomDashboardHero: boolean;
};
type BrandingTextKey = 'schoolName' | 'portalName' | 'welcomeMessage' | 'primaryColor';
const demoBranding: BrandingSettings = {
    schoolName: 'ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอเสนา',
    portalName: 'SDL School',
    welcomeMessage: 'เรียนง่าย เห็นความก้าวหน้าชัดเจน',
    primaryColor: '#2563eb',
    districtName: 'อำเภอเสนา',
    logoImageUrl: null,
    hasCustomLogo: false,
    heroImageUrl: '/images/sena-students-hero.png',
    hasCustomHero: false,
    dashboardHeroImageUrl: '/images/dashboard-hero-sena-v2.webp',
    hasCustomDashboardHero: false,
};

type BrandAssetSlot = 'logo' | 'hero' | 'dashboard-hero';

function BrandAssetEditor({ slot, values }: { slot: BrandAssetSlot; values: BrandingSettings }) {
    const queryClient = useQueryClient();
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const config = slot === 'logo'
        ? { title: 'โลโก้หน่วยงาน', description: 'ใช้ใน sidebar หน้าแรก และหน้าเข้าสู่ระบบ', imageUrl: values.logoImageUrl, hasCustom: values.hasCustomLogo, endpoint: '/api/v1/admin/branding/assets/logo', field: 'asset', hint: 'JPG, PNG หรือ WebP ไม่เกิน 2 MB ขนาดอย่างน้อย 128×128 พิกเซล' }
        : slot === 'dashboard-hero'
            ? { title: 'ภาพหน้าปกหน้าแรกระบบ', description: 'ใช้ในแบนเนอร์สรุปข้อมูลหลังเข้าสู่ระบบ', imageUrl: values.dashboardHeroImageUrl, hasCustom: values.hasCustomDashboardHero, endpoint: '/api/v1/admin/branding/assets/dashboard-hero', field: 'asset', hint: 'JPG, PNG หรือ WebP ไม่เกิน 8 MB ขนาดอย่างน้อย 1200×600 พิกเซล' }
            : { title: 'ภาพหน้าปกหน้าเข้าสู่ระบบ', description: 'ใช้ในหน้าแนะนำและหน้าเข้าสู่ระบบ', imageUrl: values.heroImageUrl, hasCustom: values.hasCustomHero, endpoint: '/api/v1/admin/branding/hero', field: 'hero', hint: 'JPG, PNG หรือ WebP ไม่เกิน 6 MB ขนาด 800×600 ถึง 6000×6000 พิกเซล' };
    const updateCache = (response: { data: BrandingSettings; meta: Record<string, unknown> }) => {
        queryClient.setQueryData(['admin', 'branding'], response);
        void queryClient.invalidateQueries({ queryKey: ['auth', 'branding'] });
        setFile(null);
    };
    const upload = useMutation({
        meta: { notification: { success: `บันทึก${config.title}สำเร็จ` } },
        mutationFn: () => {
            const form = new FormData();
            if (file) form.append(config.field, file);
            return sendFeatureData<BrandingSettings>(config.endpoint, 'POST', form);
        },
        onSuccess: updateCache,
    });
    const remove = useMutation({
        meta: { notification: { success: `คืนค่า${config.title}เป็นค่าเริ่มต้นแล้ว` } },
        mutationFn: () => sendFeatureData<BrandingSettings>(config.endpoint, 'DELETE'),
        onSuccess: updateCache,
    });
    useEffect(() => {
        if (!file) { setPreview(null); return undefined; }
        const url = URL.createObjectURL(file);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);

    return (
        <Panel title={config.title} description={config.description}>
            {slot === 'logo' ? (
                <div className="grid min-h-44 place-items-center rounded-2xl border border-slate-200 bg-slate-50 p-6">
                    {preview || config.imageUrl
                        ? <img src={preview ?? config.imageUrl ?? ''} alt="ตัวอย่างโลโก้หน่วยงาน" className="max-h-32 max-w-[80%] object-contain" />
                        : <span className="grid size-28 place-items-center rounded-3xl bg-white text-brand-700 shadow-sm ring-1 ring-slate-200"><GraduationCap size={58} weight="duotone" /></span>}
                </div>
            ) : (
                <div className={`relative overflow-hidden rounded-2xl bg-slate-950 ${slot === 'dashboard-hero' ? 'aspect-[2/1]' : 'aspect-[16/10]'}`}>
                    <img src={preview ?? config.imageUrl ?? ''} alt={`ตัวอย่าง${config.title}`} className="absolute inset-0 size-full object-cover" />
                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/15 to-transparent" />
                    <div className="absolute inset-x-0 bottom-0 p-5 text-white"><p className="text-xs font-bold text-brand-200">{values.portalName}</p><h2 className="mt-2 max-w-[20ch] text-xl font-bold leading-tight">{slot === 'dashboard-hero' ? values.schoolName : values.welcomeMessage}</h2></div>
                </div>
            )}
            <div className="mt-4 flex flex-wrap items-center gap-2">
                <label className={secondaryButton}>
                    <ImageSquare size={17} weight="bold" /> {config.hasCustom ? 'เลือกไฟล์ใหม่' : 'เลือกไฟล์'}
                    <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(event) => { setFile(event.target.files?.[0] ?? null); upload.reset(); }} />
                </label>
                <button type="button" onClick={() => upload.mutate()} disabled={!file || upload.isPending} className={primaryButton}><UploadSimple size={17} weight="bold" /> {upload.isPending ? 'กำลังอัปโหลด' : 'บันทึกไฟล์'}</button>
                {config.hasCustom && <button type="button" onClick={() => remove.mutate()} disabled={remove.isPending} className={secondaryButton}><Trash size={17} weight="bold" /> ใช้ค่าเริ่มต้น</button>}
            </div>
            {file && <p className="mt-3 truncate text-sm font-semibold text-slate-700">ไฟล์ที่เลือก: {file.name}</p>}
            <p className="mt-2 text-xs leading-5 text-slate-500">{config.hint}</p>
            <MutationError error={upload.error ?? remove.error} />
        </Panel>
    );
}

export function SuperAdminBrandingPage() {
    const queryClient = useQueryClient();
    const branding = useQuery({ queryKey: ['admin', 'branding'], queryFn: ({ signal }) => getFeatureDataWithDemo<BrandingSettings>('/api/v1/admin/branding', demoBranding, signal) });
    const [draft, setDraft] = useState<Partial<Pick<BrandingSettings, BrandingTextKey>> | null>(null);
    const values = { ...(branding.data?.data ?? demoBranding), ...(draft ?? {}) };
    const save = useMutation({ meta: { notification: { success: 'บันทึกข้อมูลแบรนด์สำเร็จ' } }, mutationFn: () => sendFeatureData<BrandingSettings>('/api/v1/admin/branding', 'PATCH', values), onSuccess: (response) => { queryClient.setQueryData(['admin', 'branding'], response); void queryClient.invalidateQueries({ queryKey: ['auth', 'branding'] }); setDraft(null); } });
    function update(key: BrandingTextKey, value: string) { setDraft({ ...(draft ?? {}), [key]: value }); }
    return (
        <div>
            <PageHeader category="จัดการพื้นที่" title="แบรนด์และสื่อของระบบ" description="เปลี่ยนชื่อ สี โลโก้ และภาพหลักแยกตามอำเภอ เพื่อรองรับการขยายระบบในอนาคต" icon={PaintBrushBroad} />
            {branding.isPending && <QuerySkeleton rows={5} />}
            {branding.isError && <QueryError onRetry={() => branding.refetch()} />}
            {branding.data && (
                <div className="grid gap-5 lg:grid-cols-[0.9fr_1.1fr]">
                    <Panel title="ข้อมูลแบรนด์" description="ข้อมูลชุดนี้จะแสดงในทุกจุดหลักของระบบ">
                        <div className="grid gap-4">
                            {([['schoolName', 'ชื่อหน่วยงาน'], ['portalName', 'ชื่อระบบ'], ['welcomeMessage', 'ข้อความต้อนรับ']] as Array<[BrandingTextKey, string]>).map(([key, label]) => (
                                <Field key={key} label={label}><input value={values[key]} onChange={(event) => update(key, event.target.value)} className={inputClass} /></Field>
                            ))}
                            <Field label="พื้นที่" hint="ชื่อพื้นที่มาจากทะเบียนอำเภอและแก้ไขจากหน้านี้ไม่ได้"><input value={values.districtName} readOnly className={inputClass} /></Field>
                            <Field label="สีแบรนด์ประจำหน่วยงาน" hint="ใช้กับหน้าแนะนำและหน้าล็อกอิน ส่วนผู้ใช้แต่ละคนยังเลือกธีมสีส่วนตัวได้"><div className="flex gap-3"><input type="color" value={values.primaryColor} onChange={(event) => update('primaryColor', event.target.value)} className="h-10 w-16 rounded-xl border border-slate-300 bg-white p-1" /><input value={values.primaryColor} onChange={(event) => update('primaryColor', event.target.value)} className={inputClass} /></div></Field>
                            <MutationError error={save.error} />
                            <button type="button" onClick={() => save.mutate()} disabled={!draft || save.isPending} className={primaryButton}>{save.isPending ? 'กำลังบันทึก' : 'บันทึกแบรนด์'}</button>
                        </div>
                    </Panel>
                    <div className="grid gap-5">
                        <BrandAssetEditor slot="logo" values={values} />
                        <BrandAssetEditor slot="hero" values={values} />
                        <BrandAssetEditor slot="dashboard-hero" values={values} />
                    </div>
                </div>
            )}
        </div>
    );
}
