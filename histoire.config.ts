/**
 * Histoire — component playground config (Volume 3.5)
 * ====================================================
 * The component playground for the Afyanova design system.
 * Every component has a story showing all variants, all four states,
 * all densities, and all themes. No story = not merged.
 */

import { defineConfig } from 'histoire';
import { HstVue } from '@histoire/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [HstVue()],
    storyMatch: ['**/*.story.vue'],
    outDir: 'public/histoire',
    setupFile: 'histoire.setup.ts',
    vite: {
        resolve: {
            alias: {
                // Mirrors vite.config.ts — the single `@/` → resources/ts alias
                '@': fileURLToPath(new URL('./resources/ts', import.meta.url)),
            },
        },
    },
    tree: {
        groups: [
            { id: 'top', title: 'Afyanova AHS' },
            { id: 'state', title: 'State Components' },
            { id: 'feedback', title: 'Feedback Components' },
            { id: 'data', title: 'Data Components' },
            { id: 'shell', title: 'Shell Components' },
        ],
    },
});