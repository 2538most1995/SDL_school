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
} from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useDeferredValue, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Button, Field, Input, Select } from '../../components/MaterialUI';
import { SearchableSelect } from '../../components/SearchableSelect';
import { PageHeader } from '../../components/PageHeader';
import { Pagination } from '../../components/Pagination';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole } from '../../context/DemoRoleContext';
import { showSuccessAlert } from '../../lib/feedback';
import { downloadExcel } from '../../lib/excel';
import { retainSelectedFilterOption } from '../../lib/filterOptions';
import { getFeatureDataWithDemo, sendFeatureData } from '../api';
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

function ImportedScoresPage() {
    const queryClient = useQueryClient();
    const [draft, setDraft] = useState<ImportedScoreRow | null>(null);
    const [editError, setEditError] = useState('');
    const [searchParams] = useSearchParams();
    const tableScrollRef = useRef<HTMLDivElement>(null);
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
    const importedScores = useQuery({
        queryKey: ['learning', 'scores', 'imported', term, level, group, subjectCode, deferredSearch, showIncorrectOnly, showLowMidtermOnly, page, perPage],
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
        enabled: draft === null,
        refetchOnWindowFocus: false,
    });
    const data = importedScores.data?.data;
    const rows = data?.rows ?? [];
    const scoreLabels = useMemo(() => importedAssessmentLabels(data?.score_labels), [data?.score_labels]);
    const saveImported = useMutation({
        mutationFn: () => {
            if (!draft || !data?.batch_key) throw new Error('กรุณาโหลดข้อมูลใหม่');
            return sendFeatureData('/api/v1/learning/scores/imported', 'PUT', {
                    batch_key: data.batch_key, student_code: draft.student_code, level: draft.level,
                    subject_code: draft.subject_code, academic_term: draft.academic_term, edit_token: draft.edit_token,
                    assessment_scores: draft.assessment_scores, midterm_score: draft.midterm_score, final_exam_score: draft.final_exam_score,
            });
        },
        onSuccess: async () => {
            setDraft(null); setEditError('');
            await queryClient.invalidateQueries();
            void showSuccessAlert('บันทึกคะแนนแล้ว กรุณาส่งออก ZIP (ITW) และคำนวณเกรดใน ITW ใหม่');
        },
        onError: (error: Error) => setEditError(error.message),
    });
    useEffect(() => {
        if (!draft) return;
        const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', guard);
        return () => window.removeEventListener('beforeunload', guard);
    }, [draft]);
    const setScore = (field: 'midterm_score' | 'final_exam_score' | number, raw: string) => {
        setDraft((current) => {
            if (!current) return current;
            const next = { ...current, assessment_scores: [...current.assessment_scores] };
            const value = raw === '' ? null : Number(raw);
            if (typeof field === 'number') {
                next.assessment_scores[field] = value;
                const filled = next.assessment_scores.filter((score): score is number => score !== null);
                next.midterm_score = filled.length ? filled.reduce((sum, score) => sum + score, 0) : null;
            } else next[field] = value;
            next.total_score = next.midterm_score === null || next.final_exam_score === null ? null : next.midterm_score + next.final_exam_score;
            next.grade = null;
            return next;
        });
    };
    const scoreInput = (value: number | null, field: 'midterm_score' | 'final_exam_score' | number, label: string) => (
        <input type="number" min={0} max={100} step="any" aria-label={label} value={value ?? ''} disabled={saveImported.isPending}
            onChange={(event) => setScore(field, event.target.value)} className="w-20 rounded border border-brand-500 bg-white px-2 py-2 text-center text-slate-950 focus:ring-2 focus:ring-brand-300" />
    );


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
        <p role="status" className="mb-5 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-950"><strong>{data?.can_edit ? 'กรอกและแก้ไขคะแนนได้:' : 'คะแนนจากข้อมูลนำเข้า:'}</strong> กดแก้ไขคะแนนในแถวนักศึกษา กรอกคะแนนย่อย 1–9 กลางภาค (คะแนนเก็บ) และปลายภาค แล้วบันทึก จากนั้นส่งออก ZIP (ITW) เพื่อนำ GRADE.DBF ไปแทนไฟล์ใน ITW ให้ ITW จัดดัชนีและคำนวณเกรดใหม่ ช่องว่างหมายถึงยังไม่มีคะแนน ค่า 0 คือศูนย์จริง ระบบจะปฏิเสธทศนิยมถ้า DBF เดิมไม่รองรับ <span className="font-bold text-rose-700">คะแนนกลางภาคต่ำกว่า 40 แสดงเป็นสีแดง</span></p>

        {editError && <p role="alert" className="mb-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{editError}</p>}
        <fieldset disabled={draft !== null} className="contents">
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
                <Field label="กลุ่มเรียน"><SearchableSelect value={group} options={retainedGroupOptions} allLabel="ทุกกลุ่มเรียน" onChange={(value) => { setGroup(value); setPage(1); }} /></Field>
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

        </fieldset>
        <Panel className="mt-5" title="ตารางคะแนนจากไฟล์นำเข้า" description={importedScores.isPending ? 'กำลังโหลดข้อมูล' : `พบทั้งหมด ${(data?.pagination.total ?? rows.length).toLocaleString('th-TH')} รายการ · หน้านี้ ${studentCount} คน และ ${subjectCount} วิชา`} action={
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
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
                    disabled={importedScores.isFetching || draft !== null}
                >
                    {showIncorrectOnly ? 'แสดงคะแนนทั้งหมด' : 'เช็คการคำนวณจาก ITW'}
                </Button>
                <Button
                    type="button"
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
                    disabled={importedScores.isFetching || draft !== null}
                >
                    {showLowMidtermOnly ? 'แสดงคะแนนทั้งหมด' : 'ตรวจคะแนนกลางภาค < 40'}
                </Button>
                <Button type="button" appearance="outline" icon={<ListChecks size={18} weight="bold" />} onClick={() => setShowScoreLegend((prev) => !prev)}>
                    {showScoreLegend ? 'ซ่อนคำอธิบาย 1-9' : 'คำอธิบายคะแนน 1-9'}
                </Button>
                <Button type="button" appearance="outline" icon={<ArrowsClockwise size={18} weight="bold" />} onClick={() => importedScores.refetch()} disabled={importedScores.isFetching || draft !== null}>
                    โหลดข้อมูลใหม่
                </Button>
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
            <details className="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-800">
                <summary className="cursor-pointer font-bold">วิธีพิจารณาการคำนวณและเกรดจาก ITW</summary>
                <ol className="mt-3 list-decimal space-y-1 pl-5">
                    <li>วิชาเทียบโอนและเกรดพิเศษ ม, มส, ผ, มผ, ร: ไม่เทียบเกรดจากคะแนนรวม</li>
                    <li>ปลายภาคว่าง (–): ไม่คำนวณเกรดตัวเลข แม้มีคะแนนรวมค้างอยู่; หากเกรด ข ให้แสดง “คำนวณตรงกัน” เพราะถูกต้องตามเงื่อนไขไม่มีคะแนนปลายภาค</li>
                    <li>มีคะแนนปลายภาค (รวม 0 จริง): ตรวจผลรวมช่อง 1–9 ที่มีค่าเทียบกับคะแนนกลางภาค และกลางภาค + ปลายภาคเทียบกับคะแนนรวม เมื่อมีข้อมูลสำหรับคู่ที่ตรวจ</li>
                    <li>เทียบเกรดกับคะแนนรวม; ถ้าคะแนนรวมว่าง ใช้กลางภาค + ปลายภาคแทน: 80 ขึ้นไป = 4, 75–ต่ำกว่า 80 = 3.5, 70–ต่ำกว่า 75 = 3, 65–ต่ำกว่า 70 = 2.5, 60–ต่ำกว่า 65 = 2, 55–ต่ำกว่า 60 = 1.5, 50–ต่ำกว่า 55 = 1, ต่ำกว่า 50 = 0</li>
                    <li>ตัวอย่าง: กลางภาค 52 + ปลายภาค 34 = 86 แต่เกรด ข ต้องตรวจใน ITW เพราะมีคะแนนปลายภาคแล้ว; หากปลายภาคว่างและเกรด ข จะไม่แจ้งว่าเกรดผิด</li>
                    <li>ถ้าข้อมูลไม่ครบ ตรวจได้เฉพาะคู่ที่มีข้อมูล; คะแนนกลางภาคต่ำกว่า 40 เป็นตัวกรองติดตามผู้เรียนแยกจากการตรวจคำนวณ</li>
                </ol>
            </details>
            {showScoreLegend && (
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
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs leading-5 text-slate-500">เลื่อนซ้าย–ขวาเพื่อดูทุกช่อง · หัวตารางและชื่อคงอยู่ขณะเลื่อน</p>
                    <div className="flex flex-wrap gap-2">
                        <Button appearance="outline" size="small" onClick={() => tableScrollRef.current?.scrollTo({ left: 0 })}>ข้อมูลนักศึกษา</Button>
                        <Button appearance="outline" size="small" onClick={() => {
                            const container = tableScrollRef.current;
                            const target = container?.querySelector<HTMLElement>('[data-score-column]');
                            if (container && target) container.scrollTo({ left: Math.max(0, target.offsetLeft - (window.innerWidth >= 768 ? 240 : 160)) });
                        }}>ไปช่องคะแนน</Button>
                        <Button appearance="outline" size="small" onClick={() => tableScrollRef.current?.scrollTo({ left: tableScrollRef.current.scrollWidth })}>ผลตรวจการคำนวณ</Button>
                    </div>
                </div>
                <div ref={tableScrollRef} role="region" aria-label="ตารางคะแนนจากข้อมูลนำเข้า เลื่อนแนวนอนเพื่อดูคะแนนทุกช่อง" tabIndex={0} className="imported-score-scroll rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-brand-200">
                <table className="imported-score-table w-full min-w-[2080px] text-sm">
                    <caption className="sr-only">คะแนนจากข้อมูลนำเข้า ตามตัวกรองที่เลือก</caption>
                    <thead className="bg-slate-50 text-slate-700">
                        <tr>
                            <th scope="col" className="w-14 border-b border-r border-slate-200 bg-slate-50 px-2 py-3 text-center">ลำดับ</th>
                            <th scope="col" className="min-w-36 border-b border-r border-slate-200 bg-slate-50 px-3 py-3 text-left">รหัสนักศึกษา</th>
                            <th scope="col" className="score-student-column min-w-40 border-b border-r border-slate-200 bg-slate-50 px-4 py-3 text-left">ชื่อ-นามสกุล</th>
                            <th scope="col" className="min-w-36 border-b border-r border-slate-200 px-3 py-3 text-left">กลุ่ม</th>
                            <th scope="col" className="min-w-64 border-b border-r border-slate-200 px-4 py-3 text-left">วิชา</th>
                            <th scope="col" className="min-w-28 border-b border-r border-slate-200 px-3 py-3 text-center">วิธีเรียน</th>
                            {scoreLabels.map((label, index) => <th scope="col" data-score-column={index === 0 ? true : undefined} key={`${label}-${index}`} className="w-16 border-b border-r border-slate-200 px-2 py-3 text-center" title={label}><span className="block truncate">{index + 1}</span><span className="sr-only">{label}</span></th>)}
                            <th scope="col" className="w-24 border-b border-r border-slate-200 bg-sky-50 px-3 py-3 text-center">กลางภาค</th>
                            <th scope="col" className="w-24 border-b border-r border-slate-200 bg-amber-50 px-3 py-3 text-center">ปลายภาค</th>
                            <th scope="col" className="w-20 border-b border-r border-slate-200 bg-brand-50 px-3 py-3 text-center">รวม</th>
                            <th scope="col" className="w-20 border-b border-slate-200 px-3 py-3 text-center">เกรด</th>
                            <th scope="col" className="min-w-72 border-b border-slate-200 px-4 py-3 text-left">ผลตรวจการคำนวณ</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((original, rowIndex) => {
                            const editing = draft !== null && draft.edit_token === original.edit_token;
                            const row = editing ? draft : original;
                            return <tr key={`${row.level}|${row.student_code}|${row.subject_code}|${rowIndex}`} className="score-data-row">
                            <td className="border-b border-r border-slate-200 bg-inherit px-2 py-3 text-center font-mono text-xs text-slate-500">{(((data?.pagination.current_page ?? page) - 1) * (data?.pagination.per_page ?? perPage)) + rowIndex + 1}</td>
                            <td className="border-b border-r border-slate-200 bg-inherit px-3 py-3 font-mono font-bold text-slate-700">{row.student_code}</td>
                            <td className="score-student-column border-b border-r border-slate-200 bg-inherit px-4 py-3"><p className="font-black text-slate-950">{row.full_name}</p><p className="mt-1 text-xs text-slate-500">{levelLabel(row.level)} · {row.academic_term}</p>
                                {data?.can_edit && (editing ? <div className="mt-2 flex flex-wrap gap-2"><button type="button" onClick={() => saveImported.mutate()} disabled={saveImported.isPending} className="rounded bg-brand-700 px-3 py-2 text-xs font-bold text-white">{saveImported.isPending ? 'กำลังบันทึก…' : 'บันทึกคะแนน'}</button><button type="button" disabled={saveImported.isPending} onClick={() => { setDraft(null); setEditError(''); }} className="rounded border border-slate-300 px-3 py-2 text-xs">ยกเลิก</button></div> : !row.is_transferred && !['ม', 'มส', 'ผ', 'มผ', 'ร'].includes(row.grade ?? '') && <button type="button" disabled={draft !== null || importedScores.isFetching} onClick={() => { setEditError(''); setDraft({ ...row, assessment_scores: normalizeAssessmentScores(row.assessment_scores) }); }} className="mt-2 rounded border border-brand-600 bg-white px-3 py-2 text-xs font-bold text-brand-800 disabled:cursor-not-allowed disabled:opacity-50">แก้ไขคะแนน</button>)}
                            </td>
                            <td className="border-b border-r border-slate-200 px-3 py-3"><p className="font-bold text-slate-800">{row.group_name || row.group_code || '-'}</p>{row.group_name && row.group_code && <p className="mt-1 font-mono text-xs text-slate-500">{row.group_code}</p>}</td>
                            <td className="border-b border-r border-slate-200 px-4 py-3"><p className="font-black text-slate-950">{row.subject_name || '-'}</p><p className="mt-1 font-mono text-xs text-slate-500">{row.subject_code}</p></td>
                            <td className="border-b border-r border-slate-200 px-3 py-3 text-center text-slate-700">{row.learning_method || '-'}</td>
                            {normalizeAssessmentScores(row.assessment_scores).map((score, scoreIndex) => <td key={scoreIndex} className="border-b border-r border-slate-200 px-2 py-3 text-center font-mono tabular-nums text-slate-700">{editing ? scoreInput(score, scoreIndex, `คะแนนย่อย ${scoreIndex + 1}`) : nullableScore(score)}</td>)}
                            <td className={`border-b border-r border-slate-200 bg-sky-50/60 px-3 py-3 text-center font-mono font-black tabular-nums ${isLowImportedMidtermScore(row.midterm_score) ? 'text-rose-700' : 'text-sky-900'}`}>{editing ? scoreInput(row.midterm_score, 'midterm_score', 'คะแนนกลางภาค') : nullableScore(row.midterm_score)}{isLowImportedMidtermScore(row.midterm_score) && <span className="sr-only"> คะแนนกลางภาคต่ำกว่า 40</span>}</td>
                            <td className="border-b border-r border-slate-200 bg-amber-50/60 px-3 py-3 text-center font-mono font-black tabular-nums text-amber-900">{editing ? scoreInput(row.final_exam_score, 'final_exam_score', 'คะแนนปลายภาค') : nullableScore(row.final_exam_score)}</td>
                            <td className="border-b border-r border-slate-200 bg-brand-50/60 px-3 py-3 text-center font-mono font-black tabular-nums text-brand-900">{nullableScore(row.total_score)}</td>
                            <td className="border-b border-slate-200 px-3 py-3 text-center"><span className="inline-flex flex-wrap items-center justify-center gap-1"><span className="inline-flex min-w-10 justify-center rounded-lg bg-slate-100 px-2 py-1 font-mono font-black text-slate-900">{editing ? 'รอ ITW' : row.grade || '-'}</span>{row.is_remedial && <span className="rounded bg-amber-50 px-1 py-0.5 text-[10px] font-semibold leading-none text-amber-800" title="ผลการเรียนจากรายการสอบซ่อมใน ITW">ซ่อม</span>}</span></td>
                            <td className="border-b border-slate-200 px-4 py-3">
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
                            </td>
                        </tr>; })}
                    </tbody>
                </table>
            </div></>}
            {importedScores.data && <Pagination currentPage={data?.pagination.current_page ?? page} totalPages={data?.pagination.last_page ?? 1} totalItems={data?.pagination.total ?? rows.length} pageSize={data?.pagination.per_page ?? perPage} itemLabel="รายการคะแนน" disabled={importedScores.isFetching || draft !== null} onPageChange={setPage} onPageSizeChange={(nextPageSize) => { setPerPage(nextPageSize); setPage(1); }} />}
        </Panel>
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
