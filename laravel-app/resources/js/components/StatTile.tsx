import { Box, Card, Typography } from '@mui/material';
import type { Icon } from '@phosphor-icons/react';
import type { CSSProperties } from 'react';

type StatTileProps = {
    label: string;
    value: string | number;
    detail: string;
    icon: Icon;
    tone?: 'emerald' | 'sky' | 'amber' | 'rose';
};

type MetricVariables = CSSProperties & {
    '--metric-accent': string;
    '--metric-accent-strong': string;
    '--metric-surface': string;
    '--metric-border': string;
};

const tones: Record<NonNullable<StatTileProps['tone']>, MetricVariables> = {
    sky: {
        '--metric-accent': '#1876d2',
        '--metric-accent-strong': '#0d4f98',
        '--metric-surface': '#edf6ff',
        '--metric-border': '#b7dbfb',
    },
    emerald: {
        '--metric-accent': '#0a8f75',
        '--metric-accent-strong': '#07614f',
        '--metric-surface': '#eafaf5',
        '--metric-border': '#a9e5d5',
    },
    amber: {
        '--metric-accent': '#c87309',
        '--metric-accent-strong': '#855005',
        '--metric-surface': '#fff8e8',
        '--metric-border': '#f4d28e',
    },
    rose: {
        '--metric-accent': '#d23d68',
        '--metric-accent-strong': '#982649',
        '--metric-surface': '#fff1f6',
        '--metric-border': '#f3b9cc',
    },
};

export function StatTile({ label, value, detail, icon: Icon, tone = 'emerald' }: StatTileProps) {
    return (
        <Card
            variant="outlined"
            role="article"
            style={tones[tone]}
            sx={{
                position: 'relative',
                minWidth: 0,
                minHeight: 182,
                overflow: 'hidden',
                p: 2.75,
                borderRadius: '18px',
                borderColor: 'color-mix(in srgb, var(--metric-border) 74%, var(--ui-border))',
                bgcolor: 'var(--metric-surface)',
                backgroundImage: 'linear-gradient(150deg, color-mix(in srgb, var(--metric-surface) 92%, white), color-mix(in srgb, var(--metric-surface) 72%, white))',
                boxShadow: '0 12px 28px color-mix(in srgb, var(--metric-accent) 8%, transparent), 0 2px 4px rgb(var(--ui-shadow) / 0.05)',
                transition: 'transform 160ms cubic-bezier(0.23, 1, 0.32, 1), border-color 160ms ease, box-shadow 160ms ease',
                '[data-ui-mode="dark"] &': {
                    borderColor: 'color-mix(in srgb, var(--metric-accent) 38%, var(--ui-border))',
                    bgcolor: 'color-mix(in srgb, var(--metric-accent) 12%, var(--ui-surface))',
                    backgroundImage: 'linear-gradient(150deg, color-mix(in srgb, var(--metric-accent) 16%, var(--ui-surface)), var(--ui-surface))',
                },
                '@media (hover: hover) and (pointer: fine)': {
                    '&:hover': {
                        transform: 'translateY(-3px)',
                        borderColor: 'var(--metric-border)',
                        boxShadow: '0 18px 36px color-mix(in srgb, var(--metric-accent) 13%, transparent), 0 3px 6px rgb(var(--ui-shadow) / 0.06)',
                    },
                },
                '&::before': {
                    content: '""',
                    position: 'absolute',
                    insetInlineStart: 0,
                    insetBlockStart: 0,
                    insetBlockEnd: 0,
                    width: 5,
                    bgcolor: 'var(--metric-accent)',
                },
                '&::after': {
                    content: '""',
                    position: 'absolute',
                    insetInlineEnd: -36,
                    insetBlockEnd: -48,
                    width: 142,
                    height: 142,
                    borderRadius: '50%',
                    bgcolor: 'color-mix(in srgb, var(--metric-accent) 10%, transparent)',
                    pointerEvents: 'none',
                },
            }}
        >
            <Box sx={{ position: 'relative', zIndex: 1, display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 2 }}>
                <Typography component="p" variant="body1" sx={{ minWidth: 0, color: 'var(--metric-accent-strong)', lineHeight: '22px', fontWeight: 700, '[data-ui-mode="dark"] &': { color: 'color-mix(in srgb, var(--metric-accent) 44%, white)' } }}>
                    {label}
                </Typography>
                <Box
                    component="span"
                    aria-hidden="true"
                    sx={{
                        display: 'grid',
                        flexShrink: 0,
                        width: 52,
                        height: 52,
                        placeItems: 'center',
                        borderRadius: '16px',
                        color: 'var(--metric-accent)',
                        bgcolor: 'color-mix(in srgb, white 84%, var(--metric-surface))',
                        border: '1px solid color-mix(in srgb, var(--metric-accent) 20%, transparent)',
                        boxShadow: '0 8px 18px color-mix(in srgb, var(--metric-accent) 10%, transparent)',
                        '[data-ui-mode="dark"] &': {
                            color: 'color-mix(in srgb, var(--metric-accent) 46%, white)',
                            bgcolor: 'color-mix(in srgb, var(--metric-accent) 18%, var(--ui-surface))',
                            borderColor: 'color-mix(in srgb, var(--metric-accent) 36%, var(--ui-border))',
                        },
                    }}
                >
                    <Icon size={29} weight="duotone" />
                </Box>
            </Box>
            <Typography
                component="p"
                title={String(value)}
                sx={{
                    position: 'relative',
                    zIndex: 1,
                    mt: 2.25,
                    minWidth: 0,
                    overflow: 'hidden',
                    color: 'text.primary',
                    fontSize: 'clamp(30px, 2.25vw, 42px)',
                    fontWeight: 800,
                    letterSpacing: '-0.035em',
                    lineHeight: 1.05,
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                }}
            >
                {value}
            </Typography>
            <Typography
                component="p"
                variant="body2"
                title={detail}
                sx={{
                    position: 'relative',
                    zIndex: 1,
                    mt: 1.25,
                    minWidth: 0,
                    overflow: 'hidden',
                    color: 'text.secondary',
                    lineHeight: '20px',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                }}
            >
                {detail}
            </Typography>
        </Card>
    );
}
