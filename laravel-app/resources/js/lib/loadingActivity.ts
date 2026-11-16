/** Tracks overlapping requests/indicators without one cleanup clearing another. */
export function createLoadingActivity() {
    let count = 0;
    const listeners = new Set<() => void>();
    const notify = () => listeners.forEach(listener => listener());

    return {
        getSnapshot: () => count,
        subscribe(listener: () => void) {
            listeners.add(listener);
            return () => { listeners.delete(listener); };
        },
        begin() {
            count += 1;
            notify();
            let finished = false;
            return () => {
                if (finished) return;
                finished = true;
                count -= 1;
                notify();
            };
        },
    };
}

export const localLoadingActivity = createLoadingActivity();
export const manualDataLoadingActivity = createLoadingActivity();
