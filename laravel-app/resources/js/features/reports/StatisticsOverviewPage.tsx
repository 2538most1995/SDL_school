import { useQuery } from '@tanstack/react-query';
import {
    ArrowRight,
    ArrowsLeftRight,
    Books,
    ChartLineUp,
    ChartPieSlice,
    CheckSquare,
    GraduationCap,
    Student,
    TrendUp,
    UsersThree,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { Link } from 'react-router-dom';
import { EmptyState, QueryError, QuerySkeleton } from '../../components/QueryState';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { StatGrid } from '../../components/StatGrid';
import { StatTile } from '../../components/StatTile';
import { apiGet } from '../../lib/api';
import { useDemoRole } from '../../context/DemoRoleContext';
import type { PortalData } from '../../types';

type ReportLink = {
    label: string;
    description: string;
    route: string;
    icon: Icon;
};

const statisticLinks: ReportLink[] = [
    { label: 'สถิตินักศึกษาลงทะเบียน', description: 'วิเคราะห์ตามกลุ่ม เพศ ระดับ อาชีพ สัญชาติ และอายุ', route: '/reports/registration-statistics', icon: ChartLineUp },
    { label: 'นักศึกษาใหม่', description: 'ตรวจจำนวนนักศึกษาใหม่ในภาคเรียนปัจจุบัน', route: '/reports/new-students', icon: Student },
    { label: 'ผู้จบหลักสูตร', description: 'ติดตามสถานะและภาคเรียนที่สำเร็จการศึกษา', route: '/reports/graduates', icon: GraduationCap },
    { label: 'นักศึกษาคาดว่าจะจบ', description: 'ประเมินผู้เรียนที่ผ่านเกณฑ์หน่วยกิตรวม', route: '/reports/expected-graduates', icon: TrendUp },
    { label: 'ข้อมูลเทียบโอน', description: 'ตรวจรายวิชาและผลการเทียบโอนของนักศึกษา', route: '/reports/transfers', icon: ArrowsLeftRight },
];

const resultLinks: ReportLink[] = [
    { label: 'วิชาลงทะเบียน', description: 'ดูรายวิชาตามภาคเรียน ระดับ และกลุ่มเรียน', route: '/reports/registered-subjects', icon: Books },
    { label: 'สถิติเกรด 2 ขึ้นไป', description: 'ประเมินผลสัมฤทธิ์ตามรายวิชาและกลุ่มเรียน', route: '/reports/grade-threshold', icon: TrendUp },
    { label: 'สถิติการเข้าสอบ', description: 'สรุปการเข้าสอบและขาดสอบตามรายวิชา', route: '/reports/exam-attendance', icon: CheckSquare },
    { label: 'นักศึกษามีสิทธิ์สอบ', description: 'ตรวจรายชื่อผู้มีสิทธิ์สอบตามภาคเรียน', route: '/reports/exam-eligible', icon: GraduationCap },
    { label: 'เช็คชื่อเข้าสอบ', description: 'บันทึกและตรวจสอบสถานะการเข้าสอบ', route: '/learning/exam-attendance-check', icon: CheckSquare },
];

const numberFormat = new Intl.NumberFormat('th-TH');
const decimalFormat = new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function ReportLinkList({ items, ariaLabel }: { items: ReportLink[]; ariaLabel: string }) {
    return (
        <nav aria-label={ariaLabel} className="grid gap-2 sm:grid-cols-2">
            {items.map((item) => {
                const ItemIcon = item.icon;

                return (
                    <Link
                        key={item.route}
                        to={item.route}
                        className="group flex min-w-0 items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 transition hover:border-brand-300 hover:bg-brand-50 active:translate-y-px"
                    >
                        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-brand-700 shadow-sm ring-1 ring-slate-200 group-hover:ring-brand-200">
                            <ItemIcon size={21} weight="duotone" aria-hidden="true" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <strong className="block text-sm font-black leading-6 text-slate-900">{item.label}</strong>
                            <span className="mt-0.5 block text-xs leading-5 text-slate-500">{item.description}</span>
                        </span>
                        <ArrowRight size={17} weight="bold" className="mt-2 shrink-0 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-brand-700" aria-hidden="true" />
                    </Link>
                );
            })}
        </nav>
    );
}

export function StatisticsOverviewPage() {
    const { role } = useDemoRole();
    const districtId = window.localStorage.getItem('sena-district-id');
    const overview = useQuery({
        queryKey: ['reports', 'overview', role, districtId],
        queryFn: ({ signal }) => apiGet<PortalData>('/api/v1/portal', signal).then((response) => response.data),
        staleTime: 2 * 60_000,
    });

    if (overview.isPending) {
        return (
            <div className="space-y-5">
                <PageHeader category="สถิติ" title="สารสนเทศรวม" description="สรุปข้อมูลสำคัญเพื่อเลือกประเด็นประเมินผลและเปิดรายงานที่เกี่ยวข้อง" icon={ChartPieSlice} />
                <QuerySkeleton rows={6} />
            </div>
        );
    }

    if (overview.isError) {
        return (
            <div className="space-y-5">
                <PageHeader category="สถิติ" title="สารสนเทศรวม" description="สรุปข้อมูลสำคัญเพื่อเลือกประเด็นประเมินผลและเปิดรายงานที่เกี่ยวข้อง" icon={ChartPieSlice} />
                <QueryError onRetry={() => overview.refetch()} />
            </div>
        );
    }

    const analytics = overview.data.analytics;
    const totalStudents = analytics.totals.students;
    const averageGpax = analytics.averages.gpax;
    const scopeDescription = role === 'teacher' ? 'เฉพาะกลุ่มเรียนที่รับผิดชอบ' : `พื้นที่ ${overview.data.viewer.district}`;

    return (
        <div className="space-y-6 pb-2">
            <PageHeader
                category="สถิติ"
                title="สารสนเทศรวม"
                description="มองภาพรวมข้อมูลนักศึกษาและเข้าสู่รายงานประเมินผลได้จากจุดเดียว"
                icon={ChartPieSlice}
            />

            <StatGrid>
                <StatTile label="นักศึกษาทั้งหมด" value={`${numberFormat.format(totalStudents)} คน`} detail={scopeDescription} icon={UsersThree} tone="sky" />
                <StatTile label="กลุ่มเรียน" value={`${numberFormat.format(analytics.totals.groups)} กลุ่ม`} detail="กลุ่มที่อยู่ในขอบเขตข้อมูล" icon={Books} tone="emerald" />
                <StatTile label="นักศึกษาใหม่" value={`${numberFormat.format(analytics.totals.new_students)} คน`} detail={analytics.current_term ? `ภาคเรียน ${analytics.current_term}` : 'ยังไม่พบภาคเรียนหลัก'} icon={Student} tone="amber" />
                <StatTile label="GPAX เฉลี่ย" value={averageGpax === null ? '-' : decimalFormat.format(averageGpax)} detail="คำนวณจากนักศึกษาที่มีผลการเรียน" icon={TrendUp} tone="rose" />
            </StatGrid>

            {totalStudents === 0 ? (
                <EmptyState title="ยังไม่มีข้อมูลสำหรับประเมินผล" description="ตรวจสอบอำเภอที่เลือก สิทธิ์กลุ่มเรียน หรือชุดข้อมูลนำเข้าปัจจุบัน แล้วลองเปิดหน้านี้อีกครั้ง" />
            ) : (
                <Panel title="โครงสร้างนักศึกษาตามระดับ" description="จำนวนและสัดส่วนคำนวณจากข้อมูลชุดเดียวกับสารสนเทศรวม">
                    <dl className="grid gap-3 md:grid-cols-3">
                        {analytics.by_level.map((item) => {
                            const percent = totalStudents > 0 ? (item.value / totalStudents) * 100 : 0;

                            return (
                                <div key={item.label} className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                    <dt className="text-sm font-bold text-slate-600">{item.label}</dt>
                                    <dd className="mt-2 flex items-end justify-between gap-3">
                                        <strong className="text-2xl font-black tracking-[-0.03em] text-slate-950">{numberFormat.format(item.value)} คน</strong>
                                        <span className="text-sm font-bold text-brand-700">{percent.toFixed(1)}%</span>
                                    </dd>
                                </div>
                            );
                        })}
                    </dl>
                </Panel>
            )}

            <Panel title="สถิติ" description="เลือกข้อมูลเชิงจำนวนและสถานะนักศึกษาเพื่อใช้วางแผนและประเมินผล">
                <ReportLinkList items={statisticLinks} ariaLabel="เมนูสถิติ" />
            </Panel>

            <Panel title="รายงานผล" description="เปิดรายงานการลงทะเบียน ผลสัมฤทธิ์ สิทธิ์สอบ และการเข้าสอบ">
                <ReportLinkList items={resultLinks} ariaLabel="เมนูรายงานผล" />
            </Panel>
        </div>
    );
}
