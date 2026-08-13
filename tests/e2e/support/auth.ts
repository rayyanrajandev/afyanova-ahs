import { expect, test, type Page } from '@playwright/test';

const configuredEmail = process.env.E2E_USER_EMAIL ?? '';
const configuredPassword = process.env.E2E_USER_PASSWORD ?? '';

export const hasConfiguredE2EUser =
    configuredEmail.trim() !== '' && configuredPassword.trim() !== '';

export function skipWithoutConfiguredE2EUser() {
    test.skip(
        !hasConfiguredE2EUser,
        'Set E2E_USER_EMAIL and E2E_USER_PASSWORD to run authenticated workflow tests.',
    );
}

export async function loginAsConfiguredE2EUser(page: Page) {
    if (!hasConfiguredE2EUser) {
        throw new Error('E2E credentials are not configured.');
    }

    await page.goto('/login');
    await page.locator('input[name="email"]').fill(configuredEmail);
    await page.locator('input[name="password"]').fill(configuredPassword);
    await page.getByTestId('login-button').click();
    await page.waitForLoadState('networkidle');

    await expect(page).not.toHaveURL(/\/login(?:\?|$)/);
}
