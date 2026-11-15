export type ContentMove = { id: string; placement: 'before' | 'after' | 'first'; anchor_id?: string };

/** Preview pending moves without changing server/cache data. */
export function previewContentOrder<T extends { id: string }>(items: T[], moves: ContentMove[]): T[] {
    const ordered = [...items];
    for (const move of moves) {
        const from = ordered.findIndex(item => item.id === move.id);
        if (from < 0 || move.id === move.anchor_id) continue;
        if (move.placement !== 'first' && !ordered.some(item => item.id === move.anchor_id)) continue;
        const [item] = ordered.splice(from, 1);
        const index = move.placement === 'first' ? 0 : ordered.findIndex(item => item.id === move.anchor_id) + (move.placement === 'after' ? 1 : 0);
        ordered.splice(index, 0, item);
    }
    return ordered;
}
