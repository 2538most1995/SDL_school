export type UiMode = 'light' | 'dark' | 'system';
export type ColorScheme = 'blue' | 'teal' | 'violet' | 'rose' | 'amber';
export type FontSize = 'normal' | 'large';
export type Density = 'comfortable' | 'compact';

export type AppearanceSettings = {
    theme: UiMode;
    colorScheme: ColorScheme;
    fontSize: FontSize;
    density: Density;
};

export const DEFAULT_APPEARANCE: AppearanceSettings = {
    theme: 'system',
    colorScheme: 'blue',
    fontSize: 'normal',
    density: 'comfortable',
};

const storageKey = 'sena-appearance';
export const APPEARANCE_CHANGE_EVENT = 'sena:appearance-change';
let mediaQuery: MediaQueryList | null = null;
let mediaListener: (() => void) | null = null;

function notifyAppearance(settings: AppearanceSettings): void {
    window.dispatchEvent(new CustomEvent<AppearanceSettings>(APPEARANCE_CHANGE_EVENT, { detail: settings }));
}

function isAppearance(value: unknown): value is AppearanceSettings {
    if (!value || typeof value !== 'object') return false;
    const candidate = value as Partial<AppearanceSettings>;
    return ['light', 'dark', 'system'].includes(candidate.theme ?? '')
        && ['blue', 'teal', 'violet', 'rose', 'amber'].includes(candidate.colorScheme ?? '')
        && ['normal', 'large'].includes(candidate.fontSize ?? '')
        && ['comfortable', 'compact'].includes(candidate.density ?? '');
}

function resolvedMode(theme: UiMode): 'light' | 'dark' {
    if (theme !== 'system') return theme;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function applyAppearance(settings: AppearanceSettings, persist = true): void {
    const root = document.documentElement;
    root.dataset.uiMode = resolvedMode(settings.theme);
    root.dataset.themePreference = settings.theme;
    root.dataset.colorScheme = settings.colorScheme;
    root.dataset.fontSize = settings.fontSize;
    root.dataset.density = settings.density;
    root.style.colorScheme = root.dataset.uiMode;

    if (persist) window.localStorage.setItem(storageKey, JSON.stringify(settings));
    notifyAppearance(settings);

    if (mediaQuery && mediaListener) mediaQuery.removeEventListener('change', mediaListener);
    mediaQuery = null;
    mediaListener = null;
    if (settings.theme === 'system') {
        mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        mediaListener = () => {
            root.dataset.uiMode = mediaQuery?.matches ? 'dark' : 'light';
            root.style.colorScheme = root.dataset.uiMode;
            notifyAppearance(settings);
        };
        mediaQuery.addEventListener('change', mediaListener);
    }
}

export function loadStoredAppearance(): AppearanceSettings {
    try {
        const stored = JSON.parse(window.localStorage.getItem(storageKey) ?? 'null');
        return isAppearance(stored) ? stored : DEFAULT_APPEARANCE;
    } catch {
        return DEFAULT_APPEARANCE;
    }
}

export function initializeAppearance(): void {
    applyAppearance(loadStoredAppearance(), false);
}
