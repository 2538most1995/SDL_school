import { Card, Text } from './MaterialUI';
import type { ReactNode } from 'react';
import type { Icon } from '@phosphor-icons/react';

type PanelProps = {
    title?: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
    icon?: Icon;
};

export function Panel({ title, description, action, children, className = '', icon: Icon }: PanelProps) {
    return (
        <Card appearance="filled" role="region" aria-label={title} className={`ui-panel ${className}`}>
            {(title || description || action) && (
                <div className="ui-panel__header mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex min-w-0 items-center gap-3">
                        {Icon && <span className="ui-panel-heading-icon grid size-9 shrink-0 place-items-center rounded-xl"><Icon size={22} weight="duotone" aria-hidden="true" /></span>}
                        <div className="min-w-0">
                            {title && <h2 className="text-base font-bold tracking-[-0.015em]" style={{ color: 'var(--ui-text)' }}>{title}</h2>}
                            {description && <Text as="p" size={200} className="mt-1 leading-5" style={{ color: 'var(--ui-text-muted)' }}>{description}</Text>}
                        </div>
                    </div>
                    {action && <div className="flex shrink-0 flex-wrap items-center gap-2">{action}</div>}
                </div>
            )}
            {children}
        </Card>
    );
}
