import { Box, Card, Typography } from '@mui/material';
import type { Icon } from '@phosphor-icons/react';
import type { CSSProperties } from 'react';

type StatTileProps = {
    label: string;
    value: string | number;
    unit?: string;
    detail: string;
    icon: Icon;
    tone?: 'emerald' | 'sky' | 'amber' | 'rose' | 'violet';
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
    violet: {
        '--metric-accent': '#8350dc',
        '--metric-surface': '#f6f2ff',
        '--metric-border': '#dfd0fa',
    },
};

export function StatTile({ label, value, unit, detail, icon: Icon, tone = 'emerald' }: StatTileProps) {
    return (
        <Card
            variant="outlined"
            role="article"
            style={tones[tone]}
            sx={{
                minWidth: 0,
                minHeight: { xs: 132, sm: 144 },
                p: { xs: 2, sm: 2.25 },
                position: 'relative',
                overflow: 'hidden',
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
            <Box
                aria-hidden="true"
                sx={{
                    position: 'absolute',
                    right: 12,
                    bottom: 10,
                    color: 'var(--metric-accent)',
                    opacity: 0.08,
                    pointerEvents: 'none',
                }}
            >
                <Icon size={62} weight="duotone" />
            </Box>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.25, position: 'relative', zIndex: 1 }}>
                <Box
                    component="span"
                    aria-hidden="true"
                    sx={{
                        display: 'grid',
                        flexShrink: 0,
                        width: 40,
                        height: 40,
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
                    <Icon size={23} weight="duotone" />
                </Box>
                <Typography component="p" sx={{ minWidth: 0, color: 'var(--ui-text-muted)', fontSize: '0.875rem', lineHeight: 1.5, fontWeight: 600 }}>
                    {label}
                </Typography>
            </Box>
            <Typography
                component="p"
                title={unit ? `${value} ${unit}` : String(value)}
                sx={{
                    mt: 1.25,
                    position: 'relative',
                    zIndex: 1,
                    minWidth: 0,
                    overflow: 'hidden',
                    color: 'var(--ui-text)',
                    fontSize: 'clamp(28px, 2.1vw, 32px)',
                    fontWeight: 700,
                    fontVariantNumeric: 'tabular-nums',
                    letterSpacing: '-0.025em',
                    lineHeight: 1.15,
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                }}
            >
                {value}
                {unit && (
                    <Box component="span" sx={{ ml: 1, fontSize: '0.875rem', fontWeight: 600, letterSpacing: 0 }}>
                        {unit}
                    </Box>
                )}
            </Typography>
            <Typography
                component="p"
                variant="body2"
                sx={{
                    mt: 0.625,
                    position: 'relative',
                    zIndex: 1,
                    minWidth: 0,
                    color: 'var(--ui-text-muted)',
                    fontSize: '0.8125rem',
                    lineHeight: 1.5,
                    overflowWrap: 'anywhere',
                }}
            >
                {detail}
            </Typography>
        </Card>
    );
}
