import type { SVGProps } from 'react';

type FileTypeIconProps = SVGProps<SVGSVGElement> & { size?: number | string };

/** Fixed file colors stay recognizable inside any district theme or button. */
export function ExcelFileIcon({ size = 20, className, ...props }: FileTypeIconProps) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" className={className} {...props}>
            <path d="M8 2.5h8l5 5v13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1v-17a1 1 0 0 1 1-1Z" fill="#fff" stroke="#107C41" strokeWidth="1.25" />
            <path d="M16 2.5v5h5" fill="#D3EEDD" stroke="#107C41" strokeWidth="1.25" strokeLinejoin="round" />
            <path d="M13 11h5.5M13 14h5.5M13 17h5.5M15.5 11v6" stroke="#21A366" strokeWidth="1.25" />
            <rect x="1.5" y="8" width="11" height="12" rx="1.5" fill="#107C41" stroke="#fff" strokeWidth=".6" />
            <path d="m4.5 11 5 6m0-6-5 6" stroke="#fff" strokeWidth="1.8" strokeLinecap="square" />
        </svg>
    );
}

export function PdfFileIcon({ size = 20, className, ...props }: FileTypeIconProps) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" className={className} {...props}>
            <path d="M6 2.5h10l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-17a1 1 0 0 1 1-1Z" fill="#fff" stroke="#D32F2F" strokeWidth="1.25" />
            <path d="M16 2.5v5h5" fill="#FCE0E0" stroke="#D32F2F" strokeWidth="1.25" strokeLinejoin="round" />
            <path d="M8 10h9M8 12.5h6" stroke="#EF9A9A" strokeWidth="1.25" strokeLinecap="round" />
            <rect x="1" y="14" width="21" height="8.5" rx="1.5" fill="#D32F2F" stroke="#fff" strokeWidth=".6" />
            <path d="M4.5 20v-4h1.4a1.1 1.1 0 0 1 0 2.2H4.5M9.5 16v4h1.2c1.3 0 1.8-.9 1.8-2s-.5-2-1.8-2H9.5Zm6 4v-4h3m-3 2h2.5" stroke="#fff" strokeWidth="1.05" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}
