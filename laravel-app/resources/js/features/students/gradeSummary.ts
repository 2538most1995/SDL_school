export type GradeSummaryRow = {
    term: string;
    credits: number;
    type: 'compulsory' | 'elective' | string;
    grade: string | null;
};

export type GradeSummary = {
    gpa: number | null;
    earned: number;
    compulsory: number;
    elective: number;
    gradedCredits: number;
    passed: number;
    total: number;
};

export function summarizeGradeRows(rows: GradeSummaryRow[]): GradeSummary {
    let points = 0;
    let gradedCredits = 0;
    let earned = 0;
    let compulsory = 0;
    let elective = 0;
    let passed = 0;

    rows.forEach((row) => {
        const rawGrade = String(row.grade ?? '').trim();
        const numeric = Number(rawGrade);
        const isNumeric = rawGrade !== '' && Number.isFinite(numeric);
        const isPassed = (isNumeric && numeric >= 1) || rawGrade === 'ผ' || rawGrade === 'ผ่าน';

        if (isNumeric && numeric >= 1) {
            points += numeric * row.credits;
            gradedCredits += row.credits;
        }
        if (!isPassed) return;

        earned += row.credits;
        if (row.type === 'compulsory') compulsory += row.credits;
        if (row.type === 'elective') elective += row.credits;
        passed += 1;
    });

    return {
        gpa: gradedCredits > 0 ? Math.round(((points / gradedCredits) + Number.EPSILON) * 100) / 100 : null,
        earned: Math.round(earned * 100) / 100,
        compulsory: Math.round(compulsory * 100) / 100,
        elective: Math.round(elective * 100) / 100,
        gradedCredits: Math.round(gradedCredits * 100) / 100,
        passed,
        total: rows.length,
    };
}

export function summarizeGradesByTerm(rows: GradeSummaryRow[]): Array<GradeSummary & { term: string }> {
    const terms = new Map<string, GradeSummaryRow[]>();
    rows.forEach((row) => {
        const term = row.term.trim();
        if (!term) return;
        terms.set(term, [...(terms.get(term) ?? []), row]);
    });

    return Array.from(terms, ([term, termRows]) => ({ term, ...summarizeGradeRows(termRows) }));
}
