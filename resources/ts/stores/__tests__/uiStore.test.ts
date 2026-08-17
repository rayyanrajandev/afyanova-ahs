/**
 * uiStore — density auto-suggest on touch devices (Volume 0.3 §5,
 * Volume 2.3 §15, Volume 3.8 Phase 7).
 * =======================================================================
 * `spacious` should be the real starting density on a genuine first visit
 * from a coarse-pointer (touch) device, but must never override an
 * explicit prior choice already in `localStorage` — this is exactly the
 * distinction that's easy to get backwards (e.g. checking a *default*
 * value instead of key *presence*), so it's covered directly rather than
 * trusted from a read-through.
 */

import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const DENSITY_KEY = 'afyanova:density';

function mockPointer(coarse: boolean) {
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
        matches: query === '(pointer: coarse)' ? coarse : false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })) as unknown as typeof window.matchMedia;
}

describe('uiStore — density auto-suggest', () => {
    afterEach(() => {
        localStorage.clear();
        document.documentElement.removeAttribute('data-density');
        vi.resetModules();
        vi.restoreAllMocks();
    });

    beforeEach(() => {
        setActivePinia(createPinia());
        localStorage.clear();
    });

    it('auto-suggests spacious on a first-ever visit from a coarse-pointer device', async () => {
        mockPointer(true);
        const { useUiStore } = await import('../uiStore');
        setActivePinia(createPinia());
        const store = useUiStore();
        expect(store.density).toBe('spacious');
        expect(localStorage.getItem(DENSITY_KEY)).toBe('spacious');
    });

    it('defaults to comfortable on a first-ever visit from a fine-pointer (mouse) device', async () => {
        mockPointer(false);
        const { useUiStore } = await import('../uiStore');
        setActivePinia(createPinia());
        const store = useUiStore();
        expect(store.density).toBe('comfortable');
    });

    it('never overrides an explicit prior choice, even on a touch device', async () => {
        localStorage.setItem(DENSITY_KEY, 'compact');
        mockPointer(true);
        const { useUiStore } = await import('../uiStore');
        setActivePinia(createPinia());
        const store = useUiStore();
        expect(store.density).toBe('compact');
    });

    it('setDensity still persists a manual choice normally', async () => {
        mockPointer(false);
        const { useUiStore } = await import('../uiStore');
        setActivePinia(createPinia());
        const store = useUiStore();
        store.setDensity('spacious');
        expect(store.density).toBe('spacious');
        expect(localStorage.getItem(DENSITY_KEY)).toBe('spacious');
    });
});
