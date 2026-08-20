import { describe, expect, it } from "vitest";
import {
  buildCashDrawerKickCommand,
  buildEscPosReceipt,
  isWebHardwareSupported,
} from "../cashierHardwareDriver";
import type { CashierReceipt } from "../composables/useCashierPayment";

describe("cashierHardwareDriver", () => {
  const mockReceipt: CashierReceipt = {
    id: "rec-001",
    receiptNumber: "RCT-2026-0001",
    paymentId: "pay-001",
    patientId: "pat-001",
    currencyCode: "TZS",
    total: "25000.00",
    issuedAt: "2026-08-19T10:00:00Z",
    snapshot: {
      lines: [
        {
          chargeId: "chg-1",
          chargeNumber: "CHG-001",
          description: "Consultation OPD",
          quantity: 1,
          unitPrice: "15000.00",
          amount: "15000.00",
        },
        {
          chargeId: "chg-2",
          chargeNumber: "CHG-002",
          description: "Full Blood Count",
          quantity: 1,
          unitPrice: "10000.00",
          amount: "10000.00",
        },
      ],
      total: "25000.00",
      tendered: "30000.00",
      change: "5000.00",
      currencyCode: "TZS",
      paymentNumber: "PAY-2026-0001",
      issuedAt: "2026-08-19T10:00:00Z",
    },
    fiscalStatus: "not_required",
    fiscalReference: null,
    reprintCount: 0,
  };

  it("generates valid ESC/POS byte sequence containing header, lines, totals, and cut command", () => {
    const bytes = buildEscPosReceipt(mockReceipt, {
      patientName: "Asha Juma",
      patientNumber: "MRN-12345",
      isReprint: false,
    });

    expect(bytes).toBeInstanceOf(Uint8Array);
    expect(bytes.length).toBeGreaterThan(50);

    // Initial ESC @ sequence
    expect(bytes[0]).toBe(0x1b);
    expect(bytes[1]).toBe(0x40);

    // End cut command: GS V 66 0
    const len = bytes.length;
    expect(bytes[len - 4]).toBe(0x1d);
    expect(bytes[len - 3]).toBe(0x56);
    expect(bytes[len - 2]).toBe(66);
    expect(bytes[len - 1]).toBe(0);

    // Text content presence in binary
    const textDecoder = new TextDecoder("utf-8");
    const rawText = textDecoder.decode(bytes);

    expect(rawText).toContain("PAYMENT RECEIPT");
    expect(rawText).toContain("RCT-2026-0001");
    expect(rawText).toContain("Consultation OPD");
    expect(rawText).toContain("Full Blood Count");
    expect(rawText).toContain("Asha Juma");
    expect(rawText).toContain("MRN-12345");
  });

  it("adds REPRINT banner when isReprint option is set", () => {
    const bytes = buildEscPosReceipt(mockReceipt, { isReprint: true });
    const textDecoder = new TextDecoder("utf-8");
    const rawText = textDecoder.decode(bytes);

    expect(rawText).toContain("*** REPRINT ***");
  });

  it("generates valid cash drawer kick pulse bytes", () => {
    const kick = buildCashDrawerKickCommand();
    expect(kick).toEqual(new Uint8Array([0x1b, 0x70, 0x00, 0x19, 0xfa]));
  });

  it("detects Web Hardware API availability cleanly", () => {
    expect(typeof isWebHardwareSupported()).toBe("boolean");
  });
});
