import { CaretDown, CaretUp, CaretUpDown } from '@phosphor-icons/react';
import {
    Box,
    Button,
    Card,
    Paper,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
    Typography,
} from '@mui/material';
import {
    flexRender,
    getCoreRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    useReactTable,
    type Column,
    type ColumnDef,
    type PaginationState,
    type RowData,
    type SortingState,
} from '@tanstack/react-table';
import { useEffect, useState } from 'react';
import { Pagination } from './Pagination';
import { EmptyState } from './QueryState';

declare module '@tanstack/react-table' {
    interface ColumnMeta<TData extends RowData, TValue> {
        compactSize?: number;
        compactHeader?: string;
        compactTextAlign?: 'left' | 'center' | 'right';
    }
}

type DataTableProps<T> = {
    data: T[];
    columns: ColumnDef<T, unknown>[];
    emptyTitle?: string;
    emptyDescription?: string;
    pageSize?: number;
    showPagination?: boolean;
    minWidth?: 'default' | 'wide' | 'extra-wide';
    responsiveMode?: 'cards' | 'compact-table';
};

const minimumWidths = {
    default: 760,
    wide: 1040,
    'extra-wide': 1280,
} as const;

function getColumnSearchKey<T>(column: Column<T, unknown>): string {
    const header = typeof column.columnDef.header === 'string' ? column.columnDef.header : '';
    return `${column.id} ${header}`.toLocaleLowerCase('th-TH');
}

function isActionColumn<T>(column: Column<T, unknown>): boolean {
    return /actions|details|manage|open|จัดการ|รายละเอียด|เปิดสื่อ/.test(getColumnSearchKey(column));
}

function getCompactColumnSize<T>(column: Column<T, unknown>): number {
    if (column.columnDef.meta?.compactSize) return column.columnDef.meta.compactSize;

    const key = getColumnSearchKey(column);

    if (isActionColumn(column)) return 46;
    if (/gpax|gpa|เพศ|คะแนน|จำนวน|ตาราง|คำเตือน|หน่วยกิต|ชั่วโมง|ภาคเรียน|วันเกิด|สิทธิ์/.test(key)) return 64;
    if (/สถานะ|ผลประเมิน|ผลการเรียน|การเข้าสอบ|จัดตาม|ประเภท/.test(key)) return 78;
    if (/รหัส|เลขบัตร|batch|ช่วง/.test(key)) return 88;
    if (/นักศึกษา|ชื่อผู้ใช้งาน|รายวิชา|รายการ|กิจกรรม|กลุ่ม|พื้นที่|ห้องสอบ/.test(key)) return 136;

    return Math.max(58, Math.min(column.getSize(), 128));
}

export function DataTable<T>({
    data,
    columns,
    emptyTitle = 'ยังไม่มีข้อมูล',
    emptyDescription = 'ข้อมูลจะแสดงที่นี่เมื่อมีรายการในระบบ',
    pageSize = 25,
    showPagination = true,
    minWidth = 'default',
    responsiveMode = 'compact-table',
}: DataTableProps<T>) {
    const [sorting, setSorting] = useState<SortingState>([]);
    const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize });

    useEffect(() => {
        setPagination((current) => current.pageSize === pageSize ? current : { pageIndex: 0, pageSize });
    }, [pageSize]);

    const table = useReactTable({
        data,
        columns,
        state: { sorting, pagination },
        onSortingChange: setSorting,
        onPaginationChange: setPagination,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getPaginationRowModel: getPaginationRowModel(),
    });
    const compactTable = responsiveMode === 'compact-table';
    const compactTotalColumnSize = Math.max(
        table.getVisibleLeafColumns().reduce((sum, column) => sum + getCompactColumnSize(column), 0),
        1,
    );

    if (data.length === 0) {
        return <EmptyState title={emptyTitle} description={emptyDescription} />;
    }

    return (
        <Box
            className={compactTable ? 'responsive-data-table responsive-data-table--compact' : 'responsive-data-table'}
            sx={{ minWidth: 0 }}
        >
            <Box
                sx={{
                    display: compactTable ? 'flex' : 'none',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: 2,
                    mb: 1,
                    px: 0.5,
                    color: 'text.secondary',
                    '@media (min-width:768px)': { display: 'flex' },
                }}
            >
                <Typography variant="caption" sx={{ fontWeight: 700 }}>
                    ทั้งหมด {data.length.toLocaleString('th-TH')} รายการ
                </Typography>
                <Typography variant="caption" sx={{ display: compactTable ? 'none' : 'block', '@media (min-width:640px)': { display: 'block' } }}>
                    เลือกชื่อคอลัมน์เพื่อเรียงข้อมูล
                </Typography>
            </Box>

            <TableContainer
                component={Paper}
                variant="outlined"
                sx={{
                    display: compactTable ? 'block' : 'none',
                    overflowX: 'auto',
                    scrollbarWidth: 'thin',
                    borderColor: 'divider',
                    borderRadius: compactTable ? '12px' : '16px',
                    bgcolor: 'background.paper',
                    boxShadow: '0 10px 28px rgb(var(--ui-shadow) / 0.055)',
                    '@media (min-width:768px)': { display: 'block' },
                    ...(compactTable ? {
                        '@media (min-width:1024px)': {
                            overflowX: 'auto',
                            borderRadius: '16px',
                        },
                    } : {}),
                }}
            >
                <Table
                    aria-label={`ตารางข้อมูลทั้งหมด ${data.length.toLocaleString('th-TH')} รายการ`}
                    size="small"
                    stickyHeader
                    sx={{
                        minWidth: compactTable ? 0 : minimumWidths[minWidth],
                        width: '100%',
                        tableLayout: compactTable ? 'fixed' : 'auto',
                        color: 'text.primary',
                        ...(compactTable ? {
                            '@media (min-width:1024px)': {
                                minWidth: minimumWidths[minWidth],
                                tableLayout: 'auto',
                            },
                        } : {}),
                    }}
                >
                    <TableHead>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <TableRow key={headerGroup.id}>
                                {headerGroup.headers.map((header) => (
                                    <TableCell
                                        key={header.id}
                                        sx={{
                                            width: compactTable ? `${(getCompactColumnSize(header.column) / compactTotalColumnSize) * 100}%` : header.getSize(),
                                            minWidth: compactTable ? 0 : header.getSize(),
                                            maxWidth: compactTable ? `${(getCompactColumnSize(header.column) / compactTotalColumnSize) * 100}%` : 'none',
                                            height: compactTable ? 42 : 52,
                                            overflow: compactTable ? 'hidden' : 'visible',
                                            px: compactTable ? 0.3 : 2,
                                            py: compactTable ? 0.5 : 1,
                                            whiteSpace: compactTable ? 'normal' : 'nowrap',
                                            overflowWrap: compactTable ? 'normal' : 'normal',
                                            verticalAlign: 'middle',
                                            bgcolor: 'color-mix(in srgb, var(--ui-accent-50) 68%, var(--ui-surface))',
                                            ...(compactTable ? {
                                                '@media (min-width:640px)': { px: 0.6, py: 0.65 },
                                                '@media (min-width:1024px)': {
                                                    width: header.getSize(),
                                                    minWidth: header.getSize(),
                                                    maxWidth: 'none',
                                                    height: 52,
                                                    overflow: 'visible',
                                                    px: 2,
                                                    whiteSpace: 'nowrap',
                                                },
                                            } : {}),
                                        }}
                                    >
                                        {header.isPlaceholder ? null : (
                                            <Button
                                                variant="text"
                                                size="small"
                                                disabled={!header.column.getCanSort()}
                                                onClick={header.column.getToggleSortingHandler()}
                                                sx={{
                                                    width: compactTable ? '100%' : 'max-content',
                                                    minWidth: compactTable ? 0 : 'max-content',
                                                    justifyContent: compactTable ? 'center' : 'flex-start',
                                                    px: 0,
                                                    overflow: compactTable ? 'hidden' : 'visible',
                                                    color: 'inherit',
                                                    fontWeight: 700,
                                                    fontSize: compactTable ? '0.58rem' : '0.875rem',
                                                    lineHeight: compactTable ? 1.3 : 1.5,
                                                    whiteSpace: compactTable ? 'normal' : 'nowrap',
                                                    letterSpacing: 0,
                                                    '&.Mui-disabled': {
                                                        color: 'inherit',
                                                        opacity: 1,
                                                    },
                                                    ...(compactTable ? {
                                                        '@media (min-width:640px)': { fontSize: '0.66rem' },
                                                        '@media (min-width:1024px)': {
                                                            width: 'max-content',
                                                            minWidth: 'max-content',
                                                            justifyContent: 'flex-start',
                                                            overflow: 'visible',
                                                            fontSize: '0.875rem',
                                                            lineHeight: 1.5,
                                                            whiteSpace: 'nowrap',
                                                        },
                                                    } : {}),
                                                }}
                                            >
                                                <Box
                                                    component="span"
                                                    sx={{
                                                        display: compactTable && isActionColumn(header.column) ? 'none' : 'block',
                                                        minWidth: compactTable ? 0 : 'max-content',
                                                        overflow: compactTable ? 'hidden' : 'visible',
                                                        overflowWrap: compactTable ? 'normal' : 'normal',
                                                        textOverflow: 'clip',
                                                        whiteSpace: compactTable ? 'normal' : 'nowrap',
                                                        textAlign: compactTable ? 'center' : 'start',
                                                        ...(compactTable ? {
                                                            '@media (min-width:1024px)': {
                                                                display: 'block',
                                                                minWidth: 'max-content',
                                                                overflow: 'visible',
                                                                overflowWrap: 'normal',
                                                                whiteSpace: 'nowrap',
                                                                textAlign: 'start',
                                                            },
                                                        } : {}),
                                                    }}
                                                >
                                                    {compactTable && header.column.columnDef.meta?.compactHeader ? (
                                                        <>
                                                            <Box component="span" sx={{ display: 'block', '@media (min-width:1024px)': { display: 'none' } }}>
                                                                {header.column.columnDef.meta.compactHeader}
                                                            </Box>
                                                            <Box component="span" sx={{ display: 'none', '@media (min-width:1024px)': { display: 'block' } }}>
                                                                {flexRender(header.column.columnDef.header, header.getContext())}
                                                            </Box>
                                                        </>
                                                    ) : flexRender(header.column.columnDef.header, header.getContext())}
                                                </Box>
                                                {header.column.getCanSort() && (
                                                    <Box
                                                        component="span"
                                                        sx={{
                                                            display: compactTable ? 'none' : 'inline-flex',
                                                            ml: 0.5,
                                                            flexShrink: 0,
                                                            '@media (min-width:1024px)': { display: 'inline-flex' },
                                                        }}
                                                    >
                                                        {header.column.getIsSorted() === 'asc'
                                                            ? <CaretUp size={13} />
                                                            : header.column.getIsSorted() === 'desc'
                                                                ? <CaretDown size={13} />
                                                                : <CaretUpDown size={13} />}
                                                    </Box>
                                                )}
                                            </Button>
                                        )}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableHead>
                    <TableBody>
                        {table.getRowModel().rows.map((row) => (
                            <TableRow
                                key={row.id}
                                hover
                                sx={{
                                    height: compactTable ? 'auto' : 76,
                                    '&:nth-of-type(even)': { bgcolor: 'color-mix(in srgb, var(--ui-surface-subtle) 42%, var(--ui-surface))' },
                                    '&:hover': { bgcolor: 'color-mix(in srgb, var(--ui-accent-50) 58%, var(--ui-surface))' },
                                    ...(compactTable ? {
                                        '@media (min-width:1024px)': { height: 76 },
                                    } : {}),
                                }}
                            >
                                {row.getVisibleCells().map((cell) => (
                                    <TableCell
                                        key={cell.id}
                                        className={compactTable && isActionColumn(cell.column) ? 'responsive-table-action-cell' : undefined}
                                        sx={{
                                            width: compactTable ? `${(getCompactColumnSize(cell.column) / compactTotalColumnSize) * 100}%` : cell.column.getSize(),
                                            minWidth: 0,
                                            maxWidth: compactTable ? `${(getCompactColumnSize(cell.column) / compactTotalColumnSize) * 100}%` : '100%',
                                            height: compactTable ? 'auto' : 76,
                                            overflow: compactTable && isActionColumn(cell.column) ? 'visible' : 'hidden',
                                            overflowWrap: compactTable ? 'normal' : 'normal',
                                            px: compactTable ? (isActionColumn(cell.column) ? 0.1 : 0.3) : 2,
                                            py: compactTable ? 0.55 : 1,
                                            verticalAlign: 'middle',
                                            color: 'text.secondary',
                                            fontSize: compactTable ? '0.62rem' : '0.875rem',
                                            lineHeight: compactTable ? 1.38 : 1.5,
                                            letterSpacing: 0,
                                            textAlign: compactTable ? (cell.column.columnDef.meta?.compactTextAlign ?? 'left') : 'left',
                                            ...(compactTable ? {
                                                '@media (min-width:640px)': {
                                                    px: isActionColumn(cell.column) ? 0.2 : 0.6,
                                                    py: 0.68,
                                                    fontSize: '0.7rem',
                                                },
                                                '@media (min-width:1024px)': {
                                                    width: cell.column.getSize(),
                                                    maxWidth: '100%',
                                                    height: 76,
                                                    overflowWrap: 'normal',
                                                    px: 2,
                                                    fontSize: '0.875rem',
                                                    lineHeight: 1.5,
                                                },
                                            } : {}),
                                        }}
                                    >
                                        <Box
                                            className="responsive-table-cell-content"
                                            sx={{
                                                display: 'block',
                                                minWidth: 0,
                                                maxWidth: '100%',
                                                overflow: 'hidden',
                                            }}
                                        >
                                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                        </Box>
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </TableContainer>

            <Box
                sx={{
                    display: compactTable ? 'none' : 'grid',
                    gap: 1.5,
                    '@media (min-width:768px)': { display: 'none' },
                }}
            >
                {table.getRowModel().rows.map((row) => (
                    <Card
                        variant="outlined"
                        role="article"
                        key={row.id}
                        sx={{
                            p: 2.25,
                            borderColor: 'divider',
                            borderRadius: '16px',
                            bgcolor: 'color-mix(in srgb, var(--ui-surface-muted) 84%, var(--ui-surface))',
                            boxShadow: '0 8px 20px rgb(var(--ui-shadow) / 0.055)',
                        }}
                    >
                        <Box component="dl" sx={{ display: 'grid', gap: 2, m: 0 }}>
                            {row.getVisibleCells().map((cell) => {
                                const header = cell.column.columnDef.header;
                                return (
                                    <Box
                                        key={cell.id}
                                        sx={{
                                            display: 'grid',
                                            gridTemplateColumns: 'minmax(0, .8fr) minmax(0, 1.2fr)',
                                            gap: 2,
                                            alignItems: 'start',
                                        }}
                                    >
                                        <Typography component="dt" variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                                            {typeof header === 'string' ? header : ''}
                                        </Typography>
                                        <Box component="dd" sx={{ minWidth: 0, m: 0, overflow: 'hidden', color: 'text.primary', fontSize: '0.875rem', lineHeight: 1.5 }}>
                                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                        </Box>
                                    </Box>
                                );
                            })}
                        </Box>
                    </Card>
                ))}
            </Box>

            {showPagination && <Pagination
                currentPage={table.getState().pagination.pageIndex + 1}
                totalPages={table.getPageCount()}
                totalItems={data.length}
                pageSize={table.getState().pagination.pageSize}
                onPageChange={(nextPage) => table.setPageIndex(nextPage - 1)}
                onPageSizeChange={(nextPageSize) => {
                    table.setPageSize(nextPageSize);
                    table.setPageIndex(0);
                }}
            />}
        </Box>
    );
}
