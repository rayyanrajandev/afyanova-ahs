/**
 * Restoring the queue stage must also load that stage's rows.
 *
 * Reception fetches per stage, and the fetch used to hang off setStage(). So
 * persisting the stage selected the right tab and never asked the server for it:
 * "In Consult" came back after a refresh with an empty list under it.
 */

import { createPinia, setActivePinia } from "pinia";
import { nextTick } from "vue";
import { beforeEach, describe, expect, it, vi } from "vitest";

const fetchReceptionQueue = vi.fn().mockResolvedValue([]);

vi.mock("@/stores/queueStore", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/stores/queueStore")>();
  return {
    ...actual,
    useQueueStore: () => ({
      fetchReceptionQueue,
      fetchStageCounts: vi.fn().mockResolvedValue(undefined),
      stageCounts: {},
      receptionQueue: [],
    }),
  };
});

vi.mock("@/composables/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn(), critical: vi.fn() }),
}));

vi.mock("vue-i18n", () => ({
  useI18n: () => ({ t: (k: string) => k, locale: { value: "en" } }),
}));

const { useQueueActions } = await import("@/pages/reception/composables/useQueueActions");

describe("reception queue stage", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    fetchReceptionQueue.mockClear();
  });

  it("loads the default stage on open", () => {
    useQueueActions();

    expect(fetchReceptionQueue).toHaveBeenCalledWith("waiting_triage");
  });

  it("loads the restored stage, not the default — the refresh bug", () => {
    useQueueActions({ initialStage: "in_consultation" });

    expect(fetchReceptionQueue).toHaveBeenCalledTimes(1);
    expect(fetchReceptionQueue).toHaveBeenCalledWith("in_consultation");
    expect(fetchReceptionQueue).not.toHaveBeenCalledWith("waiting_triage");
  });

  it("loads rows for a stage set on the ref directly, not only via setStage", async () => {
    // Persistence and URL restore both assign the ref. Before the fetch followed
    // the stage, those paths changed the tab without ever loading its rows.
    const queue = useQueueActions();
    fetchReceptionQueue.mockClear();

    queue.selectedStage.value = "admitted";
    await nextTick();

    expect(fetchReceptionQueue).toHaveBeenCalledWith("admitted");
  });

  it("loads once per stage change, not twice", async () => {
    const queue = useQueueActions();
    fetchReceptionQueue.mockClear();

    queue.setStage("waiting_provider");
    await nextTick();

    expect(fetchReceptionQueue).toHaveBeenCalledTimes(1);
    expect(fetchReceptionQueue).toHaveBeenCalledWith("waiting_provider");
  });
});
