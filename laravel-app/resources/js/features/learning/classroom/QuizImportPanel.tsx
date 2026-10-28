import { Alert } from '@mui/material';
import { DownloadSimple, UploadSimple, FileXls, FileCode, CheckCircle, X } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button } from '../../../components/MaterialUI';
import type { QuizImport } from './quizImport';
import { questionLabels } from './types';

function download(bytes: BlobPart, name: string, type: string) {
    const url = URL.createObjectURL(new Blob([bytes], { type }));
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = name; document.body.appendChild(anchor); anchor.click(); anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function QuizTemplateActions({ compact = false }: { compact?: boolean }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    async function template(format: 'json' | 'xlsx') {
        setBusy(true); setError('');
        try {
            const helper = await import('./quizImport');
            if (format === 'json') download(helper.createQuizJsonTemplate(), 'classroom-quiz-template.json', 'application/json;charset=utf-8');
            else download(new Uint8Array(helper.createQuizExcelTemplate()).buffer, 'classroom-quiz-template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        } catch (cause) { setError(cause instanceof Error ? cause.message : 'ดาวน์โหลดเทมเพลตไม่ได้ กรุณาลองอีกครั้ง'); }
        finally { setBusy(false); }
    }
    return <div className="classroom-template-actions">
        {!compact && <span className="classroom-muted"><DownloadSimple size={17} /> ดาวน์โหลดเทมเพลตตัวอย่าง</span>}
        <Button size="small" appearance="outline" icon={<FileCode size={19} />} disabled={busy} onClick={() => void template('json')}>เทมเพลต JSON</Button>
        <Button size="small" appearance="outline" icon={<FileXls size={19} />} disabled={busy} onClick={() => void template('xlsx')}>เทมเพลต Excel</Button>
        {error && <Alert severity="error">{error}</Alert>}
    </div>;
}

export function QuizImportPanel({ disabled, existingCount, apply }: { disabled: boolean; existingCount: number; apply: (value: QuizImport) => void }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [preview, setPreview] = useState<{ filename: string; value: QuizImport } | null>(null);
    const [applied, setApplied] = useState<number | null>(null);
    async function read(file: File) {
        setBusy(true); setError(''); setPreview(null); setApplied(null);
        try {
            if (file.size > 5 * 1024 * 1024) throw new Error('ไฟล์ต้องมีขนาดไม่เกิน 5 MB');
            if (!/\.(json|xlsx)$/i.test(file.name)) throw new Error('รองรับไฟล์ .json และ .xlsx กรุณาใช้เทมเพลตที่ดาวน์โหลดจากหน้านี้');
            const helper = await import('./quizImport');
            const value = /\.json$/i.test(file.name) ? helper.parseQuizJson(await file.text()) : helper.parseQuizWorkbook(await file.arrayBuffer());
            setPreview({ filename: file.name, value });
        } catch (cause) { setError(cause instanceof Error ? cause.message : 'อ่านไฟล์ไม่ได้ กรุณาตรวจรูปแบบไฟล์'); }
        finally { setBusy(false); }
    }
    return <section className="classroom-import-panel" aria-label="นำเข้าข้อสอบจากไฟล์">
        <div className="classroom-import-heading"><span className="classroom-import-icon"><UploadSimple size={26} /></span><div><h3>มีข้อสอบอยู่แล้ว? นำเข้าได้เลย</h3><p>JSON หรือ Excel (.xlsx) ไม่เกิน 5 MB · สูงสุด 100 ข้อ</p></div></div>
        <QuizTemplateActions />
        <div className="classroom-import-actions"><Button as="label" appearance="primary" icon={<UploadSimple size={18} />} disabled={disabled || busy}>{busy ? 'กำลังตรวจไฟล์…' : 'เลือกไฟล์ข้อสอบ'}<input aria-label="เลือกไฟล์ข้อสอบ JSON หรือ Excel" type="file" hidden accept=".json,.xlsx,application/json,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" disabled={disabled || busy} onChange={event => { const file = event.target.files?.[0]; event.target.value = ''; if (file) void read(file); }} /></Button><span className="classroom-muted">ตรวจตัวอย่างก่อนนำมาแทนคำถามในแบบร่าง</span></div>
        {error && <Alert severity="error" className="mt-3">{error}</Alert>}
        {applied !== null && <Alert severity="success" role="status" className="mt-3">นำคำถาม {applied} ข้อมาไว้ในแบบร่างแล้ว ตรวจทานด้านล่างแล้วกดบันทึกกิจกรรม</Alert>}
        {preview && <div className="classroom-import-preview" role="region" aria-label="ตรวจสอบข้อสอบก่อนนำเข้า">
            <div className="classroom-import-preview-title"><CheckCircle size={22} weight="fill" /><strong>ตรวจไฟล์แล้ว · {preview.value.questions.length} ข้อ</strong><Button size="small" aria-label="ปิดตัวอย่างนำเข้า" icon={<X />} onClick={() => setPreview(null)} /></div>
            <p className="break-all text-sm">{preview.filename}</p>
            <p className="my-2 text-sm">คะแนนรวม {Number(preview.value.questions.reduce((sum, question) => sum + question.points, 0).toFixed(2))} คะแนน · {preview.value.title || 'ใช้ชื่อกิจกรรมที่คุณกำหนด'}</p>
            <ol className="classroom-import-question-preview">{preview.value.questions.map((question, index) => <li key={question.id}><span>{index + 1}</span><div><strong>{question.prompt}</strong><small>{questionLabels[question.type]} · {question.points} คะแนน</small></div></li>)}</ol>
            <Alert severity="info">การนำเข้าจะแทนที่คำถามเดิม {existingCount} ข้อในแบบร่าง ยังไม่บันทึกหรือเผยแพร่ คุณแก้ไขและอัปโหลดภาพโจทย์ต่อได้</Alert>
            <Button appearance="primary" disabled={disabled || busy} className="mt-3" onClick={() => { apply(preview.value); setApplied(preview.value.questions.length); setPreview(null); }}>ใช้ข้อสอบ {preview.value.questions.length} ข้อในแบบร่าง</Button>
        </div>}
    </section>;
}
