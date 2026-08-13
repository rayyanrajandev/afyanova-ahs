/**
 * ErrorState — component tests (Volume 3.4)
 * ==========================================
 * - Renders title/description, Retry + Contact IT actions
 * - Emits retry / contactIt events
 * - role="alert" for screen readers
 * - Debug details are in a collapsible <details>, not shown by default
 */

import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ErrorState from '../ErrorState.vue';

describe('ErrorState', () => {
    it('renders the error title', () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Failed to load results' },
        });
        expect(wrapper.text()).toContain('Failed to load results');
    });

    it('renders the description when provided', () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Failed', description: 'The server returned an error.' },
        });
        expect(wrapper.text()).toContain('The server returned an error.');
    });

    it('renders a Retry button by default and emits retry on click', async () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Failed' },
        });
        const retryButton = wrapper.findAll('button').find((b) => b.text().includes('Retry'));
        expect(retryButton).toBeDefined();
        await retryButton!.trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
    });

    it('renders Contact IT only when critical', () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Critical error', critical: true },
        });
        const contactButton = wrapper.findAll('button').find((b) => b.text().includes('Contact IT'));
        expect(contactButton).toBeDefined();
    });

    it('does not render Contact IT when not critical', () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Error' },
        });
        const contactButton = wrapper.findAll('button').find((b) => b.text().includes('Contact IT'));
        expect(contactButton).toBeUndefined();
    });

    it('emits contactIt when Contact IT is clicked', async () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Critical error', critical: true },
        });
        const contactButton = wrapper.findAll('button').find((b) => b.text().includes('Contact IT'));
        await contactButton!.trigger('click');
        expect(wrapper.emitted('contactIt')).toHaveLength(1);
    });

    it('hides the Retry button when showRetry is false', () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Error', showRetry: false },
        });
        const retryButton = wrapper.findAll('button').find((b) => b.text().includes('Retry'));
        expect(retryButton).toBeUndefined();
    });

    it('has role="alert"', () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Error' },
        });
        expect(wrapper.attributes('role')).toBe('alert');
    });

    it('shows debug details only in a collapsible <details>', () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Error', details: 'Stack trace here' },
        });
        const details = wrapper.find('details');
        expect(details.exists()).toBe(true);
        // Details should NOT be visible by default
        expect(details.attributes('open')).toBeUndefined();
        expect(wrapper.find('pre').exists()).toBe(true);
    });

    it('does not render <details> when no details provided', () => {
        const wrapper = mount(ErrorState, {
            props: { title: 'Error' },
        });
        expect(wrapper.find('details').exists()).toBe(false);
    });
});