/**
 * UI Store (Volume 1.4 §3.1)
 * ===========================
 * Manages UI state: theme, density, locale, layout preferences.
 * Theme/density are set via `data-theme` / `data-density` on <html>
 * (Volume 0.2 §11) — a CSS variable swap, no re-render.
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export type ThemeName = 'light' | 'dark' | 'high-contrast' | 'deuteranopia' | 'tritanopia' | 'imaging';
export type DensityName = 'compact' | 'comfortable' | 'spacious';

const THEME_KEY = 'afyanova:theme';
const DENSITY_KEY = 'afyanova:density';
const LOCALE_KEY = 'afyanova:locale';

function applyTheme(theme: ThemeName) {
    document.documentElement.setAttribute('data-theme', theme);
}

function applyDensity(density: DensityName) {
    document.documentElement.setAttribute('data-density', density);
}

export const useUiStore = defineStore('ui', () => {
    // ---- State ----
    const theme = ref<ThemeName>((localStorage.getItem(THEME_KEY) as ThemeName) || 'light');
    const density = ref<DensityName>((localStorage.getItem(DENSITY_KEY) as DensityName) || 'comfortable');
    const locale = ref<string>(localStorage.getItem(LOCALE_KEY) || 'en');
    const navCollapsed = ref(localStorage.getItem('afyanova:nav-collapsed') === 'true');
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