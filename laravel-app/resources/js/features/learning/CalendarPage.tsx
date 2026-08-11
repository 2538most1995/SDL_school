import {
    ArrowSquareOut,
    ArrowsOut,
    CalendarBlank,
    CaretLeft,
    CaretRight,
    Clock,
    ImageSquare,
    MapPin,
    PencilSimple,
    Plus,
    Star,
    Trash,
    UploadSimple,
    X,
} from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Button, Field, Input, Select } from '../../components/MaterialUI';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { withAppBasePath } from '../../lib/urls';
import { getFeatureDataWithDemo, sendFeatureData } from '../api';

type CalendarEvent = {
    id: string;
    type: 'assignment' | 'meeting' | 'exam' | 'activity';
    title: string;
    description?: string;
    starts_at: string;
    ends_at: string;
    location: string;
    subject_code: string | null;
    image_url?: string | null;
    external_url?: string | null;
    featured_on_dashboard?: boolean;
    schedule_days?: CalendarDaySchedule[];
    can_edit?: boolean;
    raw?: CalendarRaw;
};

type CalendarDaySchedule = {
    date: string;
    start_time: string;
    end_time: string;
};

type CalendarRaw = {
    title?: string;
    event_type?: string;
    event_date?: string;
    end_date?: string;
    start_time?: string;
    end_time?: string;
    location?: string;
    target_group?: string;
    notes?: string;
    external_url?: string;
    featured_on_dashboard?: boolean;
    daily_schedule?: CalendarDaySchedule[];
};

type CalendarDraft = {
    title: string;
    event_type: 'meeting' | 'activity' | 'exam';
    event_date: string;
    end_date: string;
    start_time: string;
    end_time: string;
    location: string;
    target_group: string;
    notes: string;
    external_url: string;
    featured_on_dashboard: boolean;
};

type AvailableGroup = {
    code: string;
    name: string;
    label: string;
    level: string | null;
};

const eventTypeLabels: Record<CalendarEvent['type'], string> = {
    assignment: 'งาน',
    meeting: 'พบกลุ่ม',
    exam: 'สอบ',
    activity: 'กิจกรรม',
};

const eventTypeClasses: Record<CalendarEvent['type'], string> = {
    assignment: 'border-violet-200 bg-violet-50 text-violet-800',
    meeting: 'border-sky-200 bg-sky-50 text-sky-800',
    exam: 'border-rose-200 bg-rose-50 text-rose-800',
    activity: 'border-emerald-200 bg-emerald-50 text-emerald-800',
};

const weekdays = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

function parseEventDate(value: string): Date {
    return new Date(value.replace(' ', 'T'));
}

function dateKey(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function scheduleDateRange(start: string, end: string): string[] {
    const first = new Date(`${start}T00:00:00`);
    const last = new Date(`${end}T00:00:00`);
    if (Number.isNaN(first.getTime()) || Number.isNaN(last.getTime()) || last < first) return [];
    const dates: string[] = [];
    const cursor = new Date(first);
    while (cursor <= last && dates.length < 31) {
        dates.push(dateKey(cursor));
        cursor.setDate(cursor.getDate() + 1);
    }

    return dates;
}

function scheduleForRange(start: string, end: string, existing: CalendarDaySchedule[], defaultStart = '09:00', defaultEnd = '12:00'): CalendarDaySchedule[] {
    const byDate = new Map(existing.map((day) => [day.date, day]));

    return scheduleDateRange(start, end).map((date) => byDate.get(date) ?? { date, start_time: defaultStart, end_time: defaultEnd });
}

function formatScheduleDate(value: string): string {
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('th-TH', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(date);
}

function eventDateKeys(event: CalendarEvent, month: Date): string[] {
    const start = parseEventDate(event.starts_at);
    const rawEnd = parseEventDate(event.ends_at || event.starts_at);
    if (Number.isNaN(start.getTime())) return [];
    const end = Number.isNaN(rawEnd.getTime()) || rawEnd < start ? start : rawEnd;
    const cursor = new Date(start.getFullYear(), start.getMonth(), start.getDate());
    const finalDay = new Date(end.getFullYear(), end.getMonth(), end.getDate());
    const keys: string[] = [];

    for (let index = 0; cursor <= finalDay && index < 370; index += 1) {
        if (cursor.getFullYear() === month.getFullYear() && cursor.getMonth() === month.getMonth()) {
            keys.push(dateKey(cursor));
        }
        cursor.setDate(cursor.getDate() + 1);
    }

    return keys;
}

function eventOverlapsMonth(event: CalendarEvent, month: Date): boolean {
    const start = parseEventDate(event.starts_at);
    const rawEnd = parseEventDate(event.ends_at || event.starts_at);
    if (Number.isNaN(start.getTime())) return false;
    const end = Number.isNaN(rawEnd.getTime()) || rawEnd < start ? start : rawEnd;
    const monthStart = new Date(month.getFullYear(), month.getMonth(), 1);
    const nextMonth = new Date(month.getFullYear(), month.getMonth() + 1, 1);

    return start < nextMonth && end >= monthStart;
}

function eventTimeForDate(event: CalendarEvent, date: string): string {
    const schedule = event.schedule_days?.find((day) => day.date === date);
    if (schedule) return schedule.start_time;
    const startsAt = parseEventDate(event.starts_at);

    return !Number.isNaN(startsAt.getTime()) && dateKey(startsAt) === date
        ? startsAt.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', hour12: false })
        : '';
}

function formatEventDateTime(value: string): string {
    const date = parseEventDate(value);
    if (Number.isNaN(date.getTime())) return value || '-';

    return new Intl.DateTimeFormat('th-TH', {
        day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(date);
}

function calendarContentId(event: CalendarEvent): string | null {
    const match = event.id.match(/^event-(\d+)$/);

    return match?.[1] ?? null;
}

function CalendarEventCard({ event, onSelect }: { event: CalendarEvent; onSelect: (event: CalendarEvent) => void }) {
    return (
        <button type="button" onClick={() => onSelect(event)} className="group grid w-full overflow-hidden rounded-2xl border border-slate-200 bg-white text-left shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md sm:grid-cols-[160px_1fr]">
            {event.image_url ? (
                <img src={withAppBasePath(event.image_url)} alt="" className="h-40 w-full object-cover sm:h-full" />
            ) : (
                <span className="grid h-32 place-items-center bg-gradient-to-br from-brand-50 to-sky-100 text-brand-600 sm:h-full">
                    <ImageSquare size={36} weight="duotone" aria-hidden="true" />
                </span>
            )}
            <span className="min-w-0 p-4">
                <span className="flex flex-wrap gap-2"><span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-black ${eventTypeClasses[event.type]}`}>{eventTypeLabels[event.type]}</span>{event.featured_on_dashboard && <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-black text-amber-800"><Star size={13} weight="fill" />หน้าแรก</span>}</span>
                <strong className="mt-2 block text-base font-black leading-6 text-slate-950 group-hover:text-brand-800">{event.title}</strong>
                <span className="mt-2 flex items-center gap-2 text-sm font-semibold text-slate-600"><Clock size={16} aria-hidden="true" />{formatEventDateTime(event.starts_at)}</span>
                {event.location && <span className="mt-1.5 flex items-center gap-2 text-sm text-slate-500"><MapPin size={16} aria-hidden="true" />{event.location}</span>}
            </span>
        </button>
    );
}

function CalendarDetail({ event, canManage, onClose, onEdit, onDelete }: {
    event: CalendarEvent;
    canManage: boolean;
    onClose: () => void;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const canEdit = canManage && event.can_edit === true && calendarContentId(event) !== null;
    const [imageOpen, setImageOpen] = useState(false);
    const scheduleDays = event.schedule_days ?? [];

    return (
        <div className="fixed inset-0 z-[70] grid place-items-center overflow-y-auto bg-slate-950/55 p-3" role="dialog" aria-modal="true" aria-labelledby="calendar-detail-title">
            <section className="my-auto w-full max-w-2xl overflow-hidden rounded-3xl bg-white shadow-2xl">
                {event.image_url ? (
                    <button type="button" onClick={() => setImageOpen(true)} className="group relative block w-full overflow-hidden bg-slate-950" aria-label={`เปิดดูรูป ${event.title} ขนาดเต็ม`}>
                        <img src={withAppBasePath(event.image_url)} alt={`ภาพกิจกรรม ${event.title}`} className="aspect-[16/8] w-full object-cover transition group-hover:opacity-90" />
                        <span className="absolute bottom-3 right-3 inline-flex items-center gap-2 rounded-full bg-slate-950/75 px-3 py-1.5 text-xs font-bold text-white"><ArrowsOut size={16} />คลิกดูรูปเต็ม</span>
                    </button>
                ) : (
                    <div className="grid aspect-[16/5] place-items-center bg-gradient-to-br from-brand-50 to-sky-100 text-brand-600"><CalendarBlank size={48} weight="duotone" /></div>
                )}
                <div className="p-5 sm:p-7">
                    <div className="flex items-start justify-between gap-4">
                        <div className="min-w-0">
                            <div className="flex flex-wrap gap-2"><span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-black ${eventTypeClasses[event.type]}`}>{eventTypeLabels[event.type]}</span>{event.featured_on_dashboard && <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-black text-amber-800"><Star size={13} weight="fill" />แสดงบนหน้าแรก</span>}</div>
                            <h2 id="calendar-detail-title" className="mt-3 text-2xl font-black tracking-[-0.025em] text-slate-950">{event.title}</h2>
                        </div>
                        <button type="button" onClick={onClose} className="rounded-full p-2 text-slate-500 hover:bg-slate-100" aria-label="ปิด"><X size={21} /></button>
                    </div>
                    <div className="mt-5 rounded-2xl bg-slate-50 p-4 text-sm">
                        <h3 className="font-black text-slate-900">วันและเวลาของกิจกรรม</h3>
                        <dl className="mt-3 divide-y divide-slate-200">
                            {scheduleDays.length > 0 ? scheduleDays.map((day) => <div key={day.date} className="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0"><dt className="font-bold text-slate-600">{formatScheduleDate(day.date)}</dt><dd className="font-black text-slate-900">{day.start_time}–{day.end_time} น.</dd></div>) : <><div><dt className="font-bold text-slate-500">วันและเวลาเริ่ม</dt><dd className="mt-1 font-black text-slate-900">{formatEventDateTime(event.starts_at)}</dd></div><div className="mt-3"><dt className="font-bold text-slate-500">วันและเวลาสิ้นสุด</dt><dd className="mt-1 font-black text-slate-900">{formatEventDateTime(event.ends_at)}</dd></div></>}
                        </dl>
                        <div className="mt-4 border-t border-slate-200 pt-3"><span className="font-bold text-slate-500">สถานที่</span><strong className="mt-1 block text-slate-900">{event.location || 'ไม่ระบุสถานที่'}</strong></div>
                    </div>
                    {event.description && <p className="mt-5 whitespace-pre-line text-sm font-medium leading-7 text-slate-700">{event.description}</p>}
                    {event.external_url && <a href={event.external_url} target="_blank" rel="noopener noreferrer" className="mt-5 inline-flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm font-black text-brand-800 hover:bg-brand-100"><ArrowSquareOut size={18} />เปิดลิงก์กิจกรรม</a>}
                    <div className="mt-6 flex flex-wrap justify-end gap-2">
                        {canEdit && <Button appearance="outline" icon={<PencilSimple size={17} />} onClick={onEdit}>แก้ไข</Button>}
                        {canEdit && <Button appearance="outline" icon={<Trash size={17} />} onClick={onDelete}>ลบกิจกรรม</Button>}
                        <Button appearance="primary" onClick={onClose}>ปิด</Button>
                    </div>
                </div>
            </section>
            {imageOpen && event.image_url && <div className="fixed inset-0 z-[90] grid place-items-center bg-slate-950/95 p-3 sm:p-6" role="dialog" aria-modal="true" aria-label={`รูป ${event.title} ขนาดเต็ม`} onClick={() => setImageOpen(false)}><button type="button" onClick={() => setImageOpen(false)} className="absolute right-4 top-4 rounded-full bg-white/10 p-3 text-white hover:bg-white/20" aria-label="ปิดรูปขนาดเต็ม"><X size={24} /></button><img src={withAppBasePath(event.image_url)} alt={`ภาพกิจกรรม ${event.title}`} className="max-h-[92vh] max-w-[96vw] rounded-xl object-contain shadow-2xl" onClick={(clickEvent) => clickEvent.stopPropagation()} /></div>}
        </div>
    );
}

function CalendarEditor({ event, pending, error, onClose, onSubmit, availableGroups, canFeature }: {
    event: CalendarEvent | 'new';
    pending: boolean;
    error: Error | null;
    onClose: () => void;
    onSubmit: (form: FormData) => void;
    availableGroups: AvailableGroup[];
    canFeature: boolean;
}) {
    const raw = event === 'new' ? undefined : event.raw;
    const matchedGroup = availableGroups.find((group) => group.code === raw?.target_group || group.name === raw?.target_group);
    const initialDate = raw?.event_date ?? dateKey(new Date());
    const initialEndDate = raw?.end_date ?? initialDate;
    const existingSchedule = event === 'new' ? [] : (event.schedule_days ?? raw?.daily_schedule ?? []);
    const [draft, setDraft] = useState<CalendarDraft>({
        title: raw?.title ?? '',
        event_type: (raw?.event_type as CalendarDraft['event_type'] | undefined) ?? 'activity',
        event_date: initialDate,
        end_date: initialEndDate,
        start_time: raw?.start_time ?? '09:00',
        end_time: raw?.end_time ?? '12:00',
        location: raw?.location ?? '',
        target_group: matchedGroup?.code ?? raw?.target_group ?? '',
        notes: raw?.notes ?? '',
        external_url: raw?.external_url ?? (event === 'new' ? '' : (event.external_url ?? '')),
        featured_on_dashboard: raw?.featured_on_dashboard ?? (event === 'new' ? false : Boolean(event.featured_on_dashboard)),
    });
    const [dailySchedule, setDailySchedule] = useState<CalendarDaySchedule[]>(() => scheduleForRange(
        initialDate,
        initialEndDate,
        existingSchedule,
        raw?.start_time ?? '09:00',
        raw?.end_time ?? '12:00',
    ));
    const [image, setImage] = useState<File | null>(null);
    const [removeImage, setRemoveImage] = useState(false);
    const preview = useMemo(() => image ? URL.createObjectURL(image) : null, [image]);
    useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);

    const submit = (submitEvent: FormEvent) => {
        submitEvent.preventDefault();
        const form = new FormData();
        Object.entries(draft).forEach(([key, value]) => {
            if (key !== 'featured_on_dashboard') form.append(key, String(value));
        });
        const firstDay = dailySchedule[0];
        const lastDay = dailySchedule[dailySchedule.length - 1];
        if (firstDay) form.set('start_time', firstDay.start_time);
        if (lastDay) form.set('end_time', lastDay.end_time);
        dailySchedule.forEach((day, index) => {
            form.append(`daily_schedule[${index}][date]`, day.date);
            form.append(`daily_schedule[${index}][start_time]`, day.start_time);
            form.append(`daily_schedule[${index}][end_time]`, day.end_time);
        });
        if (canFeature) form.append('featured_on_dashboard', draft.featured_on_dashboard ? '1' : '0');
        if (image) form.append('image', image);
        if (removeImage) form.append('remove_image', '1');
        onSubmit(form);
    };

    const currentImage = event === 'new' ? null : event.image_url;
    const maxEndDate = useMemo(() => {
        const date = new Date(`${draft.event_date}T00:00:00`);
        if (Number.isNaN(date.getTime())) return draft.event_date;
        date.setDate(date.getDate() + 30);

        return dateKey(date);
    }, [draft.event_date]);

    return (
        <div className="fixed inset-0 z-[75] grid place-items-center overflow-y-auto bg-slate-950/55 p-3" role="dialog" aria-modal="true" aria-labelledby="calendar-editor-title">
            <section className="my-auto w-full max-w-3xl overflow-hidden rounded-3xl bg-white shadow-2xl">
                <header className="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-7">
                    <div><p className="text-xs font-black text-brand-700">ปฏิทินกิจกรรม</p><h2 id="calendar-editor-title" className="mt-1 text-xl font-black text-slate-950">{event === 'new' ? 'เพิ่มกิจกรรมใหม่' : 'แก้ไขกิจกรรม'}</h2></div>
                    <button type="button" onClick={onClose} className="rounded-full p-2 text-slate-500 hover:bg-slate-100" aria-label="ปิด"><X size={21} /></button>
                </header>
                <form onSubmit={submit} className="grid max-h-[78vh] gap-4 overflow-y-auto p-5 sm:grid-cols-2 sm:p-7">
                    <Field label="ชื่อกิจกรรม" required className="sm:col-span-2"><Input value={draft.title} onChange={(_, data) => setDraft({ ...draft, title: data.value })} maxLength={220} required /></Field>
                    <Field label="ประเภทกิจกรรม" required><Select value={draft.event_type} onChange={(_, data) => setDraft({ ...draft, event_type: data.value as CalendarDraft['event_type'] })} required><option value="activity">กิจกรรม</option><option value="meeting">พบกลุ่ม</option><option value="exam">สอบ</option></Select></Field>
                    <Field label="วันที่เริ่ม" required><Input type="date" value={draft.event_date} onChange={(_, data) => { const nextEnd = draft.end_date < data.value ? data.value : draft.end_date; setDraft({ ...draft, event_date: data.value, end_date: nextEnd }); setDailySchedule(scheduleForRange(data.value, nextEnd, dailySchedule, draft.start_time, draft.end_time)); }} required /></Field>
                    <Field label="วันที่สิ้นสุด" hint="กำหนดต่อเนื่องได้สูงสุด 31 วัน" required><Input type="date" min={draft.event_date} max={maxEndDate} value={draft.end_date} onChange={(_, data) => { setDraft({ ...draft, end_date: data.value }); setDailySchedule(scheduleForRange(draft.event_date, data.value, dailySchedule, draft.start_time, draft.end_time)); }} required /></Field>
                    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                        <div><h3 className="text-sm font-black text-slate-900">เวลาเริ่มและสิ้นสุดในแต่ละวัน</h3><p className="mt-1 text-xs leading-5 text-slate-500">ปรับเวลาแยกกันได้ทุกวันที่อยู่ในช่วงกิจกรรม</p></div>
                        <div className="mt-4 space-y-3">{dailySchedule.map((day, index) => <div key={day.date} className="grid gap-3 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-[minmax(150px,1fr)_140px_140px] sm:items-end"><div><span className="text-xs font-bold text-slate-500">วันที่</span><strong className="mt-1 block text-sm text-slate-900">{formatScheduleDate(day.date)}</strong></div><Field label="เวลาเริ่ม" required><Input type="time" value={day.start_time} onChange={(_, data) => setDailySchedule((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, start_time: data.value } : item))} required /></Field><Field label="เวลาสิ้นสุด" required><Input type="time" value={day.end_time} onChange={(_, data) => setDailySchedule((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, end_time: data.value } : item))} required /></Field></div>)}</div>
                    </div>
                    <Field label="สถานที่"><Input value={draft.location} onChange={(_, data) => setDraft({ ...draft, location: data.value })} maxLength={255} /></Field>
                    <Field label="กลุ่มเป้าหมาย" hint="เลือกทุกกลุ่มเพื่อให้นักศึกษาทั้งอำเภอมองเห็น"><Select value={draft.target_group} onChange={(_, data) => setDraft({ ...draft, target_group: data.value })}><option value="">ทุกกลุ่มเรียนในอำเภอ</option>{availableGroups.map((group) => <option key={group.code} value={group.code}>{group.label} · รหัส {group.code}</option>)}</Select></Field>
                    <Field label="ลิงก์กิจกรรม" hint="เช่น แบบลงทะเบียน เอกสาร หรือห้องประชุมออนไลน์" className="sm:col-span-2"><Input type="url" value={draft.external_url} onChange={(_, data) => setDraft({ ...draft, external_url: data.value })} placeholder="https://example.com" maxLength={2000} /></Field>
                    <label className="sm:col-span-2"><span className="mb-1.5 block text-sm font-bold text-slate-700">รายละเอียดกิจกรรม</span><textarea value={draft.notes} onChange={(changeEvent) => setDraft({ ...draft, notes: changeEvent.target.value })} rows={4} maxLength={5000} className="w-full rounded-xl border border-slate-300 p-3 outline-none focus:border-brand-500" /></label>
                    {canFeature && <label className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:col-span-2"><input type="checkbox" checked={draft.featured_on_dashboard} onChange={(changeEvent) => setDraft({ ...draft, featured_on_dashboard: changeEvent.target.checked })} className="mt-1 size-4 accent-amber-600" /><span><strong className="flex items-center gap-2 text-sm text-amber-950"><Star size={18} weight="fill" />แสดงกิจกรรมนี้บนหน้าแรกนักศึกษา</strong><span className="mt-1 block text-xs leading-5 text-amber-800">เมื่อเลือก รายการนี้จะแทนกิจกรรมที่เคยเลือกไว้ก่อนหน้าในอำเภอนี้ ถ้าไม่มีรูป หน้าแรกจะแสดงชื่อกิจกรรม</span></span></label>}
                    <div className="sm:col-span-2">
                        <span className="mb-1.5 block text-sm font-bold text-slate-700">รูปภาพกิจกรรม</span>
                        <label className="grid cursor-pointer gap-3 rounded-2xl border border-dashed border-brand-300 bg-brand-50/60 p-4 transition hover:bg-brand-50 sm:grid-cols-[180px_1fr] sm:items-center">
                            {preview || (currentImage && !removeImage) ? <img src={preview ?? withAppBasePath(currentImage ?? '')} alt="ตัวอย่างรูปกิจกรรม" className="aspect-video w-full rounded-xl object-cover" /> : <span className="grid aspect-video place-items-center rounded-xl bg-white text-brand-600"><ImageSquare size={36} weight="duotone" /></span>}
                            <span><span className="inline-flex items-center gap-2 font-black text-brand-800"><UploadSimple size={19} />เลือกรูปจากเครื่อง</span><span className="mt-1 block text-xs leading-5 text-slate-600">รองรับ JPG, PNG หรือ WebP ไม่เกิน 6 MB และขนาดอย่างน้อย 480×270 พิกเซล</span>{image && <span className="mt-2 block truncate text-xs font-bold text-emerald-700">{image.name}</span>}</span>
                            <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(changeEvent) => { setImage(changeEvent.target.files?.[0] ?? null); setRemoveImage(false); }} />
                        </label>
                        {currentImage && <label className="mt-3 inline-flex items-center gap-2 text-sm font-bold text-slate-700"><input type="checkbox" checked={removeImage} onChange={(changeEvent) => { setRemoveImage(changeEvent.target.checked); if (changeEvent.target.checked) setImage(null); }} />ลบรูปเดิม</label>}
                    </div>
                    {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm font-bold text-rose-800 sm:col-span-2">{error.message}</p>}
                    <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4 sm:col-span-2"><Button type="button" appearance="outline" onClick={onClose}>ยกเลิก</Button><Button type="submit" appearance="primary" disabled={pending}>{pending ? 'กำลังบันทึก' : 'บันทึกและเผยแพร่'}</Button></div>
                </form>
            </section>
        </div>
    );
}

export function CalendarPage() {
    const { role } = useDemoRole();
    const queryClient = useQueryClient();
    const [month, setMonth] = useState(() => new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    const [type, setType] = useState<'all' | CalendarEvent['type']>('all');
    const [selected, setSelected] = useState<CalendarEvent | null>(null);
    const [editor, setEditor] = useState<CalendarEvent | 'new' | null>(null);
    const query = useQuery({
        queryKey: ['learning', 'calendar'],
        queryFn: ({ signal }) => getFeatureDataWithDemo<CalendarEvent[]>('/api/v1/learning/calendar', [], signal),
    });
    const events = useMemo(() => [...(query.data?.data ?? [])].sort((left, right) => parseEventDate(left.starts_at).getTime() - parseEventDate(right.starts_at).getTime()), [query.data?.data]);
    const availableGroups = (query.data?.meta.available_groups as AvailableGroup[] | undefined) ?? [];
    const canManage = query.data !== undefined && role !== 'student' && query.data.meta.read_only !== true;
    const canFeature = canManage && (role === 'admin' || role === 'super_admin');
    const monthEvents = useMemo(() => events.filter((event) => {
        return eventOverlapsMonth(event, month) && (type === 'all' || event.type === type);
    }), [events, month, type]);
    const eventsByDate = useMemo(() => {
        const map = new Map<string, CalendarEvent[]>();
        for (const event of monthEvents) {
            for (const key of eventDateKeys(event, month)) {
                map.set(key, [...(map.get(key) ?? []), event]);
            }
        }
        return map;
    }, [monthEvents, month]);
    const cells = useMemo(() => {
        const first = new Date(month.getFullYear(), month.getMonth(), 1);
        return Array.from({ length: 42 }, (_, index) => {
            const date = new Date(first.getFullYear(), first.getMonth(), 1 - first.getDay() + index);
            return { date, current: date.getMonth() === month.getMonth() };
        });
    }, [month]);
    const monthLabel = new Intl.DateTimeFormat('th-TH', { month: 'long', year: 'numeric' }).format(month);

    const refreshCalendar = () => {
        void queryClient.invalidateQueries({ queryKey: ['learning', 'calendar'] });
        void queryClient.invalidateQueries({ queryKey: ['learning', 'overview'] });
        void queryClient.invalidateQueries({ queryKey: ['dashboard', 'calendar'] });
    };
    const save = useMutation({
        mutationFn: (form: FormData) => {
            if (editor === 'new') return sendFeatureData('/api/v1/learning/calendar', 'POST', form);
            const contentId = editor && calendarContentId(editor);
            if (!contentId) throw new Error('ไม่พบรหัสกิจกรรมที่ต้องการแก้ไข');
            form.append('_method', 'PATCH');
            return sendFeatureData(`/api/v1/learning/calendar/${contentId}`, 'POST', form);
        },
        onSuccess: () => { setEditor(null); setSelected(null); refreshCalendar(); },
    });
    const remove = useMutation({
        mutationFn: (event: CalendarEvent) => {
            const contentId = calendarContentId(event);
            if (!contentId) throw new Error('ไม่พบรหัสกิจกรรมที่ต้องการลบ');
            return sendFeatureData(`/api/v1/learning/calendar/${contentId}`, 'DELETE');
        },
        onSuccess: () => { setSelected(null); refreshCalendar(); },
    });
    const deleteSelected = () => {
        if (!selected || !window.confirm(`ยืนยันลบกิจกรรม “${selected.title}” และรูปภาพที่เกี่ยวข้อง?`)) return;
        remove.mutate(selected);
    };

    return (
        <div>
            <PageHeader category="learning" title="ปฏิทินกิจกรรมและพบกลุ่ม" description="ดูวันนัดหมาย รายละเอียด สถานที่ และภาพกิจกรรมที่เผยแพร่สำหรับกลุ่มของคุณ" icon={CalendarBlank} actions={canManage ? <Button appearance="primary" icon={<Plus size={18} weight="bold" />} onClick={() => { save.reset(); setEditor('new'); }}>เพิ่มกิจกรรม</Button> : undefined} />
            <Panel
                title={monthLabel}
                description={`มีกำหนดการ ${monthEvents.length} รายการในเดือนนี้`}
                action={<div className="flex flex-wrap items-center gap-2"><Select aria-label="กรองประเภทกิจกรรม" value={type} onChange={(_, data) => setType(data.value as typeof type)}><option value="all">ทุกประเภท</option><option value="activity">กิจกรรม</option><option value="meeting">พบกลุ่ม</option><option value="exam">สอบ</option><option value="assignment">งาน</option></Select><Button appearance="outline" icon={<CaretLeft size={17} />} aria-label="เดือนก่อนหน้า" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))} /><Button appearance="outline" onClick={() => setMonth(new Date(new Date().getFullYear(), new Date().getMonth(), 1))}>เดือนนี้</Button><Button appearance="outline" icon={<CaretRight size={17} />} aria-label="เดือนถัดไป" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))} /></div>}
            >
                {query.isPending && <QuerySkeleton rows={6} />}
                {query.isError && <QueryError onRetry={() => query.refetch()} />}
                {query.data && <>
                    <div className="hidden overflow-hidden rounded-2xl border border-slate-200 md:block">
                        <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50">{weekdays.map((weekday) => <div key={weekday} className="px-2 py-3 text-center text-xs font-black text-slate-500">{weekday}</div>)}</div>
                        <div className="grid grid-cols-7">
                            {cells.map((cell) => {
                                const key = dateKey(cell.date);
                                const dayEvents = eventsByDate.get(key) ?? [];
                                const today = key === dateKey(new Date());
                                return <div key={key} className={`min-h-32 border-b border-r border-slate-100 p-2 ${cell.current ? 'bg-white' : 'bg-slate-50/70'}`}><div className={`grid size-7 place-items-center rounded-full text-xs font-black ${today ? 'bg-brand-700 text-white' : cell.current ? 'text-slate-700' : 'text-slate-400'}`}>{cell.date.getDate()}</div><div className="mt-1.5 space-y-1">{dayEvents.slice(0, 3).map((event) => <button key={event.id} type="button" onClick={() => setSelected(event)} className={`block w-full truncate rounded-lg border px-2 py-1 text-left text-[11px] font-black ${eventTypeClasses[event.type]}`}>{eventTimeForDate(event, key) && <span className="mr-1 opacity-75">{eventTimeForDate(event, key)}</span>}{event.title}</button>)}{dayEvents.length > 3 && <span className="block px-1 text-[10px] font-bold text-slate-500">อีก {dayEvents.length - 3} รายการ</span>}</div></div>;
                            })}
                        </div>
                    </div>
                    <div className="space-y-3 md:hidden">
                        {monthEvents.map((event) => <CalendarEventCard key={event.id} event={event} onSelect={setSelected} />)}
                        {monthEvents.length === 0 && <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-12 text-center text-sm font-bold text-slate-500">ยังไม่มีกิจกรรมในเดือนนี้</div>}
                    </div>
                    <div className="mt-4 flex flex-wrap gap-3 text-xs font-bold text-slate-600">{(['activity', 'meeting', 'exam', 'assignment'] as const).map((eventType) => <span key={eventType} className={`rounded-full border px-2.5 py-1 ${eventTypeClasses[eventType]}`}>{eventTypeLabels[eventType]}</span>)}</div>
                </>}
            </Panel>
            {selected && <CalendarDetail event={selected} canManage={canManage} onClose={() => setSelected(null)} onEdit={() => { save.reset(); setEditor(selected); }} onDelete={deleteSelected} />}
            {editor && <CalendarEditor event={editor} pending={save.isPending} error={save.error} availableGroups={availableGroups} canFeature={canFeature} onClose={() => setEditor(null)} onSubmit={(form) => save.mutate(form)} />}
        </div>
    );
}
