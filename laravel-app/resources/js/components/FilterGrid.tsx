import type { ReactNode } from 'react';

/** Size filters against their own panel without containing unrelated dialogs. */
export function FilterGrid({ children, columns, open = true, id, className = 'mb-4' }: {
    children: ReactNode;
    columns: 2 | 3 | 4;
    open?: boolean;
    id?: string;
    className?: string;
}) {
    return (
        <div className="ui-filter-container">
            <div id={id} hidden={!open} className={`ui-directory-filters ui-directory-filters--${columns} gap-3 ${open ? 'grid' : 'hidden'} ${className}`}>
                {children}
            </div>
        </div>
    );
}
