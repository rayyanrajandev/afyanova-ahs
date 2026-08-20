import { describe, expect, it } from "vitest";
import { hasRecordedTriageVitals, PRE_TRIAGE_STATUSES } from "../appointmentStatus";

/**
 * Regression guard for a reported glitch: a patient who had just checked in and
 * had no vitals at all was offered "Retake Vitals" in the nursing workspace.
 *
 * The predicate was written as `status !== "waiting_triage" && status !==
 * "scheduled"`, so `awaiting_payment` — a status that sits *before* triage —
 * fell through as "vitals already recorded".
 */
describe("hasRecordedTriageVitals", () => {
  it("does not claim vitals were taken for a patient still awaiting payment", () => {
    // The reported bug. awaiting_payment is before triage, not after it.
    expect(hasRecordedTriageVitals("awaiting_payment")).toBe(false);
  });

  it.each(PRE_TRIAGE_STATUSES)("treats %s as not yet triaged", (status) => {
    expect(hasRecordedTriageVitals(status)).toBe(false);
  });

  it.each(["waiting_provider", "in_consultation", "completed"])(
    "treats %s as triaged, because only recording vitals gets a visit there",
    (status) => {
      expect(hasRecordedTriageVitals(status)).toBe(true);
    },
  );

  it("treats an absent status as no visit rather than as vitals taken", () => {
    expect(hasRecordedTriageVitals(null)).toBe(false);
    expect(hasRecordedTriageVitals(undefined)).toBe(false);
    expect(hasRecordedTriageVitals("")).toBe(false);
  });

  it("keeps awaiting_payment in the pre-triage set", () => {
    // Named explicitly: the whole failure was a predicate that enumerated the
    // states it did not mean instead of the ones it did.
    expect(PRE_TRIAGE_STATUSES).toContain("awaiting_payment");
  });
});
