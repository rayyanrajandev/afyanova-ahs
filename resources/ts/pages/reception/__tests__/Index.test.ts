/**
 * Reception page — patient list + search (Phase 1, Volume 3.7 / Volume 2.1 §4.1, §7.2)
 * ======================================================================================
 * - Patient list renders through DataTable (Volume 1.2 §6), not a plain `<ul>`.
 * - Search is debounced 200ms (Volume 2.1 §7.2 / Volume 1.3 §6.3).
 * - Click on a row opens the patient in the main context (Volume 1.2 §6.2).
 * - DataTable rows reflect the store's searchResults (FHIR → list rows).
 */

import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import en from "../../../i18n/locales/en/common.json";
import Index from "../Index.vue";

// vue-sonner's <Toaster> is mounted once, globally, in app.ts — a
// sibling of the Inertia App, not part of Index.vue's own tree — so it's
// never present when Index.vue is mounted standalone here. Toast *content*
// can't be asserted via document.body in this harness; mocking the
// `toast()` call itself is how the soft-duplicate-warning test below
// verifies useToast.warning() actually fired.
const { sonnerToastMock } = vi.hoisted(() => ({ sonnerToastMock: vi.fn() }));
vi.mock("vue-sonner", async (importOriginal) => {
  const actual = await importOriginal<typeof import("vue-sonner")>();
  return { ...actual, toast: sonnerToastMock };
});

// ---- Store doubles (pinia refs unwrap to plain values in templates/computed) ----
const patient = {
  id: "p1",
  resourceType: "Patient" as const,
  name: [{ family: "Mwangi", given: ["John"] }],
  identifier: [{ system: "http://afyanova.health/mrn", value: "MRN-1001" }],
  gender: "male" as const,
  telecom: [],
  address: [],
  birthDate: "1980-01-01",
  meta: { extension: { age: 46, allergies: [] } },
};

const {
  fetchPatients,
  fetchPatient,
  setCurrentPatient,
  clearCurrentPatient,
  fetchReceptionQueue,
  addRecent,
  patientsStoreShared,
} = vi.hoisted(() => ({
  fetchPatients: vi.fn(),
  fetchPatient: vi.fn(),
  setCurrentPatient: vi.fn(),
  clearCurrentPatient: vi.fn(),
  fetchReceptionQueue: vi.fn(),
  addRecent: vi.fn(),
  patientsStoreShared: { patientsMap: new Map() },
}));

let patientsInStore: (typeof patient)[] = [];

vi.mock("@/stores/patientStore", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/stores/patientStore")>();
  return {
    ...actual,
    usePatientStore: () => ({
      searchResults: patientsInStore,
      isLoading: false,
      error: null,
      currentPatient: null,
      patients: patientsStoreShared.patientsMap,
      fetchPatients: async (query?: string) => {
        query ? fetchPatients(query) : fetchPatients();
        return patientsInStore;
      },
      // openExistingDuplicate (usePatientRegistration.ts, §16 #7 follow-up)
      // calls this to open the hard-duplicate's existing record.
      fetchPatient,
      setCurrentPatient,
      clearCurrentPatient,
      cachePatient: vi.fn(),
    }),
  };
});

vi.mock("@/stores/queueStore", () => ({
  useQueueStore: () => ({
    tasks: [],
    fetchReceptionQueue,
  }),
}));

vi.mock("@/stores/syncStore", () => ({
  useSyncStore: () => ({
    isOnline: true,
  }),
}));

vi.mock("@/stores/recentStore", () => ({
  useRecentStore: () => ({
    items: [],
    addRecent,
    removeRecent: vi.fn(),
    reconcile: vi.fn(),
    clearRecent: vi.fn(),
  }),
}));

vi.mock("@/components/shell/AppShell.vue", () => ({
  default: {
    name: "AppShell",
    template: "<main><slot /></main>",
  },
}));

// Real-time sync (§10.4, useReceptionLiveSync): this test mounts Index.vue
// standalone, without the real createInertiaApp plugin AppShell.vue's own
// (mocked-out) usePage() call previously relied on — usePage() with no
// Inertia plugin installed returns a page whose `.props` is undefined, not
// the `{ platform: {...} }` shape a real Inertia response always shares
// (HandleInertiaRequests::share()). Mocked here, not worked around in the
// composable, since production code correctly assumes Inertia's own
// guarantee that `page.props` is always an object.
vi.mock("@inertiajs/vue3", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@inertiajs/vue3")>();
  return {
    ...actual,
    usePage: () => ({
      props: { platform: { scope: { facility: { id: "facility-1" } } } },
    }),
  };
});

// useEcho isn't under test here (no configureEcho() call in this harness,
// matching real app.ts's own one-time bootstrap) — stubbed to a no-op so
// useReceptionLiveSync's subscribe call doesn't try to reach a real Echo
// client that was never configured.
vi.mock("@laravel/echo-vue", () => ({
  useEcho: vi.fn(),
}));

function makeWrapper() {
  const i18n = createI18n({ legacy: false, locale: "en", messages: { en } });
  return mount(Index, { global: { plugins: [createPinia(), i18n] } });
}

describe("Reception patient list (Phase 1)", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    patientsInStore = [patient];
    patientsStoreShared.patientsMap = new Map([[patient.id, patient]]);
    fetchPatients.mockClear();
    setCurrentPatient.mockClear();
    fetchReceptionQueue.mockClear();
    clearCurrentPatient.mockClear();
    addRecent.mockClear();
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("renders the patient list through a DataTable (<table>), not a legacy <ul>", async () => {
    const wrapper = makeWrapper();
    await flushPromises();
    expect(wrapper.find("table").exists()).toBe(true);
    expect(wrapper.find("ul").exists()).toBe(false);
    expect(wrapper.text()).toContain("Mwangi");
    expect(wrapper.text()).toContain("MRN-1001");
    wrapper.unmount();
  });

  it("debounces the search input by 200ms (Volume 1.3 §6.3)", async () => {
    const wrapper = makeWrapper();
    await flushPromises();
    fetchPatients.mockClear(); // the mount fetch
    const input = wrapper.find('input[type="search"]');
    await input.setValue("ali");

    expect(fetchPatients).not.toBeCalled();
    await vi.advanceTimersByTimeAsync(199);
    expect(fetchPatients).not.toBeCalled();
    await vi.advanceTimersByTimeAsync(1);

    expect(fetchPatients).toHaveBeenCalledWith("ali");
    wrapper.unmount();
  });

  it("fires a single request across rapid successive keystrokes", async () => {
    const wrapper = makeWrapper();
    await flushPromises();
    fetchPatients.mockClear(); // the mount fetch
    const input = wrapper.find('input[type="search"]');
    await input.setValue("j");
    await input.setValue("jo");
    await input.setValue("joh");
    await vi.advanceTimersByTimeAsync(200);

    expect(fetchPatients).toHaveBeenCalledTimes(1);
    expect(fetchPatients).toHaveBeenCalledWith("joh");
    wrapper.unmount();
  });

  it("fires an unfiltered fetch when the query is cleared", async () => {
    const wrapper = makeWrapper();
    await flushPromises();
    fetchPatients.mockClear(); // the mount fetch
    const input = wrapper.find('input[type="search"]');
    await input.setValue("ali");
    await vi.advanceTimersByTimeAsync(200);
    await input.setValue("");
    await vi.advanceTimersByTimeAsync(200);

    expect(fetchPatients).toHaveBeenLastCalledWith();
    wrapper.unmount();
  });

  it("opens the patient profile when a row is clicked (Volume 1.2 §6.2)", async () => {
    const wrapper = makeWrapper();
    await flushPromises();

    const cells = wrapper.findAll("tbody td");
    const cell = cells.find((c) => c.text().includes("Mwangi"));
    const row = cell?.element.closest("tr") as HTMLTableRowElement | null;
    expect(row).not.toBeNull();

    await wrapper.find("tbody tr").trigger("click");
    expect(setCurrentPatient).toHaveBeenCalledWith(patient.id);
    wrapper.unmount();
  });

  it("shows the empty state with a register action when no patients match", async () => {
    patientsInStore = [];
    const wrapper = makeWrapper();
    await flushPromises();
    expect(wrapper.text()).toContain("No data");
    wrapper.unmount();
  });
});

describe("Reception registration (Phase 2)", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    patientsInStore = [patient];
    patientsStoreShared.patientsMap = new Map([[patient.id, patient]]);
    fetchPatients.mockClear();
    setCurrentPatient.mockClear();
    fetchReceptionQueue.mockClear();
    clearCurrentPatient.mockClear();
    addRecent.mockClear();
    localStorage.clear();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("opens the registration form and restores a saved draft into initial values", async () => {
    localStorage.setItem(
      "afyanova:reception:draft",
      JSON.stringify({
        values: { firstName: "Asha", district: "Nyamagana" },
        savedAt: new Date().toISOString(),
      }),
    );
    patientsInStore = []; // empty state exposes the Register Patient button
    const wrapper = makeWrapper();
    await flushPromises();
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Register Patient")
      ?.trigger("click");
    await new Promise((r) => setTimeout(r, 20));
    await flushPromises();

    const firstNameInput = wrapper.find('form input[type="text"]');
    expect(firstNameInput.exists()).toBe(true);
    wrapper.unmount();
  });

  it("shows the success toast with the generated MRN and clears the draft on save", async () => {
    let resolveFetch: (v: Response) => void = () => {};
    globalThis.fetch = vi.fn().mockReturnValue(
      new Promise<Response>((resolve) => {
        resolveFetch = resolve;
      }),
    ) as unknown as typeof fetch;
    // Seed a fully valid draft so the form validates and the submit handler fires.
    const validValues = {
      firstName: "Asha",
      lastName: "Nguvumali",
      dateOfBirth: "1990-06-15",
      gender: "female",
      phone: "+255785123456",
      email: "",
      addressLine: "Nyerere Road",
      region: "Mwanza",
      district: "Nyamagana",
      countryCode: "TZ",
    };
    localStorage.setItem(
      "afyanova:reception:draft",
      JSON.stringify({
        values: validValues,
        savedAt: new Date().toISOString(),
      }),
    );
    patientsInStore = []; // empty state exposes the Register Patient button
    const wrapper = makeWrapper();
    await flushPromises();
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Register Patient")
      ?.trigger("click");
    await new Promise((r) => setTimeout(r, 20));

    const form = wrapper.find("form");

    // Submit and resolve the register POST with a 201 + MRN
    resolveFetch({
      ok: true,
      status: 201,
      json: () =>
        Promise.resolve({
          data: {
            id: "p-new",
            patientNumber: "00000027",
            firstName: "Asha",
            lastName: "Nguvumali",
            gender: "female",
            dateOfBirth: "1990-06-15",
          },
        }),
    } as unknown as Response);
    await form.trigger("submit");
    await new Promise((r) => setTimeout(r, 20));
    await flushPromises();

    expect(localStorage.getItem("afyanova:reception:draft")).toBeNull();
    expect(addRecent).toHaveBeenCalled();
    wrapper.unmount();
  });

  it("shows the duplicate Dialog with matches on a 409 and opens the existing patient instead of re-submitting", async () => {
    globalThis.fetch = vi.fn(() =>
      Promise.resolve({
        ok: false,
        status: 409,
        json: () =>
          Promise.resolve({
            message: "duplicate",
            duplicates: [
              {
                id: "p1",
                firstName: "Asha",
                lastName: "Nguvumali",
                patientNumber: "00000001",
                dateOfBirth: "1990-06-15",
                phone: "+255785123456",
              },
            ],
          }),
      } as unknown as Response),
    ) as unknown as typeof fetch;
    fetchPatient.mockResolvedValue({
      id: "p1",
      resourceType: "Patient",
      name: [{ family: "Nguvumali", given: ["Asha"] }],
      identifier: [{ system: "http://afyanova.health/mrn", value: "00000001" }],
      gender: "female",
      telecom: [],
      address: [],
      birthDate: "1990-06-15",
      meta: { extension: { age: 36, allergies: [] } },
    });
    const validValues = {
      firstName: "Asha",
      lastName: "Nguvumali",
      dateOfBirth: "1990-06-15",
      gender: "female",
      phone: "+255785123456",
      email: "",
      addressLine: "Nyerere Road",
      region: "Mwanza",
      district: "Nyamagana",
      countryCode: "TZ",
    };
    localStorage.setItem(
      "afyanova:reception:draft",
      JSON.stringify({
        values: validValues,
        savedAt: new Date().toISOString(),
      }),
    );
    patientsInStore = []; // empty state exposes the Register Patient button
    const wrapper = makeWrapper();
    await flushPromises();
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Register Patient")
      ?.trigger("click");
    await new Promise((r) => setTimeout(r, 20));

    const form = wrapper.find("form");
    await form.trigger("submit");
    await new Promise((r) => setTimeout(r, 20));
    await flushPromises();

    // reka-ui teleports DialogContent to document.body.
    const bodyText = document.body.textContent ?? "";
    expect(bodyText).toContain("Patient already registered");
    expect(bodyText).toContain("Nguvumali");
    expect(bodyText).toContain("00000001");
    // The dead-end "Proceed anyway" must not exist any more (bug fix,
    // 2026-08-11 — see usePatientRegistration.ts's file header).
    expect(bodyText).not.toContain("Proceed anyway");

    // "Open existing patient" fetches and opens the matched record instead
    // of re-submitting — no second fetch call is ever made.
    const buttons = Array.from(document.body.querySelectorAll("button"));
    const openExisting = buttons.find(
      (b) => b.textContent?.trim() === "Open existing patient",
    );
    expect(openExisting).toBeTruthy();
    (openExisting as HTMLButtonElement).click();
    await new Promise((r) => setTimeout(r, 20));
    await flushPromises();

    expect(fetchPatient).toHaveBeenCalledWith("p1");
    expect(setCurrentPatient).toHaveBeenCalledWith("p1");
    expect(fetch).toHaveBeenCalledTimes(1); // only the original 409 — no resubmit
    expect(localStorage.getItem("afyanova:reception:draft")).toBeNull();
    expect(addRecent).toHaveBeenCalled();
    wrapper.unmount();
  });

  it("surfaces a soft-duplicate warning toast on an otherwise-successful registration (bug fix 2026-08-11 — this was silently dropped before)", async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () =>
        Promise.resolve({
          data: {
            id: "p-new",
            patientNumber: "00000029",
            firstName: "Asha",
            lastName: "Nguvumali",
            gender: "female",
            dateOfBirth: "1990-06-15",
          },
          warnings: [
            {
              id: "p2",
              firstName: "Asha",
              lastName: "Nguvumal",
              dateOfBirth: "1990-06-16",
              duplicateConfidenceLabel: "possible",
            },
          ],
        }),
    } as unknown as Response);
    const validValues = {
      firstName: "Asha",
      lastName: "Nguvumali",
      dateOfBirth: "1990-06-15",
      gender: "female",
      phone: "+255785123456",
      email: "",
      addressLine: "Nyerere Road",
      region: "Mwanza",
      district: "Nyamagana",
      countryCode: "TZ",
    };
    localStorage.setItem(
      "afyanova:reception:draft",
      JSON.stringify({ values: validValues, savedAt: new Date().toISOString() }),
    );
    patientsInStore = [];
    const wrapper = makeWrapper();
    await flushPromises();
    await wrapper
      .findAll("button")
      .find((b) => b.text() === "Register Patient")
      ?.trigger("click");
    await new Promise((r) => setTimeout(r, 20));

    await wrapper.find("form").trigger("submit");
    await new Promise((r) => setTimeout(r, 20));
    await flushPromises();

    expect(sonnerToastMock).toHaveBeenCalledWith(
      expect.stringContaining("Possible duplicate: Asha Nguvumal"),
      expect.anything(),
    );
    wrapper.unmount();
  });
});
