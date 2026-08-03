import { Card, Spinner, Text } from './MaterialUI';
import { useIsMutating } from '@tanstack/react-query';

export function GlobalMutationLoading() {
    const pendingCount = useIsMutating();

    if (pendingCount === 0) return null;

    return (
        <div className="fixed inset-0 z-[1000] grid place-items-center bg-slate-950/35 p-4 backdrop-blur-[2px]" role="status" aria-live="polite" aria-label="กำลังดำเนินการ">
            <Card appearance="filled" className="flex w-full max-w-xs flex-row items-center gap-4 px-5 py-4">
                <Spinner size="medium" />
                <span className="min-w-0">
                    <strong className="block text-sm font-bold text-slate-950">กำลังดำเนินการ</strong>
                    <Text as="span" size={200} className="mt-0.5 block leading-5 text-slate-600">กรุณารอสักครู่และไม่ต้องกดซ้ำ</Text>
                </span>
            </Card>
        </div>
    );
}
