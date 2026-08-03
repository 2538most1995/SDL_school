import { Tooltip } from './MaterialUI';
import type { ReactNode } from 'react';

export function EllipsisText({ children, title }: { children: ReactNode; title: string }) {
    return (
        <Tooltip content={title} relationship="description" positioning="above-start" withArrow>
            <span className="block min-w-0 max-w-full overflow-hidden text-ellipsis whitespace-nowrap text-slate-950">{children}</span>
        </Tooltip>
    );
}
