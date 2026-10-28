import * as XLSX from 'xlsx';
import { strFromU8, strToU8, Unzip, UnzipInflate, unzipSync, zipSync, type UnzipFileInfo } from 'fflate';
import { createExcelFileBytes } from '../../../lib/excel.ts';
import type { Question, QuestionType } from './types';

export type QuizImport = { title?: string; questions: Question[] };

const FILE_LIMIT = 5 * 1024 * 1024;
const EXPANDED_LIMIT = 10 * 1024 * 1024;
const TYPES = new Set<QuestionType>(['single_choice', 'multiple_choice', 'true_false', 'short_answer', 'essay']);
const RESERVED_IDS = new Set(['__proto__', 'constructor', 'prototype']);
const OPTION_COLUMNS = Array.from({ length: 10 }, (_, index) => `option_${index + 1}`);
const COLUMNS = ['id', 'type', 'prompt', 'points', ...OPTION_COLUMNS, 'answer'];
const questionFields = new Set(['id', 'type', 'prompt', 'points', 'options', 'answer', 'image_id']);

function fail(message: string): never { throw new Error(message); }
function record(value: unknown): value is Record<string, unknown> { return value !== null && typeof value === 'object' && !Array.isArray(value); }
function rowError(row: number, message: string): never { return fail(`ข้อ ${row}: ${message}`); }
function nonEmptyText(value: unknown, maximum: number, row: number, field: string): string {
    if (typeof value !== 'string' || !value.trim() || value.length > maximum) rowError(row, `${field}ต้องเป็นข้อความที่ไม่ว่าง ความยาวไม่เกิน ${maximum} ตัวอักษร`);
    return value.trim();
}

function validateQuestions(values: unknown): Question[] {
    if (!Array.isArray(values) || values.length < 1 || values.length > 100) fail('ไฟล์ต้องมีคำถาม 1–100 ข้อ');
    const usedIds = new Set<string>();
    return values.map((value: unknown, index: number): Question => {
        const row = index + 1;
        if (!record(value)) rowError(row, 'รูปแบบคำถามไม่ถูกต้อง');
        if (Object.keys(value).some(key => !questionFields.has(key))) rowError(row, 'มีคอลัมน์หรือข้อมูลที่ไม่รองรับ ใช้รูปแบบตามเทมเพลต');
        const id = nonEmptyText(value.id, 64, row, 'id');
        if (!/^[A-Za-z0-9_-]+$/.test(id) || RESERVED_IDS.has(id)) rowError(row, 'id ใช้ได้เฉพาะอักษรอังกฤษ ตัวเลข ขีดกลาง และขีดล่าง');
        if (usedIds.has(id)) rowError(row, `id ซ้ำกับข้อก่อนหน้า (${id})`);
        usedIds.add(id);
        if (typeof value.type !== 'string' || !TYPES.has(value.type as QuestionType)) rowError(row, 'type ไม่รองรับ ใช้ single_choice, multiple_choice, true_false, short_answer หรือ essay');
        const type = value.type as QuestionType;
        const prompt = nonEmptyText(value.prompt, 5000, row, 'โจทย์');
        if (typeof value.points !== 'number' || !Number.isFinite(value.points) || value.points < 0.01 || value.points > 1000 || Math.abs(Math.round(value.points * 100) - value.points * 100) > 0.000001) rowError(row, 'คะแนนต้องเป็นตัวเลข 0.01–1000 และมีทศนิยมไม่เกิน 2 ตำแหน่ง');
        if (value.image_id !== undefined && value.image_id !== null) rowError(row, 'ไม่อนุญาตให้นำเข้า id รูปภาพ กรุณาอัปโหลดภาพโจทย์หลังนำเข้า');
        const rawOptions = value.options ?? [];
        if (!Array.isArray(rawOptions) || rawOptions.length > 10) rowError(row, 'ตัวเลือกต้องเป็นรายการไม่เกิน 10 ข้อ');
        const options = rawOptions.map(option => nonEmptyText(option, 1000, row, 'ตัวเลือก'));
        if (new Set(options).size !== options.length) rowError(row, 'ตัวเลือกต้องไม่ซ้ำกัน');
        const choice = type === 'single_choice' || type === 'multiple_choice';
        if (choice && options.length < 2) rowError(row, 'คำถามเลือกตอบต้องมีตัวเลือกอย่างน้อย 2 ข้อ');
        if (!choice && options.length > 0) rowError(row, 'คำถามชนิดนี้ไม่ใช้ตัวเลือก ให้เว้น options ว่าง');
        const question: Question = { id, type, prompt, points: value.points, options, image_id: null };
        if (type === 'essay') {
            if (value.answer !== undefined && value.answer !== null && value.answer !== '') rowError(row, 'อัตนัยไม่ต้องมีเฉลย ให้ครูตรวจภายหลัง');
        } else if (type === 'multiple_choice') {
            if (!Array.isArray(value.answer) || value.answer.length === 0 || value.answer.length > 10) rowError(row, 'เฉลยต้องเป็นรายการคำตอบอย่างน้อย 1 ข้อ');
            const answer = value.answer.map(item => nonEmptyText(item, 1000, row, 'เฉลย'));
            if (new Set(answer).size !== answer.length || answer.some(item => !options.includes(item))) rowError(row, 'เฉลยต้องเป็นตัวเลือกที่มีอยู่และไม่ซ้ำกัน');
            question.answer = answer;
        } else {
            const answer = nonEmptyText(value.answer, type === 'short_answer' ? 10000 : 1000, row, 'เฉลย');
            if (type === 'single_choice' && !options.includes(answer)) rowError(row, 'เฉลยไม่ตรงกับตัวเลือก');
            if (type === 'true_false' && !['true', 'false'].includes(answer)) rowError(row, 'เฉลยถูก/ผิดต้องเป็น true หรือ false');
            question.answer = answer;
        }
        return question;
    });
}

export function parseQuizJson(text: string): QuizImport {
    if (new TextEncoder().encode(text).length > FILE_LIMIT) fail('ไฟล์ใหญ่เกิน 5 MB');
    let parsed: unknown;
    try { parsed = JSON.parse(text.replace(/^\uFEFF/, '')); } catch { return fail('อ่าน JSON ไม่ได้ กรุณาตรวจรูปแบบเครื่องหมายและใช้ไฟล์เทมเพลต'); }
    if (!record(parsed) || parsed.version !== 1 || Object.keys(parsed).some(key => !['version', 'title', 'questions'].includes(key))) fail('JSON ต้องมี version: 1 และ questions ตามรูปแบบเทมเพลต');
    if (parsed.title !== undefined && (typeof parsed.title !== 'string' || !parsed.title.trim() || parsed.title.length > 220)) fail('ชื่อแบบทดสอบต้องเป็นข้อความไม่ว่างและยาวไม่เกิน 220 ตัวอักษร');
    return { ...(typeof parsed.title === 'string' ? { title: parsed.title.trim() } : {}), questions: validateQuestions(parsed.questions) };
}

/** Check both declared ZIP metadata and independently counted streaming output.
 * A fixed output buffer would truncate a forged originalSize and hide overflow.
 * SheetJS only receives a rebuilt archive after every actual size is checked. */
function boundedWorkbook(data: ArrayBuffer | Uint8Array): Uint8Array {
    const bytes = data instanceof Uint8Array ? data : new Uint8Array(data);
    if (bytes.length > FILE_LIMIT) fail('ไฟล์ใหญ่เกิน 5 MB');
    if (bytes.length < 4 || bytes[0] !== 0x50 || bytes[1] !== 0x4b || bytes[2] !== 3 || bytes[3] !== 4) fail('รองรับเฉพาะไฟล์ Excel .xlsx กรุณาบันทึกไฟล์ใหม่ตามเทมเพลต');
    let total = 0;
    let count = 0;
    const entries = new Map<string, UnzipFileInfo>();
    try {
        // Metadata only: returning false prevents unzipSync from allocating or
        // inflating an output buffer based on untrusted originalSize values.
        unzipSync(bytes, { filter(entry) {
            count += 1;
            total += entry.originalSize;
            if (count > 200 || total > EXPANDED_LIMIT || entry.originalSize > EXPANDED_LIMIT || entry.originalSize > Math.max(1, entry.size) * 200) fail('Excel มีข้อมูลขยายตัวมากเกินกำหนด กรุณาใช้ไฟล์เทมเพลตขนาดเล็ก');
            if (entries.has(entry.name) || /(^|[\\/])\.\.([\\/]|$)|^[/\\]|\\|\u0000/.test(entry.name)) fail('โครงสร้างไฟล์ Excel ไม่ปลอดภัย');
            if (/vbaProject|\.bin$|encryptedPackage/i.test(entry.name)) fail('ไม่รองรับ Excel ที่มีแมโครหรือไฟล์ที่เข้ารหัส');
            if (entry.compression !== 0 && entry.compression !== 8) fail('Excel ใช้รูปแบบบีบอัดที่ไม่รองรับ');
            entries.set(entry.name, entry);
            return false;
        } });
        const files: Record<string, Uint8Array> = Object.create(null);
        const started = new Set<string>();
        const finished = new Set<string>();
        let actualTotal = 0;
        const stream = new Unzip(file => {
            const expected = entries.get(file.name);
            if (!expected || started.has(file.name) || file.compression !== expected.compression
                || (file.size !== undefined && file.size !== expected.size)
                || (file.originalSize !== undefined && file.originalSize !== expected.originalSize)) fail('ข้อมูลขนาดไฟล์ Excel ไม่ตรงกันหรือโครงสร้างไม่ปลอดภัย');
            started.add(file.name);
            const chunks: Uint8Array[] = [];
            let actualSize = 0;
            file.ondata = (error, chunk, final) => {
                if (error) throw error;
                actualSize += chunk.length;
                actualTotal += chunk.length;
                if (actualSize > expected.originalSize || actualTotal > EXPANDED_LIMIT || actualSize > Math.max(1, expected.size) * 200) {
                    file.terminate();
                    fail('Excel มีข้อมูลขยายตัวจริงเกินขนาดที่ระบุหรือเกินกำหนด');
                }
                chunks.push(chunk.slice());
                if (final) {
                    if (actualSize !== expected.originalSize) fail('ข้อมูลขนาดไฟล์ Excel ไม่ตรงกับข้อมูลที่ขยายจริง');
                    const content = new Uint8Array(actualSize);
                    let offset = 0;
                    for (const part of chunks) { content.set(part, offset); offset += part.length; }
                    files[file.name] = content;
                    finished.add(file.name);
                }
            };
            file.start();
        });
        stream.register(UnzipInflate);
        // Small compressed inputs keep an individual inflater callback bounded
        // even if metadata lies about the actual expansion ratio.
        for (let offset = 0; offset < bytes.length; offset += 512) {
            stream.push(bytes.subarray(offset, offset + 512), offset + 512 >= bytes.length);
        }
        if (finished.size !== entries.size) fail('โครงสร้างไฟล์ Excel ไม่ครบถ้วน');
        if (!files['[Content_Types].xml'] || !files['xl/workbook.xml'] || !Object.keys(files).some(name => /^xl\/worksheets\/sheet\d+\.xml$/.test(name))) fail('ไฟล์นี้ไม่ใช่สมุดงาน .xlsx ที่รองรับ');
        // Some ordinary XLSX writers include an unused .bin default content type.
        // Only actual macro parts or a macro-enabled workbook override are rejected.
        if (/<Override\b[^>]*\bContentType\s*=\s*["'][^"']*(?:macroEnabled|vbaProject)/i.test(strFromU8(files['[Content_Types].xml']))) fail('ไม่รองรับ Excel ที่มีแมโคร');
        let expanded = 0;
        for (const [name, content] of Object.entries(files)) {
            expanded += content.length;
            if (expanded > EXPANDED_LIMIT) fail('ข้อมูลใน Excel ใหญ่เกินกำหนด');
            if (/^xl\/worksheets\/.*\.xml$/.test(name) && /<(?:[A-Za-z_][\w.-]*:)?f(?=[\s>/])/i.test(strFromU8(content))) fail('ไม่อนุญาตให้ใช้สูตรในไฟล์นำเข้า กรุณาแปลงเป็นค่าข้อความหรือตัวเลขก่อน');
        }
        return zipSync(files, { level: 0 });
    } catch (error) {
        if (error instanceof Error && /Excel|xlsx|ปลอดภัย|แมโคร|ข้อมูล|สูตร/.test(error.message)) throw error;
        return fail('อ่านโครงสร้าง Excel ไม่ได้ ไฟล์อาจเสียหายหรือมีการเข้ารหัส');
    }
}

function cellText(cell: XLSX.CellObject | undefined, row: number): string {
    if (!cell || cell.v === undefined || cell.v === null) return '';
    if (cell.f || cell.t === 'e' || typeof cell.v === 'object') fail(`แถว Excel ${row}: ไม่รองรับสูตร ค่า error หรือข้อมูลชนิดนี้`);
    return String(cell.v).trim();
}

export function parseQuizWorkbook(data: ArrayBuffer | Uint8Array): QuizImport {
    const bytes = boundedWorkbook(data);
    let workbook: XLSX.WorkBook;
    try { workbook = XLSX.read(bytes, { type: 'array', cellFormula: true, cellHTML: false, cellStyles: false, sheetRows: 102, bookVBA: false, bookDeps: false }); }
    catch { return fail('อ่าน Excel ไม่ได้ กรุณาเปิดและบันทึกเป็น .xlsx อีกครั้ง'); }
    const sheet = workbook.Sheets.Questions;
    if (!sheet || !sheet['!ref']) fail('ไม่พบชีต Questions กรุณาใช้ชื่อชีตตามเทมเพลต');
    const range = XLSX.utils.decode_range(sheet['!fullref'] ?? sheet['!ref']);
    if (range.e.r > 100 || range.e.c > 30 || range.s.r !== 0 || range.s.c !== 0) fail('ชีต Questions ต้องเริ่มที่ A1 และมีคำถามไม่เกิน 100 ข้อ โดยไม่เพิ่มคอลัมน์นอกเทมเพลต');
    const headers: string[] = [];
    const headerSet = new Set<string>();
    for (let column = 0; column <= range.e.c; column += 1) {
        const header = cellText(sheet[XLSX.utils.encode_cell({ r: 0, c: column })], 1);
        if (header && (!COLUMNS.includes(header) || headerSet.has(header))) fail(`หัวตาราง Excel ไม่ถูกต้องหรือซ้ำกัน: ${header}`);
        headers.push(header);
        if (header) headerSet.add(header);
    }
    if (['id', 'type', 'prompt', 'points', 'answer'].some(header => !headerSet.has(header))) fail('Excel ต้องมีหัวตาราง id, type, prompt, points และ answer ครบถ้วน');
    const values: Record<string, unknown>[] = [];
    const sourceRows: number[] = [];
    for (let row = 1; row <= range.e.r; row += 1) {
        const record: Record<string, string> = {};
        let hasData = false;
        for (let column = 0; column <= range.e.c; column += 1) {
            const value = cellText(sheet[XLSX.utils.encode_cell({ r: row, c: column })], row + 1);
            if (value) hasData = true;
            if (!headers[column] && value) fail(`แถว Excel ${row + 1}: พบข้อมูลในคอลัมน์ที่ไม่มีหัวตาราง`);
            if (headers[column]) record[headers[column]] = value;
        }
        if (!hasData) continue;
        const options: string[] = [];
        let foundBlank = false;
        for (const key of OPTION_COLUMNS) {
            if (!record[key]) { foundBlank = true; continue; }
            if (foundBlank) fail(`แถว Excel ${row + 1}: กรอกตัวเลือกต่อเนื่องจาก option_1 โดยไม่เว้นช่องกลาง`);
            options.push(record[key]);
        }
        let answer: string | string[] | undefined = record.answer;
        if (record.type === 'single_choice' || record.type === 'multiple_choice') {
            const positions = (record.answer ?? '').split(',').map(value => value.trim());
            if (positions.some(position => !/^(?:[1-9]|10)$/.test(position) || Number(position) > options.length)) fail(`แถว Excel ${row + 1}: เฉลยต้องใช้หมายเลขตัวเลือกที่มีอยู่ เช่น 1 หรือ 1,3`);
            answer = record.type === 'multiple_choice' ? positions.map(position => options[Number(position) - 1]) : positions.length === 1 ? options[Number(positions[0]) - 1] : undefined;
        }
        const points = /^(?:\d+)(?:\.\d{1,2})?$/.test(record.points ?? '') ? Number(record.points) : NaN;
        values.push({ id: record.id, type: record.type, prompt: record.prompt, points, options, answer, image_id: null });
        sourceRows.push(row + 1);
    }
    try { return { questions: validateQuestions(values) }; }
    catch (error) {
        if (error instanceof Error) {
            const match = /^ข้อ (\d+):/.exec(error.message);
            if (match) fail(error.message.replace(/^ข้อ \d+:/, `แถว Excel ${sourceRows[Number(match[1]) - 1]} (ข้อ ${match[1]}):`));
        }
        throw error;
    }
}

const exampleQuestions: Question[] = [
    { id: 'q1', type: 'single_choice', prompt: 'น้ำมีสูตรทางเคมีว่าอย่างไร?', points: 1, options: ['H2O', 'CO2', 'O2'], answer: 'H2O', image_id: null },
    { id: 'q2', type: 'multiple_choice', prompt: 'ข้อใดเป็นสิ่งมีชีวิต? เลือกทุกคำตอบที่ถูกต้อง', points: 2, options: ['ต้นไม้', 'หิน', 'แมว'], answer: ['ต้นไม้', 'แมว'], image_id: null },
    { id: 'q3', type: 'true_false', prompt: 'โลกโคจรรอบดวงอาทิตย์', points: 1, options: [], answer: 'true', image_id: null },
    { id: 'q4', type: 'short_answer', prompt: 'ดาวเคราะห์ที่เราอาศัยอยู่ชื่ออะไร?', points: 1, options: [], answer: 'โลก', image_id: null },
    { id: 'q5', type: 'essay', prompt: 'อธิบายวิธีประหยัดน้ำในชีวิตประจำวัน 2 วิธี', points: 5, options: [], image_id: null },
];

export function createQuizJsonTemplate(): string {
    return JSON.stringify({ version: 1, title: 'แบบทดสอบวิทยาศาสตร์', questions: exampleQuestions }, null, 2);
}

export function createQuizExcelTemplate(): Uint8Array {
    const rows = exampleQuestions.map(question => {
        let answer = question.answer ?? '';
        if (question.type === 'multiple_choice') answer = (question.answer as string[]).map(item => question.options.indexOf(item) + 1).join(',');
        if (question.type === 'single_choice') answer = String(question.options.indexOf(question.answer as string) + 1);
        return [question.id, question.type, question.prompt, question.points, ...Array.from({ length: 10 }, (_, index) => question.options[index] ?? ''), String(answer)];
    });
    const bytes = createExcelFileBytes([
        { name: 'Questions', columns: COLUMNS, rows },
        { name: 'Guide', columns: ['หัวข้อ', 'วิธีกรอก'], rows: [
            ['เริ่มต้น', 'แก้ตัวอย่างในชีต Questions มีคำถามได้ 1–100 ข้อ ห้ามแก้ชื่อชีตและหัวตาราง'],
            ['id', 'รหัสไม่ซ้ำกัน เช่น q1 ใช้ภาษาอังกฤษ ตัวเลข ขีดกลาง และขีดล่าง ความยาวไม่เกิน 64 ตัวอักษร'],
            ['prompt', 'โจทย์หรือคำถาม ความยาวไม่เกิน 5,000 ตัวอักษร'],
            ['points', 'คะแนนเป็นตัวเลข 0.01–1000 มีทศนิยมไม่เกิน 2 ตำแหน่ง'],
            ['single_choice', 'เลือกตอบ 1 ข้อ ใส่ตัวเลือกต่อเนื่องจาก option_1 อย่างน้อย 2 ข้อ answer ใส่หมายเลขเฉลย เช่น 1'],
            ['multiple_choice', 'เลือกหลายคำตอบ answer ใส่หมายเลขเฉลยคั่นด้วยจุลภาค เช่น 1,3 ต้องถูกครบชุดจึงได้คะแนน'],
            ['true_false', 'ถูก/ผิด เว้น option_1 ถึง option_10 ว่าง answer ใส่ true หรือ false'],
            ['short_answer', 'เติมคำ เว้นตัวเลือกว่าง answer ใส่ข้อความเฉลย ไม่สนตัวพิมพ์ใหญ่/เล็กและช่องว่างหัวท้าย'],
            ['essay', 'อัตนัย เว้นตัวเลือกและ answer ว่าง ครูตรวจและให้คะแนนภายหลัง'],
            ['ภาพโจทย์', 'อัปโหลดภาพในหน้าแก้ไขหลังนำเข้า ห้ามใส่ id รูปภาพหรือลิงก์ภาพลงในไฟล์'],
            ['ข้อมูลและสูตร', 'กรอกเฉพาะค่าข้อความหรือตัวเลข ไม่ใช้สูตร แมโคร หรือไฟล์ที่เข้ารหัส'],
            ['ก่อนบันทึก', 'ระบบจะแสดงตัวอย่างให้ตรวจสอบ การนำเข้าแทนที่คำถามในหน้าร่างและยังไม่บันทึกจนกดบันทึกกิจกรรม'],
        ] },
    ]);
    // Keep the reusable exporter unchanged. This wide authoring template needs
    // room for prompts and instructions rather than its compact report defaults.
    const files = unzipSync(bytes);
    files['xl/styles.xml'] = strToU8(strFromU8(files['xl/styles.xml']).replaceAll('<alignment vertical="center"/>', '<alignment vertical="center" wrapText="1"/>'));
    const widths = [14, 22, 65, 10, ...Array(10).fill(18), 24];
    const questionsColumns = widths.map((width, index) => `<col min="${index + 1}" max="${index + 1}" width="${width}" customWidth="1"/>`).join('');
    const guideColumns = '<col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="105" customWidth="1"/>';
    for (const [path, columns, rowHeight] of [
        ['xl/worksheets/sheet1.xml', questionsColumns, 48],
        ['xl/worksheets/sheet2.xml', guideColumns, 50],
    ] as const) {
        files[path] = strToU8(strFromU8(files[path])
            .replace(/<cols>.*?<\/cols>/, `<cols>${columns}</cols>`)
            .replace(/<row\b([^>]*)>/g, (_, attributes: string) => {
                const row = /\br="(\d+)"/.exec(attributes)?.[1];
                const clean = attributes.replace(/\s(?:ht|customHeight)="[^"]*"/g, '');
                return `<row${clean} ht="${row === '1' ? 32 : rowHeight}" customHeight="1">`;
            }));
    }
    return zipSync(files, { level: 6 });
}
