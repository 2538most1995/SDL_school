import { Card, Text } from './MaterialUI';
import type { ReactNode } from 'react';

type PanelProps = {
    title?: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
};

export function Panel({ title, description, action, children, className = '' }: PanelProps) {
    return (
        <Card appearance="filled" role="region" aria-label={title} className={`ui-panel ${className}`}>
            {(title || description || action) && (
                <div className="ui-panel__header mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        {title && <h2 className="text-lg font-black tracking-[-0.015em] text-slate-950">{title}</h2>}
                        {description && <Text as="p" size={300} className="mt-1 leading-6 text-slate-500">{description}</Text>}
                    </div>
                    {action && <div className="shrink-0">{action}</div>}
                </div>
            )}
            {children}
        </Card>
    );
}
