export type ImportedScoreFilters = {
    term: string;
    level: string;
    group: string;
    subjectCode: string;
    search: string;
    calculationStatus?: 'incorrect' | '';
    midtermStatus?: 'below_40' | '';
    page?: number;
    perPage?: number;
};

export const IMPORTED_ASSESSMENT_COUNT = 9;

export function isLowImportedMidtermScore(value: number | null | undefined): boolean {
    return typeof value === 'number' && Number.isFinite(value) && value < 40;
}

export const ITW51_ASSESSMENT_LABELS: readonly string[] = [
    'คะแนนบันทึกการเรียนรู้',
    'คะแนนบันทึกการฝึกทักษะ',
    'คะแนนรายงาน/รายงานเชิงปฏิบัติการ',
    'คะแนนแบบฝึกหัด',
    'คะแนนแต้มสะสมงาน',
    'คะแนนผลงาน/ชิ้นงาน',
    'คะแนนโครงงาน',
    'คะแนนทดสอบย่อย',
    'คะแนนอื่นๆ',
] as const;

export function buildImportedScoresPath(filters: ImportedScoreFilters): string {
    const query = new URLSearchParams();
    if (filters.term) query.set('term', filters.term);
    if (filters.level) query.set('level', filters.level);
    if (filters.group) query.set('group', filters.group);
    if (filters.subjectCode) query.set('subject_code', filters.subjectCode);
    if (filters.search.trim()) query.set('search', filters.search.trim());
    if (filters.calculationStatus) query.set('calculation_status', filters.calculationStatus);
    if (filters.midtermStatus) query.set('midterm_status', filters.midtermStatus);
    if ((filters.page ?? 1) > 1) query.set('page', String(filters.page));
    if (filters.perPage) query.set('per_page', String(filters.perPage));

    return `/api/v1/learning/scores/imported${query.size ? `?${query.toString()}` : ''}`;
}

export function importedAssessmentLabels(labels?: string[]): string[] {
    return Array.from({ length: IMPORTED_ASSESSMENT_COUNT }, (_, index) => {
        const label = labels?.[index]?.trim();
        if (label) return label;
        if (labels && labels.length > 0) return `คะแนน ${index + 1}`;
        return ITW51_ASSESSMENT_LABELS[index] ?? `คะแนน ${index + 1}`;
    });
}

export function normalizeAssessmentScores(scores?: Array<number | null>): Array<number | null> {
    return Array.from({ length: IMPORTED_ASSESSMENT_COUNT }, (_, index) => {
        const value = scores?.[index];
        return typeof value === 'number' && Number.isFinite(value) ? value : null;
    });
}

export function formatAuditScore(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }
    if (typeof value === 'string') {
        const numeric = Number(value);
        if (!Number.isNaN(numeric) && value.trim() !== '') {
            return Number.isInteger(numeric) ? String(numeric) : numeric.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
        }
        return value.trim();
    }
    if (typeof value === 'number') {
        if (!Number.isFinite(value)) return '-';
        return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
    }
    return String(value);
}

export function formatAuditIssueText(issue: { label: string; expected: number | string; actual: number | string }): string {
    return `${issue.label}: ควรเป็น ${formatAuditScore(issue.expected)} แต่ ITW เป็น ${formatAuditScore(issue.actual)}`;
}

export function formatAuditStatusText(row: {
    final_exam_score?: number | null;
    grade?: string | null;
    calculation_audit: {
        status: 'correct' | 'incorrect' | 'not_checkable';
        issues: Array<{ label: string; expected: number | string; actual: number | string }>;
    };
}): string {
    if (row.calculation_audit.status === 'incorrect') {
        return row.calculation_audit.issues.map(formatAuditIssueText).join(' | ');
    }
    if (row.calculation_audit.status === 'correct') {
        return 'คำนวณตรงกัน';
    }
    if (row.final_exam_score === null || row.final_exam_score === undefined || row.grade === 'ข' || row.grade === 'ม' || row.grade === 'มส') {
        return 'ไม่มีคะแนนปลายภาค';
    }
    return 'ข้อมูลไม่ครบสำหรับตรวจ';
}
