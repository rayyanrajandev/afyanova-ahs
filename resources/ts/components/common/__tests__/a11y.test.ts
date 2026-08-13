/**
 * Accessibility tests (Volume 3.4)
 * =================================
 * Runs axe-core against each composite component and asserts no
 * accessibility violations. Covers the "keyboard accessible" and
 * "ARIA roles" requirements of the codex.
 */

import { describe, it } from 'vitest';
import { runA11y, expectNoViolations } from '../../../tests/a11y';
import Alert from '../Alert.vue';
import DataTable from '../DataTable.vue';
import EmptyState from '../EmptyState.vue';
import ErrorState from '../ErrorState.vue';
import OfflineState from '../OfflineState.vue';
import SplitPane from '../SplitPane.vue';
import Timeline from '../Timeline.vue';

// ---- Test data ----
const tableColumns = [
    { key: 'name', label: 'Name', accessor: (r: { name: string }) => r.name },
    { key: 'age', label: 'Age', accessor: (r: { age: number }) => r.age },
];
const tableRows = [
    { id: '1', name: 'John Mwangi', age: 45 },
    { id: '2', name: 'Sarah Joseph', age: 32 },
];

const timelineEvents = [
    {
        id: '1',
        type: 'encounter' as const,
        title: 'Outpatient visit',
        timestamp: '2026-08-04T14:30:00',
    },
    {
        id: '2',
        type: 'lab' as const,
        title: 'Complete Blood Count',
        timestamp: '2026-08-04T13:15:00',
    },
];

describe('Accessibility — State Components', () => {
    it('EmptyState has no violations', async () => {
        const { violations } = await runA11y(EmptyState, {
            props: { title: 'No patients', description: 'Try adjusting your search.', actionLabel: 'Register' },
        });
        expectNoViolations(violations);
    });

    it('ErrorState has no violations', async () => {
        const { violations } = await runA11y(ErrorState, {
            props: { title: 'Failed to load', critical: true },
        });
        expectNoViolations(violations);
    });

    it('OfflineState has no violations', async () => {
        const { violations } = await runA11y(OfflineState, {
            props: { queuedCount: 3, lastSyncAt: new Date().toISOString(), hasStaleData: true },
        });
        expectNoViolations(violations);
    });
});

describe('Accessibility — Feedback Components', () => {
    it('Alert has no violations', async () => {
        const { violations } = await runA11y(Alert, {
            props: { variant: 'critical', title: 'Critical allergy', description: 'Severe penicillin allergy.' },
        });
        expectNoViolations(violations);
    });

    it('Alert action + dismissible has no violations', async () => {
        const { violations } = await runA11y(Alert, {
            props: { variant: 'warning', title: 'Warning', actionLabel: 'View', dismissible: true },
        });
        expectNoViolations(violations);
    });
});

describe('Accessibility — Data Components', () => {
    it('DataTable has no violations', async () => {
        const { violations } = await runA11y(DataTable, {
            props: {
                columns: tableColumns,
                rows: tableRows,
                rowKey: (r: { id: string }) => r.id,
            },
        });
        expectNoViolations(violations);
    });

    it('DataTable selectable has no violations', async () => {
        const { violations } = await runA11y(DataTable, {
            props: {
                columns: tableColumns,
                rows: tableRows,
                rowKey: (r: { id: string }) => r.id,
                selectable: true,
            },
        });
        expectNoViolations(violations);
    });

    it('Timeline has no violations', async () => {
        const { violations } = await runA11y(Timeline, {
            props: { events: timelineEvents },
        });
        expectNoViolations(violations);
    });
});

describe('Accessibility — Shell Components', () => {
    it('SplitPane has no violations', async () => {
        const { violations } = await runA11y(SplitPane, {
            props: { direction: 'horizontal' },
            slots: {
                start: '<div>Context</div>',
                end: '<div>Main</div>',
            },
        });
        expectNoViolations(violations);
    });
});