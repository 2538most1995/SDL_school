import { ExcelFileIcon } from '../../components/FileTypeIcon';
import {
    ArrowsClockwise,
    ChartBar,
    Calculator,
    Database,
    FunnelSimpleX,
    MagnifyingGlass,
    Users,
    ListChecks,
    WarningCircle,
    NotePencil,
    CheckCircle,
    LockKey,
} from '@phosphor-icons/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useDeferredValue, useEffect, useMemo, useRef, useState, useSyncExternalStore, type KeyboardEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Button, Field, Input, Select } from '../../components/MaterialUI';
import { SearchableSelect } from '../../components/SearchableSelect';
import { PageHeader } from '../../components/PageHeader';
import { Pagination } from '../../components/Pagination';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { ApiError } from '../../lib/api';
import { showSuccessAlert } from '../../lib/feedback';
import { downloadExcel } from '../../lib/excel';
import { retainSelectedFilterOption } from '../../lib/filterOptions';
import { getFeatureDataWithDemo, sendFeatureData } from '../api';
import { ImportedScoreAutosave, scoreFields, scoreValues, type ScoreSaveResult, type ScoreSaveState } from './importedScoreAutosave';
import { auditIssueColorClass, buildImportedScoresPath, formatAuditIssueText, formatAuditStatusText, importedAssessmentLabels, isLowImportedMidtermScore, normalizeAssessmentScores } from './importedScores';

function formatScore(value: number | string): string {
    if (typeof value === 'string') {
        const numeric = Number(value);
        if (!Number.isNaN(numeric) && value.trim() !== '') {
            return Number.isInteger(numeric) ? String(numeric) : numeric.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
        }
        return value.trim();
    }
    return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

export function ScorebookPage() {
    const { role } = useDemoRole();

    return role === 'student' ? <StudentScoreSummary /> : <StaffScoresPage />;
}

function StaffScoresPage() {
    return <ImportedScoresPage />;
}

type ImportedScoreOption = string | number | {
    value?: string | number;
    code?: string | number;
    label?: string;
    name?: string;
};

type ImportedScoreRow = {
    entry_completed?: boolean;
    academic_term: string;
    edit_token?: string;
    is_transferred?: boolean;
    student_code: string;
    full_name: string;
    group_code: string;
    group_name: string;
    level: number;
    subject_code: string;
    subject_name: string;
    learning_method: string | null;
    assessment_scores: Array<number | null>;
    midterm_score: number | null;
    final_exam_score: number | null;
    total_score: number | null;
    grade: string | null;
    is_remedial?: boolean;
    calculation_audit: {
        status: 'correct' | 'incorrect' | 'not_checkable';
        check_count: number;
        reason?: 'transferred' | 'special_grade' | 'missing_final_exam' | 'incomplete_data' | null;
        issues: Array<{ code: string; label: string; expected: number | string; actual: number | string }>;
    };
};

type ImportedScorePayload = {
    can_edit?: boolean;
    can_bulk_edit?: boolean;
    batch_key?: string;
    terms: string[];
    selected_term: string | null;
    levels: ImportedScoreOption[];
    groups: ImportedScoreOption[];
    subjects: ImportedScoreOption[];
    score_labels: string[];
    calculation_audit: { total_rows: number; checked_rows: number; incorrect_rows: number; not_checkable_rows: number; low_midterm_rows?: number };
    rows: ImportedScoreRow[];
    pagination: { current_page: number; per_page: number; total: number; last_page: number };
};

const emptyImportedScores: ImportedScorePayload = {
    terms: [],
    selected_term: null,
    levels: [],
    groups: [],
    subjects: [],
    score_labels: [],
    calculation_audit: { total_rows: 0, checked_rows: 0, incorrect_rows: 0, not_checkable_rows: 0, low_midterm_rows: 0 },
    rows: [],
    pagination: { current_page: 1, per_page: 100, total: 0, last_page: 1 },
};

function scoreOption(option: ImportedScoreOption, fallbackLabel?: string): { value: string; label: string } {
    if (typeof option === 'string' || typeof option === 'number') {
        return { value: String(option), label: fallbackLabel ?? String(option) };
    }

    const value = String(option.value ?? option.code ?? '');
    return { value, label: option.label ?? option.name ?? fallbackLabel ?? value };
}

function levelLabel(level: string | number): string {
    return { '1': 'ประถมศึกษา', '2': 'มัธยมศึกษาตอนต้น', '3': 'มัธยมศึกษาตอนปลาย' }[String(level)] ?? String(level);
}

function nullableScore(value: number | null): string {
    return value === null ? '-' : formatScore(value);
}

function auditIssueText(issue: ImportedScoreRow['calculation_audit']['issues'][number]): string {
    return formatAuditIssueText(issue);
}

function importedScoreAuditText(row: ImportedScoreRow): string {
    return formatAuditStatusText(row);
}

type ScoreEntrySession = {
    mode: 'bulk' | 'individual';
    batch: string;
    originals: Record<string, ImportedScoreRow>;
    queue: ImportedScoreAutosave;
};
const emptyScoreSaves: Record<string, ScoreSaveState> = {};
const subscribeNoScoreSaves = () => () => {};
const getNoScoreSaves = () => emptyScoreSaves;
const scoreRowKey = (row: ImportedScoreRow) => JSON.stringify([row.level, row.student_code, row.subject_code, row.academic_term, !!row.is_remedial]);
const canEnterScore = (row: ImportedScoreRow) => !!row.edit_token && !row.is_transferred && !['ม', 'มส', 'ผ', 'มผ', 'ร'].includes(row.grade ?? '');

function ImportedScoresPage() {
    const { role } = useDemoRole();
    const queryClient = useQueryClient();
    const [session, setSession] = useState<ScoreEntrySession | null>(null);
    const [finishing, setFinishing] = useState(false);
    const [scoreColumns, setScoreColumns] = useState<'all' | 'assessments' | 'exams'>('all');
    const inputs = useRef(new Map<string, HTMLInputElement>());
    const saves = useSyncExternalStore(session?.queue.subscribe ?? subscribeNoScoreSaves, session?.queue.getSnapshot ?? getNoScoreSaves);
    const entryActive = session !== null;
    const showAssessments = !entryActive || scoreColumns !== 'exams';
    const showFinal = !entryActive || scoreColumns !== 'assessments';
    const [editError, setEditError] = useState('');
    const [searchParams] = useSearchParams();
    const [term, setTerm] = useState('');
    const [level, setLevel] = useState('');
    const [group, setGroup] = useState('');
    const [subjectCode, setSubjectCode] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(100);
    const [showScoreLegend, setShowScoreLegend] = useState(false);
    const [showIncorrectOnly, setShowIncorrectOnly] = useState(() => {
        return searchParams.get('calculation_status') === 'incorrect' || searchParams.get('check') === 'incorrect';
    });
    const [showLowMidtermOnly, setShowLowMidtermOnly] = useState(() => {
        return searchParams.get('midterm_status') === 'below_40'
            || searchParams.get('check') === 'midterm_below_40'
            || searchParams.get('filter') === 'low_midterm';
    });
    const levelLabels = useRef(new Map<string, string>());
    const groupLabels = useRef(new Map<string, string>());
    const subjectLabels = useRef(new Map<string, string>());
    const deferredSearch = useDeferredValue(search);
    const scoreQueryKey = ['learning', 'scores', 'imported', term, level, group, subjectCode, deferredSearch, showIncorrectOnly, showLowMidtermOnly, page, perPage];
    const importedScores = useQuery({
        queryKey: scoreQueryKey,
        queryFn: ({ signal }) => getFeatureDataWithDemo<ImportedScorePayload>(
            buildImportedScoresPath({
                term,
                level,
                group,
                subjectCode,
                search: deferredSearch,
                calculationStatus: showIncorrectOnly ? 'incorrect' : '',
                midtermStatus: showLowMidtermOnly ? 'below_40' : '',
                page,
                perPage,
            }),
            emptyImportedScores,
            signal,
        ),
        enabled: !entryActive,
        refetchOnWindowFocus: false,
    });
    const data = importedScores.data?.data;
    const rows = data?.rows ?? [];
    const scoreLabels = useMemo(() => importedAssessmentLabels(data?.score_labels), [data?.score_labels]);
    const startEntry = (mode: 'bulk' | 'individual', candidates: ImportedScoreRow[]) => {
        if (!data?.batch_key) return;
        const eligible = candidates.filter(row => canEnterScore(row) && (mode === 'individual' || !row.entry_completed));
        if (!eligible.length) return;
        const originals = Object.fromEntries(eligible.map(row => [scoreRowKey(row), row]));
        const batch = data.batch_key;
        const queue = new ImportedScoreAutosave(Object.fromEntries(eligible.map(row => [scoreRowKey(row), { fields: scoreFields(row), token: row.edit_token! }])),
            async (key, fields, token) => {
                const row = originals[key];
                const response = await sendFeatureData<ScoreSaveResult>('/api/v1/learning/scores/imported', 'PUT', {
                    batch_key: batch, student_code: row.student_code, level: row.level, subject_code: row.subject_code,
                    academic_term: row.academic_term, edit_token: token, entry_mode: mode, ...scoreValues(fields),
                });
                return response.data;
            });
        setEditError('');
        setSession({ mode, originals, queue, batch });
    };
    useEffect(() => {
        if (session) inputs.current.values().next().value?.focus();
        return () => session?.queue.stop();
    }, [session]);
    const hasUnsaved = Object.values(saves).some(row => row.status !== 'saved');
    const errors = Object.values(saves).filter(row => row.status === 'error');
    const savedCount = Object.values(saves).filter(row => row.savedVersion > 0 && row.status === 'saved').length;
    const availableEntryCount = rows.filter(row => canEnterScore(row) && !row.entry_completed).length;
    const entryUnavailableReason = !data?.can_bulk_edit ? 'ยังไม่พร้อมกรอกคะแนน กรุณาตรวจสิทธิ์และการอัปเดตฐานข้อมูล'
        : availableEntryCount === 0 ? 'รายการในหน้านี้บันทึกเสร็จแล้วหรือไม่รองรับการกรอก ให้กดแก้ไขทีละคน' : undefined;
    useEffect(() => {
        if (!hasUnsaved) return;
        const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        const navigateAfterSave = (event: MouseEvent) => {
            if (!session || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const anchor = event.target instanceof Element ? event.target.closest<HTMLAnchorElement>('a[href]') : null;
            if (!anchor || anchor.target === '_blank' || anchor.hasAttribute('download')) return;
            const url = new URL(anchor.href, window.location.href);
            if (url.origin !== window.location.origin || url.pathname === window.location.pathname && url.search === window.location.search) return;
            event.preventDefault(); event.stopPropagation();
            void session.queue.flush().then(saved => {
                if (saved) window.location.assign(url.href);
                else setEditError('ยังมีคะแนนบันทึกไม่สำเร็จ กรุณาตรวจแถวที่แจ้งเตือนก่อนออกจากหน้านี้');
            });
        };
        window.addEventListener('beforeunload', guard);
        document.addEventListener('click', navigateAfterSave, true);
        return () => { window.removeEventListener('beforeunload', guard); document.removeEventListener('click', navigateAfterSave, true); };
    }, [hasUnsaved, session]);
    const finishEntry = async () => {
        if (!session || finishing) return;
        setFinishing(true); setEditError('');
        try {
            if (!await session.queue.flush()) {
                setEditError('ยังมีคะแนนบันทึกไม่สำเร็จ กรุณาแก้ไขแถวที่แจ้งเตือนแล้วกดบันทึกเสร็จอีกครั้ง');
                return;
            }
            const latest = session.queue.getSnapshot();
            await sendFeatureData('/api/v1/learning/scores/imported/complete', 'POST', {
                batch_key: session.batch,
                rows: Object.entries(session.originals).map(([key, row]) => ({ student_code: row.student_code, level: row.level,
                    subject_code: row.subject_code, academic_term: row.academic_term, edit_token: latest[key].token })),
            });
            const completedKeys = new Set(Object.keys(session.originals));
            queryClient.setQueryData<typeof importedScores.data>(scoreQueryKey, old => old ? { ...old, data: { ...old.data,
                rows: old.data.rows.map(row => completedKeys.has(scoreRowKey(row)) ? { ...row, entry_completed: true } : row) } } : old);
            session.queue.stop(); setSession(null);
            await queryClient.invalidateQueries();
            showSuccessAlert('บันทึกเสร็จแล้ว หากต้องการเปลี่ยนคะแนนให้กดแก้ไขทีละคน');
        } catch (error) {
            if (error instanceof ApiError) {
                const keys = Object.keys(session.originals);
                for (const [field, messages] of Object.entries(error.errors)) {
                    const match = /^rows\.(\d+)\.edit_token$/.exec(field);
                    if (match && keys[Number(match[1])]) session.queue.markConflict(keys[Number(match[1])], messages[0]);
                }
            }
            setEditError(error instanceof Error ? error.message : 'บันทึกเสร็จไม่สำเร็จ กรุณาลองใหม่');
        }
        finally { setFinishing(false); }
    };
    const reloadScore = async (key: string) => {
        if (!session || !window.confirm('ใช้คะแนนล่าสุดจากระบบแทนค่าที่ยังไม่บันทึกของแถวนี้หรือไม่?')) return;
        const original = session.originals[key];
        try {
            const result = await getFeatureDataWithDemo<ImportedScorePayload>(buildImportedScoresPath({ term: original.academic_term,
                level: String(original.level), group: '', subjectCode: original.subject_code, search: original.student_code, page: 1, perPage: 1000 }), emptyImportedScores);
            if (result.data.batch_key !== session.batch) throw new Error('มีการนำเข้าชุดข้อมูลใหม่ กรุณาออกจากหน้านี้แล้วเปิดอีกครั้ง');
            const latest = result.data.rows.find(row => scoreRowKey(row) === key);
            if (!latest?.edit_token) throw new Error('ไม่พบรายการคะแนนล่าสุด');
            queryClient.setQueryData<typeof importedScores.data>(scoreQueryKey, old => old ? { ...old, data: { ...old.data,
                rows: old.data.rows.map(row => scoreRowKey(row) === key ? latest : row) } } : old);
            if (session.mode === 'bulk' && latest.entry_completed) {
                delete session.originals[key]; session.queue.remove(key);
                if (!Object.keys(session.originals).length) { session.queue.stop(); setSession(null); }
            } else {
                session.originals[key] = latest;
                session.queue.replace(key, scoreFields(latest), latest.edit_token);
            }
            setEditError('');
        } catch (error) { setEditError(error instanceof Error ? error.message : 'โหลดคะแนนล่าสุดไม่สำเร็จ'); }
    };
    const onScoreKey = (event: KeyboardEvent<HTMLInputElement>, key: string, column: number) => {
        if (!session || event.nativeEvent.isComposing || event.altKey || event.ctrlKey || event.metaKey) return;
        const keys = rows.map(scoreRowKey).filter(key => session.originals[key]);
        const columns = [...(showAssessments ? [0, 1, 2, 3, 4, 5, 6, 7, 8] : []), 9, ...(showFinal ? [10] : [])];
        let r = keys.indexOf(key), position = columns.indexOf(column);
        if (event.key === 'Enter' || event.key === 'ArrowDown') r += event.shiftKey ? -1 : 1;
        else if (event.key === 'ArrowUp') r--;
        else if (event.key === 'ArrowLeft') position--;
        else if (event.key === 'ArrowRight') position++;
        else if (event.key === 'Tab') { position += event.shiftKey ? -1 : 1; if (position >= columns.length) { position = 0; r++; } if (position < 0) { position = columns.length - 1; r--; } }
        else return;
        if (event.key !== 'Tab' || (r >= 0 && r < keys.length)) event.preventDefault();
        if (r >= 0 && r < keys.length && position >= 0 && position < columns.length) inputs.current.get(`${keys[r]}:${columns[position]}`)?.focus();
    };
    const scoreInput = (key: string, column: number, label: string) => {
        const state = saves[key];
        return <input ref={element => { if (element) inputs.current.set(`${key}:${column}`, element); else inputs.current.delete(`${key}:${column}`); }}
            type="text" inputMode="decimal" autoComplete="off" aria-label={`${session?.originals[key].full_name} ${session?.originals[key].subject_code} ${label}`}
            aria-invalid={state.status === 'error' || undefined} aria-describedby={state.status === 'error' ? `score-error-${rows.findIndex(row => scoreRowKey(row) === key)}` : undefined}
            value={state.fields[column]} disabled={finishing}
            onChange={event => session?.queue.set(key, column, event.target.value)} onBlur={() => session?.queue.blur(key)}
            onFocus={event => { event.currentTarget.select(); event.currentTarget.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }}
            onKeyDown={event => onScoreKey(event, key, column)} className="score-entry-input" />;
    };


    useEffect(() => {
        if (!term && data?.selected_term) setTerm(data.selected_term);
    }, [data?.selected_term, term]);

    const levelOptions = useMemo(() => {
        if ((data?.levels.length ?? 0) > 0) return data!.levels.map((option) => {
            const parsed = scoreOption(option);
            return { ...parsed, label: parsed.label === parsed.value ? levelLabel(parsed.value) : parsed.label };
        });
        return Array.from(new Set(rows.map((row) => row.level))).sort().map((value) => ({ value: String(value), label: levelLabel(value) }));
    }, [data?.levels, rows]);
    const groupOptions = useMemo(() => {
        if ((data?.groups.length ?? 0) > 0) return data!.groups.map((option) => scoreOption(option));
        return Array.from(new Map(rows.filter((row) => row.group_code || row.group_name).map((row) => [row.group_code || row.group_name, row.group_name || row.group_code])).entries()).map(([value, label]) => ({ value, label }));
    }, [data?.groups, rows]);
    const subjectOptions = useMemo(() => {
        if ((data?.subjects.length ?? 0) > 0) return data!.subjects.map((option) => scoreOption(option));
        return Array.from(new Map(rows.filter((row) => row.subject_code).map((row) => [row.subject_code, `${row.subject_code} ${row.subject_name}`.trim()])).entries()).map(([value, label]) => ({ value, label }));
    }, [data?.subjects, rows]);
    useEffect(() => {
        levelOptions.forEach((option) => levelLabels.current.set(option.value, option.label));
    }, [levelOptions]);
    useEffect(() => {
        groupOptions.forEach((option) => groupLabels.current.set(option.value, option.label));
    }, [groupOptions]);
    useEffect(() => {
        subjectOptions.forEach((option) => subjectLabels.current.set(option.value, option.label));
    }, [subjectOptions]);
    const retainedLevelOptions = useMemo(
        () => retainSelectedFilterOption(levelOptions, level, levelLabels.current.get(level)),
        [level, levelOptions],
    );
    const retainedGroupOptions = useMemo(
        () => retainSelectedFilterOption(groupOptions, group, groupLabels.current.get(group)),
        [group, groupOptions],
    );
    const retainedSubjectOptions = useMemo(
        () => retainSelectedFilterOption(subjectOptions, subjectCode, subjectLabels.current.get(subjectCode)),
        [subjectCode, subjectOptions],
    );
    const retainedTermOptions = useMemo(
        () => retainSelectedFilterOption(
            (data?.terms ?? []).map((value) => ({ value, label: value })),
            term && term !== 'all' ? term : '',
            term,
        ),
        [data?.terms, term],
    );
    const studentCount = useMemo(() => new Set(rows.map((row) => `${row.level}|${row.student_code}`)).size, [rows]);
    const subjectCount = useMemo(() => new Set(rows.map((row) => `${row.level}|${row.subject_code}`)).size, [rows]);
    const hasActiveFilters = search.trim() !== ''
        || (term !== '' && term !== 'all')
        || level !== ''
        || group !== ''
        || subjectCode !== ''
        || showIncorrectOnly
        || showLowMidtermOnly;

    const clearFilters = () => {
        setSearch('');
        setTerm('all');
        setLevel('');
        setGroup('');
        setSubjectCode('');
        setShowIncorrectOnly(false);
        setShowLowMidtermOnly(false);
        setPage(1);
    };

    const exportImportedScores = () => {
        const auditSuffix = showLowMidtermOnly && showIncorrectOnly
            ? '-ตรวจคำนวณและกลางภาคต่ำกว่า40'
            : showLowMidtermOnly
                ? '-กลางภาคต่ำกว่า40'
                : showIncorrectOnly
                    ? '-ตรวจการคำนวณITW'
                    : '';
        return downloadExcel(`คะแนนนำเข้า${auditSuffix}-${data?.selected_term ?? (term || 'ทั้งหมด')}`, [{
            name: 'คะแนนนำเข้า',
            columns: ['ลำดับ', 'รหัสนักศึกษา', 'ชื่อ-นามสกุล', 'กลุ่ม', 'ระดับ', 'รหัสวิชา', 'รายวิชา', 'วิธีเรียน', ...scoreLabels, 'กลางภาค', 'ปลายภาค', 'รวม', 'เกรด', 'ผลตรวจการคำนวณ'],
            rows: rows.map((row, index) => [
                index + 1,
                row.student_code,
                row.full_name,
                row.group_name || row.group_code,
                levelLabel(row.level),
                row.subject_code,
                row.subject_name,
                row.learning_method ?? '',
                ...normalizeAssessmentScores(row.assessment_scores),
                row.midterm_score,
                row.final_exam_score,
                row.total_score,
                row.grade,
                importedScoreAuditText(row),
            ]),
        }]);
    };

    return <div>
        <PageHeader category="learning" title="คะแนนจากข้อมูลนำเข้า" description="กรอกและแก้ไขคะแนนย่อย คะแนนเก็บ และคะแนนสอบปลายภาคจากข้อมูล ITW ที่นำเข้า" icon={Database} actions={rows.length > 0 ? <Button type="button" appearance="outline" icon={<ExcelFileIcon size={18} />} onClick={exportImportedScores}>ส่งออก Excel</Button> : undefined} />
        <p className="mb-5 text-sm leading-6 text-slate-600">เลือกกลุ่มเรียนหรือรายวิชา แล้วกด <strong className="text-slate-900">กรอกคะแนน</strong> เพื่อกรอกทุกแถวในหน้านี้ ระบบบันทึกอัตโนมัติ เมื่อเสร็จให้กด <strong className="text-slate-900">บันทึกเสร็จ</strong> เพื่อปิดช่องกรอก รายการที่เสร็จแล้วแก้ไขได้ทีละคน</p>
        {!data?.can_edit && data && <p role="status" className="mb-4 rounded-xl bg-sky-50 p-4 text-sm text-sky-950">ขณะนี้เปิดดูคะแนนได้ การกรอกคะแนนต้องเปิดสิทธิ์เขียนข้อมูลและติดตั้งตารางบันทึกคะแนนก่อน</p>}
        {data?.can_edit && !data.can_bulk_edit && <p role="status" className="mb-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-950">กรุณาอัปเดตฐานข้อมูลเพื่อเปิดปุ่มกรอกคะแนนและบันทึกเสร็จ</p>}
        {entryActive && <div className="mb-4 rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm text-brand-950">
            <p className="font-bold">{session.mode === 'bulk' ? 'กำลังกรอกคะแนนทั้งตาราง' : 'กำลังแก้ไขคะแนนรายคน'} · {Object.keys(session.originals).length} รายการ</p>
            <p className="mt-1">Enter / ↑ ↓ เปลี่ยนคน · Tab / ← → เปลี่ยนช่อง · เว้นว่าง = ยังไม่มีคะแนน · 0 = ศูนย์จริง</p>
            <p className="mt-1">คะแนนย่อยจะรวมเป็นกลางภาคให้อัตโนมัติ และสามารถปรับกลางภาคเองได้</p>
        </div>}

        {editError && <p role="alert" className="mb-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{editError}</p>}
        {!entryActive && <fieldset className="contents">
        <Panel
            title="ตัวกรองคะแนนนำเข้า"
            description="จำกัดผลลัพธ์ตามภาคเรียน ระดับ กลุ่มเรียน รายวิชา หรือค้นหาชื่อและรหัสนักศึกษา"
            action={
                <Button
                    type="button"
                    aria-label="ล้างตัวกรอง"
                    appearance="outline"
                    icon={<FunnelSimpleX size={18} weight="bold" />}
                    onClick={clearFilters}
                    disabled={!hasActiveFilters}
                >
                    ล้างตัวกรอง
                </Button>
            }
        >
            <div className="grid gap-x-5 gap-y-5 md:grid-cols-2 xl:grid-cols-3">
                <Field label="ค้นหา"><Input value={search} onChange={(_, input) => { setSearch(input.value); setPage(1); }} contentBefore={<MagnifyingGlass size={18} aria-hidden="true" />} placeholder="ชื่อหรือรหัสนักศึกษา" size="large" /></Field>
                <Field label="ภาคเรียน"><Select value={term} onChange={(_, option) => { setTerm(option.value); setPage(1); }} size="large"><option value="all">ทุกภาคเรียน</option>{retainedTermOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select></Field>
                <Field label="ระดับการศึกษา"><Select value={level} onChange={(_, option) => { setLevel(option.value); setPage(1); }} size="large"><option value="">ทุกระดับ</option>{retainedLevelOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select></Field>
                <Field label="กลุ่มเรียน"><SearchableSelect value={group} options={retainedGroupOptions} allLabel={role === 'teacher' ? 'เฉพาะกลุ่มที่รับผิดชอบ' : 'ทุกกลุ่มเรียน'} onChange={(value) => { setGroup(value); setPage(1); }} /></Field>
                <Field label="รายวิชา"><SearchableSelect value={subjectCode} options={retainedSubjectOptions} allLabel="ทุกรายวิชา" onChange={(value) => { setSubjectCode(value); setPage(1); }} /></Field>
                <Field label="การตรวจเช็ค">
                    <Select
                        value={
                            showLowMidtermOnly && showIncorrectOnly
                                ? 'both'
                                : showLowMidtermOnly
                                    ? 'low_midterm'
                                    : showIncorrectOnly
                                        ? 'incorrect'
                                        : 'all'
                        }
                        onChange={(_, option) => {
                            if (option.value === 'low_midterm') {
                                setShowLowMidtermOnly(true);
                                setShowIncorrectOnly(false);
                            } else if (option.value === 'incorrect') {
                                setShowLowMidtermOnly(false);
                                setShowIncorrectOnly(true);
                            } else if (option.value === 'both') {
                                setShowLowMidtermOnly(true);
                                setShowIncorrectOnly(true);
                            } else {
                                setShowLowMidtermOnly(false);
                                setShowIncorrectOnly(false);
                            }
                            setPage(1);
                        }}
                        size="large"
                    >
                        <option value="all">ทุกรายการ</option>
                        <option value="low_midterm">คะแนนกลางภาค &lt; 40</option>
                        <option value="incorrect">เช็คการคำนวณจาก ITW</option>
                        <option value="both">ทั้งสองเงื่อนไข</option>
                    </Select>
                </Field>
            </div>
        </Panel>

        </fieldset>}
        <Panel className="score-panel mt-5" title="ตารางคะแนนจากไฟล์นำเข้า" description={importedScores.isPending ? 'กำลังโหลดข้อมูล' : `พบทั้งหมด ${(data?.pagination.total ?? rows.length).toLocaleString('th-TH')} รายการ · หน้านี้ ${studentCount} คน และ ${subjectCount} วิชา`} action={
            <div className="score-panel-actions flex flex-wrap items-center gap-2">
                {!entryActive && <div className="score-entry-start">
                    <Button type="button" appearance="primary" size="small" className="score-entry-start-button" icon={<NotePencil size={18} weight="bold" />} disabled={importedScores.isFetching || !!entryUnavailableReason} title={entryUnavailableReason} onClick={() => startEntry('bulk', rows)}>กรอกคะแนน</Button>
                    {data && entryUnavailableReason && <span className="score-entry-unavailable">{data.can_bulk_edit ? 'เสร็จแล้ว / แก้ไขทีละคน' : 'ยังไม่เปิดให้กรอก'}</span>}
                </div>}
                {!entryActive && <>
                <Button
                    type="button"
                    size="small"
                    appearance={showIncorrectOnly ? 'primary' : 'outline'}
                    icon={<Calculator size={18} weight="bold" />}
                    onClick={() => {
                        if (!showIncorrectOnly) {
                            setShowLowMidtermOnly(false);
                            setShowIncorrectOnly(true);
                        } else {
                            setShowIncorrectOnly(false);
                        }
                        setPage(1);
                    }}
                    disabled={importedScores.isFetching || entryActive}
                >
                    {showIncorrectOnly ? 'แสดงคะแนนทั้งหมด' : 'เช็คการคำนวณจาก ITW'}
                </Button>
                <Button
                    type="button"
                    size="small"
                    appearance={showLowMidtermOnly ? 'primary' : 'outline'}
                    icon={<WarningCircle size={18} weight="bold" />}
                    onClick={() => {
                        if (!showLowMidtermOnly) {
                            setShowIncorrectOnly(false);
                            setShowLowMidtermOnly(true);
                        } else {
                            setShowLowMidtermOnly(false);
                        }
                        setPage(1);
                    }}
                    disabled={importedScores.isFetching || entryActive}
                >
                    {showLowMidtermOnly ? 'แสดงคะแนนทั้งหมด' : 'ตรวจคะแนนกลางภาค < 40'}
                </Button>
                <Button type="button" size="small" appearance="outline" icon={<ListChecks size={18} weight="bold" />} onClick={() => setShowScoreLegend((prev) => !prev)}>
                    {showScoreLegend ? 'ซ่อนคำอธิบาย 1-9' : 'คำอธิบายคะแนน 1-9'}
                </Button>
                <Button type="button" size="small" appearance="outline" icon={<ArrowsClockwise size={18} weight="bold" />} onClick={() => importedScores.refetch()} disabled={importedScores.isFetching || entryActive}>
                    โหลดข้อมูลใหม่
                </Button>
                </>}
            </div>
        }>
            {showIncorrectOnly && !showLowMidtermOnly && importedScores.data && (
                <div className={`mb-4 rounded-xl border p-4 text-sm leading-6 ${data?.calculation_audit.incorrect_rows ? 'border-amber-300 bg-amber-50 text-amber-950' : 'border-emerald-300 bg-emerald-50 text-emerald-950'}`} role="status">
                    <p className="font-black">{data?.calculation_audit.incorrect_rows ? `พบ ${data.calculation_audit.incorrect_rows.toLocaleString('th-TH')} รายการที่ควรตรวจใน ITW` : 'ไม่พบการคำนวณที่ไม่ตรงกัน'}</p>
                    <p className="mt-1">ตรวจเฉพาะเงื่อนไขที่มีข้อมูลครบ ระบบนี้อ่านอย่างเดียวและไม่ได้แก้ข้อมูลต้นทาง</p>
                </div>
            )}
            {showLowMidtermOnly && !showIncorrectOnly && importedScores.data && (
                <div className={`mb-4 rounded-xl border p-4 text-sm leading-6 ${data?.calculation_audit.low_midterm_rows ? 'border-rose-300 bg-rose-50 text-rose-950' : 'border-emerald-300 bg-emerald-50 text-emerald-950'}`} role="status">
                    <p className="font-black">{data?.calculation_audit.low_midterm_rows ? `พบ ${data.calculation_audit.low_midterm_rows.toLocaleString('th-TH')} รายการที่คะแนนกลางภาคต่ำกว่า 40 คะแนน` : 'ไม่พบคะแนนกลางภาคที่ต่ำกว่า 40 คะแนน'}</p>
                    <p className="mt-1">แสดงเฉพาะรายการที่มีคะแนนกลางภาค (คะแนนเก็บ) ต่ำกว่า 40 คะแนน (รวมคะแนน 0 จริง) เพื่อให้ครูผู้สอนและผู้ดูแลระบบตรวจเช็คความถูกต้องหรือติดตามผู้เรียน</p>
                </div>
            )}
            {showIncorrectOnly && showLowMidtermOnly && importedScores.data && (
                <div className="mb-4 rounded-xl border border-purple-300 bg-purple-50 p-4 text-sm leading-6 text-purple-950" role="status">
                    <p className="font-black">กำลังตรวจเช็คทั้งสองเงื่อนไข: รายการที่ควรตรวจใน ITW และคะแนนกลางภาคต่ำกว่า 40 คะแนน</p>
                    <p className="mt-1">แสดงเฉพาะรายการที่เข้าทั้งสองเงื่อนไขพร้อมกัน</p>
                </div>
            )}
            {!entryActive &&             <details className="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-800">
                <summary className="cursor-pointer font-bold">วิธีพิจารณาการคำนวณและเกรดจาก ITW</summary>
                <ol className="mt-3 list-decimal space-y-1 pl-5">
                    <li>วิชาเทียบโอนและเกรดพิเศษ ม, มส, ผ, มผ, ร: ไม่เทียบเกรดจากคะแนนรวม</li>
                    <li>ปลายภาคว่าง (–): ไม่คำนวณเกรดตัวเลข แม้มีคะแนนรวมค้างอยู่; หากเกรด ข ให้แสดง “คำนวณตรงกัน” เพราะถูกต้องตามเงื่อนไขไม่มีคะแนนปลายภาค</li>
                    <li>มีคะแนนปลายภาค (รวม 0 จริง): ตรวจผลรวมช่อง 1–9 ที่มีค่าเทียบกับคะแนนกลางภาค และกลางภาค + ปลายภาคเทียบกับคะแนนรวม เมื่อมีข้อมูลสำหรับคู่ที่ตรวจ</li>
                    <li>เทียบเกรดกับคะแนนรวม; ถ้าคะแนนรวมว่าง ใช้กลางภาค + ปลายภาคแทน: 80 ขึ้นไป = 4, 75–ต่ำกว่า 80 = 3.5, 70–ต่ำกว่า 75 = 3, 65–ต่ำกว่า 70 = 2.5, 60–ต่ำกว่า 65 = 2, 55–ต่ำกว่า 60 = 1.5, 50–ต่ำกว่า 55 = 1, ต่ำกว่า 50 = 0</li>
                    <li>ตัวอย่าง: กลางภาค 52 + ปลายภาค 34 = 86 แต่เกรด ข ต้องตรวจใน ITW เพราะมีคะแนนปลายภาคแล้ว; หากปลายภาคว่างและเกรด ข จะไม่แจ้งว่าเกรดผิด</li>
                    <li>ถ้าข้อมูลไม่ครบ ตรวจได้เฉพาะคู่ที่มีข้อมูล; คะแนนกลางภาคต่ำกว่า 40 เป็นตัวกรองติดตามผู้เรียนแยกจากการตรวจคำนวณ</li>
                </ol>
            </details>}
            {!entryActive && showScoreLegend && (
                <div className="mb-4 rounded-2xl border border-brand-200 bg-brand-50/70 p-4 text-sm text-slate-800 shadow-xs">
                    <div className="flex items-center justify-between pb-2 mb-3 border-b border-brand-200/60">
                        <p className="font-black text-brand-950 flex items-center gap-2">
                            <ListChecks size={20} className="text-brand-700" />
                            ความหมายคะแนนย่อยช่อง 1 - 9 (ตาราง GRADE.DBF ตามระบบ ITW51)
                        </p>
                        <span className="rounded-full bg-brand-200/70 px-2.5 py-0.5 text-xs text-brand-900 font-bold">โครงสร้างไฟล์จริง ITW51</span>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                        {scoreLabels.map((label, index) => (
                            <div key={index} className="flex items-center gap-2.5 rounded-xl bg-white/90 px-3 py-2 border border-brand-100 shadow-xs">
                                <span className="grid size-6 shrink-0 place-items-center rounded-lg bg-brand-700 font-mono text-xs font-black text-white">{index + 1}</span>
                                <span className="truncate font-bold text-slate-800 text-xs" title={label}>{label}</span>
                            </div>
                        ))}
                    </div>
                    <div className="mt-3 flex flex-wrap gap-4 text-xs font-semibold text-slate-600 border-t border-brand-200/50 pt-2.5">
                        <span><strong className="text-slate-800">วิธีเรียน:</strong> พบกลุ่ม (1), ทางไกล (2), ทางไกลพิเศษ (3)</span>
                        <span><strong className="text-sky-800">กลางภาค:</strong> คะแนนเก็บรวม (MIDTERM)</span>
                        <span><strong className="text-amber-800">ปลายภาค:</strong> คะแนนสอบปลายภาค (FINAL)</span>
                        <span><strong className="text-brand-900">รวม:</strong> คะแนนรวมทั้งสิ้น (TOTAL)</span>
                        <span><strong className="text-slate-900">เกรด:</strong> ผลการเรียน (GRADE)</span>
                    </div>
                </div>
            )}

            {importedScores.isPending && <QuerySkeleton rows={7} />}
            {importedScores.isError && <QueryError onRetry={() => importedScores.refetch()} />}
            {importedScores.data && rows.length === 0 && <div className="grid min-h-48 place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm leading-6 text-slate-600">
                {showLowMidtermOnly && showIncorrectOnly
                    ? 'ไม่พบรายการที่คำนวณไม่ตรงกันและคะแนนกลางภาคต่ำกว่า 40 ตามตัวกรองที่เลือก'
                    : showLowMidtermOnly
                        ? 'ไม่พบคะแนนกลางภาคที่ต่ำกว่า 40 คะแนนตามตัวกรองที่เลือก'
                        : showIncorrectOnly
                            ? 'ไม่พบรายการที่คำนวณไม่ตรงกันตามตัวกรองที่เลือก'
                            : 'ไม่พบคะแนนจากข้อมูลนำเข้าตามตัวกรองที่เลือก'}
            </div>}
            {importedScores.data && rows.length > 0 && <>
                {entryActive && <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div role="group" aria-label="เลือกช่องคะแนนที่ต้องการกรอก" className="flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1">
                        {([['all', 'ทุกช่อง'], ['assessments', 'คะแนนย่อย 1–9'], ['exams', 'กลางภาค / ปลายภาค']] as const).map(([value, label]) => <button type="button" key={value} aria-pressed={scoreColumns === value} onClick={() => setScoreColumns(value)} className={`min-h-11 rounded-lg px-3 text-xs font-bold ${scoreColumns === value ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-600'}`}>{label}</button>)}
                    </div>
                    <p className="text-xs text-slate-500">เลือกเฉพาะช่องที่ต้องการ เพื่อกรอกได้เร็วขึ้น</p>
                </div>}
                <p className="mb-2 text-xs leading-5 text-slate-500">เลื่อนซ้าย–ขวาเพื่อดูทุกช่อง · หัวตารางและชื่อคงอยู่ขณะเลื่อน</p>
                <div role="region" aria-label="ตารางคะแนนจากข้อมูลนำเข้า เลื่อนแนวนอนเพื่อดูคะแนนทุกช่อง" tabIndex={0} className="imported-score-scroll rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-brand-200">
                <table className={`imported-score-table w-full text-sm ${entryActive ? `score-entry-table ${scoreColumns === 'exams' ? 'min-w-[620px]' : 'min-w-[1480px]'}` : 'min-w-[2080px]'}`}>
                    <caption className="sr-only">คะแนนจากข้อมูลนำเข้า ตามตัวกรองที่เลือก</caption>
                    <thead className="bg-slate-50 text-slate-700">
                        <tr>
                            {!entryActive && <th scope="col" className="w-14 border-b border-r border-slate-200 bg-slate-50 px-2 py-3 text-center">ลำดับ</th>}
                            {!entryActive && <th scope="col" className="min-w-36 border-b border-r border-slate-200 bg-slate-50 px-3 py-3 text-left">รหัสนักศึกษา</th>}
                            <th scope="col" className="score-student-column min-w-40 border-b border-r border-slate-200 bg-slate-50 px-4 py-3 text-left">ชื่อ-นามสกุล</th>
                            {!entryActive && <th scope="col" className="min-w-36 border-b border-r border-slate-200 px-3 py-3 text-left">กลุ่ม</th>}
                            {!entryActive && <th scope="col" className="min-w-64 border-b border-r border-slate-200 px-4 py-3 text-left">วิชา</th>}
                            {!entryActive && <th scope="col" className="min-w-28 border-b border-r border-slate-200 px-3 py-3 text-center">วิธีเรียน</th>}
                            {showAssessments && scoreLabels.map((label, index) => <th scope="col" key={`${label}-${index}`} className="w-16 border-b border-r border-slate-200 px-2 py-3 text-center" title={label}><span className="block truncate">{index + 1}</span><span className="sr-only">{label}</span></th>)}
                            <th scope="col" className="w-24 border-b border-r border-slate-200 bg-sky-50 px-3 py-3 text-center">กลางภาค</th>
                            {showFinal && <th scope="col" className="w-24 border-b border-r border-slate-200 bg-amber-50 px-3 py-3 text-center">ปลายภาค</th>}
                            <th scope="col" className="w-20 border-b border-r border-slate-200 bg-brand-50 px-3 py-3 text-center">รวม</th>
                            <th scope="col" className="w-20 border-b border-slate-200 px-3 py-3 text-center">เกรด</th>
                            {!entryActive && <th scope="col" className="min-w-72 border-b border-slate-200 px-4 py-3 text-left">ผลตรวจการคำนวณ</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((original, rowIndex) => {
                            const key = scoreRowKey(original);
                            const state = saves[key];
                            const editing = !!state;
                            let row = session?.originals[key] ?? original;
                            if (state) {
                                try { row = { ...row, ...scoreValues(state.fields), grade: state.status === 'saved' && 'grade' in state ? state.grade ?? null : state.version > 0 ? null : row.grade }; } catch { /* Keep last valid totals while typing. */ }
                            }
                            return <tr key={`${row.level}|${row.student_code}|${row.subject_code}|${rowIndex}`} className={`score-data-row ${editing ? 'score-entry-row' : ''}`}>
                            {!entryActive && <td className="border-b border-r border-slate-200 bg-inherit px-2 py-3 text-center font-mono text-xs text-slate-500">{(((data?.pagination.current_page ?? page) - 1) * (data?.pagination.per_page ?? perPage)) + rowIndex + 1}</td>}
                            {!entryActive && <td className="border-b border-r border-slate-200 bg-inherit px-3 py-3 font-mono font-bold text-slate-700">{row.student_code}</td>}
                            <td className="score-student-column border-b border-r border-slate-200 bg-inherit px-4 py-3"><p className="font-black text-slate-950">{row.full_name}</p><p className="mt-1 text-xs text-slate-500">{levelLabel(row.level)} · {row.academic_term}</p>
                                {entryActive && <p className="mt-1 text-xs font-semibold text-slate-600">{row.subject_name} <span className="font-mono">{row.subject_code}</span></p>}
                                {editing ? <div className="mt-2 text-xs">
                                    <p role="status" className={state.status === 'error' ? 'font-bold text-rose-700' : state.status === 'saved' ? 'font-semibold text-emerald-700' : 'font-semibold text-amber-800'}>{state.status === 'saving' ? 'กำลังบันทึก…' : state.status === 'pending' ? 'รอบันทึก…' : state.status === 'error' ? 'บันทึกไม่สำเร็จ' : state.savedVersion > 0 ? 'บันทึกแล้ว' : 'พร้อมกรอก'}</p>
                                    {state.error && <p id={`score-error-${rowIndex}`} role="alert" className="mt-1 break-words text-rose-700">{state.error}</p>}
                                    {state.status === 'error' && <button type="button" disabled={finishing} onClick={() => state.conflict ? void reloadScore(key) : session?.queue.retry(key)} className="mt-2 rounded border border-rose-300 px-2 py-2 font-bold text-rose-800">{state.conflict ? 'ใช้คะแนนล่าสุด' : 'ลองบันทึกอีกครั้ง'}</button>}
                                </div> : (row.entry_completed || data?.can_bulk_edit && !entryActive && canEnterScore(row)) && <div className="score-row-actions">
                                    {row.entry_completed && <span className="score-row-completed" title="บันทึกเสร็จแล้ว"><LockKey size={12} aria-hidden="true" /> เสร็จแล้ว</span>}
                                    {data?.can_bulk_edit && !entryActive && canEnterScore(row) && <button type="button" disabled={importedScores.isFetching} onClick={() => startEntry('individual', [row])} className="score-row-edit" aria-label={`แก้ไขคะแนน ${row.full_name} ${row.subject_code}`} title="แก้ไขคะแนนทีละคน"><NotePencil size={13} aria-hidden="true" />แก้ไข</button>}
                                </div>}
                            </td>
                            {!entryActive && <td className="border-b border-r border-slate-200 px-3 py-3"><p className="font-bold text-slate-800">{row.group_name || row.group_code || '-'}</p>{row.group_name && row.group_code && <p className="mt-1 font-mono text-xs text-slate-500">{row.group_code}</p>}</td>}
                            {!entryActive && <td className="border-b border-r border-slate-200 px-4 py-3"><p className="font-black text-slate-950">{row.subject_name || '-'}</p><p className="mt-1 font-mono text-xs text-slate-500">{row.subject_code}</p></td>}
                            {!entryActive && <td className="border-b border-r border-slate-200 px-3 py-3 text-center text-slate-700">{row.learning_method || '-'}</td>}
                            {showAssessments && normalizeAssessmentScores(row.assessment_scores).map((score, scoreIndex) => <td key={scoreIndex} className="border-b border-r border-slate-200 px-2 py-3 text-center font-mono tabular-nums text-slate-700">{editing ? scoreInput(key, scoreIndex, `คะแนนย่อย ${scoreIndex + 1}`) : nullableScore(score)}</td>)}
                            <td className={`border-b border-r border-slate-200 bg-sky-50/60 px-3 py-3 text-center font-mono font-black tabular-nums ${isLowImportedMidtermScore(row.midterm_score) ? 'text-rose-700' : 'text-sky-900'}`}>{editing ? scoreInput(key, 9, 'คะแนนกลางภาค') : nullableScore(row.midterm_score)}{isLowImportedMidtermScore(row.midterm_score) && <span className="sr-only"> คะแนนกลางภาคต่ำกว่า 40</span>}</td>
                            {showFinal && <td className="border-b border-r border-slate-200 bg-amber-50/60 px-3 py-3 text-center font-mono font-black tabular-nums text-amber-900">{editing ? scoreInput(key, 10, 'คะแนนปลายภาค') : nullableScore(row.final_exam_score)}</td>}
                            <td className="border-b border-r border-slate-200 bg-brand-50/60 px-3 py-3 text-center font-mono font-black tabular-nums text-brand-900">{nullableScore(row.total_score)}</td>
                            <td className="border-b border-slate-200 px-3 py-3 text-center"><span className="inline-flex flex-wrap items-center justify-center gap-1"><span className="inline-flex min-w-10 justify-center rounded-lg bg-slate-100 px-2 py-1 font-mono font-black text-slate-900">{row.grade || '-'}</span>{row.is_remedial && <span className="rounded bg-amber-50 px-1 py-0.5 text-[10px] font-semibold leading-none text-amber-800" title="ผลการเรียนจากรายการสอบซ่อมใน ITW">ซ่อม</span>}</span></td>
                            {!entryActive && <td className="border-b border-slate-200 px-4 py-3">
                                {row.calculation_audit.status === 'incorrect' ? (
                                    <ul className="space-y-1 text-xs font-semibold leading-5">
                                        {row.calculation_audit.issues.map((issue) => <li key={issue.code} className={auditIssueColorClass(issue.code)}>{auditIssueText(issue)}</li>)}
                                    </ul>
                                ) : row.calculation_audit.status === 'correct' ? (
                                    <span className="text-xs font-bold text-emerald-700">คำนวณตรงกัน</span>
                                ) : (
                                    <span className="text-xs text-slate-500">
                                        {formatAuditStatusText(row)}
                                    </span>
                                )}
                            </td>}
                        </tr>; })}
                    </tbody>
                </table>
            </div></>}
            {importedScores.data && <Pagination currentPage={data?.pagination.current_page ?? page} totalPages={data?.pagination.last_page ?? 1} totalItems={data?.pagination.total ?? rows.length} pageSize={data?.pagination.per_page ?? perPage} itemLabel="รายการคะแนน" disabled={importedScores.isFetching || entryActive} onPageChange={setPage} onPageSizeChange={(nextPageSize) => { setPerPage(nextPageSize); setPage(1); }} />}
        </Panel>
        {entryActive && <div className="score-entry-toolbar" role="region" aria-label="สถานะบันทึกคะแนนและบันทึกเสร็จ">
            <div role="status" aria-live="polite" className="score-entry-save-state" title={`บันทึกแล้ว ${savedCount} รายการจาก ${Object.keys(session.originals).length} รายการที่เปิดกรอก`}>
                <span className={`inline-flex items-center gap-1.5 font-semibold ${errors.length ? 'text-rose-700' : hasUnsaved ? 'text-amber-800' : 'text-emerald-700'}`}>
                    {errors.length ? <WarningCircle size={16} /> : hasUnsaved ? <ArrowsClockwise size={16} /> : <CheckCircle size={16} />}
                    {errors.length ? `ไม่สำเร็จ ${errors.length} รายการ` : hasUnsaved ? 'กำลังบันทึก…' : 'บันทึกครบแล้ว'}
                </span>
                <span className="score-entry-save-count">แก้ไขและบันทึกแล้ว {savedCount} รายการ</span>
            </div>
            <Button type="button" appearance="primary" size="small" className="score-entry-finish" icon={<CheckCircle size={16} />} disabled={finishing} onClick={() => void finishEntry()}>{finishing ? 'กำลังปิด…' : 'บันทึกเสร็จ'}</Button>
        </div>}
    </div>;
}

type StudentScorePayload = {
    term: string | null;
    summary: { score: number; maximum_score: number; items: number };
    courses: Array<{ id: string; subject_code: string; subject_name: string; score_ratio: string | null; assignment_score: number | null; exam_score: number | null; total_score: number | null; maximum_score: number; status: string }>;
    disclaimer: string;
};

function StudentScoreSummary() {
    const scores = useQuery({
        queryKey: ['learning', 'scores', 'student'],
        queryFn: ({ signal }) => getFeatureDataWithDemo<StudentScorePayload>('/api/v1/learning/scores', { term: null, summary: { score: 0, maximum_score: 0, items: 0 }, courses: [], disclaimer: '' }, signal),
    });
    if (scores.isPending) return <QuerySkeleton rows={6} />;
    if (scores.isError) return <QueryError onRetry={() => scores.refetch()} />;
    const exportStudentScores = () => downloadExcel('คะแนนของฉัน', [{
        name: 'คะแนน',
        columns: ['รายวิชา', 'รหัสวิชา', 'สัดส่วน', 'คะแนนเก็บ', 'สอบปลายภาค', 'รวม', 'คะแนนเต็ม', 'สถานะ'],
        rows: scores.data.data.courses.map((course) => [course.subject_name, course.subject_code, course.score_ratio ?? 'โครงสร้างเดิม', course.assignment_score, course.exam_score, course.total_score, course.maximum_score, course.status === 'studying' ? 'กำลังเรียน' : course.status]),
    }]);

    return <div>
        <PageHeader category="learning" title="คะแนนของฉัน" description="คะแนนเก็บ คะแนนสอบปลายภาค และคะแนนรวมที่ครูบันทึกในระบบ" icon={ChartBar} actions={scores.data.data.courses.length > 0 ? <Button type="button" appearance="outline" icon={<ExcelFileIcon size={18} />} onClick={exportStudentScores}>ส่งออก Excel</Button> : undefined} />
        <Panel title="รายการคะแนน" description={scores.data.data.disclaimer}>
            {scores.data.data.courses.length === 0 ? <div className="grid min-h-48 place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-center text-sm text-slate-600">ครูยังไม่ได้บันทึกคะแนน</div> : <div className="overflow-x-auto rounded-xl border border-slate-200">
                <table className="min-w-[900px] w-full text-sm"><thead className="bg-slate-50"><tr><th className="px-4 py-3 text-left">รายวิชา</th><th className="px-4 py-3 text-left">รหัสวิชา</th><th className="px-4 py-3 text-center">สัดส่วน</th><th className="px-4 py-3 text-center">คะแนนเก็บ</th><th className="px-4 py-3 text-center">สอบปลายภาค</th><th className="px-4 py-3 text-center">คะแนนรวม</th><th className="px-4 py-3 text-center">สถานะ</th></tr></thead><tbody>{scores.data.data.courses.map((course) => <tr key={course.id} className="border-t border-slate-200"><td className="px-4 py-3 font-bold text-slate-950">{course.subject_name}</td><td className="px-4 py-3 font-mono text-slate-600">{course.subject_code}</td><td className="px-4 py-3 text-center font-mono text-slate-700">{course.score_ratio ?? 'เดิม'}</td><td className="px-4 py-3 text-center font-mono font-bold text-sky-800">{course.assignment_score == null ? '-' : formatScore(course.assignment_score)}</td><td className="px-4 py-3 text-center font-mono font-bold text-amber-800">{course.exam_score == null ? '-' : formatScore(course.exam_score)}</td><td className="px-4 py-3 text-center font-mono font-black text-brand-800">{course.total_score == null ? '-' : formatScore(course.total_score)}</td><td className="px-4 py-3 text-center text-slate-600">{course.status === 'studying' ? 'กำลังเรียน' : course.status}</td></tr>)}</tbody></table>
            </div>}
        </Panel>
    </div>;
}
