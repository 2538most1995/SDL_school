import { ApiError, apiGet } from '../lib/api';
import type { ApiResponse } from '../types';
import { withAppBasePath } from '../lib/urls';

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

function parseJsonText<T>(raw: string): T | null {
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

async function readJsonResponse<T>(response: Response): Promise<T | null> {
    const raw = await response.text();
    return parseJsonText<T>(raw);
}

function responseError<T extends { message?: string; errors?: Record<string, string[]> }>(result: T | null, status: number): ApiError {
    const firstValidationError = result?.errors ? Object.values(result.errors).flat()[0] : undefined;
    const responseMessage = result?.message?.trim();
    const usefulResponseMessage = status >= 500 && responseMessage?.toLocaleLowerCase() === 'server error'
        ? undefined
        : responseMessage;
    const statusMessages: Record<number, string> = {
        413: 'ไฟล์มีขนาดเกินค่าที่เซิร์ฟเวอร์อนุญาต',
        419: 'เซสชันหมดอายุ กรุณารีเฟรชหน้าและเข้าสู่ระบบอีกครั้ง',
        429: 'มีการส่งข้อมูลถี่เกินไป กรุณารอสักครู่แล้วลองใหม่',
        500: 'เซิร์ฟเวอร์หยุดทำงานระหว่างประมวลผล กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ',
        503: 'ระบบนำเข้าข้อมูลยังไม่พร้อมใช้งาน',
    };

    return new ApiError(firstValidationError ?? usefulResponseMessage ?? statusMessages[status] ?? `ไม่สามารถบันทึกข้อมูลได้ (HTTP ${status})`, status, result?.errors ?? {});
}

export async function sendFeatureData<T>(path: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', payload?: unknown): Promise<ApiResponse<T>> {
    const isForm = payload instanceof FormData;
    const token = csrfToken();
    const districtId = window.localStorage.getItem('sena-district-id');
    let response: Response;
    try {
        response = await fetch(withAppBasePath(path), {
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
        throw responseError(result, response.status);
    }

    if (!result) {
        throw new ApiError('เซิร์ฟเวอร์ส่งข้อมูลตอบกลับไม่สมบูรณ์ กรุณาลองใหม่อีกครั้ง', response.status);
    }

    return result;
}

export async function getFeatureBlob(path: string): Promise<Blob> {
    const districtId = window.localStorage.getItem('sena-district-id');
    let response: Response;
    try {
        response = await fetch(withAppBasePath(path), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/pdf,image/jpeg,image/png,image/webp',
                ...(districtId ? { 'X-District-Id': districtId } : {}),
            },
        });
    } catch {
        throw new ApiError('ไม่สามารถเชื่อมต่อเพื่อเปิดไฟล์ได้ กรุณาตรวจอินเทอร์เน็ตแล้วลองใหม่', 0);
    }

    if (!response.ok) {
        const result = await readJsonResponse<{ message?: string; errors?: Record<string, string[]> }>(response);
        throw responseError(result, response.status);
    }

    return response.blob();
}

export function uploadFeatureData<T>(path: string, payload: FormData, onProgress: (percentage: number) => void): Promise<ApiResponse<T>> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        const token = csrfToken();
        const districtId = window.localStorage.getItem('sena-district-id');
        request.open('POST', withAppBasePath(path), true);
        request.withCredentials = true;
        request.timeout = 10 * 60 * 1_000;
        request.setRequestHeader('Accept', 'application/json');
        if (districtId) request.setRequestHeader('X-District-Id', districtId);
        if (token) {
            request.setRequestHeader('X-CSRF-TOKEN', token);
            request.setRequestHeader('X-XSRF-TOKEN', token);
        }
        request.upload.onprogress = (event) => {
            if (event.lengthComputable && event.total > 0) {
                onProgress(Math.min(100, Math.round((event.loaded / event.total) * 100)));
            }
        };
        request.onerror = () => reject(new ApiError('การเชื่อมต่อกับเซิร์ฟเวอร์ขาดระหว่างอัปโหลดไฟล์ กรุณาลองใหม่อีกครั้ง', 0));
        request.ontimeout = () => reject(new ApiError('อัปโหลดไฟล์ใช้เวลานานเกินกำหนด กรุณาตรวจอินเทอร์เน็ตแล้วลองใหม่', 0));
        request.onload = () => {
            const result = parseJsonText<ApiResponse<T> & { message?: string; errors?: Record<string, string[]> }>(request.responseText ?? '');
            if (request.status < 200 || request.status >= 300) {
                reject(responseError(result, request.status));
                return;
            }
            if (!result) {
                reject(new ApiError('เซิร์ฟเวอร์ส่งข้อมูลตอบกลับไม่สมบูรณ์ กรุณาลองใหม่อีกครั้ง', request.status));
                return;
            }
            onProgress(100);
            resolve(result);
        };
        request.send(payload);
    });
}
