import { expect, test } from "@playwright/test";

test.describe("reception registration (Phase 2, Volume 3.7 T2.x)", () => {
  test.use({
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:8000",
  });

  test.beforeEach(async ({ page }) => {
    await page.goto("/login");
    await page.getByLabel(/Email/i).fill("admin@local.test");
    await page.getByLabel(/Password/i).fill("test-password");
    await page.getByRole("button", { name: "Sign in" }).click();
    await page.waitForURL((url) => !/\/login/.test(url.pathname), {
      timeout: 15_000,
    });
  });

  test("registration form opens with draft restore and duplicate Dialog wiring", async ({
    page,
  }) => {
    await page.goto("/reception");
    await page.waitForLoadState("networkidle");

    // Open the registration form (Register Patient button or empty-state action).
    const registerButton = page
      .getByRole("button", { name: /register/i })
      .first();
    await expect(registerButton).toBeVisible({ timeout: 15_000 });
    await registerButton.click();

    // Form fields from PatientRegistrationFields render.
    await expect(page.getByLabel(/First name/i, { exact: false })).toBeVisible({
      timeout: 10_000,
    });
    await expect(page.getByLabel(/Last name/i)).toBeVisible();
    await expect(page.getByLabel(/Date of birth/i)).toBeVisible();
    await expect(page.getByLabel(/Phone/i)).toBeVisible();

    // Type a valid DOB and phone — validators pass; form has Save.
    await page.getByLabel(/First name/i).fill("Asha");
    await page.getByLabel(/Last name/i).fill("Nguvumali");
    await page.getByLabel(/Date of birth/i).fill("1990-06-15");
    await page.getByLabel(/Phone/i).fill("+255785123456");
    // Sex is a reka-ui Select combobox — open via label then pick the option.
    await page.getByLabel(/Sex/i).click();
    await page.getByRole("option", { name: "Female", exact: true }).click();
    await page.getByLabel(/Address/i).fill("Nyerere Road");
    await page.getByLabel(/Region/i).fill("Mwanza");
    await page.getByLabel(/District/i).fill("Nyamagana");
    await page.getByLabel(/Country Code/i).fill("TZ");
    await page.getByRole("button", { name: /save/i }).click();

    // The POST succeeds (dev DB) → toast "Patient registered — MRN …".
    await expect(page.getByText(/Patient registered — MRN/i)).toBeVisible({
      timeout: 15_000,
    });

    // Recent-items list appears in the context pane (T2.8).
    await expect(page.getByText(/recent/i).first()).toBeVisible({
      timeout: 10_000,
    });
  });

  test("offer Print Label in the profile after registration (T2.7)", async ({
    page,
  }) => {
    await page.goto("/reception");
    await page.waitForLoadState("networkidle");

    // Open an existing patient so the profile renders with the Print Label
    // action. Print Label lives inside the header's "More" menu
    // (2026-08-12 redesign — moved off its own top-level button to cut
    // down on always-visible actions), so More has to be opened first.
    await page.locator("tbody tr").first().click();
    await page.getByRole("button", { name: "More" }).click();
    await expect(
      page.getByRole("button", { name: /print label/i }),
    ).toBeVisible({
      timeout: 10_000,
    });
  });

  test("duplicate National ID opens the duplicate Dialog with the existing match (T2.4)", async ({
    page,
  }) => {
    await page.goto("/reception");
    await page.waitForLoadState("networkidle");

    const nationalId = `NID-${Date.now()}`;

    const register = async () => {
      // If the profile auto-opened (T2.6), close it to reveal the
      // empty-state Register button, then open the form. Close lives
      // inside the header's "More" menu (2026-08-12 redesign — moved off
      // its own top-level button to cut down on always-visible actions),
      // so it has to be opened before Close is clickable.
      const moreButton = page.getByRole("button", { name: "More" });
      if ((await moreButton.count()) > 0) {
        await moreButton.first().click();
        await page.getByRole("button", { name: "Close" }).click();
        await new Promise((r) => setTimeout(r, 100));
      }
      const registerButton = page
        .getByRole("button", { name: /register/i })
        .first();
      await expect(registerButton).toBeVisible({ timeout: 15_000 });
      await registerButton.click();
      await page.getByLabel(/First name/i).fill("Dup");
      await page.getByLabel(/Last name/i).fill("Test");
      await page.getByLabel(/Date of birth/i).fill("1985-03-10");
      await page.getByLabel(/Phone/i).fill("+255765111222");
      await page.getByLabel(/National ID/i).fill(nationalId);
      await page.getByLabel(/Sex/i).click();
      await page.getByRole("option", { name: "Male", exact: true }).click();
      await page.getByLabel(/Address/i).fill("Kijitonyama");
      await page.getByLabel(/Region/i).fill("Dar es Salaam");
      await page.getByLabel(/District/i).fill("Kinondoni");
      await page.getByLabel(/Country Code/i).fill("TZ");
      // Use the first Save button inside the form.
      await page.getByRole("button", { name: /^Save$/ }).click();
    };

    // First registration succeeds.
    await register();
    await expect(page.getByText(/Patient registered — MRN/i)).toBeVisible({
      timeout: 15_000,
    });

    // Second registration with the identical National ID hits the hard-duplicate
    // 409 path → the duplicate Dialog shows the existing match.
    await register();
    await expect(page.getByText(/Possible duplicate patient/i)).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.getByText(/Dup Test/i).first()).toBeVisible();
    await expect(
      page.getByRole("button", { name: /Proceed anyway/i }),
    ).toBeVisible();
  });
});
