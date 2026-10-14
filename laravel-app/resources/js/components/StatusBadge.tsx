import { Badge } from './MaterialUI';
import type { ReactNode } from 'react';

export type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const tones: Record<StatusTone, 'success' | 'warning' | 'danger' | 'informative' | 'subtle'> = {
    success: 'success',
    warning: 'warning',
    danger: 'danger',
    info: 'informative',
    neutral: 'subtle',
};

export function StatusBadge({ children, tone = 'neutral' }: { children: ReactNode; tone?: StatusTone }) {
    return <Badge appearance="tint" color={tones[tone]} size="medium" className="ui-status-badge whitespace-nowrap font-semibold"><span className="inline-flex items-center gap-1.5"><span aria-hidden="true" className="size-1.5 shrink-0 rounded-full bg-current" />{children}</span></Badge>;
}
