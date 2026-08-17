/**
 * DataTable — loading-skeleton header alignment (Volume 3.8, 2026-08-13)
 * ==========================================================================
 * Bug fix: the loading-skeleton `<th>` always rendered `text-left`,
 * ignoring a column's own `align` — the real (loaded) header correctly
 * applies `text-right`/`text-center`. A right-aligned column (e.g.
 * Nursing's Age) would visibly jump alignment the moment loading finished.
 * Reported directly by the user ("small error... where [Age] moved away
 * from table header Age").
 */

import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DataTable, { type DataTableColumn } from '../DataTable.vue';

interface Row {
    id: string;
    age: number;
}

const columns: DataTableColumn<Row>[] = [
    { key: 'age', label: 'Age', accessor: (r) => r.age, align: 'right' },
];

describe('DataTable — loading state header alignment', () => {
    it('right-aligns a right-aligned column header while loading, matching the loaded state', () => {
        const wrapper = mount(DataTable, {
            props: {
                columns,
                rows: [] as Row[],
                rowKey: (r: Row) => r.id,
                loading: true,
            },
        });
        const header = wrapper.find('th');
        expect(header.classes()).toContain('text-right');
    });

    it('right-aligns the same column header once loaded', () => {
        const wrapper = mount(DataTable, {
            props: {
                columns,
                rows: [{ id: 'p1', age: 41 }],
                rowKey: (r: Row) => r.id,
                loading: false,
            },
        });
        const header = wrapper.find('th');
        expect(header.classes()).toContain('text-right');
    });

    it('right-aligns the header label via justify-content on the inner flex row', () => {
        // The header <th> wraps its label in a block-level flex container
        // (<div class="flex items-center gap-1">), which ignores `text-align`
        // entirely — only `justify-content` on that flex row actually moves the
        // label. Without it a right-aligned column (Nursing's Age) showed a
        // left-aligned header over right-aligned values.
        const wrapper = mount(DataTable, {
            props: {
                columns,
                rows: [{ id: 'p1', age: 41 }],
                rowKey: (r: Row) => r.id,
                loading: false,
            },
        });
        const headerRow = wrapper.find('thead th .flex');
        expect(headerRow.exists()).toBe(true);
        expect(headerRow.classes()).toContain('justify-end');
    });
});
