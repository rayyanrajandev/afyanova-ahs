/**
 * Histoire playground setup — installs vue-i18n globally
 * so components that use useI18n() work in the playground.
 *
 * Histoire's plugin-vue reads `setupVue3` as a NAMED export
 * from this module (see RenderStory.js: `b?.setupVue3`).
 */

import { defineSetupVue3 } from '@histoire/plugin-vue';
import { createI18n } from 'vue-i18n';
import en from './resources/ts/i18n/locales/en/common.json';
import sw from './resources/ts/i18n/locales/sw/common.json';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en, sw },
});

export const setupVue3 = defineSetupVue3(({ app }) => {
    app.use(i18n);
});