/**
 * Every selection on a workspace survives a refresh.
 *
 * The clinician screen carries three: the context tab (Queue / Patients), the
 * chart tab (Consultation / Orders / …) and the queue's stage filter (Waiting
 * Doctor / In Consult). The stage filter had no persistence at all, so working
 * the "In Consult" list and pressing refresh dropped you back to "Waiting
 * Doctor" while the two tab bars beside it restored correctly.
 */

import { mount } from "@vue/test-utils";
import { defineComponent, h, nextTick, ref, type Ref } from "vue";
import { beforeEach, describe, expect, it } from "vitest";
import { useWorkspaceUrlSync } from "@/composables/useWorkspaceUrlSync";

const QUEUE_STAGES = ["waiting_provider", "in_consultation", "admitted", "completed"];

function mountSync(search: string) {
  window.history.replaceState({}, "", `/clinician${search}`);

  const contextTab = ref("queue");
  const chartTab = ref("consultation");
  const queueStage = ref("waiting_provider");

  const Comp = defineComponent({
    setup() {
      useWorkspaceUrlSync({
        activeTab: contextTab as Ref<string>,
        activeChartTab: chartTab as Ref<string>,
        params: {
          queueStage: {
            ref: queueStage as Ref<string>,
            isValid: (v) => QUEUE_STAGES.includes(v),
          },
        },
      });
      return () => h("div");
    },
  });

  return { wrapper: mount(Comp), contextTab, chartTab, queueStage };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe("restoring a workspace from its url", () => {
  beforeEach(() => window.history.replaceState({}, "", "/clinician"));

  it("restores the queue stage filter — the case that was lost on every refresh", async () => {
    const { queueStage } = mountSync("?queueStage=in_consultation");
    await settle();

    expect(queueStage.value).toBe("in_consultation");
  });

  it("restores all three selections together", async () => {
    const { contextTab, chartTab, queueStage } = mountSync(
      "?tab=patients&chartTab=prescriptions&queueStage=admitted",
    );
    await settle();

    expect(contextTab.value).toBe("patients");
    expect(chartTab.value).toBe("prescriptions");
    expect(queueStage.value).toBe("admitted");
  });

  it("ignores a hand-edited stage the workspace has no branch for", async () => {
    const { queueStage } = mountSync("?queueStage=not_a_real_stage");
    await settle();

    expect(queueStage.value).toBe("waiting_provider");
  });
});

describe("recording the open view in the url", () => {
  beforeEach(() => window.history.replaceState({}, "", "/clinician"));

  it("writes the current selections immediately, not only once something changes", async () => {
    // The watchers fired on change only, so a workspace restored from
    // localStorage sat on a tab the address bar knew nothing about — and
    // copying the link handed someone a different view than the one on screen.
    mountSync("");
    await settle();

    const params = new URLSearchParams(window.location.search);
    expect(params.get("tab")).toBe("queue");
    expect(params.get("chartTab")).toBe("consultation");
    expect(params.get("queueStage")).toBe("waiting_provider");
  });

  it("keeps the url in step as selections change", async () => {
    const { queueStage, chartTab } = mountSync("");
    await settle();

    queueStage.value = "in_consultation";
    chartTab.value = "orders";
    await nextTick();

    const params = new URLSearchParams(window.location.search);
    expect(params.get("queueStage")).toBe("in_consultation");
    expect(params.get("chartTab")).toBe("orders");
  });

  it("does not let those immediate writes clobber the link being restored", async () => {
    // The immediate watchers run before onMounted, so hydration reads a snapshot
    // captured during setup rather than an address bar it has already rewritten.
    const { chartTab, queueStage } = mountSync("?chartTab=results&queueStage=completed");
    await settle();

    expect(chartTab.value).toBe("results");
    expect(queueStage.value).toBe("completed");
  });
});
