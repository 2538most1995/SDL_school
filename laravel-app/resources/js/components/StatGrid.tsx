import { Box } from '@mui/material';
import type { ReactNode } from 'react';

export function StatGrid({ children }: { children: ReactNode }) {
    return (
        <Box
            component="section"
            aria-label="ข้อมูลสรุป"
            sx={{
                display: 'grid',
                gridTemplateColumns: 'minmax(0, 1fr)',
                gap: 2.25,
                mb: 4,
                '@media (min-width:560px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' },
                '@media (min-width:900px)': { gridTemplateColumns: 'repeat(4, minmax(0, 1fr))' },
            }}
        >
            {children}
        </Box>
    );
}
