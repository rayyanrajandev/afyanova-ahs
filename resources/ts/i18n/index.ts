/**
 * vue-i18n setup (Volume 0.4 §3)
 * ================================
 * Locale detection: user profile > localStorage > Accept-Language > en.
 * Fallback chain: sw → en → key (Volume 0.4 §2.3).
 * Locale switching is reactive — no page reload.
 */

import { createI18n } from 'vue-i18n';
import en from './locales/en/common.json';
import sw from './locales/sw/common.json';

const messages = {
    en,
    sw,
};

// Detect locale (Volume 0.4 §2.2)
function detectLocale(): 'en' | 'sw' {
    if (typeof window === 'undefined') return 'en';
    const stored = localStorage.getItem('afyanova:locale');
    if (stored === 'sw' || stored === 'en') return stored;

    const browser = navigator.language?.toLowerCase() ?? 'en';
    if (browser.startsWith('sw')) return 'sw';
    return 'en';
}

export const i18n = createI18n({
    legacy: false,
    locale: detectLocale(),
    fallbackLocale: 'en',
    messages,
});

export function setLocale(locale: 'en' | 'sw') {
    i18n.global.locale.value = locale;
    localStorage.setItem('afyanova:locale', locale);
    document.documentElement.setAttribute('lang', locale);
}