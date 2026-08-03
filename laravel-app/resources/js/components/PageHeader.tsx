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
        <header className="mb-6">
            <Card appearance="filled" className="ui-page-header relative overflow-hidden px-5 py-5 sm:px-7 sm:py-6">
                <span className="ui-page-header__orb ui-page-header__orb--one" aria-hidden="true" />
                <span className="ui-page-header__orb ui-page-header__orb--two" aria-hidden="true" />
                <div className="relative flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
                    <div className="flex min-w-0 items-start gap-4">
                        <span className="ui-page-header__icon grid size-12 shrink-0 place-items-center bg-brand-700 text-white">
                            <Icon size={25} weight="duotone" aria-hidden="true" />
                        </span>
                        <div className="min-w-0">
                            {category && <Text as="p" size={200} weight="semibold" className="mb-1 text-brand-700">{category}</Text>}
                            <h1 className="text-balance text-2xl font-black tracking-[-0.025em] text-slate-950 sm:text-[1.8rem]">{title}</h1>
                            <Text as="p" size={300} className="mt-1.5 max-w-[70ch] leading-6 text-slate-600">{description}</Text>
                        </div>
                    </div>
                    {actions && <div className="flex shrink-0 flex-wrap items-center gap-2 sm:pl-16 md:pl-0">{actions}</div>}
                </div>
            </Card>
        </header>
    );
}
