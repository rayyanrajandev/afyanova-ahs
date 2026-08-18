/**
 * The worklist shows a skeleton *or* rows, never both at once.
 *
 * The skeleton and the empty state form a v-if/v-else-if chain, but the rows
 * are a separate v-for hanging outside it — so `v-if="isLoadingOrders"` alone
 * lets a refresh paint placeholders directly on top of the queue. Laboratory
 * shares the template shape and gets away with it because its state is scoped
 * to the composable call and so is always empty while first loading. Pharmacy
 * holds its state at module scope: re-entering the workspace, changing a
 * status filter or typing in the search box all set the loading flag with the
 * previous rows still mounted.
 */

import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { defineComponent, ref } from "vue";
import { createI18n } from "vue-i18n";
import en from "../../../i18n/locales/en/common.json";
import PharmacyQueuePanel from "../components/PharmacyQueuePanel.vue";
import type { PharmacyOrder } from "../composables/usePharmacyOrders";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  fallbackLocale: "en",
  messages: { en },
  missingWarn: false,
  fallbackWarn: false,
});

const ORDER = {
  id: "rx-1",
  patientId: "pat-1",
  patientName: "Amina Juma",
  patientMrn: "MRN-1",
  patientGender: "F",
  patientAge: "32 yrs",
  status: "pending",
  medicationName: "Amoxicillin",
} as unknown as PharmacyOrder;

/** Enough of the composable's surface for the panel to render. */
function stubPharmacy(opts: {
  loading: boolean;
  orders: PharmacyOrder[];
  viewMode: "patient" | "prescription";
}) {
  const orders = ref(opts.orders);

  return {
    orders,
    groupedOrders: ref(
      opts.orders.length
        ? [
            {
              patientId: "pat-1",
              patientName: "Amina Juma",
              patientMrn: "MRN-1",
              patientGender: "F",
              patientAge: "32 yrs",
              orders: opts.orders,
              totalPrescriptions: opts.orders.length,
              pendingCount: opts.orders.length,
              inPreparationCount: 0,
              partiallyDispensedCount: 0,
              dispensedCount: 0,
              verifiedCount: 0,
            },
          ]
        : [],
    ),
    statusCounts: ref({ all: opts.orders.length, total: opts.orders.length }),
    selectedOrderId: ref(""),
    selectedOrder: ref(null),
    selectedStatusFilter: ref("all"),
    searchQuery: ref(""),
    viewMode: ref(opts.viewMode),
    isLoadingOrders: ref(opts.loading),
    selectOrder: () => Promise.resolve(),
  } as never;
}

function render(opts: Parameters<typeof stubPharmacy>[0]) {
  const host = defineComponent({
    components: { PharmacyQueuePanel },
    setup: () => ({ pharmacy: stubPharmacy(opts) }),
    template: `<PharmacyQueuePanel :pharmacy="pharmacy" />`,
  });

  return mount(host, { global: { plugins: [i18n] } });
}

describe.each(["patient", "prescription"] as const)(
  "pharmacy worklist skeleton (%s view)",
  (viewMode) => {
    it("does not paint placeholders over rows that are already loaded", () => {
      const wrapper = render({ loading: true, orders: [ORDER], viewMode });

      expect(wrapper.find(".animate-pulse").exists()).toBe(false);
      expect(wrapper.text()).toContain("Amina Juma");
    });

    it("shows the skeleton while the first load has nothing to show", () => {
      const wrapper = render({ loading: true, orders: [], viewMode });

      expect(wrapper.find(".animate-pulse").exists()).toBe(true);
    });

    it("shows the empty state once a load finishes with no rows", () => {
      const wrapper = render({ loading: false, orders: [], viewMode });

      expect(wrapper.find(".animate-pulse").exists()).toBe(false);
      expect(wrapper.text()).toMatch(/No matching/i);
    });
  },
);
