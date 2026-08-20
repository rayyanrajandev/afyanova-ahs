import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import ChargeBasketPanel from "../components/ChargeBasketPanel.vue";
import type { CashierCharge } from "../composables/useCashierQueue";

/**
 * Reported from a live counter: a fully paid consultation appeared in the
 * basket labelled "Not priced".
 *
 * `isPayable` is false for two unrelated reasons — unpriced, or already settled
 * — and this column rendered both the same way. That was harmless only while
 * settled charges never reached the basket; opening a patient from the "Paid
 * today" tab made it visible.
 */
function charge(overrides: Partial<CashierCharge> = {}): CashierCharge {
  return {
    id: "chg-1",
    chargeNumber: "CHG-2026-000001",
    patientId: "pat-1",
    appointmentId: null,
    sourceKind: "consultation",
    description: "General outpatient consultation",
    unit: "visit",
    quantity: 1,
    currencyCode: "TZS",
    unitPrice: "15000.00",
    grossAmount: "15000.00",
    discountAmount: "0.00",
    discountReason: null,
    taxAmount: "0.00",
    netAmount: "15000.00",
    amountPaid: "0.00",
    amountDue: "15000.00",
    payerClass: "self_pay",
    status: "pending_payment",
    pricingStatus: "priced",
    isPayable: true,
    authorizationBasis: null,
    authorizedAt: null,
    createdAt: "2026-08-19T19:11:47+00:00",
    ...overrides,
  };
}

const stubs = {
  Button: { template: "<button><slot /></button>" },
  Checkbox: { template: "<input type='checkbox' />" },
  Receipt: true,
  Plus: true,
  Undo2: true,
  TriangleAlert: true,
  Wallet: true,
};

function render(charges: CashierCharge[]) {
  return mount(ChargeBasketPanel, {
    props: {
      patient: { patientId: "pat-1", patientName: "Juma Bakari" } as never,
      charges,
      selectedChargeIds: [],
      currencyCode: "TZS",
      unpricedCount: 0,
      isLoading: false,
      canTakePayment: true,
      canAddCharge: true,
    },
    global: { stubs },
  });
}

describe("ChargeBasketPanel charge states", () => {
  it("does not call a settled charge unpriced", () => {
    // The reported bug, in one assertion.
    const panel = render([
      charge({ status: "authorized", isPayable: false, amountPaid: "15000.00", amountDue: "0.00" }),
    ]);

    expect(panel.text()).not.toContain("Not priced");
  });

  it("shows what was paid for a settled charge", () => {
    const panel = render([
      charge({ status: "authorized", isPayable: false, amountPaid: "15000.00", amountDue: "0.00" }),
    ]);

    expect(panel.text()).toContain("15,000");
    expect(panel.text()).toContain("Paid");
  });

  it("still calls a genuinely unpriced charge unpriced", () => {
    // The case the original label was written for, which must survive.
    const panel = render([
      charge({ pricingStatus: null, isPayable: false, amountDue: "0.00" }),
    ]);

    expect(panel.text()).toContain("Not priced");
  });

  it("shows the amount owed on a payable charge", () => {
    const panel = render([charge()]);

    expect(panel.text()).toContain("15,000");
    expect(panel.text()).not.toContain("Not priced");
  });
});
