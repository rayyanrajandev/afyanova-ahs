/**
 * "Send for Diagnostics" visibility.
 *
 * The button is offered only while diagnostic work is still owed back to the
 * clinician. It used to compare `status !== "complete"` while the API spells a
 * finished order `completed`, so no lab or imaging order ever stopped counting:
 * the button stayed on screen for the rest of the consultation, offering to send
 * a patient out for results that were already back on the chart.
 */

import { describe, expect, it } from "vitest";
import {
  diagnosticOrderStage,
  isDiagnosticOrderOutstanding,
  type ClinicalOrderStatus,
  type PlacedClinicalOrder,
} from "../composables/useClinicianOrders";

function order(overrides: Partial<PlacedClinicalOrder> = {}): PlacedClinicalOrder {
  return {
    id: "ord-1",
    type: "lab",
    name: "Complete Blood Count",
    priority: "routine",
    status: "ordered",
    verifiedAt: null,
    createdAt: new Date().toISOString(),
    ...overrides,
  };
}

describe("isDiagnosticOrderOutstanding", () => {
  it("stops counting a released order — the bug this exists for", () => {
    // The API value is `completed`, not `complete`.
    const released = { status: "completed" as ClinicalOrderStatus, verifiedAt: "2026-08-17T09:00:00Z" };
    expect(isDiagnosticOrderOutstanding(order(released))).toBe(false);
    expect(isDiagnosticOrderOutstanding(order({ ...released, type: "imaging" }))).toBe(false);
  });

  it("keeps counting a report that is written but not released", () => {
    // `completed` with no verifiedAt is a draft: the Results tab shows the
    // clinician nothing, so the work is still owed to them.
    expect(
      isDiagnosticOrderOutstanding(order({ status: "completed", verifiedAt: null })),
    ).toBe(true);
    expect(
      isDiagnosticOrderOutstanding(order({ type: "imaging", status: "completed", verifiedAt: null })),
    ).toBe(true);
  });

  it("stops counting a cancelled order", () => {
    expect(isDiagnosticOrderOutstanding(order({ status: "cancelled" }))).toBe(false);
  });

  it.each<ClinicalOrderStatus>(["ordered", "collected", "in_progress"])(
    "still counts a laboratory order at %s",
    (status) => {
      expect(isDiagnosticOrderOutstanding(order({ status }))).toBe(true);
    },
  );

  it.each<ClinicalOrderStatus>(["ordered", "scheduled", "in_progress"])(
    "still counts a radiology order at %s",
    (status) => {
      expect(isDiagnosticOrderOutstanding(order({ type: "imaging", status }))).toBe(true);
    },
  );

  it("never counts a prescription, at any status", () => {
    // A patient collecting medication is leaving, not coming back for the
    // doctor to read a result.
    const statuses: ClinicalOrderStatus[] = [
      "pending",
      "in_preparation",
      "partially_dispensed",
      "dispensed",
      "cancelled",
    ];

    for (const status of statuses) {
      expect(isDiagnosticOrderOutstanding(order({ type: "medication", status }))).toBe(false);
    }
  });

  it("tolerates the legacy `complete` spelling that `as any` hydration can admit", () => {
    expect(
      isDiagnosticOrderOutstanding(
        order({ status: "complete" as ClinicalOrderStatus, verifiedAt: "2026-08-17T09:00:00Z" }),
      ),
    ).toBe(false);
  });
});

describe("the button's own condition", () => {
  const hasOutstanding = (orders: PlacedClinicalOrder[]) =>
    orders.some(isDiagnosticOrderOutstanding);

  const releasedAt = "2026-08-17T09:00:00Z";

  it("hides once every diagnostic result is actually on the chart", () => {
    expect(
      hasOutstanding([
        order({ id: "a", status: "completed", verifiedAt: releasedAt }),
        order({ id: "b", type: "imaging", status: "completed", verifiedAt: releasedAt }),
        order({ id: "c", type: "medication", status: "pending" }),
      ]),
    ).toBe(false);
  });

  it("stays while any one diagnostic order is still open", () => {
    expect(
      hasOutstanding([
        order({ id: "a", status: "completed", verifiedAt: releasedAt }),
        order({ id: "b", type: "imaging", status: "in_progress" }),
      ]),
    ).toBe(true);
  });

  it("stays while a result is typed but not released", () => {
    expect(
      hasOutstanding([
        order({ id: "a", status: "completed", verifiedAt: releasedAt }),
        order({ id: "b", type: "imaging", status: "completed", verifiedAt: null }),
      ]),
    ).toBe(true);
  });

  it("hides for a consultation with prescriptions only", () => {
    expect(hasOutstanding([order({ id: "a", type: "medication", status: "pending" })])).toBe(false);
  });

  it("hides when nothing has been ordered at all", () => {
    expect(hasOutstanding([])).toBe(false);
  });
});

describe("diagnosticOrderStage", () => {
  it("separates a written report from a released one", () => {
    expect(diagnosticOrderStage(order({ status: "completed", verifiedAt: null }))).toBe(
      "awaiting_release",
    );
    expect(
      diagnosticOrderStage(order({ status: "completed", verifiedAt: "2026-08-17T09:00:00Z" })),
    ).toBe("resulted");
  });

  it("maps the bench statuses a clinician is still waiting through", () => {
    expect(diagnosticOrderStage(order({ status: "ordered" }))).toBe("awaiting_collection");
    expect(diagnosticOrderStage(order({ status: "collected" }))).toBe("in_progress");
    expect(diagnosticOrderStage(order({ type: "imaging", status: "scheduled" }))).toBe("in_progress");
    expect(diagnosticOrderStage(order({ status: "in_progress" }))).toBe("in_progress");
  });

  it("treats cancellation as terminal regardless of any release marker", () => {
    expect(
      diagnosticOrderStage(order({ status: "cancelled", verifiedAt: "2026-08-17T09:00:00Z" })),
    ).toBe("cancelled");
  });
});
