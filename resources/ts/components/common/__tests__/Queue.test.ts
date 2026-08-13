/**
 * Queue — `defaultSort="incoming"` (Volume 2.1 §10.2, Volume 3.7 T5.1)
 * =======================================================================
 * Focused coverage for the one behavior added 2026-08-10: a consumer whose
 * backend already applies a meaningful order (reception's arrival-mode
 * tiering) can opt out of Queue.vue's generic wait-derived `priority`
 * default sort without affecting any other consumer (clinician/nursing),
 * since `defaultSort` defaults to the original `'priority'` behavior.
 */

import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Queue, { type QueueItem } from '../Queue.vue';

// Deliberately NOT already priority/wait sorted — a walk-in with the
// longest wait (would be 'critical' under the generic priority scale) sits
// first, ahead of an emergency arrival that's barely waited. This is
// exactly the ordering a `defaultSort="incoming"` consumer needs preserved.
const items: QueueItem[] = [
    { id: 'walk-in-1', name: 'Walk-in Patient', priority: 'critical', waitTime: '65 min', waitMinutes: 65 },
    { id: 'emergency-1', name: 'Emergency Patient', priority: 'normal', waitTime: '5 min', waitMinutes: 5 },
    { id: 'scheduled-1', name: 'Scheduled Patient', priority: 'urgent', waitTime: '35 min', waitMinutes: 35 },
];

describe('Queue — defaultSort', () => {
    it('defaults to priority sort (unchanged behavior) when defaultSort is not passed', () => {
        const wrapper = mount(Queue, { props: { items } });
        const names = wrapper.findAll('li').map((row) => row.text());
        // priority rank: critical(0) < urgent(1) < normal(2) — the
        // wait-derived 'critical' walk-in sorts first under the default.
        expect(names[0]).toContain('Walk-in Patient');
    });

    it('preserves the prop order verbatim when defaultSort="incoming"', () => {
        const wrapper = mount(Queue, { props: { items, defaultSort: 'incoming' } });
        const names = wrapper.findAll('li').map((row) => row.text());
        expect(names[0]).toContain('Walk-in Patient');
        expect(names[1]).toContain('Emergency Patient');
        expect(names[2]).toContain('Scheduled Patient');
    });

    it('does not pin critical-priority items to the top under incoming order', () => {
        // Regression guard: 'incoming' must behave differently from
        // 'manual', which *does* pin critical items to the top (§9.4).
        // If this test starts failing because the critical walk-in jumped
        // to index 0, the two modes have been accidentally merged.
        const wrapper = mount(Queue, { props: { items, defaultSort: 'incoming' } });
        const names = wrapper.findAll('li').map((row) => row.text());
        expect(names[1]).toContain('Emergency Patient');
    });
});

// `groupByCategory` (Volume 3.7 audit, 2026-08-10) — opt-in section headers.
// Off by default, so clinician/nursing (which never pass this prop) are
// unaffected; only reception's Queue tab currently sets it.
const groupedItems: QueueItem[] = [
    { id: 'e1', name: 'Emergency Patient', priority: 'normal', waitTime: '5 min', waitMinutes: 5, category: 'Emergency' },
    { id: 'w1', name: 'Walk-in Patient A', priority: 'normal', waitTime: '10 min', waitMinutes: 10, category: 'Walk-in' },
    { id: 'w2', name: 'Walk-in Patient B', priority: 'normal', waitTime: '20 min', waitMinutes: 20, category: 'Walk-in' },
];

describe('Queue — groupByCategory', () => {
    it('does not render group headers when the prop is not set (default off)', () => {
        const wrapper = mount(Queue, { props: { items: groupedItems, defaultSort: 'incoming' } });
        expect(wrapper.find('li[role="presentation"]').exists()).toBe(false);
    });

    it('renders one header per distinct category, each with its item count', () => {
        const wrapper = mount(Queue, {
            props: { items: groupedItems, defaultSort: 'incoming', groupByCategory: true },
        });
        const headers = wrapper.findAll('li[role="presentation"]').map((h) => h.text());
        expect(headers).toEqual(['Emergency (1)', 'Walk-in (2)']);
    });

    it('lists each category’s items together under its header, not interleaved', () => {
        const wrapper = mount(Queue, {
            props: { items: groupedItems, defaultSort: 'incoming', groupByCategory: true },
        });
        const rows = wrapper.findAll('li').map((r) => r.text());
        // Header, item, header, item, item — never a header reappearing
        // after its group has already started (which would mean the same
        // category split across two places).
        expect(rows[0]).toContain('Emergency (1)');
        expect(rows[1]).toContain('Emergency Patient');
        expect(rows[2]).toContain('Walk-in (2)');
        expect(rows[3]).toContain('Walk-in Patient A');
        expect(rows[4]).toContain('Walk-in Patient B');
    });

    it('does not repeat the category text on individual rows once grouped (the header already says it)', () => {
        // Query the specific category `<span>` (`.min-w-0.truncate` — the
        // exact element the ungrouped render uses for it), not a bare text
        // search: the patient's own name ("Walk-in Patient A") legitimately
        // contains the word "Walk-in" too, which a naive substring check
        // would false-positive on.
        const flat = mount(Queue, {
            props: { items: groupedItems, defaultSort: 'incoming', groupByCategory: false },
        });
        const flatWalkInRow = flat.findAll('li').find((r) => r.text().includes('Walk-in Patient A'));
        expect(flatWalkInRow?.find('span.min-w-0.truncate').exists()).toBe(true);

        const grouped = mount(Queue, {
            props: { items: groupedItems, defaultSort: 'incoming', groupByCategory: true },
        });
        const groupedWalkInRow = grouped.findAll('li').find((r) => r.text().includes('Walk-in Patient A'));
        expect(groupedWalkInRow?.find('span.min-w-0.truncate').exists()).toBe(false);
    });

    it('still supports keyboard Enter-to-open across group boundaries', async () => {
        const wrapper = mount(Queue, {
            props: { items: groupedItems, defaultSort: 'incoming', groupByCategory: true },
        });
        const scrollContainer = wrapper.find('[class*="overflow-auto"]');
        // activeIndex starts at 0 (e1, in the Emergency group) — one
        // ArrowDown moves to flat index 1, which is w1, already in the
        // next group. Headers aren't part of this index space at all.
        await scrollContainer.trigger('keydown', { key: 'ArrowDown' });
        await scrollContainer.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('open')?.[0]?.[0]).toMatchObject({ id: 'w1' });
    });
});
