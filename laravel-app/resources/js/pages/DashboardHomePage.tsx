import { useQuery } from '@tanstack/react-query';
import {
    ArrowRight,
    Bell,
    BookOpenText,
    Books,
    CalendarBlank,
    CaretRight,
    ChartBar,
    Clock,
    Database,
    GraduationCap,
    Heart,
    MapPin,
    Sparkle,
    Student,
    UsersThree,
    WarningCircle,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useDemoRole, type DemoRole } from '../context/DemoRoleContext';
import { apiGet } from '../lib/api';
import { publicAssetUrl, publicBrandingPath, type PublicBranding } from '../lib/publicBranding';
import { withAppBasePath } from '../lib/urls';
import { queryKeys } from '../query';
import type { AnalyticsDatum, PortalData } from '../types';

const roleContent: Record<DemoRole, { title: string; subtitle: string }> = {
    student: {
        title: 'ภาพรวมการเรียนของคุณ',
        subtitle: 'ติดตามผลการเรียน กพช. คุณธรรม และตารางสอบจากข้อมูลล่าสุด',
    },
    teacher: {
        title: 'ภาพรวมกลุ่มที่ดูแล',
        subtitle: 'สรุปจำนวนนักศึกษา กลุ่มเรียน และข้อมูลสำคัญตามขอบเขตที่รับผิดชอบ',
    },
    admin: {
        title: 'ภาพรวมระบบประจำอำเภอ',
        subtitle: 'ดูจำนวนนักศึกษา โครงสร้างระดับชั้น กลุ่มเรียน และกิจกรรมล่าสุด',
    },
    super_admin: {
        title: 'ภาพรวมอำเภอที่เลือก',
        subtitle: 'สรุปข้อมูลสำคัญของพื้นที่ที่กำลังตรวจสอบจากข้อมูลจริงล่าสุด',
    },
};

type DashboardTone = 'blue' | 'teal' | 'rose' | 'amber' | 'violet';

type CalendarItem = {
    id: string;
    type: 'assignment' | 'meeting' | 'exam' | 'activity';
    title: string;
    starts_at: string;
    ends_at: string;
    location: string;
    subject_code: string | null;
    image_url?: string | null;
    featured_on_dashboard?: boolean;
};

type SummaryCardSpec = {
    label: string;
    value: string;
    detail: string;
    icon: Icon;
    tone: DashboardTone;
    breakdown?: AnalyticsDatum[];
};

type StudentProfile = {
    name: string;
    code: string;
    level: string;
    group: string;
    advisor: string;
    currentTerm: string;
    nextMeeting: string;
};

type StudentMetricSpec = {
    label: string;
    eyebrow: string;
    value: string;
    suffix?: string;
    detail: string;
    route: string;
    action: string;
    icon: Icon;
    tone: 'blue' | 'rose' | 'amber';
};

const quickMenu: Array<{ label: string; description: string; route: string; icon: Icon; tone: DashboardTone }> = [
    { label: 'ตารางสอบ', description: 'วัน เวลา และห้องสอบ', route: '/learning/schedule', icon: Clock, tone: 'amber' },
    { label: 'วิชาที่ลงทะเบียน', description: 'รายวิชาตามภาคเรียน', route: '/reports/registered-subjects', icon: Books, tone: 'violet' },
];

const formatNumber = (value: number | null, digits = 0) => value === null
    ? '-'
    : new Intl.NumberFormat('th-TH', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);

const formatEventDate = (value: string) => {
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value || '-';

    return new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short', year: '2-digit' }).format(date);
};

const shortLevelLabel = (label: string) => ({
    'ประถมศึกษา': 'ประถม',
    'มัธยมศึกษาตอนต้น': 'ม.ต้น',
    'มัธยมศึกษาตอนปลาย': 'ม.ปลาย',
}[label] ?? label);

function DashboardSkeleton() {
    return (
        <div className="animate-pulse space-y-5" aria-label="กำลังโหลดข้อมูล">
            <div className="h-72 rounded-[18px] bg-slate-200" />
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {[1, 2, 3, 4].map((item) => <div key={item} className="h-48 rounded-[18px] bg-slate-200" />)}
            </div>
            <div className="grid gap-5 md:grid-cols-2">
                {[1, 2].map((item) => <div key={item} className="h-72 rounded-[18px] bg-slate-200" />)}
            </div>
        </div>
    );
}

const calendarWeekdays = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

const eventTimestamp = (item: CalendarItem) => {
    const value = new Date(item.starts_at.replace(' ', 'T')).getTime();
    return Number.isNaN(value) ? 0 : value;
};

const eventDaysForMonth = (item: CalendarItem, year: number, month: number): number[] => {
    const start = new Date(item.starts_at.replace(' ', 'T'));
    const parsedEnd = new Date((item.ends_at || item.starts_at).replace(' ', 'T'));
    if (Number.isNaN(start.getTime())) return [];
    const end = Number.isNaN(parsedEnd.getTime()) || parsedEnd < start ? start : parsedEnd;
    const cursor = new Date(start.getFullYear(), start.getMonth(), start.getDate());
    const finalDay = new Date(end.getFullYear(), end.getMonth(), end.getDate());
    const days: number[] = [];
    for (let index = 0; cursor <= finalDay && index < 370; index += 1) {
        if (cursor.getFullYear() === year && cursor.getMonth() === month) days.push(cursor.getDate());
        cursor.setDate(cursor.getDate() + 1);
    }

    return days;
};

function StudentCalendar({ events }: { events: CalendarItem[] }) {
    const today = new Date();
    const year = today.getFullYear();
    const month = today.getMonth();
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const previousMonthDays = new Date(year, month, 0).getDate();
    const monthLabel = new Intl.DateTimeFormat('th-TH', { month: 'long', year: 'numeric' }).format(today);
    const eventDays = new Set(events.flatMap((item) => eventDaysForMonth(item, year, month)));
    const cells = Array.from({ length: 42 }, (_, index) => {
        const dayOffset = index - firstDay + 1;
        if (dayOffset < 1) return { day: previousMonthDays + dayOffset, current: false };
        if (dayOffset > daysInMonth) return { day: dayOffset - daysInMonth, current: false };
        return { day: dayOffset, current: true };
    });

    return (
        <section className="student-calendar" aria-labelledby="student-calendar-title">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-xs font-bold text-brand-700">ปฏิทินของฉัน</p>
                    <h2 id="student-calendar-title" className="mt-1 text-lg font-black text-slate-950">{monthLabel}</h2>
                </div>
                <Link to="/learning/calendar" className="student-text-link" aria-label="ดูปฏิทินทั้งหมด">
                    ดูทั้งหมด <CaretRight size={15} weight="bold" aria-hidden="true" />
                </Link>
            </div>
            <div className="student-calendar__grid mt-5" aria-label={`ปฏิทินเดือน${monthLabel}`}>
                {calendarWeekdays.map((weekday) => <span key={weekday} className="student-calendar__weekday">{weekday}</span>)}
                {cells.map((cell, index) => {
                    const isToday = cell.current && cell.day === today.getDate();
                    const hasEvent = cell.current && eventDays.has(cell.day);
                    return (
                        <span
                            key={`${cell.current ? 'current' : 'adjacent'}-${cell.day}-${index}`}
                            className={`student-calendar__day${cell.current ? '' : ' student-calendar__day--muted'}${isToday ? ' student-calendar__day--today' : ''}${hasEvent ? ' student-calendar__day--event' : ''}`}
                            aria-current={isToday ? 'date' : undefined}
                        >
                            {cell.day}
                        </span>
                    );
                })}
            </div>
            <div className="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-[11px] font-semibold text-slate-500">
                <span className="inline-flex items-center gap-1.5"><i className="size-2 rounded-full bg-brand-600" aria-hidden="true" />วันนี้</span>
                <span className="inline-flex items-center gap-1.5"><i className="size-2 rounded-full bg-emerald-500" aria-hidden="true" />มีกำหนดการ</span>
            </div>
        </section>
    );
}

const calendarTypeLabel: Record<CalendarItem['type'], string> = {
    assignment: 'งาน',
    activity: 'กิจกรรม',
    meeting: 'พบกลุ่ม',
    exam: 'สอบ',
};

function StudentFeaturedActivity({ item, loading }: { item?: CalendarItem; loading: boolean }) {
    const imageUrl = item?.image_url ? withAppBasePath(item.image_url) : null;

    return (
        <section className="student-feature" aria-labelledby="student-feature-title">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-xs font-bold text-brand-700">อัปเดตล่าสุด</p>
                    <h2 id="student-feature-title" className="mt-1 text-lg font-black text-slate-950">กิจกรรมล่าสุด</h2>
                </div>
                <Link to="/learning/calendar" className="student-text-link">
                    ดูทั้งหมด <CaretRight size={15} weight="bold" aria-hidden="true" />
                </Link>
            </div>
            {loading ? (
                <div className="mt-5 aspect-[16/9] animate-pulse rounded-[16px] bg-slate-100" />
            ) : item && imageUrl ? (
                <Link to="/learning/calendar" className="student-feature__media group mt-5">
                    <img src={imageUrl} alt={`ภาพประกอบ ${item.title}`} className="size-full object-cover" />
                    <span className="student-feature__scrim" aria-hidden="true" />
                    <span className="student-feature__caption">
                        <strong className="block text-base font-black leading-6 text-white sm:text-lg">{item.title}</strong>
                        <span className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-semibold text-white/85">
                            <time dateTime={item.starts_at}>{formatEventDate(item.starts_at)}</time>
                            {item.location && <span>{item.location}</span>}
                        </span>
                    </span>
                </Link>
            ) : item ? (
                <Link to="/learning/calendar" className="student-feature__empty mt-5">
                    {item.type === 'exam'
                        ? <Bell size={30} weight="duotone" aria-hidden="true" />
                        : <CalendarBlank size={30} weight="duotone" aria-hidden="true" />}
                    <span>{calendarTypeLabel[item.type]}</span>
                    <strong>{item.title}</strong>
                    <span>
                        {formatEventDate(item.starts_at)}
                        {item.location ? ` · ${item.location}` : ''}
                    </span>
                </Link>
            ) : (
                <div className="student-feature__empty mt-5">
                    <CalendarBlank size={30} weight="duotone" aria-hidden="true" />
                    <strong>ยังไม่มีกิจกรรมล่าสุด</strong>
                    <span>เมื่อครูเพิ่มกิจกรรม รายการล่าสุดจะแสดงที่นี่</span>
                </div>
            )}
        </section>
    );
}

function StudentTermPanel({ term, profile, viewedAt }: { term: string | null; profile?: StudentProfile; viewedAt: string }) {
    return (
        <aside className="student-term-panel" aria-labelledby="student-term-title">
            <div className="flex items-center justify-between gap-4">
                <h2 id="student-term-title" className="text-lg font-black text-brand-900">ข้อมูลภาคเรียน</h2>
                <span className="grid size-12 place-items-center rounded-2xl border border-brand-200 bg-brand-50 text-brand-700">
                    <GraduationCap size={25} weight="duotone" aria-hidden="true" />
                </span>
            </div>
            <dl className="student-term-panel__list mt-5">
                <div>
                    <dt>ภาคเรียน</dt>
                    <dd>{term ?? profile?.currentTerm ?? '-'}</dd>
                </div>
                <div>
                    <dt>กลุ่มเรียน</dt>
                    <dd>{profile?.group || '-'}</dd>
                </div>
                <div>
                    <dt>ระดับการศึกษา</dt>
                    <dd>{profile?.level || '-'}</dd>
                </div>
                <div>
                    <dt>เปิดดูข้อมูลเมื่อ</dt>
                    <dd className="student-term-panel__date">{viewedAt}</dd>
                </div>
            </dl>
            <Link to="/my-learning" className="student-term-panel__action">ดูข้อมูลของฉัน <ArrowRight size={17} weight="bold" aria-hidden="true" /></Link>
        </aside>
    );
}

function StudentMetricCard({ card }: { card: StudentMetricSpec }) {
    const MetricIcon = card.icon;

    return (
        <article className={`student-metric-card student-metric-card--${card.tone}`}>
            <div className="flex items-start gap-4">
                <span className="student-metric-card__icon"><MetricIcon size={27} weight="duotone" aria-hidden="true" /></span>
                <div className="min-w-0">
                    <p className="text-xs font-bold text-slate-500">{card.eyebrow}</p>
                    <h2 className="mt-1 text-lg font-black text-slate-900">{card.label}</h2>
                </div>
            </div>
            <p className="student-metric-card__value mt-7">
                {card.value}{card.suffix && <span>{card.suffix}</span>}
            </p>
            <p className="mt-3 text-xs font-semibold leading-5 text-slate-500">{card.detail}</p>
            <Link to={card.route} className="student-metric-card__action">
                {card.action} <ArrowRight size={16} weight="bold" aria-hidden="true" />
            </Link>
        </article>
    );
}

function StudentDashboard({
    portal,
    profile,
    events,
    calendarPending,
}: {
    portal: PortalData;
    profile?: StudentProfile;
    events: CalendarItem[];
    calendarPending: boolean;
}) {
    const analytics = portal.analytics;
    const calendarEvents = events
        .filter((item) => item.type !== 'assignment')
        .sort((left, right) => eventTimestamp(right) - eventTimestamp(left));
    const latestActivity = calendarEvents.find((item) => item.featured_on_dashboard) ?? calendarEvents[0];
    const moralResult = analytics.moral.find((item) => item.value > 0)?.label ?? 'ยังไม่มีผล';
    const viewedAt = new Intl.DateTimeFormat('th-TH', {
        day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date());
    const metrics: StudentMetricSpec[] = [
        {
            label: 'ผลการเรียน', eyebrow: 'คะแนนเฉลี่ยสะสม (GPA)', value: formatNumber(analytics.averages.gpax, 2),
            detail: analytics.current_term ? `ข้อมูลล่าสุดภาคเรียน ${analytics.current_term}` : 'คำนวณจากผลการเรียนล่าสุด',
            route: '/grades', action: 'ดูรายละเอียดผลการเรียน', icon: ChartBar, tone: 'blue',
        },
        {
            label: 'กพช.', eyebrow: 'กิจกรรมพัฒนาคุณภาพชีวิต', value: formatNumber(analytics.averages.kpch_hours, 1), suffix: ' ชั่วโมง',
            detail: 'ชั่วโมงสะสมจากข้อมูลกิจกรรมของคุณ', route: '/kpch', action: 'ดูรายละเอียด กพช.', icon: Clock, tone: 'rose',
        },
        {
            label: 'คุณธรรม', eyebrow: 'ผลการประเมินล่าสุด', value: moralResult,
            detail: 'ระดับคุณธรรมจากข้อมูลภาคเรียนล่าสุด', route: '/moral', action: 'ดูรายละเอียดคุณธรรม', icon: Heart, tone: 'amber',
        },
    ];

    return (
        <div className="space-y-6 pb-2">
            <section className="student-dashboard-shell">
                <div className="student-dashboard-shell__main">
                    <header className="student-dashboard-intro">
                        <p className="inline-flex items-center gap-2 text-sm font-black text-brand-700"><Sparkle size={18} weight="fill" aria-hidden="true" />สวัสดี {portal.viewer.name}</p>
                        <h1 className="mt-3 text-3xl font-black leading-[1.18] tracking-[-0.035em] text-slate-950 sm:text-4xl">หน้าหลักนักศึกษา</h1>
                        <p className="mt-3 max-w-[52ch] text-sm font-semibold leading-7 text-slate-600 sm:text-base">ติดตามผลการเรียน กิจกรรม และการพัฒนาตนเองให้ครบทุกด้านในที่เดียว</p>
                    </header>
                    <div className="student-dashboard-shell__content">
                        <StudentCalendar events={events} />
                        <StudentFeaturedActivity item={latestActivity} loading={calendarPending} />
                    </div>
                </div>
                <StudentTermPanel term={analytics.current_term} profile={profile} viewedAt={viewedAt} />
            </section>

            <section aria-labelledby="student-metrics-title">
                <div className="mb-4">
                    <h2 id="student-metrics-title" className="text-xl font-black tracking-[-0.02em] text-slate-950">สรุปการเรียนของคุณ</h2>
                    <p className="mt-1 text-sm text-slate-500">ข้อมูลส่วนตัวล่าสุดตามภาคเรียนและสิทธิ์บัญชีนักศึกษา</p>
                </div>
                <div className="grid gap-4 md:grid-cols-3">
                    {metrics.map((card) => <StudentMetricCard key={card.label} card={card} />)}
                </div>
            </section>
        </div>
    );
}

function SummaryCard({ card }: { card: SummaryCardSpec }) {
    const CardIcon = card.icon;

    return (
        <article className={`dashboard-summary-card dashboard-summary-card--${card.tone}`}>
            <div className="relative z-[1] flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <h2 className="text-base font-black leading-6 text-slate-800">{card.label}</h2>
                    <p className="mt-5 text-[38px] font-black leading-none tracking-[-0.055em] text-slate-950 sm:text-[42px]">{card.value}</p>
                    <p className="mt-3 text-xs font-bold leading-5 text-slate-600">{card.detail}</p>
                </div>
                <span className="dashboard-summary-card__icon">
                    <CardIcon size={28} weight="duotone" aria-hidden="true" />
                </span>
            </div>
            {card.breakdown && (
                <dl className="relative z-[1] mt-5 grid grid-cols-3 gap-2 border-t border-current/10 pt-4">
                    {card.breakdown.map((item) => (
                        <div key={item.label} className="min-w-0">
                            <dt className="truncate text-[11px] font-bold text-slate-500">{shortLevelLabel(item.label)}</dt>
                            <dd className="mt-1 text-base font-black text-slate-900">{formatNumber(item.value)}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </article>
    );
}

function DashboardPanel({ title, description, icon: PanelIcon, tone, children }: {
    title: string;
    description: string;
    icon: Icon;
    tone: DashboardTone;
    children: ReactNode;
}) {
    return (
        <section className={`dashboard-panel dashboard-panel--${tone}`}>
            <header className="flex items-start gap-3">
                <span className="dashboard-panel__icon"><PanelIcon size={21} weight="duotone" aria-hidden="true" /></span>
                <div className="min-w-0">
                    <h2 className="text-lg font-black leading-7 text-slate-950">{title}</h2>
                    <p className="mt-0.5 text-xs font-semibold leading-5 text-slate-500">{description}</p>
                </div>
            </header>
            {children}
        </section>
    );
}

function EventRows({ items, emptyText }: { items: CalendarItem[]; emptyText: string }) {
    if (items.length === 0) {
        return <p className="mt-5 rounded-[16px] bg-slate-50 px-4 py-7 text-center text-sm font-semibold text-slate-500">{emptyText}</p>;
    }

    return (
        <div className="mt-5 space-y-2.5">
            {items.map((item) => (
                <div key={item.id} className="flex items-center gap-3 rounded-[16px] bg-slate-50 px-3.5 py-3">
                    <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-white text-brand-700 shadow-sm">
                        {item.type === 'exam' || item.type === 'assignment'
                            ? <Bell size={18} weight="duotone" aria-hidden="true" />
                            : <CalendarBlank size={18} weight="duotone" aria-hidden="true" />}
                    </span>
                    <span className="min-w-0 flex-1">
                        <strong className="block truncate text-sm font-black text-slate-800">{item.title}</strong>
                        <span className="mt-0.5 block truncate text-xs text-slate-500">{item.location || item.subject_code || 'รายละเอียดในปฏิทิน'}</span>
                    </span>
                    <time dateTime={item.starts_at} className="shrink-0 text-xs font-bold text-slate-500">{formatEventDate(item.starts_at)}</time>
                </div>
            ))}
        </div>
    );
}

export function DashboardHomePage() {
    const { role } = useDemoRole();
    const content = roleContent[role];
    const districtId = window.localStorage.getItem('sena-district-id');
    const portal = useQuery({
        queryKey: [...queryKeys.portal, role, districtId],
        queryFn: ({ signal }) => apiGet<PortalData>('/api/v1/portal', signal).then((response) => response.data),
    });
    const branding = useQuery({
        queryKey: ['auth', 'branding', districtId ?? 'default'],
        queryFn: ({ signal }) => apiGet<PublicBranding>(publicBrandingPath(districtId), signal).then((response) => response.data),
        staleTime: 5 * 60_000,
    });
    const calendar = useQuery({
        queryKey: ['dashboard', 'calendar', role, districtId],
        queryFn: ({ signal }) => apiGet<CalendarItem[]>('/api/v1/learning/calendar', signal).then((response) => response.data),
        staleTime: 2 * 60_000,
    });
    const studentProfile = useQuery({
        queryKey: ['dashboard', 'student-profile', districtId],
        queryFn: ({ signal }) => apiGet<StudentProfile>('/api/v1/my-learning', signal).then((response) => response.data),
        enabled: role === 'student',
        staleTime: 5 * 60_000,
    });

    if (portal.isPending) return <DashboardSkeleton />;

    if (portal.isError) {
        return (
            <div role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-rose-950">
                <div className="flex items-start gap-3">
                    <WarningCircle size={25} weight="duotone" className="mt-0.5 shrink-0 text-rose-700" />
                    <div>
                        <h1 className="font-black">โหลดภาพรวมไม่สำเร็จ</h1>
                        <p className="mt-1 text-sm leading-6 text-rose-800">ระบบยังไม่สามารถอ่านข้อมูลล่าสุดได้ กรุณาลองใหม่อีกครั้ง</p>
                        <button onClick={() => portal.refetch()} className="mt-4 rounded-xl bg-rose-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-rose-900 active:scale-[0.98]">ลองใหม่</button>
                    </div>
                </div>
            </div>
        );
    }

    const analytics = portal.data.analytics;
    const total = analytics.totals.students;
    const events = calendar.data ?? [];
    const activityEvents = events.filter((item) => item.type === 'meeting' || item.type === 'activity').slice(0, 3);
    const noticeEvents = events.filter((item) => item.type === 'assignment' || item.type === 'exam').slice(0, 3);
    const viewedAt = new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date());
    const scopeDetail = role === 'teacher' ? 'ตามกลุ่มเรียนที่รับผิดชอบ' : role === 'student' ? 'ข้อมูลตามบัญชีของคุณ' : 'จากข้อมูลภาคเรียนล่าสุด';
    const summaryCards: SummaryCardSpec[] = [
        {
            label: 'นักศึกษาทั้งหมด',
            value: formatNumber(total),
            detail: scopeDetail,
            icon: UsersThree,
            tone: 'blue',
        },
        {
            label: 'นักศึกษาตามระดับชั้น',
            value: formatNumber(analytics.by_level.reduce((sum, item) => sum + item.value, 0)),
            detail: 'รวม ประถมศึกษา ม.ต้น และ ม.ปลาย',
            icon: GraduationCap,
            tone: 'teal',
            breakdown: analytics.by_level,
        },
        {
            label: 'นักศึกษาใหม่',
            value: formatNumber(analytics.totals.new_students),
            detail: analytics.current_term ? `เข้าเรียนในภาคเรียน ${analytics.current_term}` : 'ยังไม่พบภาคเรียนหลัก',
            icon: Student,
            tone: 'rose',
        },
        {
            label: 'กลุ่มเรียนทั้งหมด',
            value: formatNumber(analytics.totals.groups),
            detail: 'กลุ่มเรียนที่อยู่ในขอบเขตข้อมูล',
            icon: Books,
            tone: 'amber',
        },
    ];

    if (role === 'student') {
        return (
            <StudentDashboard
                portal={portal.data}
                profile={studentProfile.data}
                events={events}
                calendarPending={calendar.isPending}
            />
        );
    }

    return (
        <div className="space-y-6 pb-2">
            <section className="dashboard-hero relative overflow-hidden border border-brand-200/80 bg-brand-100">
                <img
                    src={publicAssetUrl(branding.data?.dashboardHeroImageUrl) ?? withAppBasePath('/images/dashboard-hero-sena-v2.webp')}
                    alt="ครูผู้ดูแลระบบการศึกษาในพื้นที่อำเภอเสนา"
                    fetchPriority="high"
                    className="absolute inset-0 size-full object-cover object-[67%_center] opacity-45 sm:opacity-100"
                />
                <div className="dashboard-hero-scrim absolute inset-0" aria-hidden="true" />
                <div className="relative grid min-h-[300px] gap-6 px-6 py-7 sm:px-8 lg:grid-cols-[minmax(0,1fr)_270px] lg:items-center lg:px-9">
                    <div className="max-w-[580px] self-center">
                        <p className="inline-flex items-center gap-2 text-sm font-black text-brand-700"><Sparkle size={19} weight="fill" aria-hidden="true" />สวัสดี {portal.data.viewer.name}</p>
                        <h1 className="dashboard-hero-title mt-3 max-w-[18ch] text-3xl font-black leading-[1.2] tracking-[-0.035em] sm:text-4xl lg:text-[42px]">{content.title}</h1>
                        <p className="mt-4 max-w-[48ch] text-sm font-semibold leading-7 text-slate-600 sm:text-base">{content.subtitle}</p>
                    </div>
                    <dl className="dashboard-scope-panel self-end border border-white/35 bg-[#102c55]/95 p-5 text-white lg:self-center">
                        <div className="flex items-start gap-3 pb-4"><span className="grid size-9 shrink-0 place-items-center rounded-xl bg-brand-400/20 text-brand-200"><MapPin size={19} weight="fill" /></span><div><dt className="text-xs font-bold text-sky-100/70">พื้นที่ข้อมูล</dt><dd className="mt-1 text-sm font-black">{portal.data.viewer.district}</dd></div></div>
                        <div className="flex items-start gap-3 border-t border-white/10 py-4"><span className="grid size-9 shrink-0 place-items-center rounded-xl bg-sky-400/20 text-sky-200"><BookOpenText size={19} weight="duotone" /></span><div><dt className="text-xs font-bold text-sky-100/70">ภาคเรียนหลัก</dt><dd className="mt-1 text-sm font-black">{analytics.current_term ?? '-'}</dd></div></div>
                        <div className="flex items-start gap-3 border-t border-white/10 pt-4"><span className="grid size-9 shrink-0 place-items-center rounded-xl bg-violet-400/20 text-violet-200"><CalendarBlank size={19} weight="duotone" /></span><div><dt className="text-xs font-bold text-sky-100/70">เปิดดูข้อมูลเมื่อ</dt><dd className="mt-1 text-sm font-black">{viewedAt}</dd></div></div>
                    </dl>
                </div>
            </section>

            <section aria-labelledby="dashboard-summary-title">
                <div className="mb-4">
                    <h2 id="dashboard-summary-title" className="text-xl font-black tracking-[-0.02em] text-slate-950">สรุปข้อมูลสำคัญ</h2>
                    <p className="mt-1 text-sm text-slate-500">ตัวเลขทั้งหมดคำนวณตามอำเภอ กลุ่มเรียน และสิทธิ์ของผู้ใช้งาน</p>
                </div>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {summaryCards.map((card) => <SummaryCard key={card.label} card={card} />)}
                </div>
            </section>

            {total === 0 ? (
                <section className="rounded-[24px] border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
                    <Database size={42} weight="duotone" className="mx-auto text-slate-400" />
                    <h2 className="mt-4 text-lg font-black text-slate-900">ยังไม่มีข้อมูลสำหรับสรุป</h2>
                    <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">ตรวจสอบอำเภอที่เลือก สิทธิ์กลุ่มเรียน หรือสถานะการนำเข้าข้อมูล แล้วลองเปิดหน้านี้อีกครั้ง</p>
                </section>
            ) : (
                <section aria-labelledby="dashboard-updates-title">
                    <div className="mb-4">
                        <h2 id="dashboard-updates-title" className="text-xl font-black tracking-[-0.02em] text-slate-950">ติดตามล่าสุด</h2>
                        <p className="mt-1 text-sm text-slate-500">กิจกรรม งาน และกำหนดการสำคัญในพื้นที่ของคุณ</p>
                    </div>
                    <div className="grid items-stretch gap-5 md:grid-cols-2">
                        <DashboardPanel title="กิจกรรมล่าสุด" description="กิจกรรมและวันพบกลุ่มจากปฏิทิน" icon={CalendarBlank} tone="blue">
                            {calendar.isPending
                                ? <div className="mt-5 h-44 animate-pulse rounded-[16px] bg-slate-100" />
                                : <EventRows items={activityEvents} emptyText="ยังไม่มีกิจกรรมในปฏิทิน" />}
                            <Link to="/learning/calendar" className="mt-4 inline-flex items-center gap-2 text-sm font-black text-brand-700 hover:text-brand-800">ดูปฏิทินทั้งหมด <ArrowRight size={16} /></Link>
                        </DashboardPanel>

                        <DashboardPanel title="ประกาศและแจ้งเตือน" description="งานและกำหนดสอบที่ต้องติดตาม" icon={Bell} tone="rose">
                            {calendar.isPending
                                ? <div className="mt-5 h-44 animate-pulse rounded-[16px] bg-slate-100" />
                                : <EventRows items={noticeEvents} emptyText="ยังไม่มีรายการที่ต้องติดตาม" />}
                            <Link to="/learning/assignments" className="mt-4 inline-flex items-center gap-2 text-sm font-black text-brand-700 hover:text-brand-800">ดูงานทั้งหมด <ArrowRight size={16} /></Link>
                        </DashboardPanel>
                    </div>
                </section>
            )}

            <section className="dashboard-quick-menu" aria-labelledby="quick-menu-title">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 id="quick-menu-title" className="text-lg font-black text-slate-950">เมนูลัด</h2>
                        <p className="mt-0.5 text-xs font-semibold text-slate-500">เปิดข้อมูลสำคัญได้ในคลิกเดียว</p>
                    </div>
                </div>
                <nav className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2" aria-label="เมนูลัดหน้าแรก">
                    {quickMenu.map((item) => {
                        const ItemIcon = item.icon;
                        return (
                            <Link key={item.label} to={item.route} className={`group dashboard-quick-link dashboard-quick-link--${item.tone}`}>
                                <span className="dashboard-quick-link__icon"><ItemIcon size={22} weight="duotone" aria-hidden="true" /></span>
                                <span className="min-w-0">
                                    <strong className="block text-sm font-black leading-5 text-slate-900">{item.label}</strong>
                                    <span className="mt-0.5 block truncate text-xs font-semibold text-slate-500">{item.description}</span>
                                </span>
                                <ArrowRight size={17} weight="bold" className="ml-auto shrink-0 text-slate-400 transition-transform duration-150 group-hover:translate-x-0.5" aria-hidden="true" />
                            </Link>
                        );
                    })}
                </nav>
            </section>
        </div>
    );
}
