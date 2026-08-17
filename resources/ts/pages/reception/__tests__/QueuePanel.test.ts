/**
 * Reception queue — row open + load strip (Volume 2.1 §10.2/§10.3, Volume 1.2 §9.3)
 * ==================================================================================
 * Two things neither of which had any coverage before 2026-08-13:
 *
 * 1. `handleQueueOpen` — Index.test.ts mocks `queueStore` with a permanently
 *    empty `tasks` array, so every existing assertion about this workspace
 *    passed while clicking a queue row did nothing at all. These tests use
 *    the REAL queueStore (populating `tasks` directly, the way
 *    useQueueLiveAnnouncer.test.ts already does) precisely so the
 *    queue-task-id vs patient-id distinction is exercised for real — a mock
 *    that returns the same object for either id would hide exactly the bug
 *    this is here to pin down.
 *
 * 2. `QueueLoadStrip` — the board-level urgency signal that replaced the
 *    pulse `hide-priority-chips` removed.
 *
 * Mounted through a throwaway host component because useI18n() needs a real
 * component setup context (same reasoning as useQueueLiveAnnouncer.test.ts).
 */

import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, nextTick } from "vue";
import { createI18n } from "vue-i18n";
import type { QueueItem } from "@/components/common/Queue.vue";
import { usePatientStore } from "@/stores/patientStore";
import { useQueueStore, type QueueTask } from "@/stores/queueStore";
import en from "../../../i18n/locales/en/common.json";
import QueueLoadStrip from "../components/QueueLoadStrip.vue";
import { useQueueActions } from "../composables/useQueueActions";

function task(overrides: Partial<QueueTask> = {}): QueueTask {
  return {
    id: "apt-1",
    description: "Outpatient",
    patientId: "pat-1",
    patientName: "Test Patient",
    dueTime: "09:00",
    waitMinutes: 5,
    priority: "normal",
    status: "pending",
    arrivalMode: "walk_in",
    ...overrides,
  };
}

function backendRow(id = "pat-1") {
  return {
    id,
    patientNumber: "MRN-2001",
    firstName: "Zawadi",
    lastName: "Mkeni",
    gender: "female" as const,
    dateOfBirth: "1991-04-02",
  };
}

function mountQueueActions() {
  setActivePinia(createPinia());
  const i18n = createI18n({ legacy: false, locale: "en", messages: { en } });

  const queueStore = useQueueStore();
  // useQueueActions() fires queueStore.fetchReceptionQueue() as a side
  // effect at setup time (Volume 2.1 §10, "load the queue when the
  // workspace mounts"). Left un-stubbed, that call's mocked-fetch promise
  // resolves a couple of microtask ticks later — right around a test's own
  // `await nextTick()` — and overwrites `queueStore.tasks` back to `[]`,
  // clobbering whatever a test seeded immediately after mounting. Stubbing
  // the store method itself (rather than only the global `fetch`) removes
  // that race entirely: the mount-time call becomes an inert no-op instead
  // of a competing async write to the same state.
  vi.spyOn(queueStore, "fetchReceptionQueue").mockResolvedValue([]);

  let exposed!: ReturnType<typeof useQueueActions>;
  const Host = defineComponent({
    setup() {
      exposed = useQueueActions();
      return {};
    },
    template: "<div />",
  });
  const wrapper = mount(Host, { global: { plugins: [i18n] } });
  return {
    wrapper,
    queueActions: exposed,
    queueStore,
    patientStore: usePatientStore(),
  };
}

function mountLoadStrip(items: QueueItem[]) {
  const i18n = createI18n({ legacy: false, locale: "en", messages: { en } });
  return mount(QueueLoadStrip, {
    props: { items },
    global: { plugins: [i18n] },
  });
}

function item(overrides: Partial<QueueItem> = {}): QueueItem {
  return {
    id: "apt-1",
    name: "Test Patient",
    waitTime: "5 min",
    waitMinutes: 5,
    priority: "normal",
    status: "pending",
    ...overrides,
  } as QueueItem;
}

describe("useQueueActions.handleQueueOpen (Volume 1.2 §9.3)", () => {
  beforeEach(() => {
    // useQueueActions calls fetchReceptionQueue() on setup; stub the network
    // so the real store's mount fetch can't reach out. Individual tests
    // override this when they need a specific patient payload.
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve({ data: [] }),
    } as unknown as Response) as unknown as typeof fetch;
  });

  it("opens the patient behind the row, keyed on patientId and not the queue-task id", async () => {
    const { queueActions, queueStore, patientStore, wrapper } = mountQueueActions();
    // Deliberately different ids: this is the whole bug. Looking the patient
    // cache up by `apt-77` can only ever miss.
    queueStore.tasks = [task({ id: "apt-77", patientId: "pat-9" })];
    patientStore.cachePatient({
      ...backendRow("pat-9"),
      resourceType: "Patient",
      identifier: [{ system: "http://afyanova.health/mrn", value: "MRN-2001" }],
      name: [{ family: "Mkeni", given: ["Zawadi"] }],
      telecom: [],
      address: [],
      birthDate: "1991-04-02",
      meta: { extension: { age: 35, allergies: [] } },
    } as never);
    await nextTick();

    await queueActions.handleQueueOpen(item({ id: "apt-77" }));

    expect(patientStore.currentPatient?.id).toBe("pat-9");
    wrapper.unmount();
  });

  it("hydrates a patient that is only known from the queue and was never cached", async () => {
    const { queueActions, queueStore, patientStore, wrapper } = mountQueueActions();
    queueStore.tasks = [task({ id: "apt-5", patientId: "pat-42" })];
    await nextTick();

    // A queue-only patient: the queue fetch populates queueStore, never the
    // patient cache, so this must go to the network rather than no-op.
    expect(patientStore.patients.get("pat-42")).toBeUndefined();
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve({ data: backendRow("pat-42") }),
    } as unknown as Response) as unknown as typeof fetch;

    await queueActions.handleQueueOpen(item({ id: "apt-5" }));

    expect(fetch).toHaveBeenCalledWith(
      expect.stringContaining("/reception/patients/pat-42"),
      expect.anything(),
    );
    expect(patientStore.currentPatient?.id).toBe("pat-42");
    wrapper.unmount();
  });

  it("does nothing when the row has no matching queue task", async () => {
    const { queueActions, queueStore, patientStore, wrapper } = mountQueueActions();
    queueStore.tasks = [task({ id: "apt-1", patientId: "pat-1" })];
    await nextTick();

    await queueActions.handleQueueOpen(item({ id: "apt-does-not-exist" }));

    expect(patientStore.currentPatient).toBeNull();
    wrapper.unmount();
  });
});

describe("QueueLoadStrip (Volume 2.1 §10.2)", () => {
  it("renders nothing at all when the queue is empty", () => {
    const wrapper = mountLoadStrip([]);
    expect(wrapper.text()).toBe("");
    wrapper.unmount();
  });

  it("reports the longest wait, not the first or last row's wait", () => {
    const wrapper = mountLoadStrip([
      item({ id: "a", waitMinutes: 8 }),
      item({ id: "b", waitMinutes: 41 }),
      item({ id: "c", waitMinutes: 12 }),
    ]);
    expect(wrapper.text()).toContain("41 min");
    wrapper.unmount();
  });

  it("formats a wait of an hour or more as hours and minutes", () => {
    const wrapper = mountLoadStrip([item({ waitMinutes: 95 })]);
    expect(wrapper.text()).toContain("1h 35m");
    wrapper.unmount();
  });

  it("shows no emergency alert when nobody is an emergency arrival", () => {
    const wrapper = mountLoadStrip([item({ priority: "normal" })]);
    expect(wrapper.text()).not.toContain("emergency");
    wrapper.unmount();
  });

  it("shows the emergency count when an emergency arrival is waiting", () => {
    const wrapper = mountLoadStrip([
      item({ id: "a", priority: "critical" }),
      item({ id: "b", priority: "critical" }),
      item({ id: "c", priority: "normal" }),
    ]);
    expect(wrapper.text()).toContain("2 emergency");
    wrapper.unmount();
  });

  it("ignores completed and cancelled rows — they are not still on the floor", () => {
    const wrapper = mountLoadStrip([
      item({ id: "a", waitMinutes: 200, status: "complete", priority: "critical" }),
      item({ id: "b", waitMinutes: 180, status: "cancelled", priority: "critical" }),
      item({ id: "c", waitMinutes: 7, status: "pending" }),
    ]);
    expect(wrapper.text()).toContain("7 min");
    expect(wrapper.text()).not.toContain("emergency");
    wrapper.unmount();
  });
});
