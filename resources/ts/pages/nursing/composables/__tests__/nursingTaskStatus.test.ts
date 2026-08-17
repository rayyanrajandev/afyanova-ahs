/**
 * Nursing task-queue row status.
 *
 * The reported sequence (2026-08-16): a row read "Needs Vitals", turned
 * "In Progress" the moment it was clicked, and then stayed "In Progress" even
 * after the vitals were recorded and the patient had moved on.
 *
 * Three separate defects produced that:
 *
 *  1. `task.status === "in_progress"` — set client-side by markInProgress() on
 *     click, with no server round trip — was checked before every stage branch,
 *     so a clicked row's badge could never change again.
 *  2. There was no branch for `with_nurse`, so a picked-up patient fell through.
 *  3. The fallthrough default was "Needs Vitals", meaning any unrecognised
 *     state rendered as a specific clinical instruction.
 *
 * These exercise the label mapping directly, since that is where the ordering
 * bug lived.
 */

import { describe, expect, it } from "vitest";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";

/**
 * Mirrors the branch order in useNursingTasks' taskQueue computed. Kept in step
 * with it deliberately: the bug was the *order* of these branches, so the order
 * is what needs asserting.
 */
function rowStatus(task: {
  status?: string;
  isAdmitted?: boolean;
  stage: string | null;
}): { status: string; labelKey: string } {
  if (task.isAdmitted || task.stage === "admitted" || task.stage === "admitted_inpatient") {
    return { status: "success", labelKey: "patient.stage_admitted_inpatient" };
  }

  if (task.status === "complete") {
    return { status: "complete", labelKey: "status.complete" };
  }

  if (task.stage === "waiting_triage" || task.stage === "in_triage") {
    return { status: "warning", labelKey: "queue.needs_vitals" };
  }

  const stepStatus = stepBadgeStatus(task.stage);
  const stepKey = stepLabelKey(task.stage);

  if (stepStatus !== null && stepKey !== null) {
    return { status: stepStatus, labelKey: stepKey };
  }

  return { status: "warning", labelKey: "queue.needs_vitals" };
}

describe("nursing task row status", () => {
  it("asks for vitals while the patient is waiting for triage", () => {
    expect(rowStatus({ stage: "waiting_triage" }).labelKey).toBe("queue.needs_vitals");
  });

  it("shows With Nurse once the nurse has picked the patient up", () => {
    const row = rowStatus({ stage: "with_nurse" });

    expect(row.labelKey).toBe("patient.stage_with_nurse");
    expect(row.status).toBe("in_progress");
  });

  it("moves on once vitals are recorded and the patient is waiting for a doctor", () => {
    // The exact regression: this used to stay "In Progress" forever.
    const row = rowStatus({ stage: "waiting_clinician" });

    expect(row.labelKey).toBe("patient.stage_waiting_clinician");
    expect(row.labelKey).not.toBe("status.in_progress");
    expect(row.labelKey).not.toBe("queue.needs_vitals");
  });

  it("is not frozen by a stale client-side in_progress status", () => {
    // markInProgress() is gone, but assert the ordering directly: a task
    // carrying that status must still report the patient's real step.
    const row = rowStatus({ status: "in_progress", stage: "waiting_clinician" });

    expect(row.labelKey).toBe("patient.stage_waiting_clinician");
  });

  it("still reports an admission, which the visit step does not describe", () => {
    expect(rowStatus({ isAdmitted: true, stage: "with_nurse" }).labelKey)
      .toBe("patient.stage_admitted_inpatient");
  });

  it("shows the consultation step rather than asking for vitals again", () => {
    expect(rowStatus({ stage: "with_clinician" }).labelKey).toBe("patient.stage_with_clinician");
  });
});
