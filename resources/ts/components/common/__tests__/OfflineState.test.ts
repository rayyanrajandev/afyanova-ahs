/**
 * OfflineState — component tests (Volume 3.4)
 * ============================================
 * - Shows offline title + last-sync time + queued count
 * - Stale data shown WITH the offline indicator (P8)
 * - Sync Now is disabled while offline
 * - role="status" on the indicator
 */

import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import OfflineState from '../OfflineState.vue';

describe('OfflineState', () => {
    it('renders the offline title', () => {
        const wrapper = mount(OfflineState);
        expect(wrapper.text()).toContain('You are offline');
    });

    it('shows stale data label with last sync time', () => {
        const twoMinutesAgo = new Date(Date.now() - 2 * 60 * 1000).toISOString();
        const wrapper = mount(OfflineState, {
            props: { lastSyncAt: twoMinutesAgo, hasStaleData: true },
        });
        expect(wrapper.text()).toContain('Data from');
    });

    it('shows queued actions count', () => {
        const wrapper = mount(OfflineState, {
            props: { queuedCount: 3 },
        });
        expect(wrapper.text()).toContain('3 queued');
    });

    it('renders Sync Now button disabled while offline (P8 §17.2)', () => {
        const wrapper = mount(OfflineState);
        const syncButton = wrapper.find('button');
        expect(syncButton.exists()).toBe(true);
        expect(syncButton.attributes('disabled')).toBeDefined();
        expect(syncButton.attributes('aria-disabled')).toBe('true');
    });

    it('has role="status" on the indicator', () => {
        const wrapper = mount(OfflineState);
        expect(wrapper.attributes('role')).toBe('status');
    });

    it('does not show stale data label when no lastSyncAt', () => {
        const wrapper = mount(OfflineState);
        expect(wrapper.text()).not.toContain('Data from');
    });
});