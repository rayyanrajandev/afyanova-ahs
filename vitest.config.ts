import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [
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
            // Mirrors tsconfig.json `paths` — the single `@/` → resources/ts alias
            '@': path.resolve(__dirname, './resources/ts'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/ts/tests/setup.ts'],
        include: ['resources/ts/**/*.{test,spec}.ts'],
    },
});