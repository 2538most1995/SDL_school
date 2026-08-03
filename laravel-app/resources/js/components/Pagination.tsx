import { CaretLeft, CaretRight } from '@phosphor-icons/react';
import { Button, Select, Text } from './MaterialUI';

type PaginationItem = number | 'start-ellipsis' | 'end-ellipsis';

export const PAGE_SIZE_OPTIONS = [25, 50, 100, 500, 1000] as const;

type PaginationProps = {
    currentPage: number;
    totalPages: number;
    totalItems: number;
    pageSize: number;
    onPageChange: (page: number) => void;
    onPageSizeChange?: (pageSize: number) => void;
    pageSizeOptions?: readonly number[];
    itemLabel?: string;
    disabled?: boolean;
};

function getPaginationItems(currentPage: number, totalPages: number): PaginationItem[] {
    if (totalPages <= 5) {
        return Array.from({ length: totalPages }, (_, index) => index + 1);
    }

    if (currentPage <= 3) {
        return [1, 2, 3, 'end-ellipsis', totalPages];
    }

    if (currentPage >= totalPages - 2) {
        return [1, 'start-ellipsis', totalPages - 2, totalPages - 1, totalPages];
    }

    return [1, 'start-ellipsis', currentPage, 'end-ellipsis', totalPages];
}

export function Pagination({
    currentPage,
    totalPages,
    totalItems,
    pageSize,
    onPageChange,
    onPageSizeChange,
    pageSizeOptions = PAGE_SIZE_OPTIONS,
    itemLabel = 'รายการ',
    disabled = false,
}: PaginationProps) {
    if (totalItems === 0) {
        return null;
    }

    const safeCurrentPage = Math.min(Math.max(currentPage, 1), totalPages);
    const firstItem = ((safeCurrentPage - 1) * pageSize) + 1;
    const lastItem = Math.min(safeCurrentPage * pageSize, totalItems);
    const items = getPaginationItems(safeCurrentPage, totalPages);

    return (
        <nav
            aria-label="แบ่งหน้ารายการ"
            className="ui-pagination mt-4 grid gap-3 border border-slate-200 bg-slate-50/80 p-3 lg:grid-cols-[minmax(190px,1fr)_auto_minmax(285px,1fr)] lg:items-center lg:gap-2.5"
        >
            <Text as="p" size={200} weight="semibold" className="text-center text-slate-600 sm:text-left" aria-live="polite">
                แสดง <span className="font-black tabular-nums text-slate-900">{firstItem.toLocaleString('th-TH')}</span> ถึง{' '}
                <span className="font-black tabular-nums text-slate-900">{lastItem.toLocaleString('th-TH')}</span> จาก{' '}
                <span className="font-black tabular-nums text-slate-900">{totalItems.toLocaleString('th-TH')}</span> {itemLabel}
            </Text>

            {onPageSizeChange ? (
                <label className="flex items-center justify-center gap-2 text-[13px] font-semibold text-slate-600">
                    <span>แสดง</span>
                    <Select
                        aria-label={`จำนวน${itemLabel}ต่อหน้า`}
                        value={pageSize}
                        onChange={(event) => onPageSizeChange(Number(event.target.value))}
                        disabled={disabled}
                        className="min-w-20 text-center text-[13px] font-black tabular-nums"
                    >
                        {pageSizeOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                    </Select>
                    <span>{itemLabel}</span>
                </label>
            ) : <span className="hidden lg:block" />}

            <div className="flex min-w-0 items-center justify-between gap-1.5 sm:justify-center lg:justify-end">
                <Button
                    appearance="subtle"
                    icon={<CaretLeft size={17} weight="bold" aria-hidden="true" />}
                    onClick={() => onPageChange(safeCurrentPage - 1)}
                    disabled={disabled || safeCurrentPage === 1}
                    aria-label="ไปหน้าก่อนหน้า"
                />

                <span className="px-2 text-[13px] font-black tabular-nums text-slate-700 sm:hidden" aria-live="polite">
                    หน้า {safeCurrentPage.toLocaleString('th-TH')} / {totalPages.toLocaleString('th-TH')}
                </span>

                <div className="hidden min-w-0 items-center gap-1.5 overflow-x-auto py-1 sm:flex" aria-label={`หน้าที่ ${safeCurrentPage} จาก ${totalPages}`}>
                    {items.map((item) => typeof item === 'number' ? (
                        <Button
                            key={item}
                            appearance={item === safeCurrentPage ? 'primary' : 'subtle'}
                            onClick={() => onPageChange(item)}
                            disabled={disabled}
                            aria-label={`ไปหน้าที่ ${item}`}
                            aria-current={item === safeCurrentPage ? 'page' : undefined}
                            className="ui-pagination-page min-w-9 text-[13px] font-black tabular-nums"
                        >
                            {item}
                        </Button>
                    ) : (
                        <span key={item} className="grid size-5 shrink-0 place-items-center text-xs font-black text-slate-400" aria-hidden="true">...</span>
                    ))}
                </div>

                <Button
                    appearance="subtle"
                    icon={<CaretRight size={17} weight="bold" aria-hidden="true" />}
                    onClick={() => onPageChange(safeCurrentPage + 1)}
                    disabled={disabled || safeCurrentPage === totalPages}
                    aria-label="ไปหน้าถัดไป"
                />
            </div>
        </nav>
    );
}
