import {
    Alert,
    AlertTitle,
    Avatar as MuiAvatar,
    Box,
    Button as MuiButton,
    Card as MuiCard,
    Chip,
    CircularProgress,
    FormControl,
    FormHelperText,
    FormLabel,
    InputAdornment,
    OutlinedInput,
    Select as MuiSelect,
    Tab as MuiTab,
    Tabs,
    Tooltip as MuiTooltip,
    Typography,
} from '@mui/material';
import type {
    ButtonProps as MuiButtonProps,
    CardProps as MuiCardProps,
    SelectChangeEvent,
} from '@mui/material';
import { cloneElement, isValidElement } from 'react';
import type {
    ChangeEvent,
    ComponentProps,
    ElementType,
    HTMLAttributes,
    InputHTMLAttributes,
    ReactElement,
    ReactNode,
} from 'react';

const MuiButtonCompat = MuiButton as ElementType;
const MuiTypographyCompat = Typography as ElementType;

type Appearance = 'primary' | 'outline' | 'subtle' | 'transparent';

type ButtonProps = Omit<MuiButtonProps, 'size' | 'variant' | 'startIcon' | 'endIcon'> & {
    appearance?: Appearance;
    size?: 'small' | 'medium' | 'large';
    icon?: ReactNode;
    iconPosition?: 'before' | 'after';
    as?: ElementType;
    href?: string;
    download?: string;
};

const buttonVariants: Record<Appearance, MuiButtonProps['variant']> = {
    primary: 'contained',
    outline: 'outlined',
    subtle: 'text',
    transparent: 'text',
};

export function Button({
    appearance = 'subtle',
    icon,
    iconPosition = 'before',
    as,
    children,
    className,
    ...props
}: ButtonProps) {
    const iconOnly = icon && !children;

    return (
        <MuiButtonCompat
            {...props}
            component={as}
            variant={buttonVariants[appearance]}
            disableElevation
            className={className}
            startIcon={icon && iconPosition === 'before' && !iconOnly ? icon : undefined}
            endIcon={icon && iconPosition === 'after' && !iconOnly ? icon : undefined}
            sx={{
                minWidth: iconOnly ? 40 : undefined,
                paddingInline: iconOnly ? 1 : undefined,
                ...(props.sx ?? {}),
            }}
        >
            {iconOnly ? icon : children}
        </MuiButtonCompat>
    );
}

type CardProps = MuiCardProps & {
    appearance?: 'filled' | 'filled-alternative';
};

export function Card({ appearance = 'filled', className, sx, ...props }: CardProps) {
    return (
        <MuiCard
            {...props}
            variant="outlined"
            className={className}
            sx={{
                backgroundColor: appearance === 'filled-alternative' ? 'action.hover' : 'background.paper',
                ...sx,
            }}
        />
    );
}

type InputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'size' | 'onChange' | 'color'> & {
    size?: 'small' | 'medium' | 'large';
    contentBefore?: ReactNode;
    contentAfter?: ReactNode;
    onChange?: (event: ChangeEvent<HTMLInputElement>, data: { value: string }) => void;
};

export function Input({
    size = 'medium',
    contentBefore,
    contentAfter,
    className,
    onChange,
    inputMode,
    maxLength,
    ...props
}: InputProps) {
    return (
        <OutlinedInput
            {...props}
            fullWidth
            size={size === 'large' ? 'medium' : size}
            className={className}
            startAdornment={contentBefore ? <InputAdornment position="start">{contentBefore}</InputAdornment> : undefined}
            endAdornment={contentAfter ? <InputAdornment position="end">{contentAfter}</InputAdornment> : undefined}
            inputProps={{ inputMode, maxLength }}
            onChange={(event) => onChange?.(event as ChangeEvent<HTMLInputElement>, { value: event.target.value })}
        />
    );
}

type SelectProps = Omit<React.ComponentProps<typeof MuiSelect>, 'size' | 'onChange'> & {
    size?: 'small' | 'medium' | 'large';
    onChange?: (
        event: SelectChangeEvent<unknown> | ChangeEvent<HTMLSelectElement>,
        data: { value: string },
    ) => void;
};

export function Select({ size = 'medium', className, onChange, children, ...props }: SelectProps) {
    return (
        <MuiSelect
            {...props}
            native
            fullWidth
            size={size === 'large' ? 'medium' : size}
            className={className}
            onChange={(event) => onChange?.(event, { value: String(event.target.value) })}
        >
            {children}
        </MuiSelect>
    );
}

export function Field({
    label,
    hint,
    required,
    className,
    children,
}: {
    label: ReactNode;
    hint?: ReactNode;
    required?: boolean;
    className?: string;
    children: ReactNode;
}) {
    const control = isValidElement(children) && typeof label === 'string'
        ? cloneElement(children as ReactElement<{ 'aria-label'?: string }>, {
            'aria-label': (children.props as { 'aria-label'?: string })['aria-label'] ?? label,
        })
        : children;

    return (
        <FormControl fullWidth required={required} className={className}>
            <FormLabel required={required} sx={{ mb: 0.75, fontWeight: 700 }}>
                {label}
            </FormLabel>
            {control}
            {hint ? <FormHelperText>{hint}</FormHelperText> : null}
        </FormControl>
    );
}

export function Spinner({
    label,
    size = 'medium',
    className,
}: {
    label?: string;
    size?: 'tiny' | 'small' | 'medium' | 'large' | 'huge';
    className?: string;
}) {
    const pixels = size === 'tiny' ? 16 : size === 'small' ? 20 : size === 'huge' ? 44 : size === 'large' ? 36 : 28;

    return (
        <span className={`inline-flex items-center gap-2.5 ${className ?? ''}`} role="status">
            <CircularProgress size={pixels} thickness={4.2} />
            {label ? <Typography component="span" variant="body2" sx={{ fontWeight: 700 }}>{label}</Typography> : null}
        </span>
    );
}

type TextProps = HTMLAttributes<HTMLElement> & {
    as?: ElementType;
    size?: 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 | 1000;
    weight?: 'regular' | 'medium' | 'semibold' | 'bold';
};

const textSizes: Partial<Record<NonNullable<TextProps['size']>, string>> = {
    100: '0.625rem',
    200: '0.75rem',
    300: '0.875rem',
    400: '1rem',
    500: '1.25rem',
    600: '1.5rem',
    700: '1.75rem',
    800: '2rem',
    900: '2.5rem',
    1000: '3rem',
};

const textWeights: Record<NonNullable<TextProps['weight']>, number> = {
    regular: 400,
    medium: 500,
    semibold: 600,
    bold: 700,
};

export function Text({
    as = 'span',
    size = 300,
    weight = 'regular',
    style,
    ...props
}: TextProps) {
    return (
        <MuiTypographyCompat
            {...props}
            component={as}
            style={{ fontSize: textSizes[size], fontWeight: textWeights[weight], ...style }}
        />
    );
}

type BadgeColor = 'brand' | 'success' | 'warning' | 'danger' | 'informative' | 'subtle';

export function Badge({
    children,
    color = 'subtle',
    icon,
    className,
}: {
    children: ReactNode;
    appearance?: 'tint';
    color?: BadgeColor;
    icon?: ReactElement;
    size?: 'small' | 'medium' | 'large';
    className?: string;
}) {
    const muiColor = color === 'brand'
        ? 'primary'
        : color === 'danger'
            ? 'error'
            : color === 'informative'
                ? 'info'
                : color === 'subtle'
                    ? 'default'
                    : color;

    return <Chip label={children} icon={icon} color={muiColor} size="small" className={className} />;
}

export function Avatar({
    name,
    image,
    size = 36,
    className,
}: {
    name: string;
    image?: { src: string };
    size?: number;
    color?: 'colorful';
    className?: string;
}) {
    const initials = name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('');

    return (
        <MuiAvatar
            src={image?.src}
            alt={name}
            className={className}
            sx={{ width: size, height: size, bgcolor: 'primary.main', fontSize: size * 0.34, fontWeight: 700 }}
        >
            {initials}
        </MuiAvatar>
    );
}

type MessageIntent = 'error' | 'success' | 'warning' | 'info';

export function MessageBar({
    intent = 'info',
    children,
    ...props
}: {
    intent?: MessageIntent;
    layout?: 'multiline';
    children: ReactNode;
    role?: string;
    className?: string;
}) {
    return <Alert severity={intent} {...props}>{children}</Alert>;
}

export function MessageBarBody({ children }: { children: ReactNode }) {
    return <Box>{children}</Box>;
}

export function MessageBarTitle({ children }: { children: ReactNode }) {
    return <AlertTitle sx={{ fontWeight: 700 }}>{children}</AlertTitle>;
}

export function MessageBarActions({ children }: { children: ReactNode }) {
    return <Box sx={{ mt: 1.5 }}>{children}</Box>;
}

export function TabList({
    selectedValue,
    onTabSelect,
    children,
    className,
    appearance: _appearance,
    size: _size,
    ...props
}: {
    selectedValue: string;
    onTabSelect: (event: React.SyntheticEvent, data: { value: string }) => void;
    children: ReactNode;
    className?: string;
    appearance?: 'subtle';
    size?: 'small' | 'medium' | 'large';
    'aria-label'?: string;
}) {
    return (
        <Tabs
            {...props}
            value={selectedValue}
            onChange={(event, value) => onTabSelect(event, { value: String(value) })}
            className={className}
            variant="fullWidth"
        >
            {children}
        </Tabs>
    );
}

type TabProps = Omit<ComponentProps<typeof MuiTab>, 'label' | 'children'> & {
    children: ReactNode;
};

export function Tab({ children, ...props }: TabProps) {
    return <MuiTab {...props} iconPosition="start" label={children} />;
}

export function Tooltip({
    content,
    relationship: _relationship,
    positioning,
    withArrow,
    children,
}: {
    content: ReactNode;
    relationship?: 'description' | 'label';
    positioning?: 'above-start' | 'above' | 'below';
    withArrow?: boolean;
    children: ReactElement;
}) {
    const placement = positioning === 'above-start' ? 'top-start' : positioning === 'below' ? 'bottom' : 'top';
    return <MuiTooltip title={content} placement={placement} arrow={withArrow}>{children}</MuiTooltip>;
}
