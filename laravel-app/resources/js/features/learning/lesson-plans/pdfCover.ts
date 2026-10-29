import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

const workerAddress = new URL(workerUrl, import.meta.url).href;
GlobalWorkerOptions.workerSrc = workerAddress;
const assetBase = new URL(import.meta.env.DEV ? '/pdfjs/' : '../pdfjs/', workerAddress).href;

/** Render only page one locally. PDF bytes never go to a thumbnail service. */
export async function renderPdfCover(file: File): Promise<{ blob: Blob; pages: number }> {
    if (!/\.pdf$/i.test(file.name) || file.size === 0 || file.size > 20 * 1024 * 1024) {
        throw new Error('เลือกไฟล์ PDF ขนาดไม่เกิน 20 MB');
    }
    const header = new TextDecoder().decode(await file.slice(0, 1024).arrayBuffer());
    if (!header.includes('%PDF-')) throw new Error('ไฟล์นี้ไม่ใช่ PDF ที่อ่านได้');
    const task = getDocument({
        data: new Uint8Array(await file.arrayBuffer()),
        cMapUrl: `${assetBase}cmaps/`,
        standardFontDataUrl: `${assetBase}standard_fonts/`,
        wasmUrl: `${assetBase}wasm/`,
        useSystemFonts: true,
        enableXfa: false,
        maxImageSize: 16_777_216,
        stopAtErrors: true,
    });
    let timer: ReturnType<typeof setTimeout> | undefined;
    const canvas = document.createElement('canvas');
    const operation = async () => {
        const pdf = await task.promise;
        if (pdf.numPages < 1 || pdf.numPages > 10_000) throw new Error('PDF ต้องมีจำนวนหน้าไม่เกิน 10,000 หน้า');
        const page = await pdf.getPage(1);
        const original = page.getViewport({ scale: 1 });
        if (!Number.isFinite(original.width) || !Number.isFinite(original.height) || original.width <= 0 || original.height <= 0) throw new Error('ขนาดหน้า PDF ไม่ถูกต้อง');
        const viewport = page.getViewport({ scale: Math.min(900 / original.width, 1400 / original.height) });
        canvas.width = Math.max(1, Math.ceil(viewport.width));
        canvas.height = Math.max(1, Math.ceil(viewport.height));
        await page.render({ canvas, viewport, background: '#ffffff' }).promise;
        const blob = await new Promise<Blob>((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('สร้างภาพปกไม่สำเร็จ')), 'image/jpeg', .86));
        if (blob.size > 2 * 1024 * 1024) throw new Error('ภาพปกมีขนาดใหญ่เกินไป กรุณาลดขนาด PDF แล้วลองใหม่');
        return { blob, pages: pdf.numPages };
    };
    try {
        return await Promise.race([
            operation(),
            new Promise<never>((_, reject) => { timer = setTimeout(() => reject(new Error('สร้างปกใช้เวลานานเกินไป กรุณาตรวจไฟล์ PDF แล้วลองใหม่')), 30_000); }),
        ]);
    } catch (error) {
        if (error instanceof Error && error.name === 'PasswordException') throw new Error('PDF มีรหัสผ่าน กรุณาใช้สำเนาที่ปลดรหัสแล้ว');
        if (error instanceof Error && error.name === 'InvalidPDFException') throw new Error('อ่าน PDF ไม่ได้ ไฟล์อาจเสียหาย กรุณาเปิดตรวจสอบก่อนอัปโหลด');
        throw error instanceof Error ? error : new Error('อ่าน PDF ไม่สำเร็จ กรุณาตรวจไฟล์แล้วลองใหม่');
    } finally {
        if (timer) clearTimeout(timer);
        await task.destroy();
        canvas.width = 0;
        canvas.height = 0;
    }
}
