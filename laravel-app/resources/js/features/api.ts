import { ApiError, apiGet } from '../lib/api';
import type { ApiResponse } from '../types';

export function getFeatureData<T>(path: string, signal?: AbortSignal): Promise<ApiResponse<T>> {
    return apiGet<T>(path, signal);
}

export async function getFeatureDataWithDemo<T>(path: string, demo: T, signal?: AbortSignal): Promise<ApiResponse<T>> {
    void demo;

    return apiGet<T>(path, signal);
}

function csrfToken(): string {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    if (meta) return meta;

    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : '';
}

function firstJsonValue(raw: string): string | null {
    const objectStart = raw.indexOf('{');
    const arrayStart = raw.indexOf('[');
    const start = objectStart < 0 ? arrayStart : arrayStart < 0 ? objectStart : Math.min(objectStart, arrayStart);
    if (start < 0) return null;

    const opening = raw[start];
    const closing = opening === '{' ? '}' : ']';
    let depth = 0;
    let inString = false;
    let escaped = false;

    for (let index = start; index < raw.length; index += 1) {
        const character = raw[index];
        if (inString) {
            if (escaped) escaped = false;
            else if (character === '\\') escaped = true;
            else if (character === '"') inString = false;
            continue;
        }
        if (character === '"') {
            inString = true;
            continue;
        }
        if (character === opening) depth += 1;
        else if (character === closing) {
            depth -= 1;
            if (depth === 0) return raw.slice(start, index + 1);
        }
    }

    return null;
}

async function readJsonResponse<T>(response: Response): Promise<T | null> {
    const raw = await response.text();
    if (!raw.trim()) return null;

    try {
        return JSON.parse(raw) as T;
    } catch {
        const json = firstJsonValue(raw);
        if (!json) return null;
        try {
            return JSON.parse(json) as T;
        } catch {
            return null;
        }
    }
}

export async function sendFeatureData<T>(path: string, method: 'POST' | 'PATCH' | 'DELETE', payload?: unknown): Promise<ApiResponse<T>> {
    const isForm = payload instanceof FormData;
    const token = csrfToken();
    const districtId = window.localStorage.getItem('sena-district-id');
    let response: Response;
    try {
        response = await fetch(path, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(districtId ? { 'X-District-Id': districtId } : {}),
                ...(isForm ? {} : { 'Content-Type': 'application/json' }),
                ...(token ? { 'X-CSRF-TOKEN': token, 'X-XSRF-TOKEN': token } : {}),
            },
            body: payload === undefined ? undefined : isForm ? payload : JSON.stringify(payload),
        });
    } catch {
        throw new ApiError('การเชื่อมต่อกับเซิร์ฟเวอร์ขาดระหว่างส่งข้อมูล กรุณาตรวจการเชื่อมต่อแล้วลองอีกครั้ง', 0);
    }

    const result = await readJsonResponse<ApiResponse<T> & { message?: string; errors?: Record<string, string[]> }>(response);

    if (!response.ok) {
        const firstValidationError = result?.errors ? Object.values(result.errors).flat()[0] : undefined;
        const statusMessages: Record<number, string> = {
            413: 'ไฟล์มีขนาดเกินค่าที่เซิร์ฟเวอร์อนุญาต',
            419: 'เซสชันหมดอายุ กรุณารีเฟรชหน้าและเข้าสู่ระบบอีกครั้ง',
            429: 'มีการส่งข้อมูลถี่เกินไป กรุณารอสักครู่แล้วลองใหม่',
            500: 'เซิร์ฟเวอร์หยุดทำงานระหว่างประมวลผล กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ',
            503: 'ระบบนำเข้าข้อมูลยังไม่พร้อมใช้งาน',
        };
        throw new ApiError(firstValidationError ?? result?.message ?? statusMessages[response.status] ?? `ไม่สามารถบันทึกข้อมูลได้ (HTTP ${response.status})`, response.status, result?.errors ?? {});
    }

    if (!result) {
        throw new ApiError('เซิร์ฟเวอร์ส่งข้อมูลตอบกลับไม่สมบูรณ์ กรุณาลองใหม่อีกครั้ง', response.status);
    }

    return result;
}
