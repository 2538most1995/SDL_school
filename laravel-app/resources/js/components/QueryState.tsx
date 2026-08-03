import { ArrowClockwise, FolderOpen } from '@phosphor-icons/react';
import { Button, Card, MessageBar, MessageBarActions, MessageBarBody, MessageBarTitle, Spinner } from './MaterialUI';
import type { ReactNode } from 'react';

export function QuerySkeleton({ rows = 5 }: { rows?: number }) {
    return (
        <div className="space-y-3" role="status" aria-label="กำลังโหลดข้อมูล">
            <Spinner label="กำลังโหลดข้อมูล" size="small" className="justify-start" />
            <div className="animate-pulse space-y-3" aria-hidden="true">
                {Array.from({ length: rows }, (_, index) => <div key={index} className="h-14 w-full rounded-xl bg-slate-100" />)}
            </div>
        </div>
    );
}

export function QueryError({ onRetry, message = 'ไม่สามารถโหลดข้อมูลได้ กรุณาลองอีกครั้ง' }: { onRetry: () => void; message?: string }) {
    return (
        <MessageBar intent="error" layout="multiline" role="alert">
            <MessageBarBody>
                <MessageBarTitle>โหลดข้อมูลไม่สำเร็จ</MessageBarTitle>
                {message}
            </MessageBarBody>
            <MessageBarActions>
                <Button appearance="primary" icon={<ArrowClockwise size={17} weight="bold" />} onClick={onRetry}>ลองใหม่</Button>
            </MessageBarActions>
        </MessageBar>
    );
}

export function EmptyState({ title, description, action }: { title: string; description: string; action?: ReactNode }) {
    return (
        <Card appearance="filled-alternative" className="ui-empty-state border border-dashed px-5 py-11 text-center">
            <span className="ui-empty-state__icon mx-auto grid size-14 place-items-center text-brand-700">
                <FolderOpen size={29} weight="duotone" aria-hidden="true" />
            </span>
            <h2 className="mt-4 font-black text-slate-900">{title}</h2>
            <p className="mx-auto mt-1 max-w-md text-sm leading-6 text-slate-500">{description}</p>
            {action && <div className="mt-4">{action}</div>}
        </Card>
    );
}
