import { ArrowRight, BookOpenText, ChartLineUp, GraduationCap, Sparkle } from '@phosphor-icons/react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiGet } from '../lib/api';
import { DEFAULT_HERO_IMAGE, publicBrandingCssVariables, publicBrandingPath, type PublicBranding } from '../lib/publicBranding';

const highlights = [
    { label: 'งานและบทเรียน', icon: BookOpenText },
    { label: 'ผลการเรียน', icon: ChartLineUp },
    { label: 'กิจกรรมและคุณธรรม', icon: Sparkle },
];

function Brand({ branding }: { branding?: PublicBranding }) {
    return (
        <Link to="/" className="flex items-center gap-3" aria-label="SDL School หน้าแรก">
            <span className="grid size-11 shrink-0 place-items-center overflow-hidden rounded-2xl bg-brand-700 text-white shadow-lg shadow-brand-950/20">
                {branding?.logoImageUrl ? <img src={branding.logoImageUrl} alt="" className="size-full bg-white object-contain p-1" /> : <GraduationCap size={26} weight="fill" aria-hidden="true" />}
            </span>
            <span className="leading-tight text-white">
                <strong className="block text-[17px] font-bold tracking-[-0.02em]">{branding?.portalName ?? 'SDL School'}</strong>
                <span className="text-[12px] font-semibold text-brand-100">{branding?.districtName ?? 'Digital Campus'}</span>
            </span>
        </Link>
    );
}

export function LandingPage() {
    const districtId = window.localStorage.getItem('sena-district-id');
    const branding = useQuery({
        queryKey: ['auth', 'branding', districtId ?? 'default'],
        queryFn: ({ signal }) => apiGet<PublicBranding>(publicBrandingPath(districtId), signal).then((response) => response.data),
        staleTime: 5 * 60_000,
    });

    return (
        <main style={publicBrandingCssVariables(branding.data?.primaryColor)} className="public-page min-h-[100dvh] p-3 sm:p-5 lg:p-6">
            <section className="public-hero relative mx-auto min-h-[calc(100dvh-24px)] max-w-[1500px] overflow-hidden bg-brand-950 sm:min-h-[calc(100dvh-40px)] lg:min-h-[calc(100dvh-48px)]">
                <img
                    src={branding.data?.heroImageUrl ?? DEFAULT_HERO_IMAGE}
                    alt="ภาพต้อนรับนักศึกษาและครู"
                    className="absolute inset-0 size-full object-cover object-[66%_center]"
                />
                <div className="hero-scrim absolute inset-0" aria-hidden="true" />

                <header className="relative z-10 flex h-[76px] items-center justify-between px-5 sm:px-8 lg:px-12">
                    <Brand branding={branding.data} />
                    <Link
                        to="/login"
                        className="whitespace-nowrap rounded-xl border border-white/35 bg-white/10 px-5 py-2.5 text-sm font-bold text-white backdrop-blur-sm transition-colors hover:bg-white/20 active:scale-[0.97]"
                    >
                        เข้าสู่ระบบ
                    </Link>
                </header>

                <div className="relative z-10 flex min-h-[calc(100dvh-124px)] max-w-[760px] flex-col justify-center px-6 pb-16 sm:px-10 lg:px-16">
                    <p className="mb-5 text-sm font-bold text-brand-100">พื้นที่การเรียนรู้ของทุกคน</p>
                    <h1 className="text-balance max-w-[13ch] text-4xl font-bold leading-[1.14] tracking-[-0.04em] text-white sm:text-5xl lg:text-6xl">
                        เรียนสนุก เข้าใจง่าย ไปถึงเป้าหมายด้วยกัน
                    </h1>
                    <p className="mt-6 max-w-[48ch] text-base leading-7 text-brand-50/90 sm:text-lg">
                        งาน ผลการเรียน ตารางเรียน สื่อ และข้อมูลสำคัญ รวมไว้ในระบบเดียวสำหรับนักศึกษา ครู และผู้ดูแล
                    </p>

                    <div className="mt-8 flex flex-wrap gap-2.5" aria-label="ข้อมูลสำคัญในระบบ">
                        {highlights.map((item) => (
                            <span key={item.label} className="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-3 py-2 text-xs font-bold text-white backdrop-blur-sm">
                                <span className="grid size-7 place-items-center rounded-lg bg-white/10 text-white">
                                    <item.icon size={16} weight="duotone" aria-hidden="true" />
                                </span>
                                {item.label}
                            </span>
                        ))}
                    </div>

                    <div className="mt-9 flex flex-col gap-3 sm:flex-row">
                        <Link
                            to="/login"
                            className="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-white px-6 py-3.5 font-bold text-brand-800 shadow-lg shadow-brand-950/20 transition-[transform,background-color] duration-150 hover:bg-brand-50 active:scale-[0.97]"
                        >
                            เริ่มใช้งานระบบ
                            <ArrowRight size={19} weight="bold" aria-hidden="true" />
                        </Link>
                        <Link
                            to="/login"
                            className="inline-flex items-center justify-center whitespace-nowrap rounded-xl border border-white/40 px-6 py-3.5 font-bold text-white transition-[transform,background-color] duration-150 hover:bg-white/10 active:scale-[0.97]"
                        >
                            เข้าสู่ระบบนักศึกษา
                        </Link>
                    </div>
                </div>
            </section>
        </main>
    );
}
