/**
 * Laboratory bench stage rules.
 *
 * These tests pin the guardrails the workspace was missing: an order could be
 * "verified and released" straight from `ordered`, the result sheet was live
 * before a specimen existed, and a rejected write reported success. Each case
 * below is a mistake that was previously reachable through the UI.
 */

import { describe, expect, it } from "vitest";
import {
  benchStepIndex,
  hasCompleteResults,
  isLabTabReachable,
  LAB_STAGE_SEQUENCE,
  LAB_STAGE_TAB,
  labStageOf,
  missingParameters,
  secondReviewReason,
  type LabTestParameter,
  type LaboratoryOrder,
  type LaboratoryOrderStatus,
} from "../composables/useLaboratoryOrders";

function param(overrides: Partial<LabTestParameter> = {}): LabTestParameter {
  return {
    key: "hb",
    name: "Hemoglobin (Hb)",
    value: null,
    unit: "g/dL",
    referenceRange: "12.0 – 17.5",
    flag: "normal",
    ...overrides,
  };
}

function order(overrides: Partial<LaboratoryOrder> = {}): LaboratoryOrder {
  return {
    id: "lab-1",
    patientId: "pat-1",
    testCode: "LAB-HEM-HB",
    testName: "Hemoglobin (Hb) Test",
    department: "Hematology",
    sampleType: "Whole Blood (EDTA)",
    priority: "routine",
    status: "ordered",
    createdAt: new Date().toISOString(),
    parameters: [param()],
    ...overrides,
  };
}

describe("labStageOf", () => {
  it.each([
    ["ordered", undefined, "awaiting_specimen"],
    ["collected", undefined, "ready_for_analysis"],
    ["in_progress", undefined, "in_analysis"],
    ["completed", undefined, "awaiting_release"],
    ["completed", "2026-08-17T09:00:00Z", "released"],
    ["cancelled", undefined, "rejected"],
  ] as const)("maps status %s (verifiedAt %s) to %s", (status, verifiedAt, expected) => {
    expect(labStageOf({ status: status as LaboratoryOrderStatus, verifiedAt })).toBe(expected);
  });

  it("separates a saved draft from a released report", () => {
    // The old UI treated `completed` as released, so a draft rendered as a
    // "Final Verified Report" the instant results were typed.
    const draft = order({ status: "completed", verifiedAt: null });
    const released = order({ status: "completed", verifiedAt: "2026-08-17T09:00:00Z" });

    expect(labStageOf(draft)).toBe("awaiting_release");
    expect(labStageOf(released)).toBe("released");
  });
});

describe("isLabTabReachable", () => {
  it("keeps result entry shut until analysis has started", () => {
    expect(isLabTabReachable("results", "awaiting_specimen")).toBe(false);
    expect(isLabTabReachable("results", "ready_for_analysis")).toBe(false);
    expect(isLabTabReachable("results", "in_analysis")).toBe(true);
  });

  it("keeps the release screen shut until results are saved", () => {
    expect(isLabTabReachable("verification", "awaiting_specimen")).toBe(false);
    expect(isLabTabReachable("verification", "ready_for_analysis")).toBe(false);
    expect(isLabTabReachable("verification", "in_analysis")).toBe(false);
    expect(isLabTabReachable("verification", "awaiting_release")).toBe(true);
    expect(isLabTabReachable("verification", "released")).toBe(true);
  });

  it("never offers a release screen for a rejected specimen", () => {
    expect(isLabTabReachable("verification", "rejected")).toBe(false);
    expect(isLabTabReachable("results", "rejected")).toBe(true);
  });

  it("always allows the read-only audit and journey tabs", () => {
    for (const stage of [...LAB_STAGE_SEQUENCE, "released", "rejected"] as const) {
      expect(isLabTabReachable("audit", stage)).toBe(true);
      expect(isLabTabReachable("journey", stage)).toBe(true);
    }
  });
});

describe("LAB_STAGE_TAB", () => {
  it("points every stage at exactly one workstation", () => {
    expect(LAB_STAGE_TAB.awaiting_specimen).toBe("accessioning");
    expect(LAB_STAGE_TAB.ready_for_analysis).toBe("accessioning");
    expect(LAB_STAGE_TAB.in_analysis).toBe("results");
    expect(LAB_STAGE_TAB.awaiting_release).toBe("verification");
  });

  it("only ever lands on a tab that stage can actually reach", () => {
    for (const stage of LAB_STAGE_SEQUENCE) {
      expect(isLabTabReachable(LAB_STAGE_TAB[stage], stage)).toBe(true);
    }
  });
});

describe("result completeness", () => {
  it("refuses to call a partially filled panel complete", () => {
    const partial = order({
      parameters: [param({ key: "hb", value: "13.2" }), param({ key: "mcv", value: null })],
    });

    expect(hasCompleteResults(partial)).toBe(false);
    expect(missingParameters(partial).map((p) => p.key)).toEqual(["mcv"]);
  });

  it("treats blank and whitespace-only values as missing", () => {
    // These are what produced "Hemoglobin: — g/dL" on a real patient chart.
    expect(hasCompleteResults(order({ parameters: [param({ value: "" })] }))).toBe(false);
    expect(hasCompleteResults(order({ parameters: [param({ value: "   " })] }))).toBe(false);
    expect(hasCompleteResults(order({ parameters: [param({ value: null })] }))).toBe(false);
  });

  it("accepts a fully filled panel, including a legitimate zero", () => {
    expect(hasCompleteResults(order({ parameters: [param({ value: 0 })] }))).toBe(true);
    expect(hasCompleteResults(order({ parameters: [param({ value: "Negative" })] }))).toBe(true);
  });

  it("never calls an order with no parameters complete", () => {
    expect(hasCompleteResults(order({ parameters: [] }))).toBe(false);
  });
});

describe("secondReviewReason", () => {
  it("demands a second review for any critical value", () => {
    expect(
      secondReviewReason(order({ parameters: [param({ value: "4.1", flag: "critical_low" })] })),
    ).toBe("critical");
    expect(
      secondReviewReason(order({ parameters: [param({ value: "22", flag: "critical_high" })] })),
    ).toBe("critical");
  });

  it("demands a second review for high-stakes disciplines", () => {
    expect(secondReviewReason(order({ testCode: "LAB-SER-HIV-RDT" }))).toBe("high_stakes");
    expect(secondReviewReason(order({ testCode: "LAB-BB-ABO-RH" }))).toBe("high_stakes");
    expect(secondReviewReason(order({ department: "Blood Bank" }))).toBe("high_stakes");
  });

  it("stays quiet on routine normal results, so the prompt keeps its meaning", () => {
    expect(secondReviewReason(order({ parameters: [param({ value: "13.2" })] }))).toBeNull();
    expect(
      secondReviewReason(order({ parameters: [param({ value: "18.4", flag: "abnormal" })] })),
    ).toBeNull();
  });
});

describe("benchStepIndex", () => {
  it("orders the four bench steps", () => {
    expect(benchStepIndex("awaiting_specimen")).toBe(0);
    expect(benchStepIndex("ready_for_analysis")).toBe(1);
    expect(benchStepIndex("in_analysis")).toBe(2);
    expect(benchStepIndex("awaiting_release")).toBe(3);
  });

  it("places the terminal outcomes outside the bench", () => {
    expect(benchStepIndex("released")).toBe(-1);
    expect(benchStepIndex("rejected")).toBe(-1);
  });
});
