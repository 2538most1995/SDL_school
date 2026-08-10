import {
    Box,
    CssBaseline,
    StyledEngineProvider,
    ThemeProvider,
    createTheme,
    type Theme,
} from '@mui/material';
import { useLayoutEffect, useMemo, useState, type CSSProperties, type ReactNode } from 'react';
import {
    APPEARANCE_CHANGE_EVENT,
    DEFAULT_APPEARANCE,
    loadStoredAppearance,
    type AppearanceSettings,
    type ColorScheme,
} from '../lib/appearance';

const brandPalettes: Record<ColorScheme, { light: string; main: string; dark: string; contrastText: string }> = {
    blue: { light: '#dbeafe', main: '#1d4ed8', dark: '#1e3a8a', contrastText: '#ffffff' },
    teal: { light: '#ccfbf1', main: '#0f766e', dark: '#134e4a', contrastText: '#ffffff' },
    violet: { light: '#ede9fe', main: '#6d28d9', dark: '#4c1d95', contrastText: '#ffffff' },
    rose: { light: '#ffe4e6', main: '#be123c', dark: '#881337', contrastText: '#ffffff' },
    amber: { light: '#fef3c7', main: '#b45309', dark: '#78350f', contrastText: '#ffffff' },
};

function resolveMode(): 'light' | 'dark' {
    return document.documentElement.dataset.uiMode === 'dark' ? 'dark' : 'light';
}

function buildTheme(settings: AppearanceSettings, mode: 'light' | 'dark'): Theme {
    return createTheme({
        palette: {
            mode,
            primary: brandPalettes[settings.colorScheme],
            background: mode === 'dark'
                ? { default: '#07101f', paper: '#101c30' }
                : { default: '#f3f6fb', paper: '#ffffff' },
            text: mode === 'dark'
                ? { primary: '#eef4ff', secondary: '#b9c6d9' }
                : { primary: '#10213d', secondary: '#52627a' },
            divider: mode === 'dark' ? '#293a55' : '#dbe3ee',
            success: { main: '#0b7d68', light: '#d9f7ed' },
            warning: { main: '#a95f00', light: '#fff1cf' },
            error: { main: '#c4315a', light: '#ffe1e9' },
            info: { main: '#146cc4', light: '#dceeff' },
        },
        shape: { borderRadius: 12 },
        typography: {
            fontFamily: "'Noto Sans Thai', 'Leelawadee UI', Tahoma, sans-serif",
            h1: { fontWeight: 800, letterSpacing: '-0.025em' },
            h2: { fontWeight: 800, letterSpacing: '-0.02em' },
            h3: { fontWeight: 800, letterSpacing: '-0.015em' },
            button: { fontWeight: 750, textTransform: 'none', letterSpacing: 0 },
        },
        components: {
            MuiCssBaseline: {
                styleOverrides: {
                    body: {
                        backgroundColor: 'var(--ui-page)',
                        color: 'var(--ui-text)',
                    },
                },
            },
            MuiButton: {
                defaultProps: { disableRipple: false },
                styleOverrides: {
                    root: {
                        minHeight: 42,
                        borderRadius: 12,
                        letterSpacing: 0,
                        transition: 'transform 140ms cubic-bezier(0.23, 1, 0.32, 1), background-color 160ms ease, border-color 160ms ease, box-shadow 160ms ease',
                        '&:active:not(:disabled)': {
                            transform: 'scale(0.97)',
                        },
                    },
                    sizeLarge: { minHeight: 48, paddingInline: 20 },
                    contained: {
                        boxShadow: '0 7px 18px color-mix(in srgb, var(--ui-accent-700) 24%, transparent)',
                        '&:hover': {
                            boxShadow: '0 9px 22px color-mix(in srgb, var(--ui-accent-700) 30%, transparent)',
                        },
                    },
                    textPrimary: {
                        color: mode === 'dark' ? 'var(--ui-accent-200)' : brandPalettes[settings.colorScheme].main,
                    },
                    outlinedPrimary: {
                        color: mode === 'dark' ? 'var(--ui-accent-200)' : brandPalettes[settings.colorScheme].main,
                    },
                    outlined: {
                        borderColor: 'var(--ui-border-strong)',
                        backgroundColor: 'color-mix(in srgb, var(--ui-surface) 94%, var(--ui-accent-50))',
                    },
                },
            },
            MuiCard: {
                styleOverrides: {
                    root: {
                        borderColor: 'var(--ui-border)',
                        borderRadius: 18,
                        backgroundImage: 'none',
                        boxShadow: 'var(--ui-shadow-card)',
                    },
                },
            },
            MuiPaper: {
                styleOverrides: {
                    root: {
                        backgroundImage: 'none',
                    },
                    rounded: {
                        borderRadius: 18,
                    },
                },
            },
            MuiOutlinedInput: {
                styleOverrides: {
                    root: {
                        minHeight: 'var(--ui-control-height)',
                        borderRadius: 12,
                        backgroundColor: 'color-mix(in srgb, var(--ui-surface) 97%, var(--ui-accent-50))',
                        transition: 'box-shadow 160ms ease, background-color 160ms ease',
                        '&:hover .MuiOutlinedInput-notchedOutline': {
                            borderColor: 'var(--ui-accent-400)',
                        },
                        '&.Mui-focused': {
                            boxShadow: '0 0 0 4px color-mix(in srgb, var(--ui-accent-200) 50%, transparent)',
                            backgroundColor: 'var(--ui-surface)',
                        },
                    },
                    input: {
                        paddingBlock: 10.5,
                    },
                    notchedOutline: {
                        borderColor: 'var(--ui-border-strong)',
                    },
                },
            },
            MuiSelect: {
                styleOverrides: {
                    select: {
                        minHeight: 'auto',
                        paddingBlock: 10.5,
                    },
                },
            },
            MuiFormLabel: {
                styleOverrides: {
                    root: {
                        color: 'var(--ui-text)',
                        fontSize: '0.875rem',
                        fontWeight: 700,
                        lineHeight: 1.5,
                    },
                },
            },
            MuiTab: {
                styleOverrides: {
                    root: {
                        minHeight: 48,
                        fontWeight: 700,
                        textTransform: 'none',
                    },
                },
            },
            MuiTabs: {
                styleOverrides: {
                    root: { minHeight: 48 },
                    indicator: { height: 3, borderRadius: 999 },
                },
            },
            MuiTableCell: {
                styleOverrides: {
                    root: {
                        borderColor: 'var(--ui-border)',
                        fontFamily: 'inherit',
                    },
                    head: {
                        color: 'var(--ui-text)',
                        fontWeight: 800,
                        backgroundColor: 'color-mix(in srgb, var(--ui-accent-50) 62%, var(--ui-surface))',
                    },
                },
            },
            MuiChip: {
                styleOverrides: {
                    root: { borderRadius: 999, fontWeight: 750 },
                },
            },
            MuiAlert: {
                styleOverrides: {
                    root: { borderRadius: 16, alignItems: 'flex-start' },
                    message: { width: '100%' },
                },
            },
            MuiDialog: {
                styleOverrides: {
                    paper: {
                        borderRadius: 20,
                        border: '1px solid var(--ui-border)',
                        boxShadow: '0 28px 75px rgb(var(--ui-shadow) / 0.22)',
                    },
                },
            },
            MuiMenu: {
                styleOverrides: {
                    paper: {
                        marginTop: 6,
                        borderRadius: 14,
                        border: '1px solid var(--ui-border)',
                        boxShadow: '0 18px 44px rgb(var(--ui-shadow) / 0.16)',
                    },
                },
            },
            MuiMenuItem: {
                styleOverrides: {
                    root: {
                        minHeight: 42,
                        margin: '2px 6px',
                        borderRadius: 10,
                    },
                },
            },
            MuiTooltip: {
                defaultProps: { enterDelay: 350 },
                styleOverrides: {
                    tooltip: {
                        fontFamily: "'Noto Sans Thai', 'Leelawadee UI', Tahoma, sans-serif",
                        fontSize: '0.75rem',
                    },
                },
            },
        },
    });
}

function MaterialThemeRoot({
    children,
    settings,
    mode,
    style,
}: {
    children: ReactNode;
    settings: AppearanceSettings;
    mode: 'light' | 'dark';
    style?: CSSProperties;
}) {
    const theme = useMemo(() => buildTheme(settings, mode), [settings, mode]);

    return (
        <StyledEngineProvider injectFirst>
            <ThemeProvider theme={theme}>
                <CssBaseline />
                <Box className="sena-material-root" style={style}>{children}</Box>
            </ThemeProvider>
        </StyledEngineProvider>
    );
}

export function MaterialAppProvider({ children }: { children: ReactNode }) {
    const [appearance, setAppearance] = useState(loadStoredAppearance);
    const [mode, setMode] = useState(resolveMode);

    useLayoutEffect(() => {
        const handleAppearance = (event: Event) => {
            const detail = (event as CustomEvent<AppearanceSettings>).detail;
            if (detail) setAppearance(detail);
            setMode(resolveMode());
        };
        window.addEventListener(APPEARANCE_CHANGE_EVENT, handleAppearance);
        return () => window.removeEventListener(APPEARANCE_CHANGE_EVENT, handleAppearance);
    }, []);

    return <MaterialThemeRoot settings={appearance} mode={mode}>{children}</MaterialThemeRoot>;
}

export function PublicLightMaterialProvider({ children }: { children: ReactNode }) {
    const lightSurfaceVariables = {
        '--ui-page': '#f3f6fb',
        '--ui-page-top': '#ffffff',
        '--ui-surface': '#ffffff',
        '--ui-surface-muted': '#f8faff',
        '--ui-surface-subtle': '#edf2f8',
        '--ui-border': '#dbe3ee',
        '--ui-border-strong': '#becbda',
        '--ui-text': '#10213d',
        '--ui-text-muted': '#52627a',
        '--ui-text-soft': '#718198',
        '--ui-shadow': '35 55 88',
        '--ui-shadow-card': '0 10px 30px rgb(35 55 88 / 0.08), 0 1px 2px rgb(35 55 88 / 0.05)',
        colorScheme: 'light',
    } as CSSProperties;

    return (
        <MaterialThemeRoot
            settings={{ ...DEFAULT_APPEARANCE, theme: 'light', colorScheme: 'blue' }}
            mode="light"
            style={lightSurfaceVariables}
        >
            {children}
        </MaterialThemeRoot>
    );
}
