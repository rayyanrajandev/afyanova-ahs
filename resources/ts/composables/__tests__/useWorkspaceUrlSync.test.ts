import { describe, expect, it, vi, beforeEach } from "vitest";
import { mount } from "@vue/test-utils";
import { defineComponent, ref } from "vue";
import { useWorkspaceUrlSync } from "../useWorkspaceUrlSync";

describe("useWorkspaceUrlSync", () => {
  beforeEach(() => {
    window.history.replaceState({}, "", "/reception");
  });

  it("updates URL query parameter when activeTab changes", async () => {
    const TestComp = defineComponent({
      setup() {
        const activeTab = ref("patients");
        const { updateUrlParam } = useWorkspaceUrlSync({ activeTab });
        return { activeTab, updateUrlParam };
      },
      template: "<div>{{ activeTab }}</div>",
    });

    const wrapper = mount(TestComp);
    wrapper.vm.activeTab = "queue";
    await wrapper.vm.$nextTick();

    expect(window.location.search).toContain("tab=queue");
  });

  it("updates URL query parameter when selectedPatientId changes", async () => {
    const TestComp = defineComponent({
      setup() {
        const selectedPatientId = ref<string | null>(null);
        useWorkspaceUrlSync({ selectedPatientId });
        return { selectedPatientId };
      },
      template: "<div>{{ selectedPatientId }}</div>",
    });

    const wrapper = mount(TestComp);
    wrapper.vm.selectedPatientId = "pat-123";
    await wrapper.vm.$nextTick();

    expect(window.location.search).toContain("patient=pat-123");
  });

  it("updates URL query parameter when activeChartTab or selectedEncounterId changes", async () => {
    const TestComp = defineComponent({
      setup() {
        const activeChartTab = ref("consultation");
        const selectedEncounterId = ref<string | null>(null);
        useWorkspaceUrlSync({ activeChartTab, selectedEncounterId });
        return { activeChartTab, selectedEncounterId };
      },
      template: "<div>{{ activeChartTab }} - {{ selectedEncounterId }}</div>",
    });

    const wrapper = mount(TestComp);
    wrapper.vm.activeChartTab = "orders";
    wrapper.vm.selectedEncounterId = "enc-456";
    await wrapper.vm.$nextTick();

    expect(window.location.search).toContain("chartTab=orders");
    expect(window.location.search).toContain("encounter=enc-456");
  });

  it("auto-hydrates tab, chartTab, patient, and encounter from URL on mount", async () => {
    window.history.replaceState({}, "", "/clinician?patient=pat-999&encounter=enc-888&tab=patients&chartTab=orders");

    const onHydratePatient = vi.fn();
    const onHydrateTab = vi.fn();
    const onHydrateChartTab = vi.fn();

    const TestComp = defineComponent({
      setup() {
        useWorkspaceUrlSync({
          onHydratePatient,
          onHydrateTab,
          onHydrateChartTab,
        });
        return {};
      },
      template: "<div></div>",
    });

    mount(TestComp);

    expect(onHydrateTab).toHaveBeenCalledWith("patients");
    expect(onHydrateChartTab).toHaveBeenCalledWith("orders");
    expect(onHydratePatient).toHaveBeenCalledWith("pat-999", "enc-888");
  });
});
