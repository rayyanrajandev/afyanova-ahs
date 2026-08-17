/**
 * Patient profile (Volume 2.1 §8)
 * ===================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit)
 * — pure extraction, no behavior change.
 *
 * One GET /patients/{id}/summary covers contact, insurance, real allergies,
 * latest encounter, and upcoming appointment — fetched whenever the
 * selected patient changes, cleared when deselected. `fetchUpcomingAppointments`
 * is exposed (not just internal) because Arrival Intake's Scheduled
 * check-in and Appointment Scheduling's booking both need to refresh this
 * card after they change something that affects it.
 */

import { computed, ref, watch, type ComputedRef } from "vue";
import { useI18n } from "vue-i18n";
import {
  usePatientStore,
  type Patient,
  type PatientSummary,
  type PatientUpcomingAppointmentSummary,
} from "@/stores/patientStore";

interface PatientAuditLogEntry {
  id: string;
  action: string | null;
  actionLabel: string | null;
  actor: { name?: string | null } | null;
  occurredAt: string | null;
}

export function usePatientProfile(selectedPatient: ComputedRef<Patient | null>) {
  const { t, te, locale } = useI18n({ useScope: "global" });
  const patientStore = usePatientStore();

  const profileSummary = ref<PatientSummary | null>(null);
  const isSummaryLoading = ref(false);
  const upcomingAppointments = ref<PatientUpcomingAppointmentSummary[]>([]);

  // Bug fix (2026-08-10, Volume 3.7 audit): the Contact card only rendered
  // `contact.addressLine` ("Maweni") and silently dropped district/region
  // ("Kigamboni", "Dar es salaam") even though both are on file — the summary
  // endpoint doesn't carry them (only `patient.region`/`patient.district`,
  // which PatientSummary doesn't map), but `selectedPatient.address[0]`
  // already has them from the other endpoint. Combines all three parts a
  // receptionist actually entered at registration into one address line.
  const contactAddress = computed(() => {
    const line = profileSummary.value?.contact.addressLine;
    const district = selectedPatient.value?.address[0]?.district;
    const region = selectedPatient.value?.address[0]?.city; // toPatient() maps region -> city
    const parts = [line, district, region].filter(
      (part): part is string => !!part && part.trim() !== "",
    );
    return parts.length > 0 ? parts.join(", ") : null;
  });

  /**
   * GET /api/v1/reception/appointments?patientId=…&status=scheduled (Volume
   * 2.1 §8.1 "Upcoming appointments"). A real list, not just the summary's
   * single `upcomingAppointment`. Bug fix (2026-08-10, Volume 3.7 audit): this
   * originally called the generic `/api/v1/appointments` endpoint directly —
   * repointed to the reception-scoped route (routes/api.php) so this
   * workspace's frontend never reaches into the shared/generic API surface.
   */
  async function fetchUpcomingAppointments(patientId: string) {
    try {
      const res = await fetch(
        `/api/v1/reception/appointments?patientId=${encodeURIComponent(patientId)}&status=scheduled`,
        { headers: { "X-Requested-With": "XMLHttpRequest" } },
      );
      if (!res.ok) {
        upcomingAppointments.value = [];
        return;
      }
      const body = (await res.json()) as {
        data?: PatientUpcomingAppointmentSummary[];
      };
      upcomingAppointments.value = (body.data ?? [])
        .slice()
        .sort((a, b) =>
          (a.scheduledAt ?? "").localeCompare(b.scheduledAt ?? ""),
        );
    } catch {
      upcomingAppointments.value = [];
    }
  }

  // ---- Audit trail (Volume 2.1 §8.1 "Audit trail — Access history link") ----
  // Correction (2026-08-10, Volume 3.7 audit): this section previously reused
  // `PatientSummary.recentActivity`, mislabeled as the audit trail. It isn't
  // one — GetPatientSummaryUseCase's own docblock says so explicitly ("not a
  // dedicated activity-log table — that's real, separate scope"); it's each
  // clinical module's single latest row (lab/pharmacy/procedure/invoice), not
  // an access/change log. The real audit trail is GET
  // /patients/{id}/activity-feed (PatientAuditLogRepositoryInterface — actor,
  // action, "Patient Profile Updated" etc.), same `patients.read` permission.
  const auditFeed = ref<PatientAuditLogEntry[]>([]);

  // Bug fix (2026-08-10, i18n audit): `actionLabel` comes from the backend's
  // AuditLogPresenter with hardcoded English strings ("Patient Profile
  // Updated") — no localization exists server-side. `action` (e.g.
  // "patient.updated") is a stable, language-agnostic key, so map it through
  // i18n here instead; unmapped/future actions fall back to the backend's
  // (English) label rather than showing nothing.
  function auditActionLabel(entry: PatientAuditLogEntry): string {
    if (entry.action) {
      const key = `audit.action.${entry.action.replace(/\./g, "_")}`;
      if (te(key)) return t(key);
    }
    return entry.actionLabel ?? t("patient.audit_trail");
  }

  /**
   * Re-fetches just the summary (Demographics/Allergies/Contact/Insurance/
   * Latest visit) for the currently-open profile. Bug fix (2026-08-11):
   * check-in and cancel both change what "Latest visit" should show (a new
   * encounter opens; a cancelled visit's encounter closes), but neither
   * mutation lived anywhere near this composable — the profile only ever
   * refetched on `watch(selectedPatient, ...)` below, i.e. when switching to
   * a *different* patient, never for a change to the one already open. Same
   * shape as `fetchUpcomingAppointments`/`fetchPatientActivityFeed`: exposed
   * so Index.vue's arrival-intake/queue-action callbacks can call it.
   */
  async function refreshSummary(patientId: string) {
    profileSummary.value = await patientStore.fetchPatientSummary(patientId);
  }

  async function fetchPatientActivityFeed(patientId: string) {
    try {
      const res = await fetch(
        `/api/v1/reception/patients/${encodeURIComponent(patientId)}/activity-feed?perPage=8`,
        { headers: { "X-Requested-With": "XMLHttpRequest" } },
      );
      if (!res.ok) {
        auditFeed.value = [];
        return;
      }
      const body = (await res.json()) as { data?: PatientAuditLogEntry[] };
      auditFeed.value = body.data ?? [];
    } catch {
      auditFeed.value = [];
    }
  }

  watch(selectedPatient, async (patient) => {
    if (!patient) {
      profileSummary.value = null;
      upcomingAppointments.value = [];
      auditFeed.value = [];
      return;
    }
    isSummaryLoading.value = true;
    const [summary] = await Promise.all([
      patientStore.fetchPatientSummary(patient.id),
      fetchUpcomingAppointments(patient.id),
      fetchPatientActivityFeed(patient.id),
    ]);
    profileSummary.value = summary;
    isSummaryLoading.value = false;
  });

  // Bug fix (2026-08-10, i18n audit): the Demographics card rendered
  // `selectedPatient.gender` raw ("female") instead of mapping it through
  // the same `patient.gender_*` keys the registration dropdown already uses
  // correctly — meaning it never translated when switching locale.
  function genderLabel(gender: Patient["gender"]): string {
    const key = `patient.gender_${gender}` as const;
    return t(key);
  }

  return {
    profileSummary,
    isSummaryLoading,
    upcomingAppointments,
    contactAddress,
    auditFeed,
    fetchUpcomingAppointments,
    refreshSummary,
    // Exposed for Edit demographics (Volume 2.1 §8.3) — after a successful
    // PATCH it refreshes the audit feed so the "Patient Profile Updated"
    // entry shows up without requiring a reselect.
    fetchPatientActivityFeed,
    auditActionLabel,
    genderLabel,
  };
}
