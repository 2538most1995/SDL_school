export type ScoreFields = string[];
export type ScoreSaveState = {
    fields: ScoreFields;
    savedFields: ScoreFields;
    token: string;
    version: number;
    savedVersion: number;
    status: 'saved' | 'pending' | 'saving' | 'error';
    error: string;
    conflict: boolean;
    grade?: string | null;
};
export type ScoreSaveResult = { edit_token: string; grade?: string | null };
export type ScorePersistence = (key: string, fields: ScoreFields, token: string) => Promise<ScoreSaveResult>;

export function scoreFields(row: { assessment_scores: Array<number | null>; midterm_score: number | null; final_exam_score: number | null }): ScoreFields {
    return [...Array.from({ length: 9 }, (_, i) => row.assessment_scores[i] ?? null), row.midterm_score, row.final_exam_score].map(value => value == null ? '' : String(value));
}

export function scoreValues(fields: ScoreFields) {
    const numbers = fields.map(value => value.trim() === '' ? null : Number(value));
    if (numbers.length !== 11 || numbers.some(value => value !== null && (!Number.isFinite(value) || value < 0 || value > 100))) {
        throw new Error('กรอกคะแนน 0–100 หรือเว้นว่าง');
    }
    if (numbers[9] !== null && numbers[10] !== null && numbers[9] + numbers[10] > 100) {
        throw new Error('คะแนนกลางภาคและปลายภาครวมกันต้องไม่เกิน 100');
    }
    return { assessment_scores: numbers.slice(0, 9), midterm_score: numbers[9], final_exam_score: numbers[10],
        total_score: numbers[9] === null || numbers[10] === null ? null : numbers[9] + numbers[10] };
}

/** One request at a time; edits made during a save use its returned token on the next request. */
export class ImportedScoreAutosave {
    private rows: Record<string, ScoreSaveState> = {};
    private listeners = new Set<() => void>();
    private timer: ReturnType<typeof setTimeout> | undefined;
    private running: Promise<void> | null = null;
    private stopped = false;
    private force = false;
    private due = new Map<string, number>();

    private persist: ScorePersistence;
    private delay: number;

    constructor(initial: Record<string, { fields: ScoreFields; token: string }>, persist: ScorePersistence, delay = 650) {
        this.persist = persist; this.delay = delay;
        for (const [key, row] of Object.entries(initial)) {
            this.rows[key] = { fields: [...row.fields], savedFields: [...row.fields], token: row.token, version: 0, savedVersion: 0, status: 'saved', error: '', conflict: false };
        }
    }
    subscribe = (listener: () => void) => { this.listeners.add(listener); return () => { this.listeners.delete(listener); }; };
    getSnapshot = () => this.rows;
    private emit() { this.rows = { ...this.rows }; this.listeners.forEach(listener => listener()); }
    hasUnsaved = () => Object.values(this.rows).some(row => row.status !== 'saved');

    set(key: string, column: number, value: string) {
        const row = this.rows[key];
        if (!row || this.stopped || row.fields[column] === value) return;
        const fields = [...row.fields];
        fields[column] = value;
        if (column < 9) {
            const filled = fields.slice(0, 9).filter(score => score.trim() !== '');
            fields[9] = filled.length ? String(filled.reduce((total, score) => total + Number(score), 0)) : '';
        }
        this.rows[key] = { ...row, fields, version: row.version + 1,
            status: row.conflict ? 'error' : 'pending', error: row.conflict ? row.error : '' };
        this.due.set(key, Date.now() + this.delay);
        this.emit();
        this.schedule();
    }
    retry(key: string) {
        const row = this.rows[key];
        if (!row || row.conflict) return;
        this.rows[key] = { ...row, status: 'pending', error: '' };
        this.due.set(key, 0);
        this.emit();
        this.schedule();
    }
    replace(key: string, fields: ScoreFields, token: string) {
        if (this.rows[key]?.status === 'saving') return;
        this.rows[key] = { fields: [...fields], savedFields: [...fields], token, version: 0, savedVersion: 0, status: 'saved', error: '', conflict: false };
        this.due.delete(key);
        this.emit();
    }
    markConflict(key: string, message: string) {
        const row = this.rows[key];
        if (!row) return;
        this.rows[key] = { ...row, status: 'error', conflict: true, error: message };
        this.emit();
    }
    remove(key: string) {
        if (this.rows[key]?.status === 'saving') return;
        delete this.rows[key]; this.due.delete(key); this.emit();
    }
    blur(key: string) {
        if (this.rows[key]?.status === 'pending') {
            this.due.set(key, 0);
            this.schedule();
        }
    }
    private schedule() {
        clearTimeout(this.timer);
        if (this.stopped || this.running) return;
        const waiting = Object.keys(this.rows).filter(key => this.rows[key].status === 'pending');
        if (!waiting.length) return;
        const next = Math.min(...waiting.map(key => this.due.get(key) ?? 0));
        this.timer = setTimeout(() => { void this.pump(); }, Math.max(0, next - Date.now()));
    }
    private pump(): Promise<void> {
        if (this.running) return this.running;
        this.running = (async () => {
            while (!this.stopped) {
                const key = Object.keys(this.rows).find(key => this.rows[key].status === 'pending' && (this.force || (this.due.get(key) ?? 0) <= Date.now()));
                if (!key) break;
                const snapshot = this.rows[key];
                this.rows[key] = { ...snapshot, status: 'saving' };
                this.emit();
                try {
                    scoreValues(snapshot.fields);
                    const result = await this.persist(key, [...snapshot.fields], snapshot.token);
                    if (!result.edit_token) throw new Error('เซิร์ฟเวอร์ยืนยันคะแนนไม่ครบ กรุณาโหลดคะแนนล่าสุด');
                    const current = this.rows[key];
                    this.rows[key] = { ...current, token: result.edit_token, savedFields: [...snapshot.fields], savedVersion: snapshot.version,
                        status: current.version === snapshot.version ? 'saved' : 'pending', error: '', conflict: false,
                        ...('grade' in result ? { grade: result.grade } : {}) };
                } catch (error) {
                    const current = this.rows[key];
                    const status = (error as { status?: number }).status;
                    const conflict = status === 409 || status === 404;
                    this.rows[key] = { ...current, status: !conflict && current.version !== snapshot.version ? 'pending' : 'error',
                        error: error instanceof Error ? error.message : 'บันทึกไม่สำเร็จ กรุณาลองใหม่', conflict };
                }
                this.emit();
            }
        })().finally(() => { this.running = null; this.schedule(); });
        return this.running;
    }
    async flush(): Promise<boolean> {
        clearTimeout(this.timer);
        this.force = true;
        try {
            await this.pump();
            // An in-flight pump may have finished just before force was set.
            if (Object.values(this.rows).some(row => row.status === 'pending')) await this.pump();
            return !this.hasUnsaved();
        } finally { this.force = false; }
    }
    stop() { this.stopped = true; clearTimeout(this.timer); }
}
