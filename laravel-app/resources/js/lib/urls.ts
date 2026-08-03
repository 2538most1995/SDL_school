function normalizeBasePath(value: string): string {
    const trimmed = value.trim();
    if (trimmed === '' || trimmed === '/') return '';

    return `/${trimmed.replace(/^\/+|\/+$/g, '')}`;
}

const configuredBasePath = document.querySelector<HTMLMetaElement>('meta[name="app-base-path"]')?.content ?? '';

export const APP_BASE_PATH = normalizeBasePath(configuredBasePath);

export function withAppBasePath(path: string): string {
    if (APP_BASE_PATH === '' || path === '' || !path.startsWith('/') || path.startsWith('//')) return path;
    if (path === APP_BASE_PATH || path.startsWith(`${APP_BASE_PATH}/`)) return path;

    return `${APP_BASE_PATH}${path}`;
}
