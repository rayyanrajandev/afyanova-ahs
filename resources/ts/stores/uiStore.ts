/**
 * UI Store (Volume 1.4 §3.1)
 * ===========================
 * Manages UI state: theme, density, locale, layout preferences.
 * Theme/density are set via `data-theme` / `data-density` on <html>
 * (Volume 0.2 §11) — a CSS variable swap, no re-render.
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export type ThemeName = 'light' | 'dark' | 'system';
export type DensityName = 'compact' | 'comfortable' | 'spacious';

const THEME_KEY = 'afyanova:theme';
const DENSITY_KEY = 'afyanova:density';
const LOCALE_KEY = 'afyanova:locale';

let systemThemeMediaQuery: MediaQueryList | null = null;
let systemThemeListener: ((e: MediaQueryListEvent) => void) | null = null;

function resolveEffectiveTheme(theme: ThemeName): 'light' | 'dark' {
    if (theme === 'system') {
        if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return 'light';
    }
    return theme;
}

function applyTheme(theme: ThemeName) {
    if (typeof document === 'undefined') return;

    if (systemThemeMediaQuery && systemThemeListener) {
        systemThemeMediaQuery.removeEventListener('change', systemThemeListener);
        systemThemeMediaQuery = null;
        systemThemeListener = null;
    }

    const effective = resolveEffectiveTheme(theme);
    document.documentElement.setAttribute('data-theme', effective);
    document.documentElement.classList.toggle('dark', effective === 'dark');

    if (theme === 'system' && typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
        systemThemeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        systemThemeListener = (e: MediaQueryListEvent) => {
            const nextEffective = e.matches ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', nextEffective);
            document.documentElement.classList.toggle('dark', nextEffective === 'dark');
        };
        systemThemeMediaQuery.addEventListener('change', systemThemeListener);
    }
}

function applyDensity(density: DensityName) {
    document.documentElement.setAttribute('data-density', density);
}

/**
 * Point-of-care touch detection (Volume 0.3 §5, Volume 2.3 §15, Volume 3.8
 * Phase 7). `spacious` (44px+ targets) is meant to be auto-suggested on
 * touch devices — a real, spec'd requirement that had no implementation
 * anywhere before this: `density` only ever changed via the manual
 * shell toggle (`AppShell.vue`), and nothing checked device/input type at
 * all (confirmed — no `matchMedia`/`ontouchstart`/`maxTouchPoints` usage
 * existed in the codebase before this). Uses the standard
 * `(pointer: coarse)` media query — true for touchscreens, false for a
 * mouse/trackpad — rather than `ontouchstart`, which also fires on
 * touch-capable laptops with an attached mouse and would over-suggest.
 */
function isCoarsePointerDevice(): boolean {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
    return window.matchMedia('(pointer: coarse)').matches;
}

export const useUiStore = defineStore('ui', () => {
    // ---- State ----
    const theme = ref<ThemeName>((localStorage.getItem(THEME_KEY) as ThemeName) || 'light');
    // Auto-suggest `spacious` on a coarse-pointer (touch) device, but only
    // when the nurse/user has never made an explicit choice — checking the
    // raw localStorage key's *presence*, not just falling back to a
    // default, so a user who explicitly picked `comfortable` on a tablet
    // is never silently overridden on their next visit. A first-ever visit
    // from a touch device gets `spacious` as its real starting density,
    // not merely available as a manual option they'd have to know to pick.
    const storedDensity = typeof window !== 'undefined' ? (localStorage.getItem(DENSITY_KEY) as DensityName | null) : null;
    const density = ref<DensityName>(storedDensity || (isCoarsePointerDevice() ? 'spacious' : 'comfortable'));
    const locale = ref<string>(typeof window !== 'undefined' ? (localStorage.getItem(LOCALE_KEY) || 'en') : 'en');
    const storedNavCollapsed = typeof window !== 'undefined' ? localStorage.getItem('afyanova:nav-collapsed') : null;
    const navCollapsed = ref(storedNavCollapsed !== null ? storedNavCollapsed === 'true' : true);
    const commandPaletteOpen = ref(false);

    // ---- Actions ----
    function setTheme(next: ThemeName) {
        theme.value = next;
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
    }

    function setDensity(next: DensityName) {
        density.value = next;
        localStorage.setItem(DENSITY_KEY, next);
        applyDensity(next);
    }

    function setLocale(next: string) {
        locale.value = next;
        localStorage.setItem(LOCALE_KEY, next);
    }

    function toggleNav() {
        navCollapsed.value = !navCollapsed.value;
        localStorage.setItem('afyanova:nav-collapsed', String(navCollapsed.value));
    }

    function openCommandPalette() {
        commandPaletteOpen.value = true;
    }

    function closeCommandPalette() {
        commandPaletteOpen.value = false;
    }

    // ---- Initialize on first load ----
    if (typeof document !== 'undefined') {
        applyTheme(theme.value);
        applyDensity(density.value);
        // Persist the auto-suggested density on a genuine first visit so a
        // later `localStorage.getItem(DENSITY_KEY)` read (e.g. on next
        // page load, before this store re-runs its auto-suggest check)
        // sees the same value rather than re-deriving it inconsistently.
        if (!storedDensity) {
            localStorage.setItem(DENSITY_KEY, density.value);
        }
    }

    return {
        theme,
        density,
        locale,
        navCollapsed,
        commandPaletteOpen,
        setTheme,
        setDensity,
        setLocale,
        toggleNav,
        openCommandPalette,
        closeCommandPalette,
    };
});