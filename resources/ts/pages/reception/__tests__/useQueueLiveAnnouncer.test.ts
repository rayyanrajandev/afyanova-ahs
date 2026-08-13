/**
 * useQueueLiveAnnouncer — `aria-live` new-arrival announcer (Volume 2.1
 * §10.4, Volume 3.7 T5.7).
 * =======================================================================
 * Mounted through a throwaway host component (not called bare) because
 * useI18n() needs a real component setup context — same reasoning
 * Index.test.ts's own `makeWrapper()` already established for anything in
 * this workspace that reads translations.
 */

import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { describe, expect, it } from "vitest";
import { defineComponent, nextTick } from "vue";
import { createI18n } from "vue-i18n";
import { useQueueStore, type QueueTask } from "@/stores/queueStore";
import en from "../../../i18n/locales/en/common.json";
import { useQueueLiveAnnouncer } from "../composables/useQueueLiveAnnouncer";

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

function mountAnnouncer() {
  setActivePinia(createPinia());
  const i18n = createI18n({ legacy: false, locale: "en", messages: { en } });
  let exposed!: ReturnType<typeof useQueueLiveAnnouncer>;
  const Host = defineComponent({
    setup() {
      exposed = useQueueLiveAnnouncer();
      return {};
    },
    template: "<div />",
  });
  const wrapper = mount(Host, { global: { plugins: [i18n] } });
  return { wrapper, announcer: exposed, queueStore: useQueueStore() };
}

describe("useQueueLiveAnnouncer", () => {
  it("does not announce the initial population of the queue on mount", async () => {
    const { announcer, queueStore } = mountAnnouncer();
    queueStore.tasks = [task({ id: "apt-1" }), task({ id: "apt-2" })];
    await nextTick();
    expect(announcer.announcement.value).toBe("");
  });

  it("announces a single new arrival by name after the initial load", async () => {
    const { announcer, queueStore } = mountAnnouncer();
    queueStore.tasks = [task({ id: "apt-1" })];
    await nextTick();

    queueStore.tasks = [task({ id: "apt-1" }), task({ id: "apt-2", patientName: "Livesync Roundthree" })];
    await nextTick();

    expect(announcer.announcement.value).toBe("New patient in queue: Livesync Roundthree");
  });

  it("announces a count when more than one new arrival lands in the same update", async () => {
    const { announcer, queueStore } = mountAnnouncer();
    queueStore.tasks = [task({ id: "apt-1" })];
    await nextTick();

    // apt-1 was already seen at the initial load — apt-2/apt-3 are the
    // only genuinely new arrivals in this update.
    queueStore.tasks = [
      task({ id: "apt-1" }),
      task({ id: "apt-2" }),
      task({ id: "apt-3" }),
    ];
    await nextTick();

    expect(announcer.announcement.value).toBe("2 new patients in queue");
  });

  it("does not announce when the queue shrinks (a cancel/completion, not an arrival)", async () => {
    const { announcer, queueStore } = mountAnnouncer();
    queueStore.tasks = [task({ id: "apt-1" }), task({ id: "apt-2" })];
    await nextTick();

    queueStore.tasks = [task({ id: "apt-1" })];
    await nextTick();

    expect(announcer.announcement.value).toBe("");
  });

  it("does not re-announce an item that was already seen in a previous update", async () => {
    const { announcer, queueStore } = mountAnnouncer();
    queueStore.tasks = [task({ id: "apt-1" })];
    await nextTick();

    queueStore.tasks = [task({ id: "apt-1" }), task({ id: "apt-2" })];
    await nextTick();
    expect(announcer.announcement.value).not.toBe("");
    announcer.announcement.value = ""; // simulate the region having been read/cleared

    // Same two ids again, nothing new — reorder or an unrelated refetch.
    queueStore.tasks = [task({ id: "apt-2" }), task({ id: "apt-1" })];
    await nextTick();

    expect(announcer.announcement.value).toBe("");
  });
});
