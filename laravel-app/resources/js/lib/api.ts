import type { ApiResponse } from '../types';

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

async function apiRequest<T>(path: string, init: RequestInit): Promise<ApiResponse<T>> {
    const districtId = window.localStorage.getItem('sena-district-id');
    const response = await fetch(path, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            ...(districtId ? { 'X-District-Id': districtId } : {}),
            ...init.headers,
        },
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => null) as {
            message?: string;
            errors?: Record<string, string[]>;
        } | null;
        throw new ApiError(
            payload?.message ?? 'ไม่สามารถดำเนินการได้',
            response.status,
            payload?.errors ?? {},
        );
    }

    return response.json() as Promise<ApiResponse<T>>;
}

export function apiGet<T>(path: string, signal?: AbortSignal): Promise<ApiResponse<T>> {
    return apiRequest<T>(path, { method: 'GET', signal });
}

export function apiPost<T>(path: string, body?: unknown): Promise<ApiResponse<T>> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    return apiRequest<T>(path, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify(body ?? {}),
    });
}
