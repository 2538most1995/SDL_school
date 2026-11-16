import { useEffect, useState, useSyncExternalStore } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useIsFetching, useIsMutating } from '@tanstack/react-query';
import { useLocalLoadingIndicator } from '../hooks/useDataLoading';
import { localLoadingActivity, manualDataLoadingActivity } from '../lib/loadingActivity';

export function LocalLoadingIndicator({ children }: { children: ReactNode }) {
    useLocalLoadingIndicator();
    return children;
}

function LoadingGlyph() {
    return <span className="data-loading-ring" aria-hidden="true" />;
}

/** Centered fallback for file/image reads which previously had only plain text. */
export function CenteredDataLoading({ label = 'กำลังโหลดข้อมูล' }: { label?: string }) {
    useLocalLoadingIndicator();
    return <div className="data-loading-feedback flex min-h-28 flex-col items-center justify-center gap-3 text-sm" role="status" aria-live="polite"><LoadingGlyph /><span>{label}</span></div>;
}

/** Fills gaps only: existing spinners, skeletons and mutation feedback win. */
export function GlobalQueryLoading() {
    const fetching = useIsFetching({
        predicate: query => query.isActive() && query.meta?.loadingIndicator !== 'silent',
    });
    const mutations = useIsMutating();
    const local = useSyncExternalStore(localLoadingActivity.subscribe, localLoadingActivity.getSnapshot);
    const manual = useSyncExternalStore(manualDataLoadingActivity.subscribe, manualDataLoadingActivity.getSnapshot);
    const waiting = (fetching > 0 || manual > 0) && local === 0 && mutations === 0;
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!waiting) {
            setVisible(false);
            return;
        }
        // Fast cached reads should not flash a spinner. Never delay the request.
        const timer = window.setTimeout(() => setVisible(true), 180);
        return () => window.clearTimeout(timer);
    }, [waiting]);

    if (!waiting || !visible) return null;

    return createPortal(
        <div className="data-loading-overlay" data-global-query-loading role="status" aria-live="polite" aria-atomic="true">
            <div className="data-loading-indicator">
                <LoadingGlyph />
                <span className="font-medium">กำลังโหลดข้อมูล</span>
            </div>
        </div>,
        document.body,
    );
}
