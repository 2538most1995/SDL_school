import { useEffect } from 'react';
import { localLoadingActivity, manualDataLoadingActivity } from '../lib/loadingActivity';

/** Existing loading UI takes precedence over the shared query indicator. */
export function useLocalLoadingIndicator(active = true) {
    useEffect(() => {
        if (active) return localLoadingActivity.begin();
    }, [active]);
}

/** For user-triggered reads outside React Query; cleans up on cancel/unmount. */
export function useDataLoading(active: boolean) {
    useEffect(() => {
        if (active) return manualDataLoadingActivity.begin();
    }, [active]);
}
