/**
 * Afyanova AHS — SSR Entry (Volume 3.6 §3)
 * =========================================
 * Fresh server-side rendering entry. Matches app.ts resolver.
 */

import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import type { DefineComponent } from 'vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { i18n } from './i18n';

createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,
            title: (title) => (title ? `${title} — Afyanova AHS` : 'Afyanova AHS'),
            resolve: (name) =>
                resolvePageComponent(
                    `./pages/${name}.vue`,
                    import.meta.glob<DefineComponent>('./pages/**/*.vue'),
                ),
            // Fresh Pinia + i18n per render — SSR must not share state across requests.
            setup: ({ App, props, plugin }) =>
                createSSRApp({ render: () => h(App, props) })
                    .use(plugin)
                    .use(createPinia())
                    .use(i18n),
        }),
    { cluster: true },
);