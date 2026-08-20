import { describe, expect, it } from "vitest";
import { useCashierPayment } from "../composables/useCashierPayment";
import type { CashierCharge } from "../composables/useCashierQueue";

describe("useCashierPayment - Tanzania Payment Methods", () => {
  const mockCharge: CashierCharge = {
    id: "chg-1",
    chargeNumber: "CHG-2026-001",
    patientId: "pat-1",
    appointmentId: null,
    sourceKind: "manual",
    description: "General Consultation",
    unit: null,
    quantity: 1,
    currencyCode: "TZS",
    unitPrice: "20000.00",
    grossAmount: "20000.00",
    discountAmount: "0.00",
    discountReason: null,
    taxAmount: "0.00",
    netAmount: "20000.00",
    amountPaid: "0.00",
    amountDue: "20000.00",
    payerClass: "self_pay",
    status: "outstanding",
    pricingStatus: "priced",
    isPayable: true,
    authorizationBasis: null,
    authorizedAt: null,
    createdAt: "2026-08-19T10:00:00Z",
  };

  it("defaults to cash tender with exact amount and zero change", () => {
    const payment = useCashierPayment();
    payment.beginPayment([mockCharge]);

    expect(payment.paymentMethod.value).toBe("cash");
    expect(payment.dueMinor.value).toBe(2000000);
    expect(payment.tenderedMinor.value).toBe(2000000);
    expect(payment.changeMinor.value).toBe(0);
    expect(payment.canSubmit.value).toBe(true);
  });

  it("calculates change for cash overpayment", () => {
    const payment = useCashierPayment();
    payment.beginPayment([mockCharge]);

    payment.tenderedMinor.value = 2500000; // 25,000 TZS
    expect(payment.changeMinor.value).toBe(500000); // 5,000 TZS change
    expect(payment.isShort.value).toBe(false);
  });

  it("validates mobile money phone number or SMS reference before submit", () => {
    const payment = useCashierPayment();
    payment.beginPayment([mockCharge]);
    payment.paymentMethod.value = "mobile_money";

    expect(payment.canSubmit.value).toBe(false);

    payment.phoneNumber.value = "0712345678";
    expect(payment.canSubmit.value).toBe(true);

    payment.phoneNumber.value = "";
    payment.paymentReference.value = "9K28QXYZ7";
    expect(payment.canSubmit.value).toBe(true);
  });

  it("validates SimBanking reference and GePG control number", () => {
    const payment = useCashierPayment();
    payment.beginPayment([mockCharge]);

    // SimBanking
    payment.paymentMethod.value = "bank_transfer";
    expect(payment.canSubmit.value).toBe(false);
    payment.paymentReference.value = "CRDB-9901";
    expect(payment.canSubmit.value).toBe(true);

    // GePG Control Number
    payment.paymentMethod.value = "gepg";
    payment.paymentReference.value = "";
    expect(payment.canSubmit.value).toBe(false);
    payment.paymentReference.value = "991234567890";
    expect(payment.canSubmit.value).toBe(true);
  });
});
