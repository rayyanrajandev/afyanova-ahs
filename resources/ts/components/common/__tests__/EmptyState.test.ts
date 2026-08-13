/**
 * EmptyState — component tests (Volume 3.4)
 * ==========================================
 * - Renders title, description, action
 * - Emits action event
 * - role="status" for screen readers
 */

import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import EmptyState from '../EmptyState.vue';

describe('EmptyState', () => {
    it('renders the title', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No patients found' },
        });
        expect(wrapper.text()).toContain('No patients found');
    });

    it('renders the description when provided', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No patients', description: 'Try adjusting your search.' },
        });
        expect(wrapper.text()).toContain('Try adjusting your search.');
    });

    it('renders the action button when actionLabel is provided', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No patients', actionLabel: 'Register Patient' },
        });
        const button = wrapper.find('button');
        expect(button.exists()).toBe(true);
        expect(button.text()).toContain('Register Patient');
    });

    it('does not render an action button when actionLabel is absent', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No patients' },
        });
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('emits action when the button is clicked', async () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No patients', actionLabel: 'Register' },
        });
        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('action')).toHaveLength(1);
    });

    it('has role="status" for screen readers', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No patients' },
        });
        expect(wrapper.attributes('role')).toBe('status');
    });

    it('renders an SVG illustration (not an emoji)', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No patients', illustration: 'search' },
        });
        expect(wrapper.find('svg').exists()).toBe(true);
    });
});