type CoverSources = { coverPath: string | null; pdfPath: string | null };
let pdfQueue: Promise<void> = Promise.resolve();

/** A missing stored cover can be rebuilt from the authorized PDF, locally. */
export async function loadPlanCover(
    sources: CoverSources,
    load: (path: string) => Promise<Blob>,
    render: (pdf: File) => Promise<{ blob: Blob }>,
): Promise<Blob | null> {
    if (sources.coverPath) {
        try {
            return await load(sources.coverPath);
        } catch (error) {
            if (!sources.pdfPath) throw error;
        }
    }
    if (!sources.pdfPath) return null;
    // Rebuilding several missing covers must not load many 20 MB PDFs at once.
    const job = pdfQueue.then(async () => {
        const pdf = await load(sources.pdfPath!);
        return (await render(new File([pdf], 'lesson-plan.pdf', { type: 'application/pdf' }))).blob;
    });
    pdfQueue = job.then(() => undefined, () => undefined);
    return job;
}
