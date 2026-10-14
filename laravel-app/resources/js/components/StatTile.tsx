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
    '--metric-surface': string;
    '--metric-border': string;
};

const tones: Record<NonNullable<StatTileProps['tone']>, MetricVariables> = {
    sky: {
        '--metric-accent': '#1876d2',
        '--metric-surface': '#edf6ff',
        '--metric-border': '#b7dbfb',
    },
    emerald: {
        '--metric-accent': '#0a8f75',
        '--metric-surface': '#eafaf5',
        '--metric-border': '#a9e5d5',
    },
    amber: {
        '--metric-accent': '#c87309',
        '--metric-surface': '#fff8e8',
        '--metric-border': '#f4d28e',
    },
    rose: {
        '--metric-accent': '#d23d68',
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
                minWidth: 0,
                minHeight: 172,
                p: 2.5,
                borderRadius: 'var(--ui-radius-card)',
                borderColor: 'color-mix(in srgb, var(--metric-border) 50%, var(--ui-border))',
                bgcolor: 'var(--metric-surface)',
                backgroundImage: 'linear-gradient(135deg, var(--metric-surface), color-mix(in srgb, var(--metric-surface) 50%, var(--ui-surface)))',
                boxShadow: '0 2px 8px rgb(var(--ui-shadow) / 0.025)',
                '[data-ui-mode="dark"] &': {
                    borderColor: 'color-mix(in srgb, var(--metric-accent) 26%, var(--ui-border))',
                    bgcolor: 'color-mix(in srgb, var(--metric-accent) 10%, var(--ui-surface))',
                    backgroundImage: 'linear-gradient(135deg, color-mix(in srgb, var(--metric-accent) 12%, var(--ui-surface)), var(--ui-surface))',
                },
            }}
        >
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
                <Box
                    component="span"
                    aria-hidden="true"
                    sx={{
                        display: 'grid',
                        flexShrink: 0,
                        width: 46,
                        height: 46,
                        placeItems: 'center',
                        borderRadius: '50%',
                        color: 'var(--metric-accent)',
                        bgcolor: 'color-mix(in srgb, var(--metric-accent) 11%, var(--metric-surface))',
                        '[data-ui-mode="dark"] &': {
                            color: 'color-mix(in srgb, var(--metric-accent) 46%, white)',
                            bgcolor: 'color-mix(in srgb, var(--metric-accent) 18%, var(--ui-surface))',
                        },
                    }}
                >
                    <Icon size={26} weight="duotone" />
                </Box>
                <Typography component="p" variant="body1" sx={{ minWidth: 0, color: 'var(--ui-text-muted)', lineHeight: '22px', fontWeight: 600 }}>
                    {label}
                </Typography>
            </Box>
            <Typography
                component="p"
                title={String(value)}
                sx={{
                    mt: 1.75,
                    minWidth: 0,
                    overflow: 'hidden',
                    color: 'var(--ui-text)',
                    fontSize: 'clamp(30px, 2.25vw, 36px)',
                    fontWeight: 700,
                    fontVariantNumeric: 'tabular-nums',
                    letterSpacing: '-0.025em',
                    lineHeight: 1.15,
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                }}
            >
                {value}
            </Typography>
            <Typography
                component="p"
                variant="body2"
                sx={{
                    mt: 0.75,
                    minWidth: 0,
                    color: 'var(--ui-text-muted)',
                    lineHeight: '20px',
                    overflowWrap: 'anywhere',
                }}
            >
                {detail}
            </Typography>
        </Card>
    );
}
