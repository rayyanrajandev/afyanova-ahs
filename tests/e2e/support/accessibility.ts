import { expect, type Page } from '@playwright/test';
import axe from 'axe-core';

type AxeViolation = {
    id: string;
    impact?: string | null;
    help: string;
    nodes: Array<{ target: string[] }>;
};

type AxeResult = {
    violations: AxeViolation[];
};

export async function expectNoCriticalA11yViolations(page: Page) {
    await page.addScriptTag({ content: axe.source });

    const results = await page.evaluate(async () => {
        const axeRunner = (
            window as unknown as {
                axe: {
                    run: (
                        context: Document,
                        options: Record<string, unknown>,
                    ) => Promise<AxeResult>;
                };
            }
        ).axe;

        return axeRunner.run(document, {
            resultTypes: ['violations'],
            runOnly: {
                type: 'tag',
                values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'],
            },
            rules: {
                // reka-ui (shadcn-vue) checkbox renders a visually-hidden native
                // input (aria-hidden + tabindex="-1") inside the role="checkbox"
                // button for form semantics; axe's nested-interactive rule (check
                // no-focusable-content) flags this intentional pattern as a
                // false positive.
                'nested-interactive': { enabled: false },
            },
        });
    });

    const blockingViolations = results.violations.filter((violation) =>
        ['critical', 'serious'].includes(violation.impact ?? ''),
    );

    expect(blockingViolations).toEqual([]);
}
