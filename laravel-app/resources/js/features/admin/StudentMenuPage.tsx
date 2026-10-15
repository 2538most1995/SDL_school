import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowDown, ArrowUp, ArrowCounterClockwise, FloppyDisk } from '@phosphor-icons/react';
import { Button, Card, Input, Spinner } from '../../components/MaterialUI';
import { apiGet } from '../../lib/api';
import { useSelectedDistrictId } from '../../lib/districtSelection';
import { studentMenuIcon, type StudentMenuItem } from '../../lib/studentMenu';
import { queryClient } from '../../query';
import { sendFeatureData } from '../api';

type MenuData = { items: StudentMenuItem[]; defaults: StudentMenuItem[]; can_save: boolean };

export function StudentMenuPage() {
    const districtId = useSelectedDistrictId();
    return <StudentMenuEditor key={districtId} districtId={districtId} />;
}

function StudentMenuEditor({ districtId }: { districtId: string | null }) {
    const menu = useQuery({
        queryKey: ['admin', 'student-menu', districtId],
        queryFn: ({ signal }) => {
            if (districtId !== window.localStorage.getItem('sena-district-id')) throw new Error('อำเภอที่เลือกเปลี่ยนแล้ว กรุณาโหลดเมนูอีกครั้ง');
            return apiGet<MenuData>('/api/v1/admin/student-menu', signal).then((response) => response.data);
        },
    });
    const [items, setItems] = useState<StudentMenuItem[]>([]);
    const [dirty, setDirty] = useState(false);
    const [saved, setSaved] = useState(false);
    useEffect(() => {
        if (menu.data && !dirty) setItems(menu.data.items);
    }, [menu.data, dirty]);
    const save = useMutation({
        mutationFn: (draft: StudentMenuItem[]) => {
            if (districtId !== window.localStorage.getItem('sena-district-id')) throw new Error('อำเภอที่เลือกเปลี่ยนแล้ว กรุณาโหลดเมนูก่อนบันทึก');
            return sendFeatureData<MenuData>('/api/v1/admin/student-menu', 'PATCH', {
                items: draft.map(({ key, label, description, visible }) => ({ key, label, description, visible })),
            });
        },
        meta: { notification: { success: false, error: false } },
        onSuccess: (response) => {
            void queryClient.invalidateQueries({ queryKey: ['system', 'catalog'] });
            if (districtId !== window.localStorage.getItem('sena-district-id')) {
                void queryClient.invalidateQueries({ queryKey: ['admin', 'student-menu', districtId] });
                return;
            }
            queryClient.setQueryData(['admin', 'student-menu', districtId], response.data);
            setItems(response.data.items);
            setDirty(false);
            setSaved(true);
        },
    });
    const edit = (next: StudentMenuItem[]) => {
        setItems(next);
        setDirty(true);
        setSaved(false);
        save.reset();
    };
    const update = (key: string, changes: Partial<StudentMenuItem>) => edit(items.map((item) => item.key === key ? { ...item, ...changes } : item));
    const move = (index: number, offset: number) => {
        const next = [...items];
        const target = index + offset;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        edit(next);
    };

    if (menu.isPending) return <Spinner label="กำลังโหลดเมนูนักศึกษา" />;
    if (menu.isError) return <Card className="p-6"><p role="alert">โหลดเมนูนักศึกษาไม่สำเร็จ</p><Button onClick={() => menu.refetch()}>ลองใหม่</Button></Card>;

    return <div className="space-y-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 className="text-2xl font-bold text-slate-950">เมนูนักศึกษา</h1>
                <p className="mt-2 text-sm text-slate-500">จัดการปุ่มในส่วน “เมนูการใช้งาน” บนหน้าแรกของนักศึกษาในอำเภอที่เลือก</p>
            </div>
            <Button appearance="primary" icon={<FloppyDisk size={18} />} disabled={!dirty || save.isPending || !menu.data.can_save || items.some((item) => !item.label.trim())} onClick={() => save.mutate(items)}>
                {save.isPending ? 'กำลังบันทึก' : 'บันทึกเมนูนักศึกษา'}
            </Button>
        </div>
        {!menu.data.can_save && <p role="alert" className="rounded-xl bg-amber-50 p-4 text-amber-900">ระบบยังไม่พร้อมบันทึกการตั้งค่า กรุณาให้ผู้ดูแลอัปเดตระบบก่อน</p>}
        {save.isError && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-rose-700">{save.error.message}</p>}
        {saved && <p role="status" className="rounded-xl bg-emerald-50 p-4 text-emerald-700">บันทึกเมนูนักศึกษาแล้ว</p>}
        <Card className="flex flex-wrap items-center justify-between gap-3 p-4">
            <p className="text-sm text-slate-600">แสดง {items.filter((item) => item.visible).length} จาก {items.length} ปุ่ม · เปิด–ปิด แก้ข้อความ และเลื่อนลำดับได้</p>
            <Button icon={<ArrowCounterClockwise size={17} />} disabled={save.isPending} onClick={() => edit(menu.data.defaults.map((item) => ({ ...item })))}>คืนค่าเริ่มต้น</Button>
        </Card>
        <fieldset disabled={save.isPending} className="space-y-3">
            <legend className="sr-only">รายการปุ่มเมนูนักศึกษา</legend>
            {items.map((item, index) => {
                const MenuIcon = studentMenuIcon(item.icon);
                return <Card key={item.key} className="p-4 sm:p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-brand-700"><MenuIcon size={22} weight="duotone" /></span>
                            <span className="min-w-0 text-sm font-bold text-slate-800">{index + 1}. {item.label || 'ยังไม่ระบุชื่อ'}</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="mr-2 inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700">
                                <input type="checkbox" className="size-4 accent-brand-700" checked={item.visible} onChange={(event) => update(item.key, { visible: event.target.checked })} aria-label={`แสดงเมนู ${item.label}`} />
                                {item.visible ? 'แสดง' : 'ซ่อน'}
                            </label>
                            <Button icon={<ArrowUp size={17} />} aria-label={`เลื่อน ${item.label} ขึ้น`} disabled={index === 0} onClick={() => move(index, -1)} />
                            <Button icon={<ArrowDown size={17} />} aria-label={`เลื่อน ${item.label} ลง`} disabled={index === items.length - 1} onClick={() => move(index, 1)} />
                        </div>
                    </div>
                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                        <label><span className="mb-1.5 block text-xs font-semibold text-slate-600">ชื่อปุ่ม</span><Input aria-label={`ชื่อปุ่ม ${item.key}`} maxLength={80} value={item.label} onChange={(event) => update(item.key, { label: event.target.value })} className="w-full" /></label>
                        <label><span className="mb-1.5 block text-xs font-semibold text-slate-600">คำอธิบาย</span><Input aria-label={`คำอธิบาย ${item.key}`} maxLength={160} value={item.description} onChange={(event) => update(item.key, { description: event.target.value })} className="w-full" /></label>
                    </div>
                </Card>;
            })}
        </fieldset>
    </div>;
}
