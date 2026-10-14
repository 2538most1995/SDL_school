import { Card, Text } from './MaterialUI';
import type { Icon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { EducationIllustration } from './EducationIllustration';

type PageHeaderProps = {
    title: string;
    description: string;
    icon: Icon;
    category?: string;
    actions?: ReactNode;
    illustration?: boolean;
};

export function PageHeader({ title, description, icon: Icon, category, actions, illustration = false }: PageHeaderProps) {
    return (
        <header className="mb-4">
            <Card appearance="filled" className="ui-page-header px-4 py-4 sm:px-5">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="flex min-w-0 flex-1 items-start gap-3 sm:items-center sm:gap-4">
                        <span className="ui-page-header__icon grid size-11 shrink-0 place-items-center sm:size-14" style={{ backgroundColor: 'color-mix(in srgb, var(--ui-navigation) 12%, var(--ui-surface))', color: 'var(--ui-navigation)' }}>
                            <Icon size={27} weight="duotone" aria-hidden="true" />
                        </span>
                        <div className="min-w-0">
                            {category && <Text as="p" size={200} weight="semibold" className="mb-1 text-brand-700">{category}</Text>}
                            <h1 className="text-balance text-xl font-bold tracking-[-0.02em] sm:text-2xl" style={{ color: 'var(--ui-text)' }}>{title}</h1>
                            <Text as="p" size={200} className="mt-1 max-w-[70ch] leading-5" style={{ color: 'var(--ui-text-muted)' }}>{description}</Text>
                        </div>
                    </div>
                    {illustration && <div className="hidden w-36 shrink-0 xl:block"><EducationIllustration /></div>}
                    {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
                </div>
            </Card>
        </header>
    );
}
