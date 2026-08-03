import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Badge, Button, Field, Input, MessageBar, MessageBarBody, Tab, TabList } from '../components/MaterialUI';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle,
    Eye,
    EyeSlash,
    GraduationCap,
    IdentificationCard,
    LockKey,
    Student,
    UserCircle,
} from '@phosphor-icons/react';
import { Link } from 'react-router-dom';
import type { DemoRole } from '../context/DemoRoleContext';
import { ApiError, apiGet, apiPost } from '../lib/api';
import { DEFAULT_HERO_IMAGE, publicAssetUrl, publicBrandingCssVariables, publicBrandingPath, type PublicBranding } from '../lib/publicBranding';
import { withAppBasePath } from '../lib/urls';

type LoginType = 'staff' | 'student';

type LoginUser = {
    id: number;
    name: string;
    username: string;
    role: DemoRole;
    district_id: number | null;
    assigned_groups: string[];
    auth_source: string;
    districts: DistrictOption[];
};

type DistrictOption = {
    id: number;
    name: string;
    code: string;
};

export function LoginPage() {
    const [loginType, setLoginType] = useState<LoginType>('staff');
    const [identifier, setIdentifier] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);

    const brandingDistrictId = window.localStorage.getItem('sena-district-id');
    const branding = useQuery({
        queryKey: ['auth', 'branding', brandingDistrictId ?? 'default'],
        queryFn: ({ signal }) => apiGet<PublicBranding>(publicBrandingPath(brandingDistrictId), signal).then((response) => response.data),
        staleTime: 5 * 60_000,
    });

    const login = useMutation({
        meta: { notification: { success: false } },
        mutationFn: () => apiPost<LoginUser>('/auth/login', {
            identifier,
            password,
            login_type: loginType,
        }),
        onSuccess: (response) => {
            if (response.data.district_id) {
                window.localStorage.setItem('sena-district-id', String(response.data.district_id));
            } else {
                window.localStorage.removeItem('sena-district-id');
            }
            window.sessionStorage.setItem('sena-login-feedback', 'success');
            window.location.replace(withAppBasePath('/app'));
        },
    });

    const error = login.error instanceof ApiError
        ? login.error.errors.identifier?.[0] ?? login.error.message
        : null;
    const submitDisabled = login.isPending || !identifier || !password;

    const switchType = (type: LoginType) => {
        setLoginType(type);
        setIdentifier('');
        setPassword('');
        login.reset();
    };

    return (
        <main style={publicBrandingCssVariables(branding.data?.primaryColor)} className="public-page min-h-[100dvh] p-3 sm:p-5 lg:p-6">
            <div className="public-auth-shell mx-auto grid min-h-[calc(100dvh-24px)] max-w-[1480px] overflow-hidden border border-slate-200 bg-white sm:min-h-[calc(100dvh-40px)] lg:min-h-[calc(100dvh-48px)] lg:grid-cols-[0.92fr_1.08fr]">
                <section className="relative hidden overflow-hidden bg-brand-950 lg:block" aria-label="พื้นที่ต้อนรับนักศึกษา">
                    <img src={publicAssetUrl(branding.data?.heroImageUrl) ?? DEFAULT_HERO_IMAGE} alt="ภาพต้อนรับนักศึกษาและครู" className="absolute inset-0 size-full object-cover object-[58%_center] opacity-90" />
                    <div className="absolute inset-0 bg-gradient-to-t from-brand-950 via-brand-950/25 to-transparent" aria-hidden="true" />
                    <div className="absolute inset-x-0 bottom-0 p-12 text-white">
                        <p className="text-sm font-bold text-brand-200">{branding.data?.portalName ?? 'SDL SCHOOL'}</p>
                        <h1 className="mt-4 max-w-[12ch] text-5xl font-bold leading-[1.15] tracking-[-0.035em]">{branding.data?.welcomeMessage ?? 'ทุกเป้าหมาย เริ่มได้ที่นี่'}</h1>
                        <p className="mt-5 max-w-[42ch] text-base leading-7 text-brand-50/90">{branding.data?.schoolName ?? 'ข้อมูลการเรียน ผลการเรียน และกิจกรรมของคุณ เชื่อมตรงจากระบบสถานศึกษา'}</p>
                    </div>
                </section>

                <section className="flex items-center justify-center px-5 py-8 sm:px-10 lg:px-16">
                    <div className="w-full max-w-[520px]">
                        <div className="flex items-center justify-between">
                            <Link to="/" className="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900">
                                <ArrowLeft size={18} aria-hidden="true" /> กลับหน้าแรก
                            </Link>
                            <span className="grid size-11 place-items-center overflow-hidden rounded-2xl bg-brand-600 text-white shadow-lg shadow-brand-800/20 lg:hidden">
                                {branding.data?.logoImageUrl ? <img src={publicAssetUrl(branding.data.logoImageUrl) ?? ''} alt="" className="size-full bg-white object-contain p-1" /> : <GraduationCap size={25} weight="fill" aria-hidden="true" />}
                            </span>
                        </div>

                        <div className="mt-9">
                            <Badge appearance="tint" color="brand" icon={<CheckCircle size={16} weight="fill" />}>เชื่อมข้อมูลระบบจริง</Badge>
                            <h2 className="mt-4 text-3xl font-black tracking-[-0.03em] text-slate-950 sm:text-4xl">ยินดีต้อนรับกลับมา</h2>
                            <p className="mt-3 text-slate-600">เลือกประเภทผู้ใช้งาน แล้วเข้าสู่พื้นที่ของคุณ</p>
                        </div>

                        <TabList selectedValue={loginType} onTabSelect={(_, data) => switchType(data.value as LoginType)} appearance="subtle" size="large" className="login-tab-list mt-7" aria-label="ประเภทผู้ใช้งาน">
                            <Tab value="staff" icon={<UserCircle size={20} weight="duotone" />} className="justify-center">ครูและผู้ดูแล</Tab>
                            <Tab value="student" icon={<Student size={20} weight="duotone" />} className="justify-center">นักศึกษา</Tab>
                        </TabList>

                        <form className="mt-6 space-y-5" onSubmit={(event) => { event.preventDefault(); login.mutate(); }}>
                            <Field label={loginType === 'student' ? 'เลขประจำตัวประชาชน' : 'ชื่อผู้ใช้'} required>
                                <Input id="identifier" size="large" contentBefore={<IdentificationCard size={21} aria-hidden="true" />} value={identifier} onChange={(event) => setIdentifier(event.target.value)} autoComplete="username" inputMode={loginType === 'student' ? 'numeric' : undefined} maxLength={loginType === 'student' ? 13 : 190} className="login-input w-full" placeholder={loginType === 'student' ? 'กรอกเลข 13 หลัก' : 'กรอกชื่อผู้ใช้'} />
                            </Field>

                            <Field label={loginType === 'student' ? 'รหัสนักศึกษา' : 'รหัสผ่าน'} required>
                                <Input id="password" size="large" contentBefore={<LockKey size={21} aria-hidden="true" />} contentAfter={loginType === 'staff' ? <Button type="button" appearance="transparent" size="small" icon={showPassword ? <EyeSlash size={20} /> : <Eye size={20} />} onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'} /> : undefined} type={loginType === 'student' || showPassword ? 'text' : 'password'} value={password} onChange={(event) => setPassword(event.target.value)} autoComplete={loginType === 'student' ? 'off' : 'current-password'} className="login-input w-full" placeholder={loginType === 'student' ? 'กรอกรหัสนักศึกษา' : 'กรอกรหัสผ่าน'} />
                            </Field>

                            {error && <MessageBar intent="error" role="alert"><MessageBarBody>{error}</MessageBarBody></MessageBar>}

                            <Button type="submit" size="large" appearance="primary" disabled={submitDisabled} className="login-submit w-full" icon={!login.isPending ? <ArrowRight size={19} weight="bold" /> : undefined} iconPosition="after">
                                {login.isPending ? 'กำลังตรวจสอบข้อมูล' : 'เข้าสู่ระบบ'}
                            </Button>
                        </form>

                        <p className="mt-6 text-center text-xs leading-5 text-slate-500">ระบบจะแสดงข้อมูลตามอำเภอ กลุ่ม และสิทธิ์ของบัญชีเท่านั้น</p>
                    </div>
                </section>
            </div>
        </main>
    );
}
