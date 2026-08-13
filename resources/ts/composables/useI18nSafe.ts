/**
 * useI18nSafe — safe i18n composable (Volume 0.4)
 * ================================================
 * Wraps vue-i18n's useI18n() in try/catch so components render correctly
 * even in environments where the i18n plugin isn't installed (e.g. the
 * Histoire playground's sandbox iframe before setup runs).
 *
 * Falls back to returning the translation key as the text, so the UI
 * remains functional (just untranslated) in that edge case.
 */

import { useI18n } from 'vue-i18n';

interface SafeI18n {
    t: (key: string, options?: Record<string, unknown>) => string;
    locale: string;
}

export function useI18nSafe(): SafeI18n {
    try {
        const { t, locale } = useI18n();
        return {
            t: (key, options) => t(key, options as never),
            locale: (locale.value as string) ?? 'en',
        };
    } catch {
        // i18n plugin not installed — fall back to showing the key
        return {
            t: (key) => key,
            locale: 'en',
        };
    }
}