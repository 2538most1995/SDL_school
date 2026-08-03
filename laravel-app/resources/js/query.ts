import { MutationCache, QueryClient } from '@tanstack/react-query';
import { ApiError } from './lib/api';
import { showErrorAlert, showSuccessAlert } from './lib/feedback';

type NotificationMeta = {
    success?: string | false;
    error?: string | false;
};

function notificationMeta(meta: Record<string, unknown> | undefined): NotificationMeta {
    const notification = meta?.notification;
    return notification && typeof notification === 'object' ? notification as NotificationMeta : {};
}

export const queryKeys = {
    portal: ['portal', 'current'] as const,
    students: (districtId: number, filters: Record<string, unknown>) =>
        ['students', { districtId, ...filters }] as const,
};

export const queryClient = new QueryClient({
    mutationCache: new MutationCache({
        onSuccess: (_data, _variables, _context, mutation) => {
            const notification = notificationMeta(mutation.options.meta);
            if (notification.success === false) return;
            showSuccessAlert(typeof notification.success === 'string' ? notification.success : undefined);
        },
        onError: (error, _variables, _context, mutation) => {
            const notification = notificationMeta(mutation.options.meta);
            if (notification.error === false) return;
            const message = typeof notification.error === 'string'
                ? notification.error
                : error instanceof Error ? error.message : undefined;
            showErrorAlert(message);
        },
    }),
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            retry: (count, error) => {
                if (error instanceof ApiError && [401, 403, 404, 422].includes(error.status)) {
                    return false;
                }

                return count < 2;
            },
            refetchOnWindowFocus: false,
        },
        mutations: { retry: false },
    },
});
