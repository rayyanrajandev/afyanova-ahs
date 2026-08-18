/**
 * The dispensing counter works in a fixed order, and the workspace has to say
 * so before the work is entered rather than after it is submitted.
 *
 * The server was always strict — PharmacyOrderStatus::allowedWorkflowTransitions
 * lets `pending` reach only `in_preparation`, and verification demands a
 * dispense that has actually happened. What the workspace let you do was pick a
 * batch, type a quantity and press "Complete Dispense" on an order nobody had
 * accepted, and discover the rule only from the rejection.
 */

import { describe, expect, it } from "vitest";
import {
  isPharmacyTabReachable,
  pharmacyStageOf,
  type PharmacyOrder,
  type PharmacyStage,
  type PharmacyTabId,
} from "../composables/usePharmacyOrders";

function order(overrides: Partial<PharmacyOrder> = {}): PharmacyOrder {
  return {
    id: "rx-1",
    patientId: "pat-1",
    medicationCode: "AMOX",
    medicationName: "Amoxicillin",
    quantityPrescribed: 21,
    orderedAt: "2026-08-18T09:00:00Z",
    status: "pending",
    ...overrides,
  } as PharmacyOrder;
}

describe("pharmacyStageOf", () => {
  it("maps each status onto the step the counter is actually at", () => {
    expect(pharmacyStageOf(order({ status: "pending" }))).toBe(
      "pending_review",
    );
    expect(pharmacyStageOf(order({ status: "in_preparation" }))).toBe(
      "ready_for_dispense",
    );
    expect(pharmacyStageOf(order({ status: "partially_dispensed" }))).toBe(
      "ready_for_dispense",
    );
    expect(pharmacyStageOf(order({ status: "dispensed" }))).toBe(
      "dispensed_unverified",
    );
    expect(
      pharmacyStageOf(
        order({ status: "dispensed", verifiedAt: "2026-08-18T10:00:00Z" }),
      ),
    ).toBe("verified_completed");
    expect(pharmacyStageOf(order({ status: "cancelled" }))).toBe("cancelled");
  });
});

describe("isPharmacyTabReachable", () => {
  it("refuses to open the fill counter on an unaccepted prescription", () => {
    // The exact skip the question was about: dispense before preparation.
    expect(isPharmacyTabReachable("dispense", "pending_review")).toBe(false);
  });

  it("refuses sign-off before anything has been handed over", () => {
    expect(isPharmacyTabReachable("verify", "pending_review")).toBe(false);
    expect(isPharmacyTabReachable("verify", "ready_for_dispense")).toBe(false);
  });

  it("opens each step once the order reaches it", () => {
    expect(isPharmacyTabReachable("dispense", "ready_for_dispense")).toBe(true);
    expect(isPharmacyTabReachable("verify", "dispensed_unverified")).toBe(true);
  });

  it("keeps earlier steps readable after the order has moved on", () => {
    expect(isPharmacyTabReachable("dispense", "dispensed_unverified")).toBe(
      true,
    );
    expect(isPharmacyTabReachable("dispense", "verified_completed")).toBe(true);
    expect(isPharmacyTabReachable("verify", "verified_completed")).toBe(true);
  });

  it("closes the working steps on a cancelled order", () => {
    expect(isPharmacyTabReachable("dispense", "cancelled")).toBe(false);
    expect(isPharmacyTabReachable("verify", "cancelled")).toBe(false);
  });

  it("never locks the review or the journey", () => {
    const stages: PharmacyStage[] = [
      "pending_review",
      "ready_for_dispense",
      "dispensed_unverified",
      "verified_completed",
      "cancelled",
    ];

    for (const stage of stages) {
      expect(isPharmacyTabReachable("review", stage)).toBe(true);
      expect(isPharmacyTabReachable("audit", stage)).toBe(true);
    }
  });

  it("matches the backend transition matrix step for step", () => {
    // PharmacyOrderStatus::allowedWorkflowTransitions, expressed as stages.
    const reachableFrom: Record<PharmacyStage, PharmacyTabId[]> = {
      pending_review: ["review", "audit"],
      ready_for_dispense: ["review", "audit", "dispense"],
      dispensed_unverified: ["review", "audit", "dispense", "verify"],
      verified_completed: ["review", "audit", "dispense", "verify"],
      cancelled: ["review", "audit"],
    };

    const allTabs: PharmacyTabId[] = ["review", "dispense", "verify", "audit"];

    for (const [stage, expected] of Object.entries(reachableFrom)) {
      for (const tab of allTabs) {
        expect({
          stage,
          tab,
          reachable: isPharmacyTabReachable(tab, stage as PharmacyStage),
        }).toEqual({ stage, tab, reachable: expected.includes(tab) });
      }
    }
  });
});
