import { expect, test, type Page } from "@playwright/test";
import { expectNoCriticalA11yViolations } from "../support/accessibility";

/**
 * Picks a date via DatePicker.vue's Popover + Calendar (Volume 0.2 §7,
 * component-library audit 2026-08-11) — the Date of birth field stopped
 * being a native `<input type="date">` when that component shipped, but
 * this suite kept calling `.fill()` on it, which only ever silently worked
 * because the suite's login credentials were also stale and every test
 * failed before reaching this step (fixed below, 2026-08-13 audit).
 * `<button role="button">` is deliberately NOT used for the header/nav
 * controls below — reka-ui's day cells are `<div role="button">` with an
 * aria-label containing a full date, which also matches a loose 4-digit-
 * year filter; scoping to the real `<button>` tag excludes them without
 * needing a more fragile selector.
 */
/**
 * Region/District (SearchableSelect.vue, a reka-ui Combobox) — real options
 * loaded from GET /reception/location-options, not free text (2026-08-12
 * redesign). `.fill()` alone leaves the underlying vee-validate field unset
 * (District then never leaves its "Choose a region first" disabled state,
 * since Region's model value never actually changed) — has to type to
 * filter, then click the matching option, same as a real user.
 */
async function pickComboboxOption(page: Page, label: RegExp, query: string) {
  await page.getByLabel(label).click();
  await page.getByLabel(label).fill(query);
  await page.getByRole("option", { name: query, exact: true }).click();
}

async function pickDate(page: Page, label: RegExp, iso: string) {
  const [year, monthNum, day] = iso.split("-").map(Number);
  await page.getByLabel(label).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toBeVisible();

  // Day view -> month view (heading reads e.g. "August 2026").
  await dialog.locator("button", { hasText: /^[A-Za-z]+ \d{4}$/ }).click();
  // Month view -> year view (heading reads just the year, e.g. "2026").
  await dialog.locator("button", { hasText: /^\d{4}$/ }).click();

  // Page back a dozen years at a time until the target year's own button
  // is on screen (Calendar.vue's year grid is a 12-year page).
  const yearButton = dialog.locator("button", { hasText: new RegExp(`^${year}$`) });
  for (let guard = 0; guard < 15 && (await yearButton.count()) === 0; guard += 1) {
    await dialog.getByRole("button", { name: "Previous years" }).click();
  }
  await yearButton.click();

  const monthAbbrev = new Date(Date.UTC(2000, monthNum - 1, 1)).toLocaleDateString("en-US", {
    month: "short",
  });
  await dialog.locator("button", { hasText: new RegExp(`^${monthAbbrev}$`) }).click();

  // Day cells are `<div role="button">`, not real buttons — CalendarCellTrigger's
  // aria-label is "Weekday, Month D, YYYY" (e.g. "Friday, June 15, 1990").
  const dayLabel = new Date(Date.UTC(year, monthNum - 1, day)).toLocaleDateString("en-US", {
    month: "long",
    day: "numeric",
    year: "numeric",
  });
  await page.locator(`[aria-label*="${dayLabel}"]`).click();
}

test.describe("reception registration (Phase 2, Volume 3.7 T2.x)", () => {
  test.use({
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:8000",
  });

  test.beforeEach(async ({ page }) => {
    await page.goto("/login");
    await page.getByLabel(/Email/i).fill("admin@local.test");
    // Was "test-password" (2026-08-13 audit) — never the real seeded value
    // (TEST_USERS.md), so every test in this file failed at login, before
    // ever reaching the assertions below.
    await page.getByLabel(/Password/i).fill("DevPass!2026");
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
    await pickDate(page, /Date of birth/i, "1990-06-15");
    await page.getByLabel(/Phone/i).fill("+255785123456");
    // Sex is a reka-ui Select combobox — open via label then pick the option.
    await page.getByLabel(/Sex/i).click();
    await page.getByRole("option", { name: "Female", exact: true }).click();
    await page.getByLabel(/Address/i).fill("Nyerere Road");
    await pickComboboxOption(page, /Region/i, "Mwanza");
    await pickComboboxOption(page, /District/i, "Nyamagana");
    await page.getByLabel(/Country Code/i).fill("TZ");
    // Exact match (2026-08-13 audit) — the unanchored /save/i previously
    // here also matches "Save & Add Another", a strict-mode violation
    // (two elements) that just never got exercised while login failed first.
    await page.getByRole("button", { name: "Save Patient", exact: true }).click();

    // The POST succeeds (dev DB) → toast "Patient registered — MRN …".
    await expect(page.getByText(/Patient registered — MRN/i)).toBeVisible({
      timeout: 15_000,
    });

    // Recent-items list appears in the context pane (T2.8).
    await expect(page.getByText(/recent/i).first()).toBeVisible({
      timeout: 10_000,
    });
  });

  test("page-level accessibility — empty state and patient profile (X3, Volume 3.4 §5.2)", async ({
    page,
  }) => {
    // Reuses the same axe-core helper `tests/e2e/auth/login.spec.ts` already
    // established (2026-08-13, Volume 3.7 test-pyramid audit) — Reception
    // had zero a11y e2e coverage before this. Checks both pane states, not
    // just one: the empty landing state (SplitPane's two panes, one of them
    // genuinely empty) and a real patient profile (the denser, more
    // interactive-element-heavy state — header actions, tabs, badges, forms)
    // are different enough DOM shapes that a clean sweep of one doesn't
    // guarantee the other, which is the actual point of "screen-reader
    // resolution of panes" (§5.2) — both panes, in both states.
    await page.goto("/reception");
    await page.waitForLoadState("networkidle");
    await expect(
      page.getByRole("button", { name: /register/i }).first(),
    ).toBeVisible({ timeout: 15_000 });
    await expectNoCriticalA11yViolations(page);

    await page.locator("tbody tr").first().click();
    await expect(page.getByRole("button", { name: "More" })).toBeVisible({
      timeout: 10_000,
    });
    await expectNoCriticalA11yViolations(page);
  });

  test("offer Print Label in the profile after registration (T2.7)", async ({
    page,
  }) => {
    await page.goto("/reception");
    await page.waitForLoadState("networkidle");

    // Open an existing patient so the profile renders with the Print Label
    // action. Print Label lives inside the header's "More" menu.
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
      // empty-state Register button. Close is a standalone icon-only
      // header button (2026-08-13 cross-workspace consistency pass — it
      // used to live inside the "More" menu, which this comment/selector
      // previously had to open first; that step is gone, not just this
      // comment update, since Close no longer needs it).
      const closeButton = page.getByRole("button", { name: "Close" });
      if ((await closeButton.count()) > 0) {
        await closeButton.click();
        await new Promise((r) => setTimeout(r, 100));
      }
      const registerButton = page
        .getByRole("button", { name: /register/i })
        .first();
      await expect(registerButton).toBeVisible({ timeout: 15_000 });
      await registerButton.click();
      await page.getByLabel(/First name/i).fill("Dup");
      await page.getByLabel(/Last name/i).fill("Test");
      await pickDate(page, /Date of birth/i, "1985-03-10");
      await page.getByLabel(/Phone/i).fill("+255765111222");
      await page.getByLabel(/National ID/i).fill(nationalId);
      await page.getByLabel(/Sex/i).click();
      await page.getByRole("option", { name: "Male", exact: true }).click();
      await page.getByLabel(/Address/i).fill("Kijitonyama");
      await pickComboboxOption(page, /Region/i, "Dar es Salaam");
      await pickComboboxOption(page, /District/i, "Kinondoni");
      await page.getByLabel(/Country Code/i).fill("TZ");
      // Exact match — see the same fix's note in the test above.
      await page.getByRole("button", { name: "Save Patient", exact: true }).click();
    };

    // First registration succeeds.
    await register();
    await expect(page.getByText(/Patient registered — MRN/i)).toBeVisible({
      timeout: 15_000,
    });

    // Second registration with the identical National ID hits the hard-duplicate
    // 409 path → the duplicate Dialog shows the existing match. Title/CTA
    // updated (2026-08-13 audit) to match the real, already-shipped copy
    // (Volume 3.7 §16 #7, 2026-08-11): "Possible duplicate patient" /
    // "Proceed anyway" were the *pre-fix* strings — that decision replaced
    // "Proceed anyway" (which silently re-submitted into the same 409
    // forever, per #7's own writeup) with "Open existing patient", and the
    // dialog is titled "Patient already registered", not the softer
    // "Possible duplicate" — this is a hard block, not a maybe.
    await register();
    await expect(page.getByText(/Patient already registered/i)).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.getByText(/Dup Test/i).first()).toBeVisible();
    await expect(
      page.getByRole("button", { name: /Open existing patient/i }),
    ).toBeVisible();
  });
});
