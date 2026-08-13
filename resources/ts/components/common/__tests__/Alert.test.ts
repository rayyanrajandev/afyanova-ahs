/**
 * Alert — component tests (Volume 3.4)
 * ======================================
 * - Renders title/description per variant
 * - Never color alone: icon + label always present (Volume 0.3 §3)
 * - Emits action / dismiss events
 * - role="alert" for critical, role="status" for others (§11.1)
 */

import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Alert from '../Alert.vue';

describe('Alert', () => {
    it('renders the title', () => {
        const wrapper = mount(Alert, {
            props: { title: 'Allergy warning' },
        });
        expect(wrapper.text()).toContain('Allergy warning');
    });

    it('renders the description when provided', () => {
        const wrapper = mount(Alert, {
            props: { title: 'Warning', description: 'Abnormal result detected.' },
        });
        expect(wrapper.text()).toContain('Abnormal result detected.');
    });

    it('has role="alert" for critical variant (§11.1)', () => {
        const wrapper = mount(Alert, {
            props: { variant: 'critical', title: 'Critical' },
        });
        expect(wrapper.attributes('role')).toBe('alert');
    });

    it('has role="status" for info variant (§11.1)', () => {
        const wrapper = mount(Alert, {
            props: { variant: 'info', title: 'Info' },
        });
        expect(wrapper.attributes('role')).toBe('status');
    });

    it('always renders an icon + label (never color alone — Volume 0.3 §3)', () => {
        const wrapper = mount(Alert, {
            props: { variant: 'success', title: 'Success' },
        });
        expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Success');
    });

    it('emits action when action button is clicked', async () => {
        const wrapper = mount(Alert, {
            props: { title: 'Warning', actionLabel: 'View result' },
        });
        const actionButton = wrapper.findAll('button').find((b) => b.text().includes('View result'));
        expect(actionButton).toBeDefined();
        await actionButton!.trigger('click');
        expect(wrapper.emitted('action')).toHaveLength(1);
    });

    it('emits dismiss when close button is clicked', async () => {
        const wrapper = mount(Alert, {
            props: { title: 'Warning', dismissible: true },
        });
        const closeButton = wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Close');
        expect(closeButton).toBeDefined();
        await closeButton!.trigger('click');
        expect(wrapper.emitted('dismiss')).toHaveLength(1);
    });

    it('does not render close button when not dismissible', () => {
        const wrapper = mount(Alert, {
            props: { title: 'Warning' },
        });
        const closeButton = wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Close');
        expect(closeButton).toBeUndefined();
    });
});