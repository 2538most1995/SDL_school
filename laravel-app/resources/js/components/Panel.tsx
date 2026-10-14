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
                <div className="ui-panel__header mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        {title && <h2 className="text-lg font-bold tracking-[-0.015em]" style={{ color: 'var(--ui-text)' }}>{title}</h2>}
                        {description && <Text as="p" size={300} className="mt-1 leading-6" style={{ color: 'var(--ui-text-muted)' }}>{description}</Text>}
                    </div>
                    {action && <div className="flex shrink-0 flex-wrap items-center gap-2">{action}</div>}
                </div>
            )}
            {children}
        </Card>
    );
}
