import { Card, Text } from './MaterialUI';
import type { Icon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';

type PageHeaderProps = {
    title: string;
    description: string;
    icon: Icon;
    category?: string;
    actions?: ReactNode;
};

export function PageHeader({ title, description, icon: Icon, category, actions }: PageHeaderProps) {
    return (
        <header className="mb-5">
            <Card appearance="filled" className="ui-page-header px-5 py-5 sm:px-7 sm:py-6">
                <div className="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
                    <div className="flex min-w-0 items-start gap-4 sm:items-center sm:gap-5">
                        <span className="ui-page-header__icon grid size-12 shrink-0 place-items-center sm:size-16" style={{ backgroundColor: 'color-mix(in srgb, var(--ui-accent-500) 12%, var(--ui-surface))', color: 'var(--ui-accent-700)' }}>
                            <Icon size={30} weight="duotone" aria-hidden="true" />
                        </span>
                        <div className="min-w-0">
                            {category && <Text as="p" size={200} weight="semibold" className="mb-1 text-brand-700">{category}</Text>}
                            <h1 className="text-balance text-2xl font-bold tracking-[-0.02em] sm:text-[1.8rem]" style={{ color: 'var(--ui-text)' }}>{title}</h1>
                            <Text as="p" size={300} className="mt-1.5 max-w-[70ch] leading-6" style={{ color: 'var(--ui-text-muted)' }}>{description}</Text>
                        </div>
                    </div>
                    {actions && <div className="flex shrink-0 flex-wrap items-center gap-2 md:pl-0">{actions}</div>}
                </div>
            </Card>
        </header>
    );
}
