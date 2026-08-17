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
    globalInjection: true,
    allowComposition: true,
    sync: true,
});

export function setLocale(locale: 'en' | 'sw') {
    if (typeof i18n.global.locale === 'object' && 'value' in i18n.global.locale) {
        (i18n.global.locale as any).value = locale;
    } else {
        (i18n.global as any).locale = locale;
    }
    localStorage.setItem('afyanova:locale', locale);
    if (typeof document !== 'undefined') {
        document.documentElement.setAttribute('lang', locale);
    }
}