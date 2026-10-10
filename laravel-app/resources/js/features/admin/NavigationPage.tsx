import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowDown, ArrowUp, FloppyDisk, Plus, Trash } from '@phosphor-icons/react';
import { Button, Card, Input, Select, Spinner } from '../../components/MaterialUI';
import { apiGet, apiPost } from '../../lib/api';
import { queryClient } from '../../query';

type MenuItem = { key: string; label: string; roles: string[] };
type Group = { key: string; label: string; items: MenuItem[] };
type Navigation = { groups: Group[] };

function moved<T>(list: T[], index: number, offset: number): T[] {
    const next = [...list];
    const target = index + offset;
    if (target < 0 || target >= next.length) return next;
    [next[index], next[target]] = [next[target], next[index]];
    return next;
}

export function NavigationPage() {
    const districtId = window.localStorage.getItem('sena-district-id');
    const navigation = useQuery({ queryKey: ['admin', 'navigation', districtId], queryFn: ({ signal }) => apiGet<Navigation>('/api/v1/admin/navigation', signal).then((response) => response.data) });
    const [groups, setGroups] = useState<Group[]>([]);
    const [newCategory, setNewCategory] = useState('');
    const [dirty, setDirty] = useState(false);
    const [saved, setSaved] = useState(false);
    useEffect(() => { if (navigation.data) { setGroups(navigation.data.groups); setDirty(false); } }, [navigation.data]);
    useEffect(() => { setSaved(false); }, [districtId]);
    const save = useMutation({
        mutationFn: (draft: { districtId: string | null; groups: Group[] }) => apiPost<Navigation>('/api/v1/admin/navigation', { _method: 'PATCH', groups: draft.groups.map((group) => ({ key: group.key, label: group.label, items: group.items.map((item) => item.key) })) }),
        onSuccess: (response, draft) => {
            if (draft.districtId !== window.localStorage.getItem('sena-district-id')) {
                void queryClient.invalidateQueries({ queryKey: ['admin', 'navigation', draft.districtId] });
                void queryClient.invalidateQueries({ queryKey: ['system', 'catalog'] });
                return;
            }
            setGroups(response.data.groups); setDirty(false); setSaved(true);
            queryClient.setQueryData(['admin', 'navigation', districtId], response.data);
            void queryClient.invalidateQueries({ queryKey: ['system', 'catalog'] });
        },
    });
    const edit = (next: Group[]) => { setGroups(next); setDirty(true); setSaved(false); save.reset(); };
    if (navigation.isPending) return <Spinner label="กำลังโหลดเมนู" />;
    if (navigation.isError) return <Card className="p-6"><p role="alert">โหลดเมนูไม่สำเร็จ</p><Button onClick={() => navigation.refetch()}>ลองใหม่</Button></Card>;
    return <div className="space-y-5">
        <div className="flex flex-wrap items-start justify-between gap-4"><div><h1 className="text-2xl font-bold text-slate-950">หมวดหมู่และเมนู</h1><p className="mt-2 text-sm text-slate-500">จัดลำดับหมวดหมู่และเมนูย่อยสำหรับอำเภอที่เลือก แต่ละบัญชีจะเห็นเฉพาะเมนูตามสิทธิ์เดิม</p></div><Button appearance="primary" icon={<FloppyDisk size={18} />} disabled={!dirty || save.isPending || groups.some((group) => !group.label.trim())} onClick={() => save.mutate({ districtId, groups })}>{save.isPending ? 'กำลังบันทึก' : 'บันทึกเมนู'}</Button></div>
        {save.isError && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-rose-700">{save.error.message}</p>}
        {saved && <p role="status" className="rounded-xl bg-emerald-50 p-4 text-emerald-700">บันทึกหมวดหมู่และลำดับเมนูแล้ว</p>}
        <Card className="p-4"><form className="flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); if (!newCategory.trim()) return; edit([...groups, { key: `custom-${crypto.randomUUID()}`, label: newCategory.trim(), items: [] }]); setNewCategory(''); }}><label className="min-w-0 flex-1"><span className="mb-2 block text-sm font-bold">ชื่อหมวดหมู่ใหม่</span><Input disabled={save.isPending} value={newCategory} onChange={(event) => setNewCategory(event.target.value)} maxLength={80} placeholder="เช่น รายงานประจำภาคเรียน" className="w-full" /></label><Button type="submit" icon={<Plus size={17} />} disabled={save.isPending || !newCategory.trim() || groups.length >= 30}>เพิ่มหมวดหมู่</Button></form></Card>
        <fieldset disabled={save.isPending} className="space-y-4">{groups.map((group, groupIndex) => <Card key={group.key} className="overflow-hidden p-4 sm:p-5">
            <div className="flex flex-wrap items-center gap-2"><label className="min-w-0 flex-1"><span className="mb-1 block text-xs text-slate-500">หมวดหมู่ที่ {groupIndex + 1}</span><Input aria-label={`ชื่อหมวดหมู่ที่ ${groupIndex + 1}`} maxLength={80} value={group.label} onChange={(event) => edit(groups.map((entry) => entry.key === group.key ? { ...entry, label: event.target.value } : entry))} className="w-full font-bold" /></label><Button icon={<ArrowUp size={18} />} aria-label={`เลื่อนหมวดหมู่ ${group.label} ขึ้น`} disabled={groupIndex === 0} onClick={() => edit(moved(groups, groupIndex, -1))} /><Button icon={<ArrowDown size={18} />} aria-label={`เลื่อนหมวดหมู่ ${group.label} ลง`} disabled={groupIndex === groups.length - 1} onClick={() => edit(moved(groups, groupIndex, 1))} /><Button icon={<Trash size={18} />} aria-label={`ลบหมวดหมู่ ${group.label}`} disabled={group.items.length > 0 || groups.length === 1} title="ย้ายเมนูออกก่อนลบหมวดหมู่" onClick={() => edit(groups.filter((entry) => entry.key !== group.key))} /></div>
            <ol className="mt-4 space-y-2">{group.items.map((item, itemIndex) => <li key={item.key} className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 p-3"><span className="min-w-0 flex-1 text-sm font-bold text-slate-800">{item.label}<span className="mt-1 block text-xs font-normal text-slate-500">{item.roles.map((role) => ({ student: 'นักศึกษา', teacher: 'ครู', admin: 'ผู้ดูแลอำเภอ', super_admin: 'ผู้ดูแลส่วนกลาง' })[role] ?? role).join(' · ')}</span></span><div className="flex w-full items-center gap-2 sm:w-auto"><Select value={group.key} aria-label={`ย้าย ${item.label} ไปหมวดหมู่`} className="min-w-0 flex-1 sm:max-w-48" onChange={(event) => { const target = event.target.value; edit(groups.map((entry) => entry.key === group.key ? { ...entry, items: entry.items.filter((menu) => menu.key !== item.key) } : entry.key === target ? { ...entry, items: [...entry.items, item] } : entry)); }}>{groups.map((entry) => <option key={entry.key} value={entry.key}>{entry.label}</option>)}</Select><Button icon={<ArrowUp size={17} />} aria-label={`เลื่อน ${item.label} ขึ้น`} disabled={itemIndex === 0} onClick={() => edit(groups.map((entry) => entry.key === group.key ? { ...entry, items: moved(entry.items, itemIndex, -1) } : entry))} /><Button icon={<ArrowDown size={17} />} aria-label={`เลื่อน ${item.label} ลง`} disabled={itemIndex === group.items.length - 1} onClick={() => edit(groups.map((entry) => entry.key === group.key ? { ...entry, items: moved(entry.items, itemIndex, 1) } : entry))} /></div></li>)}</ol>
            {group.items.length === 0 && <p className="mt-4 text-sm text-slate-500">ยังไม่มีเมนู เลือกย้ายเมนูจากหมวดหมู่อื่นมาที่นี่</p>}
        </Card>)}</fieldset>
    </div>;
}
