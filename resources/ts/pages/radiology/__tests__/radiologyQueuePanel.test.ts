/**
 * The imaging queue lists the studies, like the bench and the dispensary do.
 *
 * This card showed a summary of modalities — "3 Studies: CT XR" — and a tally
 * of per-status counts, with no way to open an individual study from it. The
 * whole card was one button, which is also why it could not contain any: a
 * button inside a button is invalid markup.
 */

import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { defineComponent, ref } from "vue";
import { createI18n } from "vue-i18n";
import en from "../../../i18n/locales/en/common.json";
import RadiologyQueuePanel from "../components/RadiologyQueuePanel.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  fallbackLocale: "en",
  messages: { en },
  missingWarn: false,
  fallbackWarn: false,
});

const STUDIES = [
  {
    id: "rad-1",
    patientId: "pat-1",
    modality: "ct",
    studyDescription: "CT Head Without Contrast",
    status: "ordered",
    priority: "routine",
    orderedAt: "2026-08-18T08:00:00Z",
  },
  {
    id: "rad-2",
    patientId: "pat-1",
    modality: "xr",
    studyDescription: "Chest X-Ray PA and Lateral",
    status: "completed",
    verifiedAt: "2026-08-18T10:00:00Z",
    priority: "routine",
    orderedAt: "2026-08-18T09:00:00Z",
  },
];

function render(selectedId: string | null = null) {
  const group = {
    patientId: "pat-1",
    patientName: "Amina Juma",
    patientMrn: "MRN-1",
    patientGender: "F",
    patientAge: 32,
    orders: STUDIES,
    totalStudies: STUDIES.length,
    orderedCount: 1,
    scheduledCount: 0,
    inProgressCount: 0,
    completedCount: 1,
    cancelledCount: 0,
    modalities: ["ct", "xr"],
    highestPriority: "routine",
    latestOrderedAt: "2026-08-18T09:00:00Z",
  };

  const radiology = {
    orders: ref(STUDIES),
    patientGroups: ref([group]),
    filteredPatientGroups: ref([group]),
    filteredOrders: ref(STUDIES),
    worklistOrders: ref(STUDIES),
    statusCounts: ref({ all: 2, ordered: 1, completed: 1 }),
    selectedOrderId: ref(selectedId),
    selectedPatientId: ref("pat-1"),
    selectedOrder: ref(STUDIES.find((s) => s.id === selectedId) ?? null),
    selectedStatusFilter: ref("all"),
    selectedModalityFilter: ref("all"),
    searchQuery: ref(""),
    viewMode: ref("patient"),
    isLoadingOrders: ref(false),
    selectOrder: () => {},
    selectPatient: () => {},
    fetchOrders: () => Promise.resolve(),
  } as never;

  const host = defineComponent({
    components: { RadiologyQueuePanel },
    setup: () => ({ radiology }),
    template: `<RadiologyQueuePanel :radiology="radiology" />`,
  });

  return mount(host, { global: { plugins: [i18n] } });
}

describe("radiology queue — patient view", () => {
  it("lists each study by name instead of summarising modalities", () => {
    const text = render().text();

    expect(text).toContain("CT Head Without Contrast");
    expect(text).toContain("Chest X-Ray PA and Lateral");
  });

  it("makes every study individually selectable", () => {
    const wrapper = render();

    // The card used to be one button offering only the patient.
    const rows = wrapper
      .findAll("button")
      .filter(
        (b) => b.text().includes("CT Head") || b.text().includes("Chest X-Ray"),
      );

    expect(rows).toHaveLength(2);
  });

  it("keeps the modality visible on the row it belongs to", () => {
    const text = render().text();

    expect(text).toContain("CT");
    expect(text).toContain("XR");
  });

  it("states each study's own status in words", () => {
    const text = render().text();

    // A reported study and a signed-off one are different resting states.
    expect(text).toContain("Ordered");
    expect(text).toContain("Verified");
  });

  it("marks the open study for assistive technology", () => {
    const wrapper = render("rad-2");
    const current = wrapper.findAll("[aria-current='true']");

    expect(current).toHaveLength(1);
    expect(current[0].text()).toContain("Chest X-Ray");
  });
});
