import { expect, test } from "@playwright/test";
import { expectNoCriticalA11yViolations } from "../support/accessibility";

test.describe("login smoke", () => {
  test("renders the login form with baseline accessibility", async ({
    page,
  }) => {
    await page.goto("/login");

    await expect(page.getByRole("heading", { name: /sign in/i })).toBeVisible();
    await expect(page.getByLabel(/Email/i)).toBeVisible();
    await expect(page.getByLabel(/Password/i)).toBeVisible();
    await expect(page.getByRole("button", { name: "Sign in" })).toBeVisible();

    await expectNoCriticalA11yViolations(page);
  });
});
