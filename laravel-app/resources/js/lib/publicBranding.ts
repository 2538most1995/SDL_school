import type { CSSProperties } from 'react';
import { withAppBasePath } from './urls';

export const DEFAULT_HERO_IMAGE = withAppBasePath('/images/sena-students-hero.png');

export type PublicBranding = {
    schoolName: string;
    portalName: string;
    welcomeMessage: string;
    primaryColor: string;
    districtId: number;
    districtName: string;
    logoImageUrl: string | null;
    hasCustomLogo: boolean;
    heroImageUrl: string;
    hasCustomHero: boolean;
    dashboardHeroImageUrl: string;
    hasCustomDashboardHero: boolean;
    loginMode: 'student_credentials' | 'local';
};

export function publicBrandingPath(districtId?: string | null): string {
    return districtId ? `/api/v1/auth/branding?district_id=${encodeURIComponent(districtId)}` : '/api/v1/auth/branding';
}

export function publicAssetUrl(path?: string | null): string | null {
    return path ? withAppBasePath(path) : null;
}

function mixHex(color: string, target: '#ffffff' | '#000000', targetWeight: number): string {
    const normalized = /^#[0-9a-f]{6}$/i.test(color) ? color.slice(1) : '2563eb';
    const source = [0, 2, 4].map((offset) => Number.parseInt(normalized.slice(offset, offset + 2), 16));
    const destination = target === '#ffffff' ? 255 : 0;
    const mixed = source.map((channel) => Math.round(channel + ((destination - channel) * targetWeight)));
    return `#${mixed.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
}

export function publicBrandingCssVariables(primaryColor?: string): CSSProperties {
    const color = /^#[0-9a-f]{6}$/i.test(primaryColor ?? '') ? primaryColor! : '#2563eb';
    return {
        '--ui-accent-50': mixHex(color, '#ffffff', 0.94),
        '--ui-accent-100': mixHex(color, '#ffffff', 0.86),
        '--ui-accent-200': mixHex(color, '#ffffff', 0.72),
        '--ui-accent-300': mixHex(color, '#ffffff', 0.52),
        '--ui-accent-400': mixHex(color, '#ffffff', 0.28),
        '--ui-accent-500': color,
        '--ui-accent-600': mixHex(color, '#000000', 0.1),
        '--ui-accent-700': mixHex(color, '#000000', 0.22),
        '--ui-accent-800': mixHex(color, '#000000', 0.36),
        '--ui-accent-900': mixHex(color, '#000000', 0.5),
        '--ui-accent-950': mixHex(color, '#000000', 0.64),
    } as CSSProperties;
}
