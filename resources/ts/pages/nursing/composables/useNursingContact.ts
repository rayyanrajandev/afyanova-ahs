/**
 * Nursing pickup / hand-back (2026-08-16 flow audit, finding 04)
 * =============================================================
 * Triage has had an explicit claim since Phase 2, and the board uses it to
 * show "In Triage" rather than "Waiting for Triage". Nursing had no
 * equivalent: a nurse could be actively working with a patient while every
 * other workspace still showed them sitting in a queue, and the only trace
 * afterwards was `assessed_by_user_id` quietly becoming non-null.
 *
 * Deliberately changes no appointment status — nursing contact happens
 * *inside* an existing status (a patient waiting for a doctor is still waiting
 * for that doctor while a nurse works with them). The step is recorded in the
 * flow log, which is what the board and the activity log both read.
 */

import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";

export interface UseNursingContactOptions {
  /** Active encounter id, or null when no patient is selected. */
  encounterId: () => string | null;
  /** Called after a successful claim/release so the caller can refresh queues. */
  onChanged?: () => void;
}

export function useNursingContact(options: UseNursingContactOptions) {
  const { t } = useI18n();
  const toast = useToast();

  const isUpdating = ref(false);
  /** Whether this nurse currently has the patient picked up, per the last call. */
  const hasPatient = ref(false);

  async function post(path: string, body?: Record<string, unknown>): Promise<boolean> {
    const encounter = options.encounterId();
    if (!encounter || isUpdating.value) return false;

    isUpdating.value = true;

    try {
      const res = await fetch(`/api/v1/nursing/visits/${encodeURIComponent(encounter)}/${path}`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify(body ?? {}),
      });

      if (!res.ok) {
        const failure = (await res.json().catch(() => ({}))) as { message?: string };
        toast.critical(failure.message ?? t("nursing.contact_update_failed"));
        return false;
      }

      options.onChanged?.();
      return true;
    } catch {
      toast.critical(t("nursing.contact_update_failed"));
      return false;
    } finally {
      isUpdating.value = false;
    }
  }

  /**
   * "I have called this patient in and started working with them."
   *
   * `silent` is used by the automatic claim that fires when a nurse opens the
   * vitals or assessment form: that path runs on essentially every patient, and
   * a toast confirming bookkeeping the nurse never asked for is noise. The
   * header badge flipping to "With Nurse" is the feedback. An explicit claim
   * still confirms.
   */
  async function claimPatient(options: { silent?: boolean } = {}) {
    if (await post("claim")) {
      hasPatient.value = true;
      if (!options.silent) {
        toast.success(t("nursing.patient_claimed_toast"));
      }
    }
  }

  /** "I am finished — put them back in the queue they came from." */
  async function releasePatient(reason?: string) {
    if (await post("release", reason ? { reason } : undefined)) {
      hasPatient.value = false;
      toast.success(t("nursing.patient_released_toast"));
    }
  }

  /** Clears local pickup state when the selected patient changes. */
  function resetContact() {
    hasPatient.value = false;
  }

  return { isUpdating, hasPatient, claimPatient, releasePatient, resetContact };
}
