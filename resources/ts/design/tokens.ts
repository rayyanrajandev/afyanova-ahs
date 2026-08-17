/**
 * AFYANOVA DESIGN TOKENS — SINGLE SOURCE OF TRUTH
 * =================================================
 * Volume 0.2 — Design Tokens & Theming System
 *
 * This is the ONLY place token values are authored. A build script
 * generates `resources/css/tokens.css` + `resources/css/tailwind.css`
 * from this file. No token is hand-typed in two places.
 *
 * Architecture (Volume 0.2 §3):
 *   Layer 1 — PRIMITIVE (raw scales, theme-invariant)
 *   Layer 2 — SEMANTIC (meaning, theme-variant)
 *
 * Color space: OKLCH (perceptually uniform, reliable contrast).
 * Brand: teal-cyan primary (ADR-0002).
 */

/* ================================================================
   LAYER 1 — PRIMITIVES (theme-invariant raw scales)
   ================================================================ */

export const primitives = {
  /* ---- Color primitives (OKLCH) ----
     Each hue is a 50→950 scale. Referenced only by Layer 2. */

  teal: {
    50: 'oklch(0.97 0.02 210)',
    100: 'oklch(0.93 0.04 210)',
    200: 'oklch(0.86 0.07 210)',
    300: 'oklch(0.78 0.10 210)',
    400: 'oklch(0.68 0.12 210)',
    500: 'oklch(0.58 0.12 210)',
    600: 'oklch(0.50 0.11 210)',
    700: 'oklch(0.42 0.09 210)',
    800: 'oklch(0.34 0.07 210)',
    900: 'oklch(0.26 0.05 210)',
    950: 'oklch(0.18 0.04 210)',
  },

  slate: {
    50: 'oklch(0.985 0.002 250)',
    100: 'oklch(0.967 0.003 250)',
    200: 'oklch(0.929 0.006 250)',
    300: 'oklch(0.869 0.01 250)',
    400: 'oklch(0.704 0.015 250)',
    500: 'oklch(0.554 0.02 250)',
    600: 'oklch(0.446 0.02 250)',
    700: 'oklch(0.372 0.02 250)',
    800: 'oklch(0.279 0.02 250)',
    900: 'oklch(0.208 0.02 250)',
    950: 'oklch(0.129 0.02 250)',
  },

  red: {
    50: 'oklch(0.971 0.013 25)',
    100: 'oklch(0.936 0.032 25)',
    200: 'oklch(0.885 0.062 25)',
    300: 'oklch(0.808 0.114 25)',
    400: 'oklch(0.704 0.191 25)',
    500: 'oklch(0.637 0.237 25)',
    600: 'oklch(0.577 0.245 25)',
    700: 'oklch(0.505 0.213 25)',
    800: 'oklch(0.444 0.177 25)',
    900: 'oklch(0.396 0.141 25)',
    950: 'oklch(0.258 0.092 25)',
  },

  amber: {
    50: 'oklch(0.987 0.022 75)',
    100: 'oklch(0.962 0.059 75)',
    200: 'oklch(0.924 0.12 75)',
    300: 'oklch(0.879 0.169 75)',
    400: 'oklch(0.828 0.189 75)',
    500: 'oklch(0.769 0.188 75)',
    600: 'oklch(0.666 0.179 75)',
    700: 'oklch(0.555 0.163 75)',
    800: 'oklch(0.473 0.137 75)',
    900: 'oklch(0.414 0.112 75)',
    950: 'oklch(0.279 0.077 75)',
  },

  green: {
    50: 'oklch(0.982 0.018 150)',
    100: 'oklch(0.962 0.044 150)',
    200: 'oklch(0.925 0.084 150)',
    300: 'oklch(0.871 0.15 150)',
    400: 'oklch(0.792 0.209 150)',
    500: 'oklch(0.723 0.219 150)',
    600: 'oklch(0.627 0.194 150)',
    700: 'oklch(0.527 0.154 150)',
    800: 'oklch(0.448 0.119 150)',
    900: 'oklch(0.393 0.095 150)',
    950: 'oklch(0.266 0.065 150)',
  },

  blue: {
    50: 'oklch(0.97 0.014 250)',
    100: 'oklch(0.932 0.032 250)',
    200: 'oklch(0.882 0.059 250)',
    300: 'oklch(0.809 0.105 250)',
    400: 'oklch(0.707 0.165 250)',
    500: 'oklch(0.623 0.214 250)',
    600: 'oklch(0.546 0.245 250)',
    700: 'oklch(0.488 0.243 250)',
    800: 'oklch(0.424 0.199 250)',
    900: 'oklch(0.379 0.146 250)',
    950: 'oklch(0.282 0.091 250)',
  },

  violet: {
    50: 'oklch(0.969 0.016 300)',
    100: 'oklch(0.943 0.029 300)',
    200: 'oklch(0.894 0.057 300)',
    300: 'oklch(0.811 0.111 300)',
    400: 'oklch(0.702 0.183 300)',
    500: 'oklch(0.606 0.25 300)',
    600: 'oklch(0.541 0.281 300)',
    700: 'oklch(0.491 0.27 300)',
    800: 'oklch(0.432 0.232 300)',
    900: 'oklch(0.38 0.189 300)',
    950: 'oklch(0.283 0.141 300)',
  },

  /* ---- Spacing primitives (4px base, non-linear) ---- */
  space: {
    0: '0',
    px: '1px',
    '0.5': '2px',
    1: '4px',
    1.5: '6px',
    2: '8px',
    3: '12px',
    4: '16px',
    5: '20px',
    6: '24px',
    8: '32px',
    10: '40px',
    12: '48px',
    16: '64px',
    20: '80px',
    30: '120px',
  },

  /* ---- Radius primitives ---- */
  radius: {
    none: '0',
    sm: '0.25rem',
    md: '0.375rem',
    lg: '0.5rem',
    xl: '0.75rem',
    full: '9999px',
  },

  /* ---- Motion primitives ---- */
  duration: {
    instant: '0.01ms',
    fast: '100ms',
    base: '150ms',
    slow: '250ms',
    slower: '400ms',
  },

  ease: {
    standard: 'cubic-bezier(0.2, 0, 0, 1)',
    emphasized: 'cubic-bezier(0.3, 0, 0, 1)',
    in: 'cubic-bezier(0.4, 0, 1, 1)',
    out: 'cubic-bezier(0, 0, 0.2, 1)',
  },

  /* ---- Z-index primitives ---- */
  z: {
    base: '0',
    dropdown: '1000',
    sticky: '1100',
    drawer: '1200',
    modal: '1300',
    popover: '1400',
    toast: '1500',
    command: '1600',
  },

  /* ---- Focus primitives ---- */
  focus: {
    width: '2px',
    offset: '2px',
    style: 'solid',
  },

  /* ---- Dialog width primitives (Volume 1.2 §10.4) ---- */
  dialogWidth: {
    sm: '400px',
    md: '480px',
    lg: '640px',
    xl: '800px',
  },
} as const;

/* ================================================================
   LAYER 2 — SEMANTIC TOKENS (theme-variant)
   ================================================================ */

export type ThemeName = 'light' | 'dark' | 'high-contrast' | 'deuteranopia' | 'tritanopia' | 'imaging';
export type DensityName = 'compact' | 'comfortable' | 'spacious';

export interface SemanticTokens {
  /* Surfaces */
  background: string;
  foreground: string;
  surface: string;
  'surface-foreground': string;
  'surface-raised': string;
  'surface-raised-foreground': string;

  /* Brand */
  primary: string;
  'primary-foreground': string;
  secondary: string;
  'secondary-foreground': string;
  muted: string;
  'muted-foreground': string;
  accent: string;
  'accent-foreground': string;

  /* Status */
  destructive: string;
  'destructive-foreground': string;
  critical: string;
  'critical-foreground': string;
  warning: string;
  'warning-foreground': string;
  success: string;
  'success-foreground': string;
  info: string;
  'info-foreground': string;

  /* Vitals */
  'vitals-normal': string;
  'vitals-warning': string;
  'vitals-critical': string;

  /* Borders & inputs */
  border: string;
  input: string;
  'input-background': string;
  ring: string;

  /* Charts */
  'chart-1': string;
  'chart-2': string;
  'chart-3': string;
  'chart-4': string;
  'chart-5': string;

  /* Sidebar */
  sidebar: string;
  'sidebar-foreground': string;
  'sidebar-primary': string;
  'sidebar-primary-foreground': string;
  'sidebar-accent': string;
  'sidebar-accent-foreground': string;
  'sidebar-border': string;
  'sidebar-ring': string;

  /* Privacy */
  'phi-mask': string;
}

/* ---- LIGHT THEME (default, clinical daylight) ---- */
export const light: SemanticTokens = {
  background: 'oklch(0.975 0.003 250)',
  foreground: 'oklch(0.20 0.02 250)',
  surface: 'oklch(1 0 0)',
  'surface-foreground': 'oklch(0.20 0.02 250)',
  'surface-raised': 'oklch(0.995 0.002 250)',
  'surface-raised-foreground': 'oklch(0.20 0.02 250)',

  primary: 'oklch(0.54 0.10 205)',
  'primary-foreground': 'oklch(0.99 0.002 205)',
  secondary: 'oklch(0.955 0.005 250)',
  'secondary-foreground': 'oklch(0.25 0.02 250)',
  muted: 'oklch(0.96 0.004 250)',
  'muted-foreground': 'oklch(0.52 0.015 250)',
  accent: 'oklch(0.94 0.015 205)',
  'accent-foreground': 'oklch(0.25 0.05 205)',

  destructive: 'oklch(0.55 0.18 25)',
  'destructive-foreground': 'oklch(1 0 0)',
  critical: 'oklch(0.55 0.20 25)',
  'critical-foreground': 'oklch(1 0 0)',
  warning: 'oklch(0.70 0.15 72)',
  'warning-foreground': 'oklch(0.20 0.02 250)',
  success: 'oklch(0.60 0.14 150)',
  'success-foreground': 'oklch(1 0 0)',
  info: 'oklch(0.55 0.11 245)',
  'info-foreground': 'oklch(1 0 0)',

  'vitals-normal': 'oklch(0.60 0.14 150)',
  'vitals-warning': 'oklch(0.70 0.15 72)',
  'vitals-critical': 'oklch(0.55 0.20 25)',

  border: 'oklch(0.93 0.003 250)',
  input: 'oklch(0.92 0.003 250)',
  'input-background': 'oklch(0.985 0.002 250)',
  ring: 'oklch(0.54 0.10 205)',

  'chart-1': 'oklch(0.54 0.10 205)',
  'chart-2': 'oklch(0.60 0.14 150)',
  'chart-3': 'oklch(0.55 0.20 25)',
  'chart-4': 'oklch(0.70 0.15 72)',
  'chart-5': 'oklch(0.55 0.15 300)',

  sidebar: 'oklch(0.985 0.002 250)',
  'sidebar-foreground': 'oklch(0.20 0.02 250)',
  'sidebar-primary': 'oklch(0.54 0.10 205)',
  'sidebar-primary-foreground': 'oklch(0.99 0.002 205)',
  'sidebar-accent': 'oklch(0.945 0.012 205)',
  'sidebar-accent-foreground': 'oklch(0.20 0.04 205)',
  'sidebar-border': 'oklch(0.93 0.002 250)',
  'sidebar-ring': 'oklch(0.54 0.10 205)',

  'phi-mask': 'oklch(0.93 0.003 250)',
};

/* ---- DARK THEME (night-shift / low-light) ---- */
export const dark: SemanticTokens = {
  background: 'oklch(0.16 0.012 250)',
  foreground: 'oklch(0.95 0.004 250)',
  surface: 'oklch(0.19 0.012 250)',
  'surface-foreground': 'oklch(0.95 0.004 250)',
  'surface-raised': 'oklch(0.22 0.012 250)',
  'surface-raised-foreground': 'oklch(0.95 0.004 250)',

  primary: 'oklch(0.72 0.11 195)',
  'primary-foreground': 'oklch(0.16 0.03 205)',
  secondary: 'oklch(0.26 0.012 250)',
  'secondary-foreground': 'oklch(0.94 0.005 250)',
  muted: 'oklch(0.25 0.012 250)',
  'muted-foreground': 'oklch(0.68 0.01 250)',
  accent: 'oklch(0.30 0.03 205)',
  'accent-foreground': 'oklch(0.92 0.04 195)',

  destructive: 'oklch(0.65 0.20 25)',
  'destructive-foreground': 'oklch(0.98 0.02 25)',
  critical: 'oklch(0.68 0.19 25)',
  'critical-foreground': 'oklch(0.98 0.02 25)',
  warning: 'oklch(0.78 0.15 72)',
  'warning-foreground': 'oklch(0.16 0.02 250)',
  success: 'oklch(0.72 0.14 150)',
  'success-foreground': 'oklch(0.16 0.02 150)',
  info: 'oklch(0.70 0.12 245)',
  'info-foreground': 'oklch(0.16 0.02 250)',

  'vitals-normal': 'oklch(0.72 0.14 150)',
  'vitals-warning': 'oklch(0.78 0.15 72)',
  'vitals-critical': 'oklch(0.68 0.19 25)',

  border: 'oklch(0.24 0.008 250)',
  input: 'oklch(0.25 0.008 250)',
  'input-background': 'oklch(0.18 0.01 250)',
  ring: 'oklch(0.72 0.11 195)',

  'chart-1': 'oklch(0.72 0.11 195)',
  'chart-2': 'oklch(0.72 0.14 150)',
  'chart-3': 'oklch(0.68 0.19 25)',
  'chart-4': 'oklch(0.78 0.15 72)',
  'chart-5': 'oklch(0.70 0.15 300)',

  sidebar: 'oklch(0.175 0.012 250)',
  'sidebar-foreground': 'oklch(0.95 0.004 250)',
  'sidebar-primary': 'oklch(0.72 0.11 195)',
  'sidebar-primary-foreground': 'oklch(0.16 0.03 205)',
  'sidebar-accent': 'oklch(0.26 0.025 205)',
  'sidebar-accent-foreground': 'oklch(0.96 0.03 195)',
  'sidebar-border': 'oklch(0.23 0.006 250)',
  'sidebar-ring': 'oklch(0.72 0.11 195)',

  'phi-mask': 'oklch(0.24 0.008 250)',
};

/* ---- HIGH-CONTRAST THEME (WCAG AAA-target, low-vision) ---- */
export const highContrast: SemanticTokens = {
  background: 'oklch(0.99 0 0)',
  foreground: 'oklch(0.10 0 0)',
  surface: 'oklch(1 0 0)',
  'surface-foreground': 'oklch(0.10 0 0)',
  'surface-raised': 'oklch(1 0 0)',
  'surface-raised-foreground': 'oklch(0.10 0 0)',

  primary: 'oklch(0.40 0.12 210)',
  'primary-foreground': 'oklch(1 0 0)',
  secondary: 'oklch(0.90 0.01 250)',
  'secondary-foreground': 'oklch(0.10 0 0)',
  muted: 'oklch(0.92 0.005 250)',
  'muted-foreground': 'oklch(0.30 0.01 250)',
  accent: 'oklch(0.85 0.04 210)',
  'accent-foreground': 'oklch(0.10 0 0)',

  destructive: 'oklch(0.45 0.22 25)',
  'destructive-foreground': 'oklch(1 0 0)',
  critical: 'oklch(0.45 0.22 25)',
  'critical-foreground': 'oklch(1 0 0)',
  warning: 'oklch(0.55 0.18 75)',
  'warning-foreground': 'oklch(0.10 0 0)',
  success: 'oklch(0.45 0.16 150)',
  'success-foreground': 'oklch(1 0 0)',
  info: 'oklch(0.40 0.14 250)',
  'info-foreground': 'oklch(1 0 0)',

  'vitals-normal': 'oklch(0.45 0.16 150)',
  'vitals-warning': 'oklch(0.55 0.18 75)',
  'vitals-critical': 'oklch(0.45 0.22 25)',

  border: 'oklch(0.60 0.01 250)',
  input: 'oklch(0.60 0.01 250)',
  'input-background': 'oklch(0.98 0 0)',
  ring: 'oklch(0.40 0.12 210)',

  'chart-1': 'oklch(0.40 0.12 210)',
  'chart-2': 'oklch(0.45 0.16 150)',
  'chart-3': 'oklch(0.45 0.22 25)',
  'chart-4': 'oklch(0.55 0.18 75)',
  'chart-5': 'oklch(0.40 0.18 300)',

  sidebar: 'oklch(0.99 0 0)',
  'sidebar-foreground': 'oklch(0.10 0 0)',
  'sidebar-primary': 'oklch(0.40 0.12 210)',
  'sidebar-primary-foreground': 'oklch(1 0 0)',
  'sidebar-accent': 'oklch(0.92 0.005 250)',
  'sidebar-accent-foreground': 'oklch(0.10 0 0)',
  'sidebar-border': 'oklch(0.60 0.01 250)',
  'sidebar-ring': 'oklch(0.40 0.12 210)',

  'phi-mask': 'oklch(0.85 0.01 250)',
};

/* ---- DEUTERANOPIA THEME (red-green color-blind safe) ---- */
export const deuteranopia: SemanticTokens = {
  ...light,
  // Shift red/green status hues to blue/orange for deuteranopia safety
  destructive: 'oklch(0.55 0.18 25)',
  'destructive-foreground': 'oklch(1 0 0)',
  critical: 'oklch(0.55 0.18 25)',
  'critical-foreground': 'oklch(1 0 0)',
  warning: 'oklch(0.70 0.15 75)',
  'warning-foreground': 'oklch(0.20 0.02 250)',
  success: 'oklch(0.60 0.12 250)', // blue instead of green
  'success-foreground': 'oklch(1 0 0)',
  'vitals-normal': 'oklch(0.60 0.12 250)',
  'vitals-warning': 'oklch(0.70 0.15 75)',
  'vitals-critical': 'oklch(0.55 0.18 25)',
  'chart-2': 'oklch(0.60 0.12 250)',
  'chart-3': 'oklch(0.55 0.18 25)',
};

/* ---- TRITANOPIA THEME (blue-yellow color-blind safe) ---- */
export const tritanopia: SemanticTokens = {
  ...light,
  // Shift blue/yellow hues to red/green for tritanopia safety
  info: 'oklch(0.55 0.18 25)', // red instead of blue
  'info-foreground': 'oklch(1 0 0)',
  warning: 'oklch(0.62 0.13 150)', // green instead of amber
  'warning-foreground': 'oklch(1 0 0)',
  'vitals-warning': 'oklch(0.62 0.13 150)',
  'chart-4': 'oklch(0.62 0.13 150)',
  'chart-1': 'oklch(0.55 0.18 25)',
};

/* ---- IMAGING THEME (radiology — pure black, no chroma) ---- */
export const imaging: SemanticTokens = {
  background: 'oklch(0 0 0)',
  foreground: 'oklch(0.98 0 0)',
  surface: 'oklch(0.10 0 0)',
  'surface-foreground': 'oklch(0.98 0 0)',
  'surface-raised': 'oklch(0.15 0 0)',
  'surface-raised-foreground': 'oklch(0.98 0 0)',

  primary: 'oklch(0.80 0 0)',
  'primary-foreground': 'oklch(0 0 0)',
  secondary: 'oklch(0.20 0 0)',
  'secondary-foreground': 'oklch(0.98 0 0)',
  muted: 'oklch(0.18 0 0)',
  'muted-foreground': 'oklch(0.70 0 0)',
  accent: 'oklch(0.25 0 0)',
  'accent-foreground': 'oklch(0.98 0 0)',

  destructive: 'oklch(0.70 0 0)',
  'destructive-foreground': 'oklch(0 0 0)',
  critical: 'oklch(0.70 0 0)',
  'critical-foreground': 'oklch(0 0 0)',
  warning: 'oklch(0.75 0 0)',
  'warning-foreground': 'oklch(0 0 0)',
  success: 'oklch(0.75 0 0)',
  'success-foreground': 'oklch(0 0 0)',
  info: 'oklch(0.75 0 0)',
  'info-foreground': 'oklch(0 0 0)',

  'vitals-normal': 'oklch(0.75 0 0)',
  'vitals-warning': 'oklch(0.75 0 0)',
  'vitals-critical': 'oklch(0.70 0 0)',

  border: 'oklch(0.30 0 0)',
  input: 'oklch(0.30 0 0)',
  'input-background': 'oklch(0.12 0 0)',
  ring: 'oklch(0.80 0 0)',

  'chart-1': 'oklch(0.80 0 0)',
  'chart-2': 'oklch(0.75 0 0)',
  'chart-3': 'oklch(0.70 0 0)',
  'chart-4': 'oklch(0.75 0 0)',
  'chart-5': 'oklch(0.80 0 0)',

  sidebar: 'oklch(0.05 0 0)',
  'sidebar-foreground': 'oklch(0.98 0 0)',
  'sidebar-primary': 'oklch(0.80 0 0)',
  'sidebar-primary-foreground': 'oklch(0 0 0)',
  'sidebar-accent': 'oklch(0.15 0 0)',
  'sidebar-accent-foreground': 'oklch(0.98 0 0)',
  'sidebar-border': 'oklch(0.25 0 0)',
  'sidebar-ring': 'oklch(0.80 0 0)',

  'phi-mask': 'oklch(0.25 0 0)',
};

/* ---- Theme registry ---- */
export const themes: Record<ThemeName, SemanticTokens> = {
  light,
  dark,
  'high-contrast': highContrast,
  deuteranopia,
  tritanopia,
  imaging,
};

/* ================================================================
   DENSITY TOKENS (Volume 0.2 §6.2)
   ================================================================ */

export interface DensityTokens {
  'space-control-xs': string;
  'space-control-sm': string;
  'space-control-md': string;
  'space-control-lg': string;
  'space-page': string;
  'space-section': string;
  'size-control-sm': string;
  'size-control-md': string;
  'size-control-lg': string;
  'size-icon-sm': string;
  'size-icon-md': string;
  'size-icon-lg': string;
  // Shell layout tokens (Volume 1.1 §11) — added 2026-08-10. Previously
  // referenced by AppShell.vue inline styles (`var(--shell-topbar-height,
  // 56px)` etc.) but never actually defined anywhere, so every density
  // silently fell back to the same hardcoded default and the shell never
  // varied by density despite §11's table specifying that it should.
  'shell-topbar-height': string;
  'shell-navrail-width': string;
  'shell-navrail-width-expanded': string;
  'shell-statusbar-height': string;
  'shell-pane-min-width': string;
  'shell-pane-handle-width': string;
  'shell-banner-height': string;
  // §11 names this `--space-2`/`--space-4`/`--space-6` — a numeric spacing
  // scale this codebase never built (it uses the semantic `space-page`/
  // `space-section` scale instead throughout). Mapped onto the closest
  // existing equivalent (`space-page`) rather than introducing a second,
  // parallel spacing scale for one token.
  'shell-content-padding': string;
}

export const densities: Record<DensityName, DensityTokens> = {
  compact: {
    'space-control-xs': '2px',
    'space-control-sm': '6px',
    'space-control-md': '8px',
    'space-control-lg': '12px',
    'space-page': '16px',
    'space-section': '24px',
    'size-control-sm': '28px',
    'size-control-md': '32px',
    'size-control-lg': '36px',
    'size-icon-sm': '16px',
    'size-icon-md': '20px',
    'size-icon-lg': '24px',
    'shell-topbar-height': '48px',
    'shell-navrail-width': '56px',
    'shell-navrail-width-expanded': '200px',
    'shell-statusbar-height': '24px',
    'shell-pane-min-width': '280px',
    'shell-pane-handle-width': '4px',
    'shell-banner-height': '56px',
    'shell-content-padding': 'var(--space-page)',
  },
  comfortable: {
    'space-control-xs': '6px',
    'space-control-sm': '8px',
    'space-control-md': '12px',
    'space-control-lg': '16px',
    'space-page': '24px',
    'space-section': '32px',
    'size-control-sm': '32px',
    'size-control-md': '36px',
    'size-control-lg': '40px',
    'size-icon-sm': '16px',
    'size-icon-md': '20px',
    'size-icon-lg': '24px',
    'shell-topbar-height': '56px',
    'shell-navrail-width': '64px',
    'shell-navrail-width-expanded': '220px',
    'shell-statusbar-height': '28px',
    'shell-pane-min-width': '320px',
    'shell-pane-handle-width': '6px',
    'shell-banner-height': '64px',
    'shell-content-padding': 'var(--space-page)',
  },
  spacious: {
    'space-control-xs': '8px',
    'space-control-sm': '12px',
    'space-control-md': '16px',
    'space-control-lg': '20px',
    'space-page': '32px',
    'space-section': '48px',
    'size-control-sm': '36px',
    'size-control-md': '44px', // gloved-hand minimum (Volume 0.3 §5)
    'size-control-lg': '48px',
    'size-icon-sm': '20px',
    'size-icon-md': '24px',
    'size-icon-lg': '32px',
    'shell-topbar-height': '64px',
    'shell-navrail-width': '72px',
    'shell-navrail-width-expanded': '240px',
    'shell-statusbar-height': '32px',
    'shell-pane-min-width': '360px',
    'shell-pane-handle-width': '8px',
    'shell-banner-height': '72px',
    'shell-content-padding': 'var(--space-page)',
  },
};

/* ================================================================
   EXPORT — single source of truth
   ================================================================ */

export const tokens = {
  primitives,
  themes,
  densities,
  defaultTheme: 'light' as ThemeName,
  defaultDensity: 'comfortable' as DensityName,
};