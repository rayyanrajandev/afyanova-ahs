/**
 * a11y test utility (Volume 3.4)
 * ==============================
 * Runs axe-core against a mounted Vue component and asserts no
 * accessibility violations. Reusable across all component a11y tests.
 */

import { mount, type VueWrapper } from '@vue/test-utils';
import axe from 'axe-core';
import { expect } from 'vitest';
import type { Component } from 'vue';

export interface A11yOptions {
    props?: Record<string, unknown>;
    slots?: Record<string, unknown>;
    attachTo?: HTMLElement;
}

/**
 * Mount a component and run axe-core against it.
 * Returns the wrapper and any violations found.
 */
export async function runA11y(
    component: Component,
    options: A11yOptions = {},
): Promise<{ wrapper: VueWrapper; violations: axe.Result[] }> {
    const wrapper = mount(component, {
        props: options.props,
        slots: options.slots,
        attachTo: options.attachTo ?? document.body,
    });

    const results = await axe.run(wrapper.element as HTMLElement, {
        rules: {
            // jsdom doesn't fully support color-contrast — skip it
            'color-contrast': { enabled: false },
        },
    });

    return { wrapper, violations: results.violations };
}

/**
 * Assert that a component has no axe-core violations.
 * Provides a readable failure message listing the violations.
 */
export function expectNoViolations(violations: axe.Result[]) {
    const summary = violations
        .map((v) => `- ${v.id}: ${v.help} (${v.nodes.length} nodes)`)
        .join('\n');
    expect(violations, `Accessibility violations found:\n${summary}`).toHaveLength(0);
}