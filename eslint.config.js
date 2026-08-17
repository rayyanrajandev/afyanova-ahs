import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier';
import importPlugin from 'eslint-plugin-import';
import vue from 'eslint-plugin-vue';
import afyanova from './tools/eslint-plugin-afyanova/index.js';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        // `resources/js/components/ui/*` was removed from here: no such directory
        // exists in this project (everything lives under resources/ts/ — see
        // Volume 3.6 Finding F5). It was a leftover starter-kit path that, left
        // in place, would have silently exempted the component library — the
        // one place afyanova/no-hardcoded-values most needs to run.
        ignores: ['vendor/**', 'node_modules/**', 'public/**', 'bootstrap/**', 'storage/**', '.agents/**', 'tests/**', 'tailwind.config.js', 'vite.config.ts', 'vitest.config.ts'],
    },
    {
        plugins: {
            import: importPlugin,
            afyanova,
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './tsconfig.json',
                },
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            // Catches template tags with no resolvable import/registration (e.g. <Link> used
            // without `import { Link } from '@inertiajs/vue3'`) -- Vue silently renders these
            // as inert native elements at runtime with only a console warning, and vue-tsc does
            // not treat unresolved template components as a type error, so this was previously
            // an invisible bug class. Not part of any eslint-plugin-vue preset; opt-in only.
            'vue/no-undef-components': 'error',
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: ['builtin', 'external', 'internal', 'parent', 'sibling', 'index'],
                    alphabetize: {
                        order: 'asc',
                        caseInsensitive: true,
                    },
                },
            ],
            // Volume 0.2 §13 / §12.3 — components consume tokens, never one-off
            // colors or sizes. tokens.ts itself is exempt (rule-level check).
            'afyanova/no-hardcoded-values': 'error',
            // Volume 0.4 §3.3 — no user-facing English text bypassing t().
            'afyanova/no-hardcoded-strings': 'error',
        },
    },
    prettier,
);
