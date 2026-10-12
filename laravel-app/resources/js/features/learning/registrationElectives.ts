type ElectiveMetadata = { code: string; name: string; credits: number };
type ElectiveSelection = ElectiveMetadata & { registered: boolean; transferred: boolean; remark: string };

/** Keep saved/custom rows while omitting untouched recommendations from the registration document. */
export function electivesForSave<T extends ElectiveSelection>(rows: T[], existingCodes: string[], recommendations: ElectiveMetadata[]): T[] {
    const existing = new Set(existingCodes.map((code) => code.trim()));
    return rows.filter((row) => row.registered || row.transferred || row.remark.trim() !== ''
        || existing.has(row.code.trim())
        || !recommendations.some((subject) => subject.code.trim() === row.code.trim()
            && subject.name === row.name && subject.credits === row.credits));
}
