export type ScoreGridPosition = { row: number; column: number };

const arrowMovements: Record<string, readonly [row: number, column: number]> = {
    ArrowLeft: [0, -1],
    ArrowRight: [0, 1],
    ArrowUp: [-1, 0],
    ArrowDown: [1, 0],
};

export function scoreGridCellKey(row: number, column: number): string {
    return `${row}:${column}`;
}

export function isScoreGridNavigationKey(key: string): boolean {
    return key in arrowMovements;
}

export function nextScoreGridPosition(
    key: string,
    row: number,
    column: number,
    rowCount: number,
    columnCount: number,
): ScoreGridPosition | null {
    const movement = arrowMovements[key];
    if (!movement) return null;

    const next = { row: row + movement[0], column: column + movement[1] };
    if (next.row < 0 || next.row >= rowCount || next.column < 0 || next.column >= columnCount) return null;

    return next;
}
