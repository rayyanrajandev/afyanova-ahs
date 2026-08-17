/**
 * The frontend half of PatientFlowStep (2026-08-16 flow audit)
 * ============================================================
 * The backend collapsed five copies of the status -> step mapping onto one
 * enum. This is the matching single place that turns a step into what a queue
 * row shows: a label key and a badge variant.
 *
 * It exists because the reception queue was labelling rows from *which tab the
 * user was looking at* rather than from the row's own stage — so every row on
 * the "Waiting Doctor" tab read "Waiting Doctor", including a patient a nurse
 * had actively picked up. Deriving from the row's step is the whole point of
 * having a server-resolved step at all.
 *
 * Values match App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep.
 */

import type { StatusType } from "@/components/common/StatusBadge.vue";

export type PatientFlowStep =
  | "waiting_triage"
  | "in_triage"
  | "waiting_clinician"
  | "waiting_clinician_review"
  | "with_clinician"
  | "with_nurse"
  | "waiting_lab"
  | "in_lab"
  | "waiting_imaging"
  | "in_imaging"
  | "waiting_lab_and_imaging"
  | "in_lab_and_imaging"
  | "waiting_pharmacy"
  | "waiting_direct_service"
  | "in_direct_service"
  | "admitted"
  | "returned_to_reception"
  | "completed"
  | "cancelled"
  | "no_show";

/** i18n key for a step, or null when the step isn't one we label directly. */
const STEP_LABEL_KEYS: Partial<Record<PatientFlowStep, string>> = {
  waiting_triage: "patient.stage_waiting_triage",
  in_triage: "patient.stage_in_triage",
  waiting_clinician: "patient.stage_waiting_clinician",
  waiting_clinician_review: "patient.stage_waiting_clinician_review",
  with_clinician: "patient.stage_with_clinician",
  with_nurse: "patient.stage_with_nurse",
  // The diagnostic steps had badge *colours* here but no label keys, so every
  // row for a patient standing in the lab or in imaging fell through to a
  // generic badge — the workspace could colour the row correctly while being
  // unable to say what it meant (laboratory flow plan, phase 4).
  waiting_lab: "patient.stage_waiting_lab",
  in_lab: "patient.stage_in_lab",
  waiting_imaging: "patient.stage_waiting_imaging",
  in_imaging: "patient.stage_in_imaging",
  waiting_lab_and_imaging: "patient.stage_waiting_lab_and_imaging",
  in_lab_and_imaging: "patient.stage_in_lab_and_imaging",
  admitted: "patient.stage_admitted_inpatient",
  completed: "patient.stage_completed",
};

/**
 * Badge variant per step. `in_progress`/`info` mean somebody is actively with
 * the patient; `warning` means they are waiting on a queue. That distinction is
 * the one the flow ticket cared about, so it is encoded in colour as well as
 * text rather than left to the reader.
 */
const STEP_STATUS: Partial<Record<PatientFlowStep, StatusType>> = {
  waiting_triage: "warning",
  in_triage: "in_progress",
  waiting_clinician: "warning",
  waiting_clinician_review: "warning",
  with_clinician: "info",
  with_nurse: "in_progress",
  waiting_lab: "warning",
  in_lab: "in_progress",
  waiting_imaging: "warning",
  in_imaging: "in_progress",
  waiting_lab_and_imaging: "warning",
  in_lab_and_imaging: "in_progress",
  waiting_pharmacy: "warning",
  waiting_direct_service: "warning",
  in_direct_service: "in_progress",
  admitted: "success",
  returned_to_reception: "warning",
  completed: "complete",
  cancelled: "cancelled",
  no_show: "cancelled",
};

export function stepLabelKey(step: string | null | undefined): string | null {
  if (!step) return null;

  return STEP_LABEL_KEYS[step as PatientFlowStep] ?? null;
}

export function stepBadgeStatus(step: string | null | undefined): StatusType | null {
  if (!step) return null;

  return STEP_STATUS[step as PatientFlowStep] ?? null;
}

/**
 * True when the patient is actively with a member of staff rather than waiting
 * in a queue — mirrors PatientFlowStep::isActiveContact() on the backend.
 */
export function isActiveContactStep(step: string | null | undefined): boolean {
  const status = stepBadgeStatus(step);

  return status === "in_progress" || status === "info";
}
