import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ArrowSquareOut, CalendarCheck, Megaphone, X } from '@phosphor-icons/react';
import { apiGet } from '../lib/api';
import { withAppBasePath } from '../lib/urls';

type StudentAnnouncement = {
    id: number;
    title: string;
    message: string;
    button_label: string | null;
    button_url: string | null;
    image_url: string | null;
    show_exam_link: boolean;
    exam_schedule_url: string | null;
    updated_at: string | null;
};

const dismissedKey = 'sena-dismissed-announcement';

export function StudentAnnouncementModal() {
    const [open, setOpen] = useState(false);
    const announcement = useQuery({
        queryKey: ['student', 'active-announcement'],
        queryFn: ({ signal }) => apiGet<StudentAnnouncement | null>('/api/v1/student/announcements/active', signal).then((response) => response.data),
        staleTime: 60_000,
        retry: false,
    });
    const fingerprint = announcement.data ? `${announcement.data.id}:${announcement.data.updated_at ?? ''}` : null;

    useEffect(() => {
        if (!fingerprint) {
            setOpen(false);
            return;
        }

        setOpen(window.sessionStorage.getItem(dismissedKey) !== fingerprint);
    }, [fingerprint]);

    useEffect(() => {
        if (!open) return;

        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') dismiss();
        };
        window.addEventListener('keydown', closeOnEscape);

        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [open, fingerprint]);

    function dismiss() {
        if (fingerprint) window.sessionStorage.setItem(dismissedKey, fingerprint);
        setOpen(false);
    }

    if (!open || !announcement.data) return null;

    const data = announcement.data;
    const hasButtons = data.button_url || (data.show_exam_link && data.exam_schedule_url);

    return (
        <div className="fixed inset-0 z-[70] grid place-items-center overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="student-announcement-title" onMouseDown={(event) => { if (event.target === event.currentTarget) dismiss(); }}>
            <section className="relative my-auto w-full max-w-xl overflow-hidden rounded-[28px] border border-white/60 bg-white shadow-[0_30px_100px_rgb(2_6_23_/_0.34)]">
                <div className="relative overflow-hidden bg-gradient-to-br from-brand-800 via-brand-700 to-sky-600 px-6 pb-7 pt-6 text-white sm:px-8 sm:pb-8">
                    <span className="absolute -right-12 -top-12 size-44 rounded-full bg-white/10" aria-hidden="true" />
                    <span className="absolute -bottom-20 -left-16 size-52 rounded-full bg-sky-300/15" aria-hidden="true" />
                    <div className="relative flex items-start justify-between gap-4">
                        <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-white/15 shadow-[inset_0_0_0_1px_rgb(255_255_255_/_0.16)]"><Megaphone size={25} weight="duotone" /></span>
                        <button type="button" onClick={dismiss} className="grid size-9 shrink-0 place-items-center rounded-full bg-white/10 text-white transition hover:bg-white/20" aria-label="ปิดประกาศ" autoFocus><X size={18} weight="bold" /></button>
                    </div>
                    <p className="relative mt-5 text-xs font-bold tracking-wide text-brand-100">ประกาศสำหรับนักศึกษา</p>
                    <h2 id="student-announcement-title" className="relative mt-1.5 text-balance text-2xl font-black leading-tight tracking-[-0.025em] sm:text-3xl">{data.title}</h2>
                </div>
                <div className="px-6 py-6 sm:px-8 sm:py-7">
                    {/* Announcement image */}
                    {data.image_url && (
                        <img
                            src={withAppBasePath(data.image_url)}
                            alt="ภาพประกอบประกาศ"
                            className="mb-5 w-full rounded-2xl border border-slate-200 object-cover shadow-sm"
                            style={{ maxHeight: '260px' }}
                        />
                    )}

                    <p className="max-h-[38vh] overflow-y-auto whitespace-pre-line pr-1 text-[15px] leading-7 text-slate-600">{data.message}</p>

                    <div className="mt-7 flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end">
                        <button type="button" onClick={dismiss} className="inline-flex min-h-11 items-center justify-center rounded-full border border-slate-200 bg-white px-5 text-sm font-bold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">รับทราบ</button>
                        {data.show_exam_link && data.exam_schedule_url && (
                            <a
                                href={withAppBasePath(data.exam_schedule_url)}
                                target="_blank"
                                rel="noreferrer"
                                onClick={dismiss}
                                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-full border-2 border-sky-200 bg-sky-50 px-5 text-sm font-bold text-sky-800 shadow-sm transition hover:border-sky-300 hover:bg-sky-100 active:scale-[0.98]"
                            >
                                <CalendarCheck size={18} weight="bold" /> ดูตารางสอบ
                            </a>
                        )}
                        {data.button_url && (
                            <a href={data.button_url} target="_blank" rel="noreferrer" onClick={dismiss} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-brand-700 px-5 text-sm font-bold text-white shadow-lg shadow-brand-900/15 transition hover:bg-brand-800 active:scale-[0.98]">
                                {data.button_label ?? 'ดูรายละเอียด'} <ArrowSquareOut size={17} weight="bold" />
                            </a>
                        )}
                    </div>
                </div>
            </section>
        </div>
    );
}
