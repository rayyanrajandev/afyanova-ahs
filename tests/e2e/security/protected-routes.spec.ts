import { expect, test } from "@playwright/test";

const protectedRoutes = ["/dashboard", "/settings/profile"];

test.describe("protected routes", () => {
  for (const route of protectedRoutes) {
    test(`redirects unauthenticated users from ${route} to login`, async ({
      page,
    }) => {
      await page.goto(route);

      await expect(page).toHaveURL(/\/login(?:\?|$)/);
      await expect(page.getByLabel(/Email/i)).toBeVisible();
    });
  }
});
