import {
    ArrowsLeftRight,
    Books,
    Certificate,
    ChartBar,
    CheckSquare,
    Eye,
    GraduationCap,
    MagnifyingGlass,
    Medal,
    Student,
    TrendUp,
    UserPlus,
    UsersThree,
    X,
} from '@phosphor-icons/react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { DataTable } from '../../components/DataTable';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatTile } from '../../components/StatTile';
import { StatGrid } from '../../components/StatGrid';
import { StatusBadge } from '../../components/StatusBadge';
import { useDemoRole } from '../../context/DemoRoleContext';
import { getFeatureDataWithDemo } from '../api';

export type ReportKind = 'new-students' | 'graduates' | 'expected-graduates' | 'transfers' | 'registered-subjects' | 'grade-threshold' | 'exam-attendance';

type ReportRow = {
    id: string;
    primary: string;
    secondary: string;
    group: string;
    metric: string;
    examStatus?: string;
    registeredCount?: number;
    successfulCount?: number;
    absentCount?: number;
};

type ReportPayload = {
    total: number;
    active: number;
    groups: number;
    terms?: string[];
    selected_term?: string | null;
    summary?: Record<string, unknown>;
    rows: ReportRow[];
};

type ViewMode = 'subject' | 'student';
type FilterOption = { value: string | number; label: string };
type AcademicSubjectRow = {
    id: string;
    code: string;
    name: string;
    credits: number;
    grade: string | null;
    attended: boolean;
};

const academicReportKinds: ReportKind[] = ['registered-subjects', 'grade-threshold', 'exam-attendance'];

const commonStudents: ReportRow[] = [
    { id: '1', primary: 'ณัฐชา ศรีสวัสดิ์', secondary: 'SENA-670142', group: 'ม.ปลาย กลุ่ม 1', metric: 'ภาคเรียน 1/2569' },
    { id: '2', primary: 'กิตติภพ พูลผล', secondary: 'SENA-660087', group: 'ม.ต้น กลุ่ม 2', metric: 'ภาคเรียน 1/2569' },
    { id: '3', primary: 'ธีรภัทร ภู่ทอง', secondary: 'SENA-670099', group: 'ม.ปลาย กลุ่ม 1', metric: 'ภาคเรียน 1/2569' },
];

const reportConfig: Record<ReportKind, {
    title: string;
    description: string;
    category: string;
    icon: typeof ChartBar;
    primaryLabel: string;
    secondaryLabel: string;
    groupLabel: string;
    metricLabel: string;
    endpoint: string;
    activeLabel: string;
    activeDetail: string;
    demo: ReportPayload;
}> = {
    'new-students': {
        title: 'นักศึกษาใหม่', description: 'ตรวจรายชื่อนักศึกษาใหม่ แยกตามระดับและกลุ่มเรียน', category: 'รายงานนักศึกษา', icon: UserPlus,
        primaryLabel: 'นักศึกษา', secondaryLabel: 'รหัสนักศึกษา', groupLabel: 'ระดับและกลุ่ม', metricLabel: 'ภาคเรียน',
        endpoint: '/api/v1/reports/new-students',
        activeLabel: 'นักศึกษาใหม่', activeDetail: 'จากผู้เข้าเรียนในภาคเรียน',
        demo: { total: 47, active: 45, groups: 6, rows: commonStudents },
    },
    graduates: {
        title: 'ผู้จบหลักสูตร', description: 'ตรวจข้อมูลการจบ หน่วยกิต และภาคเรียนที่สำเร็จการศึกษา', category: 'รายงานผลสำเร็จ', icon: Medal,
        primaryLabel: 'นักศึกษา', secondaryLabel: 'รหัสนักศึกษา', groupLabel: 'ระดับ', metricLabel: 'ภาคเรียนที่จบ',
        endpoint: '/api/v1/reports/graduates',
        activeLabel: 'จบหลักสูตร', activeDetail: 'ยืนยันผลจบแล้ว',
        demo: { total: 32, active: 32, groups: 3, rows: commonStudents.map((row, index) => ({ ...row, metric: index === 0 ? '2/2568' : '1/2568' })) },
    },
    'expected-graduates': {
        title: 'นักศึกษาคาดว่าจะจบ', description: 'ตรวจสอบรายชื่อนักศึกษาที่มีหน่วยกิตผ่านเกณฑ์การจบรวมวิชาที่ลงทะเบียนภาคเรียนปัจจุบัน', category: 'รายงานคาดว่าจะจบ', icon: Certificate,
        primaryLabel: 'นักศึกษา', secondaryLabel: 'รหัสนักศึกษา', groupLabel: 'ระดับและกลุ่ม', metricLabel: 'สรุปหน่วยกิต',
        endpoint: '/api/v1/reports/expected-graduates',
        activeLabel: 'คาดว่าจะจบ', activeDetail: 'ผ่านเกณฑ์บังคับและเลือก',
        demo: { total: 24, active: 24, groups: 5, rows: commonStudents.map((row, index) => ({ ...row, metric: '56/56 หน่วยกิต (บังคับ 40 / เลือก 16)', examStatus: index % 3 === 0 ? 'ยังไม่ได้สอบ' : 'สอบแล้ว' })) },
    },
    transfers: {
        title: 'ข้อมูลเทียบโอน', description: 'ดูรายวิชา หน่วยกิต และผลการเทียบโอนของนักศึกษา', category: 'รายงานวิชา', icon: ArrowsLeftRight,
        primaryLabel: 'รายวิชา', secondaryLabel: 'รหัสวิชา', groupLabel: 'นักศึกษา', metricLabel: 'หน่วยกิต',
        endpoint: '/api/v1/reports/transfers',
        activeLabel: 'อนุมัติเทียบโอน', activeDetail: 'รายการที่บันทึกในระบบเดิม',
        demo: { total: 18, active: 16, groups: 9, rows: [
            { id: '1', primary: 'ภาษาไทย', secondary: 'พท31001', group: 'ณัฐชา ศรีสวัสดิ์', metric: '5 หน่วยกิต' },
            { id: '2', primary: 'สังคมศึกษา', secondary: 'สค31001', group: 'กิตติภพ พูลผล', metric: '3 หน่วยกิต' },
            { id: '3', primary: 'วิทยาศาสตร์', secondary: 'พว31001', group: 'ธีรภัทร ภู่ทอง', metric: '5 หน่วยกิต' },
        ] },
    },
    'registered-subjects': {
        title: 'วิชาลงทะเบียน', description: 'สรุปรายวิชาที่เปิดสอนและจำนวนนักศึกษาที่ลงทะเบียน', category: 'รายงานรายวิชา', icon: Books,
        primaryLabel: 'รายวิชา', secondaryLabel: 'รหัสวิชา', groupLabel: 'ระดับ', metricLabel: 'ผู้ลงทะเบียน',
        endpoint: '/api/v1/reports/registered-subjects',
        activeLabel: 'รายวิชาเปิดสอน', activeDetail: 'รายการที่พร้อมใช้งาน',
        demo: { total: 24, active: 22, groups: 3, rows: [
            { id: '1', primary: 'ภาษาไทย', secondary: 'พท31001', group: 'มัธยมศึกษาตอนปลาย', metric: '38 คน' },
            { id: '2', primary: 'วิทยาศาสตร์', secondary: 'พว31001', group: 'มัธยมศึกษาตอนปลาย', metric: '41 คน' },
            { id: '3', primary: 'คณิตศาสตร์', secondary: 'พค21001', group: 'มัธยมศึกษาตอนต้น', metric: '35 คน' },
        ] },
    },
    'grade-threshold': {
        title: 'สถิติเกรด 2 ขึ้นไป', description: 'ติดตามผลสัมฤทธิ์แยกตามรายวิชา ระดับ และกลุ่มเรียน', category: 'รายงานผลการเรียน', icon: TrendUp,
        primaryLabel: 'รายวิชา', secondaryLabel: 'รหัสวิชา', groupLabel: 'ระดับ', metricLabel: 'ผ่านเกณฑ์',
        endpoint: '/api/v1/reports/students/grades-above-two',
        activeLabel: 'ผ่านเกณฑ์', activeDetail: 'ผลการเรียนเกรด 2 ขึ้นไป',
        demo: { total: 418, active: 356, groups: 12, rows: [
            { id: '1', primary: 'ภาษาไทย', secondary: 'พท31001', group: 'มัธยมศึกษาตอนปลาย', metric: '34 จาก 38 คน (89.5%)' },
            { id: '2', primary: 'วิทยาศาสตร์', secondary: 'พว31001', group: 'มัธยมศึกษาตอนปลาย', metric: '33 จาก 41 คน (80.5%)' },
            { id: '3', primary: 'คณิตศาสตร์', secondary: 'พค21001', group: 'มัธยมศึกษาตอนต้น', metric: '27 จาก 35 คน (77.1%)' },
        ] },
    },
    'exam-attendance': {
        title: 'สถิติการเข้าสอบ', description: 'ตรวจจำนวนผู้มีสิทธิ์ เข้าสอบ และขาดสอบตามรายวิชา', category: 'รายงานการสอบ', icon: CheckSquare,
        primaryLabel: 'รายวิชา', secondaryLabel: 'รหัสวิชา', groupLabel: 'ระดับการศึกษา', metricLabel: 'เข้าสอบ',
        endpoint: '/api/v1/reports/students/exam-attendance',
        activeLabel: 'เข้าสอบ', activeDetail: 'รายการเข้าสอบที่บันทึกแล้ว',
        demo: { total: 436, active: 409, groups: 14, rows: [
            { id: '1', primary: 'ภาษาไทย', secondary: 'พท31001', group: 'ห้องสอบ 1', metric: '36 จาก 38 คน' },
            { id: '2', primary: 'วิทยาศาสตร์', secondary: 'พว31001', group: 'ห้องสอบ 2', metric: '39 จาก 41 คน' },
            { id: '3', primary: 'คณิตศาสตร์', secondary: 'พค21001', group: 'ห้องสอบ 3', metric: '31 จาก 35 คน' },
        ] },
    },
};

function normalizeReportPayload(kind: ReportKind, payload: unknown, fallback: ReportPayload, viewMode: ViewMode = 'subject'): ReportPayload {
    if (payload && typeof payload === 'object' && 'rows' in payload) return payload as ReportPayload;
    if (!payload || typeof payload !== 'object' || !('items' in payload) || !Array.isArray((payload as { items: unknown }).items)) return fallback;
    const canonical = payload as {
        items: Array<Record<string, unknown>>;
        summary?: Record<string, unknown>;
        terms?: unknown[];
        selected_term?: unknown;
    };
    const rows: ReportRow[] = canonical.items.map((item, index) => {
        const student = item.student && typeof item.student === 'object' ? item.student as Record<string, unknown> : null;
        if (student) {
            const studentLevel = student.level && typeof student.level === 'object' ? student.level as Record<string, unknown> : {};
            const studentGroup = student.group && typeof student.group === 'object' ? student.group as Record<string, unknown> : {};
            const registered = Number(item.registered_subjects ?? 0);
            const successful = Number(kind === 'exam-attendance' ? item.attended_subjects : kind === 'grade-threshold' ? item.grade_two_or_above : registered);
            return {
                id: String(student.code ?? index),
                primary: String(student.full_name ?? 'ไม่พบชื่อนักศึกษา'),
                secondary: String(student.code ?? ''),
                group: `${String(studentLevel.label ?? '-')} · ${String(studentGroup.name ?? studentGroup.code ?? '-')}`,
                metric: kind === 'registered-subjects'
                    ? `${registered.toLocaleString('th-TH')} วิชา`
                    : `${successful.toLocaleString('th-TH')} จาก ${registered.toLocaleString('th-TH')} วิชา (${String(item.success_rate ?? 0)}%)`,
                registeredCount: registered,
                successfulCount: successful,
                absentCount: Number(item.absent_subjects ?? 0),
            };
        }
        const subject = item.subject && typeof item.subject === 'object' ? item.subject as Record<string, unknown> : {};
        const level = item.level && typeof item.level === 'object' ? item.level as Record<string, unknown> : {};
        const registered = Number(item.registered_students ?? 0);
        const successful = Number(kind === 'exam-attendance' ? item.attended_students : item.grade_two_or_above ?? 0);
        return {
            id: `${String(item.term ?? 'term')}-${String(subject.code ?? index)}`,
            primary: String(subject.name ?? 'รายวิชา'),
            secondary: String(subject.code ?? ''),
            group: String(level.label ?? item.term ?? '-'),
            metric: `${successful.toLocaleString('th-TH')} จาก ${registered.toLocaleString('th-TH')} คน (${String(kind === 'exam-attendance' ? item.attendance_rate ?? 0 : item.success_rate ?? 0)}%)`,
            registeredCount: registered,
            successfulCount: successful,
            absentCount: Number(item.absent_students ?? 0),
        };
    });
    const summary = canonical.summary ?? {};
    return {
        total: viewMode === 'student' ? Number(summary.unique_students ?? rows.length) : Number(summary.registered_records ?? rows.length),
        active: Number(kind === 'exam-attendance'
            ? summary.attended_records ?? summary.successful_records
            : kind === 'grade-threshold'
                ? summary.grade_two_or_above ?? summary.successful_records
                : summary.successful_records ?? rows.length),
        groups: viewMode === 'student' ? new Set(rows.map((row) => row.group)).size : rows.length,
        terms: (canonical.terms ?? []).filter((term): term is string => typeof term === 'string' && term !== ''),
        selected_term: typeof canonical.selected_term === 'string' ? canonical.selected_term : null,
        summary,
        rows,
    };
}

type StatCard = {
    label: string;
    value: string | number;
    detail: string;
    icon: typeof ChartBar;
    tone?: 'emerald' | 'sky' | 'amber' | 'rose';
};

function academicStatCards(kind: ReportKind, viewMode: ViewMode, payload: ReportPayload): StatCard[] {
    const summary = payload.summary ?? {};
    const count = (key: string, fallback = 0) => Number(summary[key] ?? fallback);
    const uniqueStudents = count('unique_students', viewMode === 'student' ? payload.rows.length : 0);
    const registeredRecords = count('registered_records', payload.rows.reduce((total, row) => total + (row.registeredCount ?? 0), 0));
    const subjectCount = count('subject_count', viewMode === 'subject' ? payload.rows.length : 0);
    const percent = (part: number, whole: number) => whole > 0 ? ((part / whole) * 100).toFixed(1) : '0.0';

    if (viewMode === 'student' && kind === 'exam-attendance') {
        const attended = count('students_attended');
        const noAttendance = count('students_no_attendance', Math.max(0, uniqueStudents - attended));
        const complete = count('students_complete');
        return [
            { label: 'ผู้มีสิทธิ์เข้าสอบ', value: `${uniqueStudents.toLocaleString('th-TH')} คน`, detail: 'ฐานการคำนวณ ร้อยละ 100', icon: Student, tone: 'sky' },
            { label: 'เข้าสอบ', value: `${attended.toLocaleString('th-TH')} คน`, detail: `ร้อยละ ${percent(attended, uniqueStudents)} · เข้าสอบอย่างน้อย 1 วิชา`, icon: CheckSquare },
            { label: 'ขาดสอบ', value: `${noAttendance.toLocaleString('th-TH')} คน`, detail: `ร้อยละ ${percent(noAttendance, uniqueStudents)} · ไม่เข้าสอบเลยสักวิชา`, icon: UsersThree, tone: 'rose' },
            { label: 'เข้าสอบครบทุกวิชา', value: `${complete.toLocaleString('th-TH')} คน`, detail: `ร้อยละ ${percent(complete, uniqueStudents)} · สถิติเสริม`, icon: GraduationCap, tone: 'amber' },
        ];
    }

    if (viewMode === 'student' && kind === 'grade-threshold') {
        const allPassed = count('students_grade_two_all');
        const successfulRecords = count('successful_records');
        const needsFollowUp = Math.max(0, uniqueStudents - allPassed);
        return [
            { label: 'นักศึกษาที่มีผลเรียน', value: `${uniqueStudents.toLocaleString('th-TH')} คน`, detail: 'ฐานการคำนวณ ร้อยละ 100', icon: Student, tone: 'sky' },
            { label: 'เกรด 2 ขึ้นไปทุกวิชา', value: `${allPassed.toLocaleString('th-TH')} คน`, detail: `ร้อยละ ${percent(allPassed, uniqueStudents)} · ผ่านเกณฑ์ครบทุกวิชา`, icon: GraduationCap },
            { label: 'นักศึกษาที่ต้องติดตาม', value: `${needsFollowUp.toLocaleString('th-TH')} คน`, detail: `ร้อยละ ${percent(needsFollowUp, uniqueStudents)} · มีวิชาต่ำกว่าเกณฑ์`, icon: UsersThree, tone: 'rose' },
            { label: 'รายการเกรด 2 ขึ้นไป', value: successfulRecords, detail: `ร้อยละ ${count('success_rate').toLocaleString('th-TH')} จาก ${registeredRecords.toLocaleString('th-TH')} ผลการเรียน`, icon: TrendUp, tone: 'amber' },
        ];
    }

    if (viewMode === 'student') {
        return [
            { label: 'นักศึกษาที่ลงทะเบียน', value: uniqueStudents, detail: 'นับเป็นคนในภาคเรียนนี้', icon: Student, tone: 'sky' },
            { label: 'รายการลงทะเบียน', value: registeredRecords, detail: 'จำนวนวิชารวมของนักศึกษาทุกคน', icon: Books },
            { label: 'กลุ่มเรียน', value: payload.groups, detail: 'กลุ่มที่มีผู้ลงทะเบียน', icon: UsersThree, tone: 'amber' },
            { label: 'เฉลี่ยวิชาต่อคน', value: uniqueStudents > 0 ? (registeredRecords / uniqueStudents).toFixed(1) : '0.0', detail: 'รายวิชาต่อนักศึกษา 1 คน', icon: ChartBar, tone: 'sky' },
        ];
    }

    if (kind === 'exam-attendance') {
        const attendedRecords = count('attended_records');
        const absentRecords = count('absent_records');
        return [
            { label: 'รายวิชาที่จัดสอบ', value: subjectCount, detail: 'จำนวนรายวิชาในผลลัพธ์', icon: Books, tone: 'sky' },
            { label: 'ผู้มีสิทธิ์เข้าสอบ', value: uniqueStudents, detail: 'นับนักศึกษาแต่ละคนหนึ่งครั้ง', icon: Student },
            { label: 'รายการเข้าสอบ', value: attendedRecords, detail: `ร้อยละ ${percent(attendedRecords, registeredRecords)} ของรายการสอบ`, icon: CheckSquare, tone: 'amber' },
            { label: 'รายการขาดสอบ', value: absentRecords, detail: `ร้อยละ ${percent(absentRecords, registeredRecords)} ของรายการสอบ`, icon: UsersThree, tone: 'rose' },
        ];
    }

    if (kind === 'grade-threshold') {
        return [
            { label: 'รายวิชาที่มีผลเรียน', value: subjectCount, detail: 'จำนวนรายวิชาในผลลัพธ์', icon: Books, tone: 'sky' },
            { label: 'นักศึกษาที่มีผลเรียน', value: uniqueStudents, detail: 'นับนักศึกษาแต่ละคนหนึ่งครั้ง', icon: Student },
            { label: 'ผลการเรียนทั้งหมด', value: registeredRecords, detail: 'จำนวนผลการเรียนทุกวิชา', icon: ChartBar, tone: 'amber' },
            { label: 'เกรด 2 ขึ้นไป', value: count('grade_two_or_above'), detail: `อัตราผ่าน ร้อยละ ${count('success_rate').toLocaleString('th-TH')}`, icon: TrendUp },
        ];
    }

    return [
        { label: 'รายวิชาที่เปิด', value: subjectCount, detail: 'จำนวนรายวิชาในผลลัพธ์', icon: Books, tone: 'sky' },
        { label: 'นักศึกษาที่ลงทะเบียน', value: uniqueStudents, detail: 'นับนักศึกษาแต่ละคนหนึ่งครั้ง', icon: Student },
        { label: 'รายการลงทะเบียน', value: registeredRecords, detail: 'จำนวนคนรวมในทุกรายวิชา', icon: UsersThree, tone: 'amber' },
        { label: 'เฉลี่ยวิชาต่อคน', value: uniqueStudents > 0 ? (registeredRecords / uniqueStudents).toFixed(1) : '0.0', detail: 'รายวิชาต่อนักศึกษา 1 คน', icon: ChartBar, tone: 'sky' },
    ];
}

function AcademicStudentDetailDialog({ kind, student, term, onClose }: { kind: ReportKind; student: ReportRow; term: string; onClose: () => void }) {
    const endpoint = kind === 'registered-subjects'
        ? `/api/v1/students/${encodeURIComponent(student.secondary)}/subjects?term=${encodeURIComponent(term)}`
        : `/api/v1/students/${encodeURIComponent(student.secondary)}/grades?term=${encodeURIComponent(term)}`;
    const detail = useQuery({
        queryKey: ['report-student-subjects', kind, student.secondary, term],
        queryFn: ({ signal }) => getFeatureDataWithDemo<{ items: Array<Record<string, unknown>>; summary?: Record<string, unknown> }>(endpoint, { items: [] }, signal),
    });
    const subjectRows = useMemo<AcademicSubjectRow[]>(() => (detail.data?.data.items ?? []).map((item, index) => {
        const nestedSubject = item.subject && typeof item.subject === 'object' ? item.subject as Record<string, unknown> : null;
        const code = String(nestedSubject?.code ?? item.code ?? '');
        const name = String(nestedSubject?.name ?? item.name ?? 'ไม่พบชื่อรายวิชา');
        const grade = item.grade === null || item.grade === undefined ? null : String(item.grade);
        return {
            id: `${term}-${code || index}`,
            code,
            name,
            credits: Number(nestedSubject?.credits ?? item.credits ?? 0),
            grade,
            attended: Boolean(item.exam_attended),
        };
    }), [detail.data, term]);
    const columns = useMemo<ColumnDef<AcademicSubjectRow>[]>(() => [
        { accessorKey: 'code', header: 'รหัสวิชา', size: 100, meta: { compactSize: 74 }, cell: ({ getValue }) => <span className="font-mono text-xs font-bold text-slate-600">{getValue<string>()}</span> },
        { accessorKey: 'name', header: 'รายวิชา', size: 320, meta: { compactSize: 164 }, cell: ({ getValue }) => <span className="font-bold text-slate-950">{getValue<string>()}</span> },
        { accessorKey: 'credits', header: 'หน่วยกิต', size: 92, meta: { compactSize: 58, compactTextAlign: 'center' }, cell: ({ getValue }) => Number(getValue<number>()).toFixed(1) },
        ...(kind === 'registered-subjects' ? [] : [{ accessorKey: 'grade', header: 'ผลการเรียน', size: 120, meta: { compactSize: 74, compactTextAlign: 'center' }, cell: ({ getValue }) => getValue<string | null>() ?? 'รอผล' } as ColumnDef<AcademicSubjectRow>]),
        ...(kind === 'grade-threshold' ? [{
            id: 'grade_result', header: 'ผลเกณฑ์เกรด 2', size: 170, meta: { compactSize: 92, compactTextAlign: 'center' }, cell: ({ row }: { row: { original: AcademicSubjectRow } }) => {
                const numeric = row.original.grade !== null && !Number.isNaN(Number(row.original.grade)) ? Number(row.original.grade) : null;
                const passed = numeric !== null && numeric >= 2;
                return <StatusBadge tone={passed ? 'success' : row.original.grade === null ? 'neutral' : 'warning'}>{passed ? 'เกรด 2 ขึ้นไป' : row.original.grade === null ? 'รอผล' : 'ต่ำกว่าเกณฑ์'}</StatusBadge>;
            },
        } as ColumnDef<AcademicSubjectRow>] : []),
        ...(kind === 'exam-attendance' ? [{
            id: 'attendance', header: 'การเข้าสอบ', size: 138, meta: { compactSize: 86, compactTextAlign: 'center' }, cell: ({ row }: { row: { original: AcademicSubjectRow } }) => <StatusBadge tone={row.original.attended ? 'success' : 'warning'}>{row.original.attended ? 'เข้าสอบ' : 'ขาดสอบ/ไม่มีผล'}</StatusBadge>,
        } as ColumnDef<AcademicSubjectRow>] : []),
    ], [kind]);

    useEffect(() => {
        const previousOverflow = document.body.style.overflow;
        const handleKeyDown = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', handleKeyDown);
        return () => { document.body.style.overflow = previousOverflow; window.removeEventListener('keydown', handleKeyDown); };
    }, [onClose]);

    return <div className="fixed inset-0 z-[70] grid place-items-center p-3 sm:p-5" role="presentation">
        <button type="button" className="absolute inset-0 bg-slate-950/55" onClick={onClose} aria-label="ปิดรายละเอียดรายวิชา" />
        <section role="dialog" aria-modal="true" aria-labelledby="student-subject-detail-title" className="relative max-h-[calc(100dvh-2rem)] w-full max-w-5xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-950/20">
            <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-100 bg-white px-5 py-4 sm:px-6">
                <div className="min-w-0"><h2 id="student-subject-detail-title" className="text-xl font-black text-slate-950">รายวิชาของ {student.primary}</h2><p className="mt-1 text-sm leading-6 text-slate-600">รหัสนักศึกษา {student.secondary} · ภาคเรียน {term}<br />{student.group}</p></div>
                <button type="button" onClick={onClose} autoFocus className="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-100" aria-label="ปิดรายละเอียด"><X size={20} weight="bold" /></button>
            </header>
            <div className="p-4 sm:p-6">
                {detail.isPending && <QuerySkeleton rows={6} />}
                {detail.isError && <QueryError onRetry={() => detail.refetch()} />}
                {detail.data && <><div className="mb-4 inline-flex items-center gap-2 rounded-xl bg-brand-50 px-4 py-2 text-sm font-bold text-brand-900"><Books size={18} /> พบ {subjectRows.length.toLocaleString('th-TH')} รายวิชา</div><DataTable data={subjectRows} columns={columns} minWidth="wide" emptyTitle="ไม่พบรายวิชา" emptyDescription="นักศึกษาคนนี้ไม่มีรายวิชาในภาคเรียนที่เลือก" /></>}
            </div>
        </section>
    </div>;
}

function AcademicSubjectStudentsDialog({ kind, subject, term, level, group, endpoint, onClose }: {
    kind: ReportKind;
    subject: ReportRow;
    term: string;
    level: string;
    group: string;
    endpoint: string;
    onClose: () => void;
}) {
    const params = new URLSearchParams({ term, view: 'student', subject: subject.secondary });
    if (level) params.set('level', level);
    if (group) params.set('group', group);
    const students = useQuery({
        queryKey: ['report-subject-students', kind, subject.secondary, term, level, group],
        queryFn: async ({ signal }) => {
            const response = await getFeatureDataWithDemo<unknown>(`${endpoint}?${params.toString()}`, { items: [], summary: {} }, signal);
            return normalizeReportPayload(kind, response.data, { total: 0, active: 0, groups: 0, rows: [] }, 'student');
        },
    });
    const columns = useMemo<ColumnDef<ReportRow>[]>(() => [
        { accessorKey: 'primary', header: 'นักศึกษา', size: 255, meta: { compactSize: 138 }, cell: ({ row }) => <div><p className="font-bold text-slate-950">{row.original.primary}</p><p className="mt-0.5 font-mono text-xs text-slate-500">{row.original.secondary}</p></div> },
        { accessorKey: 'group', header: 'ระดับ / กลุ่มเรียน', size: 235, meta: { compactSize: 122 } },
        { accessorKey: 'metric', header: kind === 'registered-subjects' ? 'การลงทะเบียน' : kind === 'grade-threshold' ? 'ผลตามเกณฑ์' : 'การเข้าสอบ', size: 170, meta: { compactSize: 88, compactTextAlign: 'center' } },
    ], [kind]);

    useEffect(() => {
        const previousOverflow = document.body.style.overflow;
        const handleKeyDown = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', handleKeyDown);
        return () => { document.body.style.overflow = previousOverflow; window.removeEventListener('keydown', handleKeyDown); };
    }, [onClose]);

    return <div className="fixed inset-0 z-[70] grid place-items-center p-3 sm:p-5" role="presentation">
        <button type="button" className="absolute inset-0 bg-slate-950/55" onClick={onClose} aria-label="ปิดรายชื่อนักศึกษา" />
        <section role="dialog" aria-modal="true" aria-labelledby="subject-students-title" className="relative max-h-[calc(100dvh-2rem)] w-full max-w-5xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-950/20">
            <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-100 bg-white px-5 py-4 sm:px-6">
                <div className="min-w-0"><p className="text-xs font-bold uppercase tracking-wide text-sky-700">รายชื่อนักศึกษาที่ลงทะเบียน</p><h2 id="subject-students-title" className="mt-1 text-xl font-black text-slate-950">{subject.primary}</h2><p className="mt-1 text-sm leading-6 text-slate-600">รหัสวิชา {subject.secondary} · ภาคเรียน {term}{level ? ` · ระดับ ${level}` : ''}</p></div>
                <button type="button" onClick={onClose} autoFocus className="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-100" aria-label="ปิดรายชื่อ"><X size={20} weight="bold" /></button>
            </header>
            <div className="p-4 sm:p-6">
                {students.isPending && <QuerySkeleton rows={7} />}
                {students.isError && <QueryError onRetry={() => students.refetch()} />}
                {students.data && <><div className="mb-4 inline-flex items-center gap-2 rounded-xl bg-sky-50 px-4 py-2 text-sm font-bold text-sky-900"><UsersThree size={18} /> พบ {students.data.total.toLocaleString('th-TH')} คน</div><DataTable data={students.data.rows} columns={columns} minWidth="wide" emptyTitle="ไม่พบนักศึกษาที่ลงทะเบียน" emptyDescription="ไม่พบผู้ลงทะเบียนตามภาคเรียน ระดับ และกลุ่มเรียนที่เลือก" /></>}
            </div>
        </section>
    </div>;
}

export function ReportPage({ kind }: { kind: ReportKind }) {
    const { role } = useDemoRole();
    const config = reportConfig[kind];
    const [term, setTerm] = useState('');
    const [search, setSearch] = useState('');
    const [level, setLevel] = useState('');
    const [group, setGroup] = useState('');
    const [examStatus, setExamStatus] = useState('');
    const [viewMode, setViewMode] = useState<ViewMode>('subject');
    const [selectedStudent, setSelectedStudent] = useState<ReportRow | null>(null);
    const [selectedSubject, setSelectedSubject] = useState<ReportRow | null>(null);
    const deferredSearch = useDeferredValue(search);
    const isAcademicReport = academicReportKinds.includes(kind);
    const canFilterGroups = role === 'teacher' || role === 'admin' || role === 'super_admin';
    useEffect(() => { if (!canFilterGroups && group !== '') setGroup(''); }, [canFilterGroups, group]);
    const directoryOptions = useQuery({
        queryKey: ['report-filter-options', kind],
        queryFn: ({ signal }) => getFeatureDataWithDemo<unknown[]>('/api/v1/students?per_page=1', [], signal),
        enabled: canFilterGroups,
        staleTime: 60_000,
    });
    const filterOptions = useMemo(() => {
        const meta = directoryOptions.data?.meta as unknown as { filter_options?: { groups?: FilterOption[] } } | undefined;
        return { groups: meta?.filter_options?.groups ?? [] };
    }, [directoryOptions.data]);
    const report = useQuery({
        queryKey: ['report', kind, term, deferredSearch, level, canFilterGroups ? group : '', viewMode, examStatus],
        queryFn: async ({ signal }) => {
            const params = new URLSearchParams({ search: deferredSearch });
            if (term) params.set('term', term);
            if (isAcademicReport) {
                params.set('view', viewMode);
            }
            if (level) params.set('level', level);
            if (canFilterGroups && group) params.set('group', group);
            if (kind === 'expected-graduates' && examStatus) params.set('exam_status', examStatus);
            const response = await getFeatureDataWithDemo<unknown>(`${config.endpoint}?${params.toString()}`, config.demo, signal);
            return { ...response, data: normalizeReportPayload(kind, response.data, { total: 0, active: 0, groups: 0, rows: [] }, viewMode) };
        },
        placeholderData: keepPreviousData,
    });

    const rows = useMemo(() => {
        const data = report.data?.data.rows ?? [];
        const keyword = deferredSearch.trim().toLocaleLowerCase('th');
        return !keyword ? data : data.filter((row) => `${row.primary} ${row.secondary} ${row.group}`.toLocaleLowerCase('th').includes(keyword));
    }, [report.data, deferredSearch]);

    const columns = useMemo<ColumnDef<ReportRow>[]>(() => [
        {
            accessorKey: 'primary', header: isAcademicReport && viewMode === 'student' ? 'นักศึกษา' : config.primaryLabel,
            size: 270,
            meta: { compactSize: isAcademicReport && viewMode === 'student' ? 132 : 150 },
            cell: ({ row }) => <div><p className="font-bold text-slate-950">{row.original.primary}</p><p className="mt-0.5 text-xs text-slate-500">{row.original.secondary}</p></div>,
        },
        { accessorKey: 'group', header: isAcademicReport && viewMode === 'student' ? 'ระดับ / กลุ่มเรียน' : config.groupLabel, size: 230, meta: { compactSize: 116 } },
        { accessorKey: 'metric', header: isAcademicReport && viewMode === 'student' ? (kind === 'registered-subjects' ? 'วิชาลงทะเบียน' : config.metricLabel) : config.metricLabel, size: 170, meta: { compactSize: 84, compactTextAlign: 'center' } },
        ...(kind === 'expected-graduates' ? [{
            id: 'exam_status',
            header: 'การสอบ N-Net / E-Exam',
            size: 180,
            meta: { compactSize: 96, compactTextAlign: 'center' },
            cell: ({ row }: { row: { original: ReportRow } }) => {
                const status = row.original.examStatus ?? 'สอบแล้ว';
                const isPassed = status === 'สอบแล้ว';
                return (
                    <StatusBadge tone={isPassed ? 'success' : 'warning'}>
                        {status}
                    </StatusBadge>
                );
            },
        } as ColumnDef<ReportRow>] : []),
        ...(isAcademicReport ? [{
            id: 'details', header: 'รายละเอียด', size: 145, meta: { compactSize: 52, compactTextAlign: 'center' }, enableSorting: false,
            cell: ({ row }: { row: { original: ReportRow } }) => viewMode === 'student'
                ? <button type="button" onClick={() => setSelectedStudent(row.original)} className="responsive-table-action inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1.5 text-xs font-bold text-brand-800 hover:bg-brand-100" aria-label={`เปิดรายละเอียด ${row.original.primary}`}><Eye size={16} weight="bold" /> <span>เปิดดู</span></button>
                : <button type="button" onClick={() => setSelectedSubject(row.original)} className="responsive-table-action inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs font-bold text-sky-800 hover:bg-sky-100" aria-label={`ดูรายชื่อ ${row.original.primary}`}><UsersThree size={16} weight="bold" /> <span>ดูรายชื่อ</span></button>,
        } as ColumnDef<ReportRow>] : []),
    ], [config, isAcademicReport, kind, viewMode]);

    const payload = report.data?.data;
    const termOptions = useMemo(() => {
        const available = payload?.terms?.filter(Boolean) ?? [];
        return Array.from(new Set([...(term ? [term] : []), ...available]));
    }, [payload?.terms, term]);
    useEffect(() => {
        if (term !== '') return;
        const currentTerm = payload?.selected_term ?? payload?.terms?.[0];
        if (currentTerm) setTerm(currentTerm);
    }, [payload?.selected_term, payload?.terms, term]);
    const statCards = useMemo<StatCard[]>(() => {
        if (!payload || !isAcademicReport) return [];
        return academicStatCards(kind, viewMode, payload);
    }, [isAcademicReport, kind, payload, viewMode]);
    return (
        <div>
            <PageHeader
                category={config.category}
                title={config.title}
                description={config.description}
                icon={config.icon}
                actions={(
                    <label className="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <span>ภาคเรียน</span>
                        <select value={term} onChange={(event) => setTerm(event.target.value)} className="h-10 rounded-xl border border-slate-300 bg-white px-3 text-sm">
                            {termOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                        </select>
                    </label>
                )}
            />

            {payload && (isAcademicReport ? (
                <StatGrid>
                    {statCards.map((card) => <StatTile key={card.label} label={card.label} value={card.value} detail={card.detail} icon={card.icon} tone={card.tone} />)}
                </StatGrid>
            ) : (
                <div className={`mb-5 grid gap-3 ${kind === 'new-students' ? 'sm:grid-cols-2' : 'sm:grid-cols-3'}`}>
                    <StatTile label="รายการทั้งหมด" value={payload.total} detail="ในภาคเรียนที่เลือก" icon={ChartBar} tone="sky" />
                    {kind !== 'new-students' && <StatTile label={config.activeLabel} value={payload.active} detail={config.activeDetail} icon={GraduationCap} />}
                    <StatTile label="กลุ่มข้อมูล" value={payload.groups} detail="แยกตามรายงาน" icon={Student} tone="amber" />
                </div>
            ))}

            <Panel title="รายละเอียดรายงาน" description="ผลลัพธ์ถูกจำกัดตามอำเภอและสิทธิ์ของผู้ใช้งาน">
                {isAcademicReport && <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-2.5">
                    <div className="inline-flex rounded-xl bg-white p-1 shadow-sm ring-1 ring-slate-200" aria-label="รูปแบบการแสดงรายงาน">
                        <button type="button" onClick={() => setViewMode('subject')} className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition ${viewMode === 'subject' ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100'}`}><Books size={17} /> ดูเป็นรายวิชา</button>
                        <button type="button" onClick={() => setViewMode('student')} className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition ${viewMode === 'student' ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100'}`}><UsersThree size={17} /> ดูเป็นรายคน</button>
                    </div>
                </div>}

                <div className={`mb-5 grid gap-3 ${kind === 'expected-graduates' ? (canFilterGroups ? 'md:grid-cols-4' : 'md:grid-cols-3') : (canFilterGroups ? 'md:grid-cols-3' : 'md:grid-cols-2')}`}>
                    <label>
                        <span className="mb-2 block text-sm font-bold text-slate-700">ค้นหาในรายงาน</span>
                        <span className="relative block">
                            <MagnifyingGlass size={18} className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={viewMode === 'student' && isAcademicReport ? canFilterGroups ? 'ค้นหาชื่อ รหัส หรือกลุ่มเรียน' : 'ค้นหาชื่อหรือรหัสนักศึกษา' : `ค้นหา${config.primaryLabel} หรือ${config.secondaryLabel}`} className="h-11 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-sm placeholder:text-slate-400" />
                        </span>
                    </label>
                    <label><span className="mb-2 block text-sm font-bold text-slate-700">ระดับการศึกษา</span><select value={level} onChange={(event) => { setLevel(event.target.value); setGroup(''); }} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"><option value="">ทุกระดับ</option><option value="1">ประถมศึกษา</option><option value="2">มัธยมศึกษาตอนต้น</option><option value="3">มัธยมศึกษาตอนปลาย</option></select></label>
                    {canFilterGroups && <label><span className="mb-2 block text-sm font-bold text-slate-700">กลุ่มเรียน</span><select value={group} onChange={(event) => setGroup(event.target.value)} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm"><option value="">ทุกกลุ่มเรียน</option>{filterOptions.groups.map((option) => <option key={String(option.value)} value={String(option.value)}>{option.label}</option>)}</select></label>}
                    {kind === 'expected-graduates' && (
                        <label>
                            <span className="mb-2 block text-sm font-bold text-slate-700">สถานะ N-Net / E-Exam</span>
                            <select value={examStatus} onChange={(event) => setExamStatus(event.target.value)} className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                                <option value="">ทุกสถานะการสอบ</option>
                                <option value="taken">สอบแล้ว</option>
                                <option value="not_taken">ยังไม่ได้สอบ</option>
                            </select>
                        </label>
                    )}
                </div>
                {report.isPending && <QuerySkeleton />}
                {report.isError && <QueryError onRetry={() => report.refetch()} />}
                {payload && <DataTable data={rows} columns={columns} minWidth={isAcademicReport ? 'wide' : 'default'} emptyTitle="ไม่พบข้อมูลในรายงาน" emptyDescription="ลองเปลี่ยนภาคเรียน ระดับ กลุ่มเรียน หรือคำค้นหา" />}
            </Panel>
            {selectedStudent && <AcademicStudentDetailDialog kind={kind} student={selectedStudent} term={term} onClose={() => setSelectedStudent(null)} />}
            {selectedSubject && <AcademicSubjectStudentsDialog kind={kind} subject={selectedSubject} term={term} level={level} group={canFilterGroups ? group : ''} endpoint={config.endpoint} onClose={() => setSelectedSubject(null)} />}
        </div>
    );
}
