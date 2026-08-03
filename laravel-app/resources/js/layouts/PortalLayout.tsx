import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Avatar, Button, Card, Input, Select, Spinner, Text } from '../components/MaterialUI';
import {
    Bell,
    BookOpenText,
    Books,
    CalendarBlank,
    CaretDown,
    ChartLineUp,
    ClipboardText,
    Clock,
    Database,
    DoorOpen,
    FolderOpen,
    GraduationCap,
    Heart,
    House,
    List,
    MagnifyingGlass,
    Medal,
    Notebook,
    PaintBrush,
    Palette,
    Planet,
    SignOut,
    Sparkle,
    Student,
    TrendUp,
    Trophy,
    UploadSimple,
    User,
    UserPlus,
    Users,
    UsersThree,
    X,
} from '@phosphor-icons/react';
import { Link, Navigate, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { ApiError, apiGet, apiPost } from '../lib/api';
import { applyAppearance, DEFAULT_APPEARANCE, type AppearanceSettings } from '../lib/appearance';
import { showSuccessAlert } from '../lib/feedback';
import { publicBrandingPath, type PublicBranding } from '../lib/publicBranding';
import { queryClient } from '../query';
import { useDemoRole, type DemoRole } from '../context/DemoRoleContext';

type CatalogItem = {
    key: string;
    label: string;
    description: string;
    route: string;
    icon: string;
    roles: DemoRole[];
};

type CatalogGroup = {
    key: string;
    label: string;
    color: string;
    items: CatalogItem[];
};

type Catalog = {
    role: DemoRole;
    groups: CatalogGroup[];
    capabilities: string[];
};

type CurrentUser = {
    id: number;
    name: string;
    avatar_url: string | null;
    username: string;
    role: DemoRole;
    district_id: number | null;
    assigned_groups: string[];
    auth_source: string;
    appearance: AppearanceSettings;
    districts: Array<{ id: number; name: string; code: string }>;
};

const icons = {
    house: House,
    students: Users,
    student: Student,
    chart: ChartLineUp,
    sparkle: Sparkle,
    heart: Heart,
    'user-plus': UserPlus,
    medal: Medal,
    arrows: TrendUp,
    books: Books,
    trend: TrendUp,
    clipboard: ClipboardText,
    planet: Planet,
    assignment: BookOpenText,
    folder: FolderOpen,
    notebook: Notebook,
    calendar: CalendarBlank,
    clock: Clock,
    trophy: Trophy,
    'users-three': UsersThree,
    door: DoorOpen,
    upload: UploadSimple,
    database: Database,
    palette: Palette,
    user: User,
    'paint-brush': PaintBrush,
} as const;

const roleOptions: Array<{ value: DemoRole; label: string; short: string }> = [
    { value: 'student', label: 'นักศึกษา', short: 'นศ' },
    { value: 'teacher', label: 'ครูผู้สอน', short: 'ครู' },
    { value: 'admin', label: 'ผู้ดูแลอำเภอ', short: 'ผด' },
    { value: 'super_admin', label: 'ผู้ดูแลส่วนกลาง', short: 'สก' },
];

function SidebarSkeleton() {
    return (
        <div className="animate-pulse space-y-3 px-2 py-3">
            {[1, 2, 3].map((group) => (
                <div key={group} className="space-y-1.5">
                    <div className="h-3 w-20 rounded bg-white/15" />
                    <div className="h-8 rounded-xl bg-white/[0.08]" />
                    <div className="h-8 rounded-xl bg-white/[0.08]" />
                </div>
            ))}
        </div>
    );
}

export function PortalLayout() {
    const { role, setRole } = useDemoRole();
    const location = useLocation();
    const navigate = useNavigate();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [roleMenuOpen, setRoleMenuOpen] = useState(false);
    const [notificationOpen, setNotificationOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const [globalSearch, setGlobalSearch] = useState('');
    const [selectedDistrictId, setSelectedDistrictId] = useState(() => window.localStorage.getItem('sena-district-id') ?? '');

    const me = useQuery({
        queryKey: ['auth', 'me'],
        queryFn: ({ signal }) => apiGet<CurrentUser>('/api/v1/me', signal).then((response) => response.data),
        retry: false,
    });

    useEffect(() => {
        if (!me.isSuccess || window.sessionStorage.getItem('sena-login-feedback') !== 'success') return;
        window.sessionStorage.removeItem('sena-login-feedback');
        showSuccessAlert('เข้าสู่ระบบสำเร็จ');
    }, [me.isSuccess]);

    useEffect(() => {
        if (!me.data) return;

        setRole(me.data.role);
        applyAppearance(me.data.appearance ?? DEFAULT_APPEARANCE);
        const allowedDistricts = (me.data.districts ?? []).map((district) => String(district.id));
        const nextDistrict = me.data.role === 'super_admin'
            ? (allowedDistricts.includes(selectedDistrictId) ? selectedDistrictId : allowedDistricts[0] ?? '')
            : (me.data.district_id ? String(me.data.district_id) : '');

        if (nextDistrict) {
            window.localStorage.setItem('sena-district-id', nextDistrict);
        } else {
            window.localStorage.removeItem('sena-district-id');
        }
        setSelectedDistrictId(nextDistrict);
    }, [me.data]);

    const catalog = useQuery({
        queryKey: ['system', 'catalog', me.data?.role],
        queryFn: ({ signal }) => apiGet<Catalog>('/api/v1/system/catalog', signal).then((response) => response.data),
        staleTime: 5 * 60_000,
        enabled: me.isSuccess,
    });

    const branding = useQuery({
        queryKey: ['auth', 'branding', selectedDistrictId],
        queryFn: ({ signal }) => apiGet<PublicBranding>(publicBrandingPath(selectedDistrictId), signal).then((response) => response.data),
        enabled: me.isSuccess && selectedDistrictId !== '',
        staleTime: 5 * 60_000,
    });

    const logout = useMutation({
        meta: { notification: { success: 'ออกจากระบบเรียบร้อยแล้ว' } },
        mutationFn: () => apiPost<{ logged_out: boolean }>('/auth/logout'),
        onSettled: async () => {
            window.localStorage.removeItem('sena-district-id');
            window.localStorage.removeItem('sena-appearance');
            applyAppearance(DEFAULT_APPEARANCE, false);
            await queryClient.cancelQueries();
            queryClient.removeQueries();
            navigate('/login', { replace: true });
        },
    });

    const activeItem = catalog.data?.groups
        .flatMap((group) => group.items)
        .find((item) => item.route === location.pathname);
    const currentRole = roleOptions.find((option) => option.value === (me.data?.role ?? role)) ?? roleOptions[0];
    const availableDistricts = me.data?.districts ?? [];
    const currentDistrict = availableDistricts.find((district) => String(district.id) === selectedDistrictId);
    const searchableItems = catalog.data?.groups.flatMap((group) => group.items) ?? [];
    const normalizedSearch = globalSearch.trim().toLocaleLowerCase('th-TH');
    const searchResults = normalizedSearch === '' ? searchableItems.slice(0, 6) : searchableItems.filter((item) => `${item.label} ${item.description}`.toLocaleLowerCase('th-TH').includes(normalizedSearch)).slice(0, 8);

    const submitGlobalSearch = () => {
        const first = searchResults[0];
        if (!first) return;
        navigate(first.route);
        setGlobalSearch('');
        setSearchOpen(false);
    };

    const changeDistrict = (districtId: string) => {
        window.localStorage.setItem('sena-district-id', districtId);
        setSelectedDistrictId(districtId);
        queryClient.removeQueries({ predicate: (query) => !['auth', 'system'].includes(String(query.queryKey[0])) });
    };

    if (me.isPending) {
        return <div className="grid min-h-[100dvh] place-items-center bg-[#f7f8fc]"><Spinner size="huge" label="กำลังตรวจสอบสิทธิ์" /></div>;
    }

    if (me.error instanceof ApiError && me.error.status === 401) {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    if (me.isError || !me.data) {
        return <div className="grid min-h-[100dvh] place-items-center bg-[#f7f8fc] p-6"><Card role="alert" className="max-w-md p-7 text-center"><h1 className="text-xl font-bold text-slate-950">ตรวจสอบบัญชีไม่สำเร็จ</h1><Text as="p" className="mt-2 text-slate-600">กรุณาตรวจการเชื่อมต่อ แล้วลองอีกครั้ง</Text><Button appearance="primary" onClick={() => me.refetch()} className="mt-5">ลองใหม่</Button></Card></div>;
    }

    return (
        <div className="portal-shell">
            {sidebarOpen && <button className="fixed inset-0 z-30 bg-slate-950/30 lg:hidden" onClick={() => setSidebarOpen(false)} aria-label="ปิดเมนู" />}
            <aside className={`portal-sidebar fixed inset-y-0 left-0 z-40 flex w-[282px] flex-col overflow-hidden text-white transition-transform lg:w-[266px] lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="flex h-[78px] items-center justify-between border-b border-white/10 px-4">
                    <Link to="/app" className="flex items-center gap-2.5">
                        <span className="grid size-11 place-items-center overflow-hidden rounded-2xl border border-white/70 bg-white text-brand-700 shadow-[0_10px_26px_rgb(3_15_52_/_0.22)]">
                            {branding.data?.logoImageUrl ? <img src={branding.data.logoImageUrl} alt="" className="size-full object-contain p-1" /> : <GraduationCap size={23} weight="fill" aria-hidden="true" />}
                        </span>
                        <span className="leading-tight text-white">
                            <strong className="block max-w-40 truncate text-[16px] font-black tracking-[-0.025em]">{branding.data?.portalName ?? 'SDL School'}</strong>
                            <span className="block max-w-40 truncate text-[10px] font-semibold text-brand-100">{branding.data?.districtName ?? currentDistrict?.name ?? 'Digital Campus'}</span>
                        </span>
                    </Link>
                    <span className="lg:hidden">
                        <Button appearance="subtle" icon={<X size={20} />} onClick={() => setSidebarOpen(false)} className="sidebar-icon-button" aria-label="ปิดเมนู" />
                    </span>
                </div>

                <div className="sidebar-context-card mx-3 mt-4 flex items-center gap-3 border border-white/15 px-3.5 py-3">
                    <span className="grid size-10 shrink-0 place-items-center rounded-[14px] bg-white/10 text-brand-100 shadow-[inset_0_0_0_1px_rgb(255_255_255_/_0.08)]">
                        <User size={20} weight="duotone" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-[10px] font-bold text-brand-100">สิทธิ์การใช้งาน</p>
                        <p className="mt-0.5 text-[14px] font-black text-white">{currentRole.label}</p>
                        <p className="truncate text-[10px] text-sky-100/75">{currentDistrict?.name ?? availableDistricts[0]?.name ?? 'เลือกอำเภอ'}</p>
                    </div>
                </div>

                <nav aria-label="เมนูหลัก" className="mt-2 flex-1 overflow-y-auto px-2.5 pb-4">
                    {catalog.isPending && <SidebarSkeleton />}
                    {catalog.data?.groups.map((group) => (
                        <section key={group.key} className="mt-4">
                            <div className="mb-1 px-2.5">
                                <h2 className="text-[10px] font-bold tracking-[0.04em] text-brand-200/80">{group.label}</h2>
                            </div>
                            <div className="space-y-0.5">
                                {group.items.map((item) => {
                                    const Icon = icons[item.icon as keyof typeof icons] ?? BookOpenText;
                                    return (
                                        <NavLink
                                            key={item.key}
                                            to={item.route}
                                            end={item.route === '/app'}
                                            onClick={() => setSidebarOpen(false)}
                                            className={({ isActive }) => `sidebar-nav-link flex items-center gap-2.5 px-2.5 py-2 text-[13px] font-bold leading-5 ${isActive ? 'is-active text-white' : 'text-white/80 hover:text-white'}`}
                                        >
                                            <span className="grid size-7 shrink-0 place-items-center rounded-[9px] bg-white/[0.07]">
                                                <Icon size={17} weight="duotone" aria-hidden="true" />
                                            </span>
                                            <span className="truncate">{item.label}</span>
                                        </NavLink>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </nav>
            </aside>

            <div className="lg:pl-[266px]">
                <header className="portal-topbar sticky top-0 z-20 flex h-[78px] items-center gap-3 border-b px-4 backdrop-blur-xl sm:px-7 lg:px-8">
                    <span className="lg:hidden">
                        <Button appearance="subtle" icon={<List size={22} />} onClick={() => setSidebarOpen(true)} aria-label="เปิดเมนู" />
                    </span>
                    <div className="min-w-0 lg:hidden">
                        <p className="truncate text-sm font-bold text-slate-900">{activeItem?.label ?? branding.data?.portalName ?? 'SDL School'}</p>
                        <p className="text-xs text-slate-500">{currentRole.label}</p>
                    </div>
                    <form onSubmit={(event) => { event.preventDefault(); submitGlobalSearch(); }} className="relative hidden max-w-[560px] flex-1 md:block">
                        <Input contentBefore={<MagnifyingGlass size={18} aria-hidden="true" />} value={globalSearch} onChange={(event) => { setGlobalSearch(event.target.value); setSearchOpen(true); }} onFocus={() => setSearchOpen(true)} aria-label="ค้นหาเมนูในระบบ" placeholder="ค้นหาเมนูหรือหน้ารายงาน" className="material-global-search w-full" />
                        {searchOpen && <Card className="absolute left-0 right-0 top-[48px] z-30 p-2">
                            {searchResults.length > 0 ? searchResults.map((item) => <Button key={item.key} appearance="subtle" onClick={() => { navigate(item.route); setGlobalSearch(''); setSearchOpen(false); }} className="search-result-button w-full justify-start text-left" icon={<MagnifyingGlass size={16} className="text-brand-700" />}><span><strong className="block text-sm text-slate-900">{item.label}</strong><span className="line-clamp-1 text-xs text-slate-500">{item.description}</span></span></Button>) : <p className="px-3 py-4 text-center text-sm text-slate-500">ไม่พบเมนูที่ค้นหา</p>}
                            <Button appearance="transparent" onClick={() => setSearchOpen(false)} className="mt-1 w-full">ปิดผลการค้นหา</Button>
                        </Card>}
                    </form>
                    <div className="ml-auto flex items-center gap-2">
                        {me.data.role === 'super_admin' && (
                            <span className="hidden sm:block">
                                <Select value={selectedDistrictId} onChange={(event) => changeDistrict(String(event.target.value))} aria-label="เลือกอำเภอ" className="max-w-52 font-bold">
                                    {availableDistricts.map((district) => <option key={district.id} value={district.id}>{district.name}</option>)}
                                </Select>
                            </span>
                        )}
                        <div className="relative">
                        <Button appearance="subtle" icon={<Bell size={20} weight="duotone" />} onClick={() => { setNotificationOpen((open) => !open); setRoleMenuOpen(false); }} aria-label="การแจ้งเตือน" aria-expanded={notificationOpen} />
                        {notificationOpen && <Card className="absolute right-0 top-[48px] w-72 p-4 text-center"><Bell size={28} weight="duotone" className="mx-auto text-brand-700" /><p className="mt-2 text-sm font-bold text-slate-900">ยังไม่มีการแจ้งเตือนใหม่</p><Text as="p" size={200} className="mt-1 leading-5 text-slate-500">ประกาศและงานที่ต้องติดตามจะแสดงที่นี่</Text></Card>}
                        </div>
                        <div className="relative">
                            <Button
                                appearance="subtle"
                                onClick={() => { setRoleMenuOpen((open) => !open); setNotificationOpen(false); }}
                                className="user-menu-trigger font-bold"
                                aria-expanded={roleMenuOpen}
                                aria-haspopup="menu"
                            >
                                <Avatar name={me.data.name} image={me.data.avatar_url ? { src: me.data.avatar_url } : undefined} size={36} color="colorful" />
                                <span className="hidden max-w-32 truncate sm:inline">{me.data.name}</span>
                                <CaretDown size={14} />
                            </Button>
                            {roleMenuOpen && (
                                <Card role="menu" className="absolute right-0 top-[48px] w-56 p-2">
                                    <div className="px-3 py-2"><p className="truncate text-sm font-bold text-slate-900">{me.data.name}</p><p className="mt-0.5 truncate text-xs text-slate-500">{me.data.username} · {currentRole.label}</p></div>
                                    <Link to="/settings/profile" role="menuitem" onClick={() => setRoleMenuOpen(false)} className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50"><User size={19} />โปรไฟล์ของฉัน</Link>
                                    <Link to="/settings/appearance" role="menuitem" onClick={() => setRoleMenuOpen(false)} className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 hover:bg-brand-50"><PaintBrush size={19} />รูปแบบการแสดงผล</Link>
                                    <button type="button" role="menuitem" disabled={logout.isPending} onClick={() => logout.mutate()} className="mt-1 flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-60"><SignOut size={19} />{logout.isPending ? 'กำลังออกจากระบบ' : 'ออกจากระบบ'}</button>
                                </Card>
                            )}
                        </div>
                    </div>
                </header>

                <main className="portal-content relative min-h-[calc(100dvh-78px)] overflow-hidden">
                    <div className="relative mx-auto max-w-[1500px] px-4 py-5 sm:px-6 lg:px-7 lg:py-7">
                        {role !== me.data.role ? <SidebarSkeleton /> : me.data.role !== 'super_admin' || selectedDistrictId ? <Outlet /> : <div className="rounded-3xl border border-amber-200 bg-amber-50 p-6 font-bold text-amber-900">กรุณาเลือกอำเภอเพื่อดูข้อมูล</div>}
                    </div>
                </main>
            </div>
        </div>
    );
}
