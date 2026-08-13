import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/ts/app.ts'],
            ssr: 'resources/ts/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            // Mirrors tsconfig.json `paths` — the single `@/` → resources/ts
            // alias convention (Volume 3.6 §3, components.json aliases).
            // Required so Vite resolves `@/components/...`, `@/lib/...`
            // imports at build/dev time, otherwise it 404s on them.
            '@': fileURLToPath(new URL('./resources/ts', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/vendor/**', '**/storage/**', '**/node_modules/**'],
        },
    },
});