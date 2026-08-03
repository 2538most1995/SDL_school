import { useQuery } from '@tanstack/react-query';
import {
    ArrowRight,
    Bell,
    BookOpenText,
    Books,
    CalendarBlank,
    Clock,
    Database,
    GraduationCap,
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
import { publicBrandingPath, type PublicBranding } from '../lib/publicBranding';
import { queryKeys } from '../query';
import type { AnalyticsDatum, PortalData } from '../types';

const roleContent: Record<DemoRole, { title: string; subtitle: string }> = {
    student: {
        title: 'ภาพรวมการเรียนของคุณ',
        subtitle: 'ติดตามสถานะ ผลการเรียน กพช. คุณธรรม และตารางสอบจากข้อมูลล่าสุด',
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
};

type SummaryCardSpec = {
    label: string;
    value: string;
    detail: string;
    icon: Icon;
    tone: DashboardTone;
    breakdown?: AnalyticsDatum[];
};

const quickMenu: Array<{ label: string; description: string; route: string; studentRoute?: string; icon: Icon; tone: DashboardTone }> = [
    { label: 'ตารางสอบ', description: 'วัน เวลา และห้องสอบ', route: '/learning/schedule', icon: Clock, tone: 'amber' },
    { label: 'วิชาที่ลงทะเบียน', description: 'รายวิชาตามภาคเรียน', route: '/reports/registered-subjects', studentRoute: '/grades', icon: Books, tone: 'violet' },
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

    return (
        <div className="space-y-6 pb-2">
            <section className="dashboard-hero relative overflow-hidden border border-brand-200/80 bg-brand-100">
                <img
                    src={branding.data?.dashboardHeroImageUrl ?? '/images/dashboard-hero-sena-v2.webp'}
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
                        const route = role === 'student' && item.studentRoute ? item.studentRoute : item.route;
                        return (
                            <Link key={item.label} to={route} className={`group dashboard-quick-link dashboard-quick-link--${item.tone}`}>
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
