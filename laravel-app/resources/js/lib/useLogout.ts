import { useMutation } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { apiPost } from './api';
import { applyAppearance, DEFAULT_APPEARANCE } from './appearance';
import { queryClient } from '../query';

export function useLogout() {
    const navigate = useNavigate();

    return useMutation({
        meta: { notification: { success: 'ออกจากระบบเรียบร้อยแล้ว' } },
        mutationFn: () => apiPost<{ logged_out: boolean }>('/auth/logout'),
        onSettled: async () => {
            window.localStorage.removeItem('sena-district-id');
            window.localStorage.removeItem('sena-appearance');
            applyAppearance(DEFAULT_APPEARANCE, false);
            await queryClient.cancelQueries();
            queryClient.removeQueries();
            navigate('/login', { replace: true });
        },
    });
}
