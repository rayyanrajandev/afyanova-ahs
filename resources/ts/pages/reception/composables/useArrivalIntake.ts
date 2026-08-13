/**
 * Arrival intake (Volume 2.1 §10.1, Volume 3.7 T5.0a/T5.0b/T5.0c)
 * ====================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit)
 * — pure extraction, no behavior change.
 *
 * Walk-in and Emergency both go through the same reception-scoped endpoint
 * (RegisterWalkInAndCheckInUseCase — atomic register+appointment+check-in),
 * differentiated only by `arrivalMode`. Scheduled check-in
 * (`checkInAppointment`) is a different endpoint for an *existing*
 * appointment, but grouped in the same composable/doc section (§10.1) — it's
 * the same real-world action (a patient arriving) via a third arrival mode,
 * not a separate feature.
 */

import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import { usePatientStore, type Patient } from "@/stores/patientStore";
import { useQueueStore } from "@/stores/queueStore";
import { patientDisplayName } from "../receptionFormatters";

export interface UseArrivalIntakeOptions {
  /**
   * Called after any successful check-in (walk-in/emergency via
   * submitArrival, or an existing appointment via checkInAppointment) so
   * the caller can refresh whatever depends on it — originally just the
   * patient profile's Upcoming appointments card; as of the 2026-08-11 bug
   * fix, callers should also refresh Latest visit/Audit trail, since
   * check-in opens a new encounter and both cards can be stale if the
   * profile was already open when it happened.
   */
  onCheckedIn?: (patientId: string) => void;
}

export function useArrivalIntake(options: UseArrivalIntakeOptions = {}) {
  const { t } = useI18n();
  const toast = useToast();
  const patientStore = usePatientStore();
  const queueStore = useQueueStore();

  const showArrivalDialog = ref(false);
  const arrivalMode = ref<"walk_in" | "emergency">("walk_in");
  const arrivalReason = ref("");
  const arrivalSubmitting = ref(false);

  function openArrivalDialog() {
    arrivalMode.value = "walk_in";
    arrivalReason.value = "";
    showArrivalDialog.value = true;
  }

  function closeArrivalDialog() {
    showArrivalDialog.value = false;
  }

  /** POST /api/v1/reception/walk-ins — Walk-in and Emergency arrival (§10.1). */
  async function submitArrival(patient: Patient | null) {
    if (!patient) return;
    arrivalSubmitting.value = true;
    try {
      const res = await fetch("/api/v1/reception/walk-ins", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          patientId: patient.id,
          arrivalMode: arrivalMode.value,
          reason: arrivalReason.value.trim() || undefined,
        }),
      });
      if (res.ok) {
        toast.success(
          t(
            arrivalMode.value === "emergency"
              ? "arrival.emergency_success"
              : "arrival.walkin_success",
            { name: patientDisplayName(patient) },
          ),
        );
        showArrivalDialog.value = false;
        void queueStore.fetchReceptionQueue();
        // Bug fix (2026-08-11): walk-in/emergency check-in (the profile
        // header's primary "Check-in" button) never fired onCheckedIn at
        // all — only the separate Upcoming-appointments inline check-in
        // (checkInAppointment below) did. Latest visit/Audit trail on an
        // already-open profile silently never picked up the new encounter.
        options.onCheckedIn?.(patient.id);
      } else {
        const body = await res.json().catch(() => null);
        toast.critical(body?.message ?? t("arrival.failed"));
      }
    } catch {
      toast.critical(t("arrival.failed"));
    } finally {
      arrivalSubmitting.value = false;
    }
  }

  /**
   * POST /api/v1/reception/queue/{id}/check-in — Scheduled arrival (§10.1).
   * Bug fix (2026-08-10): this called the generic `PATCH /api/v1/appointments/
   * {id}/check-in` directly — a reception-scoped route pointing at the exact
   * same controller method (routes/api.php, ReceptionController::checkIn)
   * already existed and wasn't being used. Same rule as everywhere else in
   * this file: new/edited reception-workspace calls stay on the
   * `reception/*` contract, never the generic one, even when they're
   * functionally identical.
   */
  async function checkInAppointment(appointmentId: string) {
    try {
      const res = await fetch(
        `/api/v1/reception/queue/${encodeURIComponent(appointmentId)}/check-in`,
        {
          method: "POST",
          headers: { "X-Requested-With": "XMLHttpRequest" },
        },
      );
      if (res.ok) {
        toast.success(t("arrival.checkin_success"));
        const patientId = patientStore.currentPatient?.id;
        if (patientId) options.onCheckedIn?.(patientId);
        void queueStore.fetchReceptionQueue();
      } else {
        const body = await res.json().catch(() => null);
        toast.critical(body?.message ?? t("arrival.failed"));
      }
    } catch {
      toast.critical(t("arrival.failed"));
    }
  }

  return {
    showArrivalDialog,
    arrivalMode,
    arrivalReason,
    arrivalSubmitting,
    openArrivalDialog,
    closeArrivalDialog,
    submitArrival,
    checkInAppointment,
  };
}
