import { keepPreviousData, useQuery } from '@tanstack/react-query';
import {
    Buildings,
    CalendarBlank,
    CaretLeft,
    CaretRight,
    ChartBar,
    ChartPieSlice,
    FileText,
    FileXls,
    FloppyDisk,
    GearSix,
    Info,
    MagnifyingGlass,
    Play,
    Printer,
    Rows,
    SignOut,
    SlidersHorizontal,
    Trash,
    UsersThree,
    X,
} from '@phosphor-icons/react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { apiGet } from '../../lib/api';
import { showErrorAlert, showSuccessAlert } from '../../lib/feedback';
import type { PortalData } from '../../types';
import { getFeatureDataWithDemo } from '../api';
import {
    canExportRegistrationStatistics,
    type CategoryKey,
} from './registrationStatisticsExport';
import {
    buildStatisticsCrossTabSheets,
    createDefaultAxisConfiguration,
    genericPayloadToCrossTab,
    reportById,
    statisticCategories,
    statisticReports,
    supportedCategoryKeys,
    type GenericReportPayload,
    type StatisticAxisConfiguration,
    type StatisticCrossTabPayload,
    type StatisticOrientation,
    type StatisticReportDefinition,
    type StatisticReportId,
} from './statisticsWorkspace';

type PeriodType = 'semester' | 'academic-year' | 'budget-year';
type DisplayScope = 'district' | 'province';
type WorkspaceRequest = {
    report: StatisticReportDefinition;
    term: string;
    configuration: StatisticAxisConfiguration;
};
type WorkspaceResponse = { crossTab: StatisticCrossTabPayload; selectedTerm: string | null };
type CurrentUser = { districts: Array<{ id: number; name: string; code: string }> };

const emptyCrossTabPayload: StatisticCrossTabPayload = {
    categories: [],
    row_categories: [{ key: 'level', label: 'ระดับชั้น' }],
    column_categories: [{ key: 'group', label: 'รหัสกลุ่ม' }],
    terms: [],
    selected_term: null,
    summary: { registered_students: 0, row_count: 0, column_count: 0, non_zero_cells: 0, largest_cell: null },
    rows: [],
    columns: [],
};
const emptyGenericPayload: GenericReportPayload = { total: 0, active: 0, groups: 0, rows: [] };
const pageSize = 10;

function inputClassName(disabled = false): string {
    return `h-11 w-full rounded-xl border px-3 text-sm font-semibold outline-none transition-[border-color,box-shadow,background-color] duration-150 ${disabled
        ? 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400'
        : 'border-slate-300 bg-white text-slate-900 focus:border-brand-600 focus:ring-4 focus:ring-brand-100'}`;
}

function ActionButton({ children, tone = 'neutral', disabled = false, onClick }: {
    children: ReactNode;
    tone?: 'primary' | 'neutral' | 'success' | 'danger';
    disabled?: boolean;
    onClick: () => void;
}) {
    const tones = {
        primary: 'border-brand-700 bg-brand-700 text-white shadow-sm shadow-brand-200 hover:bg-brand-800',
        neutral: 'border-brand-200 bg-white text-brand-800 hover:border-brand-400 hover:bg-brand-50',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:border-emerald-400 hover:bg-emerald-100',
        danger: 'border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-400 hover:bg-rose-100',
    };
    return (
        <button type="button" disabled={disabled} onClick={onClick} className={`inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border px-4 text-sm font-black transition-[transform,background-color,border-color,color,box-shadow] duration-150 active:scale-[0.97] disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400 disabled:shadow-none disabled:active:scale-100 ${tones[tone]}`}>
            {children}
        </button>
    );
}

function InformationCard({ label, value, detail, icon: Icon, tone }: {
    label: string;
    value: string;
    detail: string;
    icon: typeof UsersThree;
    tone: 'sky' | 'emerald' | 'amber' | 'rose';
}) {
    const tones = {
        sky: 'border-sky-200 bg-sky-50 text-sky-800',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        amber: 'border-amber-200 bg-amber-50 text-amber-800',
        rose: 'border-rose-200 bg-rose-50 text-rose-800',
    };
    return <article className={`flex min-w-0 items-center gap-3 rounded-2xl border px-4 py-3.5 ${tones[tone]}`}>
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-white/80 shadow-sm"><Icon size={21} weight="duotone" /></span>
        <div className="min-w-0"><p className="truncate text-xs font-black">{label}</p><p className="mt-0.5 truncate text-xl font-black tracking-[-0.02em] text-slate-950">{value}</p><p className="truncate text-[11px] text-slate-500">{detail}</p></div>
    </article>;
}

function ResultInformationSummary({ crossTab }: { crossTab: StatisticCrossTabPayload }) {
    const largest = crossTab.summary.largest_cell;
    const largestRow = largest ? crossTab.rows.find((row) => row.key === largest.row_key) : null;
    const largestColumn = largest ? crossTab.columns.find((column) => column.key === largest.column_key) : null;
    return <aside aria-label="สรุปสารสนเทศท้ายตาราง" className="mt-4 overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 via-white to-sky-50">
        <div className="flex flex-col gap-1 border-b border-brand-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <h3 className="flex items-center gap-2 text-sm font-black text-brand-950"><ChartPieSlice size={19} weight="duotone" />สรุปสารสนเทศท้ายตาราง</h3>
            <p className="text-xs text-slate-500">สรุปจากจุดตัดของแกนตั้งและแกนนอน โดยนับผู้เรียนไม่ซ้ำ</p>
        </div>
        <div className="grid gap-px bg-brand-100 sm:grid-cols-2 xl:grid-cols-4">
            <div className="bg-white/90 px-4 py-3"><p className="text-xs font-bold text-slate-500">รวมทั้งหมด</p><p className="mt-1 text-2xl font-black text-brand-800">{crossTab.summary.registered_students.toLocaleString('th-TH')} <span className="text-sm">คน</span></p></div>
            <div className="bg-white/90 px-4 py-3"><p className="text-xs font-bold text-slate-500">ชุดข้อมูลแนวตั้ง</p><p className="mt-1 text-2xl font-black text-slate-950">{crossTab.summary.row_count.toLocaleString('th-TH')} <span className="text-sm">แถว</span></p></div>
            <div className="bg-white/90 px-4 py-3"><p className="text-xs font-bold text-slate-500">ชุดข้อมูลแนวนอน</p><p className="mt-1 text-2xl font-black text-slate-950">{crossTab.summary.column_count.toLocaleString('th-TH')} <span className="text-sm">คอลัมน์</span></p></div>
            <div className="bg-white/90 px-4 py-3"><p className="text-xs font-bold text-slate-500">จุดตัดสูงสุด</p><p className="mt-1 line-clamp-2 text-sm font-black text-emerald-700">{largest && largestRow && largestColumn ? `${largestRow.label} × ${largestColumn.label} · ${largest.count.toLocaleString('th-TH')} คน` : '-'}</p></div>
        </div>
    </aside>;
}

function CrossTabResultTable({ crossTab, page, onPageChange, showPagination = true }: { crossTab: StatisticCrossTabPayload; page: number; onPageChange: (page: number) => void; showPagination?: boolean }) {
    const pageCount = Math.max(1, Math.ceil(crossTab.rows.length / pageSize));
    const visibleRows = showPagination ? crossTab.rows.slice((page - 1) * pageSize, page * pageSize) : crossTab.rows;
    const hasRows = crossTab.rows.length > 0;
    return (
        <>
            {hasRows && <div className="mb-4 grid gap-3 rounded-xl border border-brand-200 bg-brand-50/70 px-4 py-3 sm:grid-cols-2"><div><p className="text-xs font-black text-indigo-700">แกนตั้ง · เรียงเป็นแถว</p><p className="mt-1 text-sm font-black text-slate-950">{crossTab.row_categories.map((item) => item.label).join(' › ')}</p></div><div><p className="text-xs font-black text-sky-700">แกนนอน · ขยายเป็นคอลัมน์</p><p className="mt-1 text-sm font-black text-slate-950">{crossTab.column_categories.map((item) => item.label).join(' › ')}</p></div></div>}
            <div className="overflow-x-auto rounded-2xl border border-slate-200">
                <table className="w-full min-w-max border-collapse text-sm">
                    <thead><tr className="bg-gradient-to-b from-brand-50 to-sky-50 text-brand-950"><th className="sticky left-0 z-[2] min-w-64 border-b border-r border-slate-200 bg-brand-50 px-4 py-3 text-left"><span className="block text-xs font-bold text-indigo-700">ข้อมูลแนวตั้ง</span><span className="mt-0.5 block font-black">{crossTab.row_categories.map((item) => item.label).join(' › ')}</span></th>{crossTab.columns.map((column) => <th key={column.key} className="min-w-36 border-b border-r border-slate-200 px-3 py-3 text-center align-top">{column.parts.map((part) => <span key={`${column.key}-${part.category}`} className="block"><span className="block text-[10px] font-bold text-sky-700">{part.category_label}</span><span className="block font-black text-slate-900">{part.label}</span></span>)}</th>)}<th className="min-w-28 border-b border-slate-200 bg-brand-100 px-4 py-3 text-center font-black">รวมแถว</th></tr></thead>
                    <tbody>{visibleRows.map((row) => <tr key={row.key} className="bg-white transition-colors hover:bg-slate-50"><th className="sticky left-0 z-[1] border-b border-r border-slate-200 bg-white px-4 py-3 text-left align-top">{row.parts.map((part) => <span key={`${row.key}-${part.category}`} className="block"><span className="text-[10px] font-bold text-indigo-600">{part.category_label}</span><span className="ml-2 font-black text-slate-900">{part.label}</span></span>)}</th>{crossTab.columns.map((column) => { const count = row.cells[column.key] ?? 0; return <td key={`${row.key}-${column.key}`} className={`border-b border-r border-slate-100 px-4 py-3 text-center font-black ${count > 0 ? 'bg-sky-50/50 text-slate-950' : 'text-slate-300'}`}>{count.toLocaleString('th-TH')}</td>; })}<td className="border-b border-slate-200 bg-brand-50 px-4 py-3 text-center font-black text-brand-900">{row.total.toLocaleString('th-TH')}</td></tr>)}</tbody>
                    {hasRows && <tfoot><tr className="bg-brand-100 text-brand-950"><th className="sticky left-0 z-[2] border-t border-r border-brand-200 bg-brand-100 px-4 py-3 text-right font-black">รวมคอลัมน์</th>{crossTab.columns.map((column) => <td key={column.key} className="border-t border-r border-brand-200 px-4 py-3 text-center font-black">{column.total.toLocaleString('th-TH')}</td>)}<td className="border-t border-brand-300 bg-brand-200 px-4 py-3 text-center text-base font-black">{crossTab.summary.registered_students.toLocaleString('th-TH')}</td></tr></tfoot>}
                </table>
                {!hasRows && <div className="grid min-h-64 place-items-center bg-white px-6 py-12 text-center"><div><span className="mx-auto grid size-16 place-items-center rounded-2xl bg-slate-100 text-slate-400"><Rows size={30} weight="duotone" /></span><h3 className="mt-4 font-black text-slate-800">ยังไม่มีข้อมูลในตาราง</h3><p className="mt-1 text-sm text-slate-500">ตั้งค่าทั้งสองแกนและกด “ประมวลผล” เพื่อสร้างตารางไขว้</p></div></div>}
            </div>
            {showPagination && <div className="mt-4 flex flex-col gap-3 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <p>แสดง {crossTab.rows.length === 0 ? 0 : ((page - 1) * pageSize) + 1}–{Math.min(page * pageSize, crossTab.rows.length)} จาก {crossTab.rows.length.toLocaleString('th-TH')} ชุดแถว</p>
                <div className="flex items-center gap-2">
                    <button type="button" aria-label="หน้าก่อนหน้า" disabled={page === 1} onClick={() => onPageChange(page - 1)} className="grid size-10 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 transition-[transform,background-color] duration-150 hover:bg-slate-50 active:scale-[0.97] disabled:cursor-not-allowed disabled:text-slate-300"><CaretLeft size={18} weight="bold" /></button>
                    <span className="grid min-w-10 place-items-center rounded-xl bg-brand-700 px-3 py-2.5 font-black text-white">{page}</span>
                    <button type="button" aria-label="หน้าถัดไป" disabled={page >= pageCount} onClick={() => onPageChange(page + 1)} className="grid size-10 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 transition-[transform,background-color] duration-150 hover:bg-slate-50 active:scale-[0.97] disabled:cursor-not-allowed disabled:text-slate-300"><CaretRight size={18} weight="bold" /></button>
                </div>
            </div>}
            {hasRows && <ResultInformationSummary crossTab={crossTab} />}
        </>
    );
}

function ReportPreviewDialog({ report, district, term, crossTab, onClose }: { report: StatisticReportDefinition; district: string; term: string; crossTab: StatisticCrossTabPayload; onClose: () => void }) {
    return <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-[2px]" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
        <section role="dialog" aria-modal="true" aria-labelledby="report-preview-title" className="statistics-print-surface max-h-[calc(100dvh-2rem)] w-full max-w-6xl overflow-y-auto rounded-[24px] border border-white/70 bg-white shadow-[0_30px_100px_rgb(2_6_23_/_0.35)]">
            <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-7"><div><p className="text-xs font-black uppercase tracking-[0.15em] text-brand-700">ตัวอย่างก่อนพิมพ์</p><h2 id="report-preview-title" className="mt-1 text-xl font-black text-slate-950">{report.label}</h2><p className="mt-1 text-sm text-slate-500">{district} · ภาคเรียน {term || '-'}</p></div><button type="button" onClick={onClose} className="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 transition-[transform,background-color] duration-150 hover:bg-slate-100 active:scale-[0.97]" aria-label="ปิดตัวอย่าง"><X size={20} weight="bold" /></button></header>
            <div className="p-5 sm:p-7"><CrossTabResultTable crossTab={crossTab} page={1} onPageChange={() => undefined} showPagination={false} /><div className="mt-5 flex justify-end gap-2 print:hidden"><ActionButton onClick={onClose}>ปิด</ActionButton><ActionButton tone="primary" onClick={() => window.print()}><Printer size={18} weight="bold" />พิมพ์รายงาน</ActionButton></div></div>
        </section>
    </div>;
}

function ReportFormatDialog({ report, initialConfiguration, initialOrientation, onSave, onClose }: {
    report: StatisticReportDefinition;
    initialConfiguration: StatisticAxisConfiguration;
    initialOrientation: StatisticOrientation;
    onSave: (configuration: StatisticAxisConfiguration, orientation: StatisticOrientation) => void;
    onClose: () => void;
}) {
    const [orientation, setOrientation] = useState(initialOrientation);
    const [configuration, setConfiguration] = useState<StatisticAxisConfiguration>({
        vertical: [...initialConfiguration.vertical],
        horizontal: [...initialConfiguration.horizontal],
    });
    const [candidate, setCandidate] = useState<CategoryKey>('level');
    const availableCategories = report.source === 'registration-statistics'
        ? statisticCategories
        : statisticCategories.map((category) => ({ ...category, supported: category.key === 'level' || category.key === 'group' }));
    const candidateDefinition = availableCategories.find((category) => category.key === candidate);
    const selected = configuration[orientation];
    const updateSelected = (updater: (items: CategoryKey[]) => CategoryKey[]) => {
        setConfiguration((current) => ({
            ...current,
            [orientation]: updater(current[orientation]),
        }));
    };
    const addCategory = () => {
        if (!candidateDefinition?.supported || selected.includes(candidate) || selected.length >= 3) return;
        updateSelected((current) => [...current, candidate]);
    };
    return <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-[2px]" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
        <section role="dialog" aria-modal="true" aria-labelledby="format-dialog-title" className="max-h-[calc(100dvh-2rem)] w-full max-w-5xl overflow-y-auto rounded-[24px] border border-white/70 bg-white shadow-[0_30px_100px_rgb(2_6_23_/_0.35)]">
            <header className="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-7"><div className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-brand-50 text-brand-700"><ChartBar size={24} weight="duotone" /></span><div><p className="text-xs font-bold text-brand-700">รูปแบบการนำเสนอ</p><h2 id="format-dialog-title" className="text-xl font-black text-slate-950">กำหนดรูปแบบรายงานสถิติ</h2></div></div><button type="button" onClick={onClose} className="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 transition-[transform,background-color] duration-150 hover:bg-slate-100 active:scale-[0.97]" aria-label="ปิดหน้าต่าง"><X size={20} weight="bold" /></button></header>
            <div className="space-y-5 p-5 sm:p-7">
                <div className="grid gap-3 sm:grid-cols-[140px_minmax(0,1fr)] sm:items-center"><span className="text-sm font-black text-slate-700">รายงาน</span><div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-800">{report.id}. {report.label}</div></div>
                <div className="grid gap-4 lg:grid-cols-[1fr_1.2fr_1fr]">
                    <section className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4"><h3 className="font-black text-slate-900">เลือกด้านที่ต้องการตั้งค่า</h3><div className="mt-4 space-y-3"><label className={`flex cursor-pointer items-center justify-between gap-3 rounded-xl border px-3 py-3 text-sm font-bold transition-[border-color,background-color] duration-150 ${orientation === 'vertical' ? 'border-indigo-300 bg-indigo-50 text-indigo-950' : 'border-slate-200 bg-white text-slate-700'}`}><span className="flex items-center gap-3"><input type="radio" name="orientation" value="vertical" checked={orientation === 'vertical'} onChange={() => setOrientation('vertical')} className="size-4 accent-indigo-600" />ข้อมูลแกนตั้ง</span><span className="rounded-full bg-white px-2 py-0.5 text-xs text-indigo-700 shadow-sm">{configuration.vertical.length}</span></label><label className={`flex cursor-pointer items-center justify-between gap-3 rounded-xl border px-3 py-3 text-sm font-bold transition-[border-color,background-color] duration-150 ${orientation === 'horizontal' ? 'border-sky-300 bg-sky-50 text-sky-950' : 'border-slate-200 bg-white text-slate-700'}`}><span className="flex items-center gap-3"><input type="radio" name="orientation" value="horizontal" checked={orientation === 'horizontal'} onChange={() => setOrientation('horizontal')} className="size-4 accent-sky-600" />ข้อมูลแกนนอน</span><span className="rounded-full bg-white px-2 py-0.5 text-xs text-sky-700 shadow-sm">{configuration.horizontal.length}</span></label></div><p className="mt-3 text-xs leading-5 text-slate-500">ปุ่มนี้ใช้เลือกด้านเพื่อแก้ไขเท่านั้น เมื่อประมวลผล ระบบจะแสดงค่าของทั้งสองแกนพร้อมกันในตารางเดียว</p><div className="mt-5 border-t border-slate-200 pt-4"><p className="text-xs font-black text-slate-600">ตัวอย่างตารางไขว้สองแกน</p><div className="mt-3 overflow-hidden rounded-xl border border-brand-200 bg-white">{Array.from({ length: 5 }, (_, row) => <div key={row} className="grid grid-cols-[86px_repeat(4,1fr)]">{Array.from({ length: 5 }, (_, col) => <span key={col} className={`h-8 border-b border-r border-brand-100 ${col === 0 ? 'bg-indigo-100' : row === 0 ? 'bg-sky-100' : ''}`} />)}</div>)}</div></div></section>
                    <section className="rounded-2xl border border-slate-200 bg-white p-4"><div className="flex items-center justify-between gap-3"><h3 className="font-black text-slate-900">ข้อมูล{orientation === 'vertical' ? 'แนวตั้ง' : 'แนวนอน'}</h3><span className={`rounded-full px-2.5 py-1 text-xs font-black ${orientation === 'vertical' ? 'bg-indigo-50 text-indigo-700' : 'bg-sky-50 text-sky-700'}`}>ตั้งค่าแยกอิสระ</span></div><ol className="mt-4 min-h-56 space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-2">{selected.map((key, index) => { const category = statisticCategories.find((item) => item.key === key); return <li key={key} className={`flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-sm font-bold text-white ${orientation === 'vertical' ? 'bg-indigo-700' : 'bg-sky-700'}`}><span>{index + 1}. {category?.label}</span><button type="button" onClick={() => updateSelected((items) => items.filter((item) => item !== key))} className="grid size-7 place-items-center rounded-lg bg-white/10 transition-colors hover:bg-white/20" aria-label={`ลบ ${category?.label} จาก${orientation === 'vertical' ? 'แนวตั้ง' : 'แนวนอน'}`}><X size={15} weight="bold" /></button></li>; })}{selected.length === 0 && <li className="grid min-h-48 place-items-center px-4 text-center text-sm text-slate-400">เพิ่มประเภทข้อมูลสำหรับ{orientation === 'vertical' ? 'แนวตั้ง' : 'แนวนอน'}อย่างน้อย 1 รายการ</li>}</ol></section>
                    <section className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4"><label className="grid gap-2 text-sm font-black text-slate-700">ประเภทข้อมูล<select value={candidate} onChange={(event) => setCandidate(event.target.value as CategoryKey)} className={inputClassName()}>{availableCategories.map((category) => <option key={category.key} value={category.key} disabled={!category.supported}>{category.order}. {category.label}{category.supported ? '' : ' (รอข้อมูลต้นทาง)'}</option>)}</select></label><div className="mt-4 grid gap-2"><ActionButton onClick={addCategory} disabled={!candidateDefinition?.supported || selected.includes(candidate) || selected.length >= 3}><SlidersHorizontal size={18} weight="bold" />เพิ่มใน{orientation === 'vertical' ? 'แกนตั้ง' : 'แกนนอน'}</ActionButton><ActionButton tone="danger" disabled={selected.length === 0} onClick={() => updateSelected((items) => items.slice(0, -1))}><Trash size={18} weight="bold" />ลบรายการล่าสุด</ActionButton><ActionButton disabled={selected.length === 0} onClick={() => updateSelected(() => [])}><Trash size={18} weight="bold" />ลบทั้งหมดเฉพาะด้านนี้</ActionButton></div><p className="mt-4 text-xs leading-5 text-slate-500">แต่ละแกนเพิ่มได้สูงสุด 3 มิติ มิติที่เพิ่มจะสร้างกลุ่มย่อยตามลำดับจริง และทุกช่องนับจากจุดตัดของทั้งสองแกน</p></section>
                </div>
            </div>
            <footer className="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7"><p className="text-xs text-slate-500">แนวตั้ง {configuration.vertical.length} มิติ · แนวนอน {configuration.horizontal.length} มิติ{configuration.vertical.length === 0 || configuration.horizontal.length === 0 ? ' · กรุณาเลือกอย่างน้อยด้านละ 1 มิติ' : ''}</p><div className="flex flex-col-reverse gap-2 sm:flex-row"><ActionButton tone="danger" onClick={onClose}><SignOut size={18} weight="bold" />ออก</ActionButton><ActionButton tone="primary" disabled={configuration.vertical.length === 0 || configuration.horizontal.length === 0} onClick={() => onSave(configuration, orientation)}><FloppyDisk size={18} weight="bold" />บันทึก</ActionButton></div></footer>
        </section>
    </div>;
}

async function loadWorkspace(request: WorkspaceRequest, signal?: AbortSignal): Promise<WorkspaceResponse> {
    const params = new URLSearchParams();
    if (request.term) params.set('term', request.term);
    if (request.report.level) params.set('level', String(request.report.level));
    if (request.report.source === 'registration-statistics') {
        request.configuration.vertical.forEach((category) => params.append('row_categories[]', category));
        request.configuration.horizontal.forEach((category) => params.append('column_categories[]', category));
        const response = await getFeatureDataWithDemo<StatisticCrossTabPayload>(`/api/v1/reports/students/registration-statistics?${params.toString()}`, emptyCrossTabPayload, signal);
        return { crossTab: response.data, selectedTerm: response.data.selected_term ?? request.term };
    }
    const endpoint = request.report.source === 'new-students' ? '/api/v1/reports/new-students' : request.report.source === 'graduates' ? '/api/v1/reports/graduates' : '/api/v1/reports/transfers';
    const response = await getFeatureDataWithDemo<GenericReportPayload>(`${endpoint}?${params.toString()}`, emptyGenericPayload, signal);
    const crossTab = genericPayloadToCrossTab(response.data, request.configuration);
    return { crossTab, selectedTerm: response.data.selected_term ?? request.term };
}

export function StatisticsOverviewPage() {
    const navigate = useNavigate();
    const { role } = useDemoRole();
    const districtId = window.localStorage.getItem('sena-district-id');
    const [reportId, setReportId] = useState<StatisticReportId>(1);
    const [periodType, setPeriodType] = useState<PeriodType>('semester');
    const [termStart, setTermStart] = useState('');
    const [termEnd, setTermEnd] = useState('');
    const [displayScope, setDisplayScope] = useState<DisplayScope>('district');
    const [axisConfiguration, setAxisConfiguration] = useState<StatisticAxisConfiguration>(() => createDefaultAxisConfiguration());
    const [orientation, setOrientation] = useState<StatisticOrientation>('vertical');
    const [request, setRequest] = useState<WorkspaceRequest | null>(null);
    const [formatOpen, setFormatOpen] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    const [page, setPage] = useState(1);
    const [validationMessage, setValidationMessage] = useState('');

    const portal = useQuery({ queryKey: ['reports', 'overview', role, districtId], queryFn: ({ signal }) => apiGet<PortalData>('/api/v1/portal', signal).then((response) => response.data), staleTime: 2 * 60_000 });
    const me = useQuery({ queryKey: ['statistics', 'me', districtId], queryFn: ({ signal }) => apiGet<CurrentUser>('/api/v1/me', signal).then((response) => response.data), staleTime: 5 * 60_000 });
    const workspace = useQuery({ queryKey: ['statistics-workspace', request], queryFn: ({ signal }) => loadWorkspace(request!, signal), enabled: request !== null && request.report.source !== null, placeholderData: keepPreviousData });

    useEffect(() => { const currentTerm = portal.data?.analytics.current_term; if (!currentTerm || termStart !== '') return; setTermStart(currentTerm); setTermEnd(currentTerm); }, [portal.data?.analytics.current_term, termStart]);
    useEffect(() => { if (!formatOpen && !previewOpen) return undefined; const onKeyDown = (event: KeyboardEvent) => { if (event.key === 'Escape') { setFormatOpen(false); setPreviewOpen(false); } }; const previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden'; window.addEventListener('keydown', onKeyDown); return () => { document.body.style.overflow = previousOverflow; window.removeEventListener('keydown', onKeyDown); }; }, [formatOpen, previewOpen]);

    const selectedReport = reportById(reportId);
    const selectedDistrict = me.data?.districts.find((district) => String(district.id) === districtId);
    const districtName = selectedDistrict?.name ?? portal.data?.viewer.district ?? '-';
    const districtCode = selectedDistrict?.code ?? districtId ?? '-';
    const crossTab = workspace.data?.crossTab ?? emptyCrossTabPayload;
    const totalStudents = portal.data?.analytics.totals.students ?? 0;
    const resultTotal = crossTab.summary.registered_students;
    const verticalSummary = axisConfiguration.vertical.map((key) => statisticCategories.find((category) => category.key === key)?.label).filter(Boolean).join(' › ');
    const horizontalSummary = axisConfiguration.horizontal.map((key) => statisticCategories.find((category) => category.key === key)?.label).filter(Boolean).join(' › ');
    const canExport = canExportRegistrationStatistics(role);
    const processedReport = request?.report ?? selectedReport;
    const hasProcessedResult = request !== null && workspace.isSuccess;
    const clearProcessedResult = () => { setRequest(null); setPage(1); setValidationMessage(''); };
    const changeReport = (nextId: StatisticReportId) => { setReportId(nextId); setAxisConfiguration(createDefaultAxisConfiguration()); clearProcessedResult(); };

    const processReport = () => {
        setValidationMessage('');
        if (selectedReport.source === null) { setRequest(null); setValidationMessage(selectedReport.unavailableReason ?? 'รายงานนี้ยังไม่มีข้อมูลต้นทาง'); return; }
        if (displayScope === 'province') { setValidationMessage('ข้อมูลระดับจังหวัดยังไม่มี API รวมหลายอำเภอ ระบบจึงไม่ประมวลผลแทนด้วยข้อมูลอำเภอเดียว'); return; }
        if (periodType !== 'semester') { setValidationMessage('ระบบข้อมูลปัจจุบันรองรับการประมวลผลทีละภาคเรียน กรุณาเลือก “ภาคเรียน”'); return; }
        if (termStart !== termEnd) { setValidationMessage('ระบบข้อมูลปัจจุบันรองรับครั้งละ 1 ภาคเรียน กรุณากำหนดภาคเรียนเริ่มต้นและสิ้นสุดให้ตรงกัน'); return; }
        if (!/^([12])\/25\d{2}$/.test(termStart)) { setValidationMessage('กรุณาระบุภาคเรียนในรูปแบบ 1/2569 หรือ 2/2569'); return; }
        if (axisConfiguration.vertical.length === 0 || axisConfiguration.horizontal.length === 0) { setValidationMessage('กรุณาเลือกมิติข้อมูลอย่างน้อย 1 รายการให้ครบทั้งแกนตั้งและแกนนอน'); return; }
        const vertical = supportedCategoryKeys(axisConfiguration.vertical);
        const horizontal = supportedCategoryKeys(axisConfiguration.horizontal);
        setRequest({ report: selectedReport, term: termStart, configuration: { vertical, horizontal } });
        setPage(1);
    };

    const exportExcel = async () => {
        if (!request || crossTab.rows.length === 0 || !canExport) return;
        try {
            const { downloadExcel } = await import('../../lib/excel');
            downloadExcel(`รายงานสถิติ-${processedReport.id}-${request.term}`, buildStatisticsCrossTabSheets(processedReport, request.term, districtName, crossTab));
            showSuccessAlert('จัดทำไฟล์ Excel เรียบร้อยแล้ว');
        }
        catch (error) { showErrorAlert(error instanceof Error ? error.message : 'ไม่สามารถจัดทำไฟล์ Excel ได้'); }
    };

    const largestCell = crossTab.summary.largest_cell;
    const largestRow = useMemo(() => crossTab.rows.find((row) => row.key === largestCell?.row_key), [crossTab.rows, largestCell?.row_key]);
    const largestColumn = useMemo(() => crossTab.columns.find((column) => column.key === largestCell?.column_key), [crossTab.columns, largestCell?.column_key]);
    if (portal.isPending) return <div className="space-y-5"><PageHeader category="สถิติ" title="รายงานสถิติ" description="ค้นหา ประมวลผล และส่งออกรายงานสถิติทางการศึกษา" icon={ChartPieSlice} /><QuerySkeleton rows={7} /></div>;
    if (portal.isError) return <div className="space-y-5"><PageHeader category="สถิติ" title="รายงานสถิติ" description="ค้นหา ประมวลผล และส่งออกรายงานสถิติทางการศึกษา" icon={ChartPieSlice} /><QueryError onRetry={() => portal.refetch()} /></div>;

    return <div className="space-y-5 pb-3">
        <PageHeader category="สถิติ" title="รายงานสถิติ" description="ค้นหา ประมวลผล และแสดงข้อมูลรายงานสถิติทางการศึกษาเพื่อใช้วางแผนและประเมินผล" icon={ChartPieSlice} actions={<button type="button" onClick={() => setFormatOpen(true)} className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-brand-200 bg-white px-4 text-sm font-black text-brand-800 transition-[transform,background-color,border-color] duration-150 hover:border-brand-400 hover:bg-brand-50 active:scale-[0.97]"><GearSix size={19} weight="bold" />กำหนดรูปแบบรายงาน</button>} />
        <section aria-label="สารสนเทศรวม" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <InformationCard label="นักศึกษาในพื้นที่" value={`${totalStudents.toLocaleString('th-TH')} คน`} detail={role === 'teacher' ? 'เฉพาะกลุ่มเรียนที่รับผิดชอบ' : districtName} icon={UsersThree} tone="sky" />
            <InformationCard label="กลุ่มเรียน" value={`${portal.data.analytics.totals.groups.toLocaleString('th-TH')} กลุ่ม`} detail="อยู่ในขอบเขตข้อมูลปัจจุบัน" icon={Buildings} tone="emerald" />
            <InformationCard label="ภาคเรียนข้อมูลล่าสุด" value={portal.data.analytics.current_term ?? '-'} detail="ใช้เป็นค่าเริ่มต้นของรายงาน" icon={CalendarBlank} tone="amber" />
            <InformationCard label="ผลประมวลผลล่าสุด" value={workspace.isError ? 'ไม่สำเร็จ' : workspace.isFetching ? 'กำลังโหลด' : hasProcessedResult ? `${resultTotal.toLocaleString('th-TH')} รายการ` : '-'} detail={workspace.isError ? 'ตรวจสอบแหล่งข้อมูลแล้วลองใหม่' : hasProcessedResult ? processedReport.label : 'ยังไม่ได้ประมวลผล'} icon={ChartBar} tone="rose" />
        </section>
        <section aria-labelledby="statistics-filter-title" className="rounded-[24px] border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
            <div className="grid gap-6 p-5 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:p-6">
                <div className="space-y-5 lg:border-r lg:border-slate-200 lg:pr-6">
                    <div><label htmlFor="statistics-report" className="mb-2 flex items-center gap-2 text-sm font-black text-slate-800"><FileText size={19} className="text-emerald-600" weight="duotone" />รายงาน</label><select id="statistics-report" value={reportId} onChange={(event) => changeReport(Number(event.target.value) as StatisticReportId)} className={inputClassName()}>{statisticReports.map((report) => <option key={report.id} value={report.id}>{report.id}. {report.label}{report.source ? '' : ' — รอข้อมูลต้นทาง'}</option>)}</select></div>
                    <div className="grid gap-3 sm:grid-cols-[190px_minmax(0,1fr)] sm:items-end"><label className="grid gap-2 text-sm font-black text-slate-800"><span className="flex items-center gap-2"><Buildings size={19} className="text-brand-700" weight="duotone" />รหัสพื้นที่/สถานศึกษา</span><div className="relative"><input readOnly value={districtCode} className={`${inputClassName(true)} pr-11`} /><MagnifyingGlass size={18} className="absolute right-3 top-1/2 -translate-y-1/2 text-brand-600" /></div></label><label className="grid gap-2 text-sm font-black text-slate-800">พื้นที่ข้อมูล<input readOnly value={districtName} className={inputClassName(true)} /></label></div>
                    <fieldset><legend id="statistics-filter-title" className="mb-3 flex items-center gap-2 text-sm font-black text-slate-800"><CalendarBlank size={19} className="text-brand-700" weight="duotone" />ช่วงข้อมูล</legend><div className="flex flex-wrap gap-x-5 gap-y-2">{([['semester', 'ภาคเรียน'], ['academic-year', 'ปีการศึกษา'], ['budget-year', 'ปีงบประมาณ']] as const).map(([value, label]) => <label key={value} className="inline-flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700"><input type="radio" name="period" value={value} checked={periodType === value} onChange={() => { setPeriodType(value); clearProcessedResult(); }} className="size-4 accent-blue-600" />{label}</label>)}</div><div className="mt-3 grid grid-cols-[1fr_auto_1fr] items-center gap-3 sm:max-w-lg"><input aria-label="ช่วงข้อมูลเริ่มต้น" value={termStart} onChange={(event) => { setTermStart(event.target.value); clearProcessedResult(); }} placeholder="1/2569" className={inputClassName(periodType !== 'semester')} disabled={periodType !== 'semester'} /><span className="text-sm font-bold text-slate-500">ถึง</span><input aria-label="ช่วงข้อมูลสิ้นสุด" value={termEnd} onChange={(event) => { setTermEnd(event.target.value); clearProcessedResult(); }} placeholder="1/2569" className={inputClassName(periodType !== 'semester')} disabled={periodType !== 'semester'} /></div></fieldset>
                </div>
                <div className="flex flex-col justify-between gap-6">
                    <div><h2 className="flex items-center gap-2 text-sm font-black text-slate-800"><ChartBar size={19} className="text-brand-700" weight="duotone" />ระดับการแสดงผล</h2><div className="mt-3 flex flex-wrap gap-x-8 gap-y-3"><label className="inline-flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700"><input type="radio" name="display-scope" checked={displayScope === 'district'} onChange={() => { setDisplayScope('district'); clearProcessedResult(); }} className="size-4 accent-blue-600" />ระดับสถานศึกษา/อำเภอ</label><label className="inline-flex cursor-pointer items-center gap-2 text-sm font-bold text-slate-700"><input type="radio" name="display-scope" checked={displayScope === 'province'} onChange={() => { setDisplayScope('province'); clearProcessedResult(); }} className="size-4 accent-blue-600" />ระดับจังหวัด</label></div><div className="mt-4 grid gap-2 rounded-xl border border-brand-100 bg-brand-50/70 px-4 py-3 text-sm text-brand-950"><p><strong className="font-black text-indigo-800">แกนตั้ง:</strong> {verticalSummary || 'ยังไม่ได้เลือก'}</p><p><strong className="font-black text-sky-800">แกนนอน:</strong> {horizontalSummary || 'ยังไม่ได้เลือก'}</p></div></div>
                    <div><div className="grid gap-3 sm:grid-cols-3"><ActionButton tone="primary" onClick={processReport} disabled={workspace.isFetching}><Play size={19} weight="fill" />{workspace.isFetching ? 'กำลังประมวลผล...' : 'ประมวลผล'}</ActionButton><ActionButton disabled={!hasProcessedResult || crossTab.rows.length === 0} onClick={() => setPreviewOpen(true)}><FileText size={19} weight="duotone" />ตัวอย่างก่อนพิมพ์</ActionButton><ActionButton tone="success" disabled={!canExport || !hasProcessedResult || crossTab.rows.length === 0} onClick={() => void exportExcel()}><FileXls size={19} weight="duotone" />บันทึกเป็น Excel</ActionButton><ActionButton onClick={() => setFormatOpen(true)}><GearSix size={19} weight="duotone" />ตั้งค่ารูปแบบ</ActionButton><ActionButton disabled={!hasProcessedResult || crossTab.rows.length === 0} onClick={() => setPreviewOpen(true)}><Printer size={19} weight="duotone" />พิมพ์</ActionButton><ActionButton tone="danger" onClick={() => navigate('/app')}><SignOut size={19} weight="duotone" />ออก</ActionButton></div>{!canExport && <p className="mt-3 text-xs text-slate-500">การส่งออก Excel เปิดให้ครูและผู้ดูแลอำเภอตามนโยบายสิทธิ์เดิม ส่วนการดูและพิมพ์ยังใช้งานได้ตามปกติ</p>}</div>
                </div>
            </div>
        </section>
        {validationMessage && <div role="alert" className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-950"><Info size={20} weight="fill" className="mt-0.5 shrink-0 text-amber-600" /><div><strong className="font-black">ยังประมวลผลรายงานนี้ไม่ได้</strong><p className="mt-0.5 leading-6">{validationMessage}</p></div></div>}
        <section aria-labelledby="statistics-result-title" className="rounded-[24px] border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/60 lg:p-6"><div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h2 id="statistics-result-title" className="flex items-center gap-2 text-lg font-black text-slate-950"><Rows size={21} className="text-brand-700" weight="duotone" />ผลการประมวลผลแบบสองแกน</h2><p className="mt-1 text-sm text-slate-500">{request ? `${processedReport.label} · ${districtName} · ภาคเรียน ${workspace.data?.selectedTerm ?? request.term}` : 'ยังไม่มีข้อมูล กรุณาตั้งค่าทั้งสองแกนและกดปุ่มประมวลผล'}</p></div>{hasProcessedResult && crossTab.rows.length > 0 && <div className="flex flex-wrap gap-2 text-xs font-bold"><span className="rounded-full bg-indigo-50 px-3 py-1.5 text-indigo-800">{crossTab.summary.row_count} ชุดแถว × {crossTab.summary.column_count} ชุดคอลัมน์</span><span className="rounded-full bg-emerald-50 px-3 py-1.5 text-emerald-800">สูงสุด {largestCell && largestRow && largestColumn ? `${largestRow.label} × ${largestColumn.label} ${largestCell.count.toLocaleString('th-TH')} คน` : '-'}</span></div>}</div>{workspace.isFetching && <QuerySkeleton rows={6} />}{workspace.isError && <QueryError onRetry={() => workspace.refetch()} />}{!workspace.isFetching && !workspace.isError && <CrossTabResultTable crossTab={hasProcessedResult ? crossTab : emptyCrossTabPayload} page={page} onPageChange={setPage} />}</section>
        {formatOpen && <ReportFormatDialog report={selectedReport} initialConfiguration={axisConfiguration} initialOrientation={orientation} onClose={() => setFormatOpen(false)} onSave={(nextConfiguration, nextOrientation) => { setAxisConfiguration(nextConfiguration); setOrientation(nextOrientation); setFormatOpen(false); clearProcessedResult(); showSuccessAlert('บันทึกรูปแบบแนวตั้งและแนวนอนแยกกันเรียบร้อยแล้ว'); }} />}
        {previewOpen && request && <ReportPreviewDialog report={processedReport} district={districtName} term={workspace.data?.selectedTerm ?? request.term} crossTab={crossTab} onClose={() => setPreviewOpen(false)} />}
    </div>;
}
