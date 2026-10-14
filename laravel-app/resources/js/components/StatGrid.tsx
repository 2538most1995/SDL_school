import { Box } from '@mui/material';
import type { ReactNode } from 'react';

export function StatGrid({ children, columns = 4 }: { children: ReactNode; columns?: 2 | 3 | 4 }) {
    return (
        <Box
            component="section"
            aria-label="ข้อมูลสรุป"
            className="ui-stat-grid"
            data-columns={columns}
            sx={{
                display: 'grid',
                gap: { xs: 1.5, sm: 1.75 },
                mb: 2,
            }}
        >
            {children}
        </Box>
    );
}
