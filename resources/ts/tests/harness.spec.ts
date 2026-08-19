/**
 * Harness smoke test — proves the Vitest setup itself works before any
 * workspace test depends on it: the jsdom environment, the `@/` alias that
 * mirrors tsconfig paths, and the global i18n plugin wired up in setup.ts.
 *
 * If this fails, a failing component test tells you nothing about the
 * component.
 */

import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent } from 'vue';
import { cn } from '@/lib/utils';

describe('vitest harness', () => {
    it('runs in a jsdom environment', () => {
        expect(typeof window).toBe('object');
        expect(typeof document.createElement).toBe('function');
    });

    it('resolves the @/ alias to resources/ts', () => {
        expect(cn('p-2', 'p-4')).toBe('p-4');
    });

    it('mounts a component with the global i18n plugin', () => {
        const Probe = defineComponent({
            template: '<span>{{ $t("nav.cashier") }}</span>',
        });

        expect(mount(Probe).text()).toBe('Cashier');
    });
});
