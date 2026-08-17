import { lazy, type ComponentType, type ReactNode } from 'react';

function lazyWithReload<Props>(loader: () => Promise<{ default: ComponentType<Props> }>) {
    return lazy(loader);
}

const StudentsPage = lazyWithReload(async () => ({ default: (await import('./students')).StudentsPage }));
const StudentDetailPage = lazyWithReload(async () => ({ default: (await import('./students')).StudentDetailPage }));
const MyLearningPage = lazyWithReload(async () => ({ default: (await import('./students')).MyLearningPage }));
const GradesPage = lazyWithReload(async () => ({ default: (await import('./students')).GradesPage }));
const AchievementPage = lazyWithReload(async () => ({ default: (await import('./students')).AchievementPage }));
const ReportPage = lazyWithReload(async () => ({ default: (await import('./reports')).ReportPage }));
const LearningHomePage = lazyWithReload(async () => ({ default: (await import('./learning')).LearningHomePage }));
const LearningListPage = lazyWithReload(async () => ({ default: (await import('./learning')).LearningListPage }));
const AdminUsersPage = lazyWithReload(async () => ({ default: (await import('./admin')).AdminUsersPage }));
const AdminExamRoomsPage = lazyWithReload(async () => ({ default: (await import('./admin')).AdminExamRoomsPage }));
const AdminImportsPage = lazyWithReload(async () => ({ default: (await import('./admin')).AdminImportsPage }));
const AdminDataMaintenancePage = lazyWithReload(async () => ({ default: (await import('./admin')).AdminDataMaintenancePage }));
const SuperAdminDistrictsPage = lazyWithReload(async () => ({ default: (await import('./admin')).SuperAdminDistrictsPage }));
const SuperAdminBrandingPage = lazyWithReload(async () => ({ default: (await import('./admin')).SuperAdminBrandingPage }));
const ProfileSettingsPage = lazyWithReload(async () => ({ default: (await import('./settings')).ProfileSettingsPage }));
const AppearanceSettingsPage = lazyWithReload(async () => ({ default: (await import('./settings')).AppearanceSettingsPage }));

export type FeatureRole = 'student' | 'teacher' | 'admin' | 'super_admin';

export type FeatureRoute = {
    path: string;
    label: string;
    roles: FeatureRole[];
    element: ReactNode;
};

export const featureRouteCatalog: FeatureRoute[] = [
    { path: '/students', label: 'ข้อมูลนักศึกษา', roles: ['teacher', 'admin', 'super_admin'], element: <StudentsPage /> },
    { path: '/students/:studentId', label: 'รายละเอียดนักศึกษา', roles: ['teacher', 'admin', 'super_admin'], element: <StudentDetailPage /> },
    { path: '/my-learning', label: 'ข้อมูลการเรียนของฉัน', roles: ['student'], element: <MyLearningPage /> },
    { path: '/grades', label: 'ผลการเรียน', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <GradesPage /> },
    { path: '/kpch', label: 'กิจกรรม กพช.', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <AchievementPage kind="kpch" /> },
    { path: '/moral', label: 'คุณธรรม', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <AchievementPage kind="moral" /> },

    { path: '/reports/new-students', label: 'นักศึกษาใหม่', roles: ['teacher', 'admin', 'super_admin'], element: <ReportPage kind="new-students" /> },
    { path: '/reports/graduates', label: 'ผู้จบหลักสูตร', roles: ['teacher', 'admin', 'super_admin'], element: <ReportPage kind="graduates" /> },
    { path: '/reports/expected-graduates', label: 'นักศึกษาคาดว่าจะจบ', roles: ['teacher', 'admin', 'super_admin'], element: <ReportPage kind="expected-graduates" /> },
    { path: '/reports/transfers', label: 'ข้อมูลเทียบโอน', roles: ['teacher', 'admin', 'super_admin'], element: <ReportPage kind="transfers" /> },
    { path: '/reports/registered-subjects', label: 'วิชาลงทะเบียน', roles: ['teacher', 'admin', 'super_admin'], element: <ReportPage kind="registered-subjects" /> },
    { path: '/reports/grade-threshold', label: 'สถิติเกรด 2 ขึ้นไป', roles: ['teacher', 'admin', 'super_admin'], element: <ReportPage kind="grade-threshold" /> },
    { path: '/reports/exam-attendance', label: 'สถิติการเข้าสอบ', roles: ['teacher', 'admin', 'super_admin'], element: <ReportPage kind="exam-attendance" /> },

    { path: '/learning', label: 'พื้นที่การเรียนรู้', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <LearningHomePage /> },
    { path: '/learning/assignments', label: 'งานและการส่งงาน', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <LearningListPage kind="assignments" /> },
    { path: '/learning/resources', label: 'คลังสื่อ', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <LearningListPage kind="resources" /> },
    { path: '/learning/lesson-plans', label: 'แผนการสอน', roles: ['teacher', 'admin', 'super_admin'], element: <LearningListPage kind="lesson-plans" /> },
    { path: '/learning/calendar', label: 'ปฏิทินพบกลุ่ม', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <LearningListPage kind="calendar" /> },
    { path: '/learning/schedule', label: 'ตารางสอบ', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <LearningListPage kind="schedule" /> },
    { path: '/learning/scores', label: 'คะแนนเก็บ', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <LearningListPage kind="scores" /> },

    { path: '/admin/users', label: 'ผู้ใช้งาน', roles: ['admin', 'super_admin'], element: <AdminUsersPage /> },
    { path: '/admin/exam-rooms', label: 'ห้องสอบ', roles: ['teacher', 'admin', 'super_admin'], element: <AdminExamRoomsPage /> },
    { path: '/admin/imports', label: 'นำเข้าข้อมูล', roles: ['admin', 'super_admin'], element: <AdminImportsPage /> },
    { path: '/admin/data-maintenance', label: 'ดูแลข้อมูล', roles: ['admin', 'super_admin'], element: <AdminDataMaintenancePage /> },
    { path: '/admin/branding', label: 'แบรนด์และหน้าเข้าสู่ระบบ', roles: ['admin', 'super_admin'], element: <SuperAdminBrandingPage /> },
    { path: '/super-admin/districts', label: 'ทะเบียนอำเภอ', roles: ['super_admin'], element: <SuperAdminDistrictsPage /> },
    { path: '/super-admin/branding', label: 'แบรนด์และหน้าเข้าสู่ระบบ', roles: ['super_admin'], element: <SuperAdminBrandingPage /> },

    { path: '/settings/profile', label: 'โปรไฟล์', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <ProfileSettingsPage /> },
    { path: '/settings/appearance', label: 'รูปแบบการแสดงผล', roles: ['student', 'teacher', 'admin', 'super_admin'], element: <AppearanceSettingsPage /> },
];
