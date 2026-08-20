/**
 * The frontend half of AppointmentStatus.
 *
 * Companion to `patientFlowStep.ts`, and written for the same reason: the
 * backend owns a vocabulary, and the frontend kept re-deriving answers from it
 * with ad-hoc comparisons that went stale the moment a case was added.
 *
 * It exists because of a specific, repeated failure. `awaiting_payment` was
 * introduced by the prepaid model, and every predicate written as "not one of
 * the statuses I know about" silently mis-classified it:
 *
 *  - Nursing offered "Retake Vitals" to a patient who had just checked in and
 *    had no vitals at all, because the test was `status !== "waiting_triage"`.
 *  - The backend's own "has this patient arrived" queries missed them too,
 *    which crashed the Reception profile and let a patient be checked in twice.
 *
 * The lesson each time was the same: enumerate the states you mean, never the
 * states you don't.
 *
 * Values match App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus.
 */

export type AppointmentStatus =
  | "scheduled"
  | "awaiting_payment"
  | "waiting_triage"
  | "waiting_provider"
  | "in_consultation"
  | "completed"
  | "cancelled"
  | "no_show";

/**
 * Statuses a visit passes through *before* triage observations are taken.
 *
 * `awaiting_payment` belongs here and is the one that was missing: it sits
 * between arrival and triage, so a patient in it has demonstrably not been
 * triaged yet.
 */
export const PRE_TRIAGE_STATUSES: readonly AppointmentStatus[] = [
  "scheduled",
  "awaiting_payment",
  "waiting_triage",
];

/**
 * Whether triage vitals have been recorded for this visit.
 *
 * Inferred from the appointment's status rather than from the latest vital set
 * on purpose: a vitals lookup can pick up a *previous* visit entirely and would
 * wrongly retire the "Record Vitals" action for someone who has not been
 * triaged today. Recording triage vitals is exactly what advances the
 * appointment past `waiting_triage`, so the status is a precise, server-owned
 * answer.
 *
 * A null or unknown status means "no visit", not "vitals taken".
 */
export function hasRecordedTriageVitals(status: string | null | undefined): boolean {
  if (!status) return false;

  return !PRE_TRIAGE_STATUSES.includes(status as AppointmentStatus);
}
