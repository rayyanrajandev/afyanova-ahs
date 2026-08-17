/**
 * Appointment Scheduling (Volume 2.1 §9)
 * =========================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit)
 * — this was ~380 lines of script sitting in one 2300+ line file with five
 * other unrelated features. Pure extraction: no behavior change, same
 * endpoints, same field logic, same conflict handling.
 *
 * The booking-ahead path into the Scheduled arrival branch (§5.1, §10.1) —
 * not the primary way a patient enters the queue (§9 intro), so this stays
 * a compact list + dialog rather than a dedicated full-page calendar.
 */

import { computed, ref, watch, type Ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import {
  patientFromBackend,
  type BackendPatientRow,
  type Patient,
  usePatientStore,
} from "@/stores/patientStore";
import { useRecentStore } from "@/stores/recentStore";
import { formatClinicalDate, formatClinicalTime, patientDisplayName } from "../receptionFormatters";

export interface ScheduleAppointment {
  id: string;
  patientId: string;
  patientName: string | null;
  patientNumber: string | null;
  department: string | null;
  clinicianUserId: number | null;
  scheduledAt: string | null;
  durationMinutes: number | null;
  reason: string | null;
  status: string | null;
  consultationType: "new" | "review" | null;
}

interface ClinicianOption {
  id: number;
  label: string;
}
interface DepartmentOption {
  value: string;
  label: string;
}

export interface UseAppointmentSchedulingOptions {
  /** The context-pane's active tab — the schedule list lazy-loads on first visit to "schedule". */
  activeTab: Ref<"patients" | "queue" | "schedule">;
  /** Called after a successful booking so the caller can refresh whatever else depends on it (e.g. the patient profile's Upcoming appointments card). */
  onAppointmentBooked?: (patientId: string) => void;
}

export function useAppointmentScheduling(options: UseAppointmentSchedulingOptions) {
  const { t, locale } = useI18n({ useScope: "global" });
  const toast = useToast();
  const patientStore = usePatientStore();
  const recentStore = useRecentStore();

  // ---- Schedule list (day/week) ----
  const scheduleView = ref<"day" | "week">("day");
  const scheduleAnchorDate = ref(new Date().toISOString().slice(0, 10)); // YYYY-MM-DD
  const scheduleNeedsClinicianOnly = ref(false);
  const scheduleAppointments = ref<ScheduleAppointment[]>([]);
  const isScheduleLoading = ref(false);
  const scheduleError = ref<string | null>(null);
  const scheduleLoadedOnce = ref(false);

  /** Day view: the anchor date only. Week view: the Monday–Sunday week it falls in. */
  const scheduleRange = computed(() => {
    const anchor = new Date(`${scheduleAnchorDate.value}T00:00:00`);
    if (scheduleView.value === "day") {
      const to = new Date(anchor);
      to.setHours(23, 59, 59, 999);
      return { from: anchor, to };
    }
    const day = anchor.getDay();
    const mondayOffset = day === 0 ? -6 : 1 - day;
    const from = new Date(anchor);
    from.setDate(from.getDate() + mondayOffset);
    const to = new Date(from);
    to.setDate(to.getDate() + 6);
    to.setHours(23, 59, 59, 999);
    return { from, to };
  });

  const scheduleRangeLabel = computed(() => {
    const { from, to } = scheduleRange.value;
    return scheduleView.value === "day"
      ? formatClinicalDate(from.toISOString())
      : `${formatClinicalDate(from.toISOString())} – ${formatClinicalDate(to.toISOString())}`;
  });

  function consultationTypeLabel(type: ScheduleAppointment["consultationType"]): string {
    return type === "review"
      ? t("appointment.consultation_type_review")
      : t("appointment.consultation_type_new");
  }

  /**
   * GET /api/v1/reception/appointments — day/week list (§9.1, §12.2).
   *
   * `status: 'scheduled'` (bug fix, 2026-08-11): this previously sent no
   * status filter at all, even though ListAppointmentsUseCase/
   * EloquentAppointmentRepository::search() already fully support one — so
   * every appointment in the date range came back regardless of status
   * *or* appointment_type, including walk-ins/emergencies. Those are
   * created already `waiting_triage` in one atomic transaction
   * (RegisterWalkInAndCheckInUseCase) and never pass through `scheduled`,
   * so this one filter is both necessary and sufficient to exclude them —
   * no appointment_type filter needed, and referral-origin appointments
   * (appointment_type: 'referral') correctly stay visible since they *are*
   * genuinely `scheduled` until someone arrives. This is a query-
   * completeness fix, not a new domain concept: the model already
   * distinguished these cases, this list just never asked for the right
   * subset of it. Also the reason this list now needs to be live-refreshed
   * on check-in (see refreshScheduleIfLoaded below) — a checked-in
   * appointment's status leaves 'scheduled', so it must disappear from
   * here the moment that happens, not just on next reload.
   */
  async function fetchSchedule() {
    isScheduleLoading.value = true;
    scheduleError.value = null;
    try {
      const params = new URLSearchParams({
        from: scheduleRange.value.from.toISOString(),
        to: scheduleRange.value.to.toISOString(),
        status: "scheduled",
        perPage: "100",
        sortBy: "scheduledAt",
        sortDir: "asc",
      });
      if (scheduleNeedsClinicianOnly.value) params.set("unassignedClinician", "1");
      const res = await fetch(`/api/v1/reception/appointments?${params.toString()}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      if (!res.ok) {
        scheduleAppointments.value = [];
        scheduleError.value = t("errors.fetch_failed");
        return;
      }
      const body = (await res.json()) as { data?: ScheduleAppointment[] };
      scheduleAppointments.value = body.data ?? [];
    } catch {
      scheduleAppointments.value = [];
      scheduleError.value = t("errors.fetch_failed");
    } finally {
      isScheduleLoading.value = false;
    }
  }

  // Lazy-load: the Schedule tab is secondary (§9 intro) — no request until a
  // receptionist actually opens it. Filter changes only re-fetch once it has.
  watch(options.activeTab, (tab) => {
    if (tab === "schedule" && !scheduleLoadedOnce.value) {
      scheduleLoadedOnce.value = true;
      void fetchSchedule();
      // Also load clinician options here, not just on dialog-open (component-
      // library audit, 2026-08-10) — the Schedule list itself now shows the
      // assigned clinician's name per row when there's room for it, which
      // needs this data before the receptionist ever opens "New appointment".
      void ensureScheduleOptionsLoaded();
    }
  });
  watch([scheduleView, scheduleAnchorDate, scheduleNeedsClinicianOnly], () => {
    if (scheduleLoadedOnce.value) void fetchSchedule();
  });

  /**
   * Bug fix (2026-08-11): check-in/cancel happen through entirely separate
   * composables (useArrivalIntake, useQueueActions) that had no way to
   * tell this list its data was now stale — the Appointments tab only ever
   * refetched on its own filter changes or a booking made through its own
   * dialog. A checked-in appointment would keep showing here (now with a
   * status that's no longer 'scheduled') until the receptionist happened
   * to trigger some other refetch. Guarded on scheduleLoadedOnce, same
   * reasoning as fetchSchedule's own lazy-load: don't fetch a tab the
   * receptionist hasn't opened yet just because something else changed.
   */
  function refreshScheduleIfLoaded() {
    if (scheduleLoadedOnce.value) void fetchSchedule();
  }

  function scheduleGoToday() {
    scheduleAnchorDate.value = new Date().toISOString().slice(0, 10);
  }

  function scheduleStep(direction: 1 | -1) {
    const d = new Date(`${scheduleAnchorDate.value}T00:00:00`);
    d.setDate(d.getDate() + direction * (scheduleView.value === "day" ? 1 : 7));
    scheduleAnchorDate.value = d.toISOString().slice(0, 10);
  }

  function openScheduleAppointmentPatient(appt: ScheduleAppointment) {
    patientStore.setCurrentPatient(appt.patientId);
    const patient = patientStore.patients.get(appt.patientId);
    if (patient) recentStore.addRecent(patient);
  }

  // ---- Schedule appointment dialog (create) ----
  const showScheduleDialog = ref(false);
  const scheduleFormPatientLocked = ref(false);
  const scheduleFormPatientId = ref<string | null>(null);
  const scheduleFormPatientLabel = ref("");
  const scheduleFormPatientQuery = ref("");
  const scheduleFormPatientResults = ref<Patient[]>([]);
  const scheduleFormDate = ref("");
  const scheduleFormTime = ref("");
  const scheduleFormClinicianUserId = ref("");
  const scheduleFormDepartment = ref("");
  const scheduleFormReason = ref("");
  const scheduleFormDuration = ref("30");
  const scheduleFormSubmitting = ref(false);
  const scheduleFormErrors = ref<Record<string, string>>({});
  const scheduleFormConflictMessage = ref<string | null>(null);

  const clinicianOptions = ref<ClinicianOption[]>([]);
  const departmentOptions = ref<DepartmentOption[]>([]);

  // Volume 2.1 §9.2: Clinician is optional (deliberately modeled — see
  // ListAppointmentsUseCase's `unassignedClinicianOnly` filter), but a
  // booking with neither a clinician nor a department has zero routing
  // information, so Department becomes required whenever Clinician is blank.
  const scheduleFormNeedsDepartment = computed(() => !scheduleFormClinicianUserId.value);

  let scheduleOptionsLoaded = false;

  /**
   * GET /api/v1/reception/clinicians + /reception/appointments/department-options
   * (§9.2, §12.2). Lazy-loaded once, on first dialog open — retried on next
   * open if it failed (both selects would otherwise be permanently empty).
   */
  async function ensureScheduleOptionsLoaded() {
    if (scheduleOptionsLoaded) return;
    scheduleOptionsLoaded = true;
    try {
      const [clinicianRes, departmentRes] = await Promise.all([
        fetch("/api/v1/reception/clinicians", {
          headers: { "X-Requested-With": "XMLHttpRequest" },
        }),
        fetch("/api/v1/reception/appointments/department-options", {
          headers: { "X-Requested-With": "XMLHttpRequest" },
        }),
      ]);
      if (clinicianRes.ok) {
        const body = (await clinicianRes.json()) as {
          data?: { userId: number | null; userName: string | null }[];
        };
        clinicianOptions.value = (body.data ?? [])
          .filter((c): c is { userId: number; userName: string | null } => c.userId !== null)
          .map((c) => ({ id: c.userId, label: c.userName ?? `#${c.userId}` }));
      }
      if (departmentRes.ok) {
        const body = (await departmentRes.json()) as { data?: DepartmentOption[] };
        departmentOptions.value = body.data ?? [];
      }
    } catch {
      scheduleOptionsLoaded = false;
    }
  }

  // Component-library audit (2026-08-10): the Schedule list only ever
  // showed an "Unassigned" badge when `clinicianUserId` was null — nothing
  // rendered when one *was* assigned, even though `clinicianOptions`
  // (loaded above) already has the name. `undefined` (options not loaded
  // yet) and `null` (an id that isn't in the loaded list, e.g. an inactive
  // clinician) both fall back to a generic label rather than showing
  // nothing or a raw numeric id.
  function clinicianName(clinicianUserId: number | null): string | null {
    if (clinicianUserId === null) return null;
    const match = clinicianOptions.value.find((c) => c.id === clinicianUserId);
    return match?.label ?? t("appointment.clinician_unknown");
  }

  function resetScheduleForm() {
    scheduleFormPatientId.value = null;
    scheduleFormPatientLabel.value = "";
    scheduleFormPatientQuery.value = "";
    scheduleFormPatientResults.value = [];
    scheduleFormDate.value = "";
    scheduleFormTime.value = "";
    scheduleFormClinicianUserId.value = "";
    scheduleFormDepartment.value = "";
    scheduleFormReason.value = "";
    scheduleFormDuration.value = "30";
    scheduleFormErrors.value = {};
    scheduleFormConflictMessage.value = null;
  }

  /** From the Schedule tab's "+ New" — patient not yet chosen. */
  function openScheduleDialogGeneral() {
    resetScheduleForm();
    scheduleFormPatientLocked.value = false;
    showScheduleDialog.value = true;
    void ensureScheduleOptionsLoaded();
  }

  /** From a patient's profile header — booking a follow-up for them (§9 intro). */
  function openScheduleDialogForPatient(patient: Patient | null) {
    if (!patient) return;
    resetScheduleForm();
    scheduleFormPatientLocked.value = true;
    scheduleFormPatientId.value = patient.id;
    scheduleFormPatientLabel.value = patientDisplayName(patient);
    showScheduleDialog.value = true;
    void ensureScheduleOptionsLoaded();
  }

  function closeScheduleDialog() {
    showScheduleDialog.value = false;
  }

  let scheduleFormPatientDebounce: ReturnType<typeof setTimeout> | undefined;

  /** GET /api/v1/reception/patients/search — patient picker inside the dialog. */
  function onScheduleFormPatientInput() {
    if (scheduleFormPatientDebounce) clearTimeout(scheduleFormPatientDebounce);
    scheduleFormPatientDebounce = setTimeout(async () => {
      const q = scheduleFormPatientQuery.value.trim();
      if (!q) {
        scheduleFormPatientResults.value = [];
        return;
      }
      try {
        const res = await fetch(
          `/api/v1/reception/patients/search?q=${encodeURIComponent(q)}`,
          { headers: { "X-Requested-With": "XMLHttpRequest" } },
        );
        if (!res.ok) {
          scheduleFormPatientResults.value = [];
          return;
        }
        const body = (await res.json()) as { data?: BackendPatientRow[] };
        scheduleFormPatientResults.value = (body.data ?? []).map(patientFromBackend);
      } catch {
        scheduleFormPatientResults.value = [];
      }
    }, 200);
  }

  function selectScheduleFormPatient(patient: Patient) {
    scheduleFormPatientId.value = patient.id;
    scheduleFormPatientLabel.value = patientDisplayName(patient);
    scheduleFormPatientQuery.value = "";
    scheduleFormPatientResults.value = [];
  }

  function clearScheduleFormPatient() {
    scheduleFormPatientId.value = null;
    scheduleFormPatientLabel.value = "";
  }

  function validateScheduleForm(): boolean {
    const errors: Record<string, string> = {};
    if (!scheduleFormPatientId.value) {
      errors.patientId = t("appointment.error_patient_required");
    }
    if (!scheduleFormDate.value || !scheduleFormTime.value) {
      errors.scheduledAt = t("appointment.error_datetime_required");
    }
    if (scheduleFormNeedsDepartment.value && !scheduleFormDepartment.value) {
      errors.department = t("appointment.error_department_required");
    }
    scheduleFormErrors.value = errors;
    return Object.keys(errors).length === 0;
  }

  /**
   * POST /api/v1/reception/appointments (§9.3, §12.2). Surfaces the shared
   * ActiveAppointmentConflictException/ClinicianScheduleConflictException/
   * PatientActiveEncounterConflictException shapes
   * (`context.activeAppointmentConflict` / `context.clinicianScheduleConflict`
   * / `context.activePatientEncounterConflict`, AppointmentController::store)
   * as one inline conflict message rather than a generic field error — the
   * whole point of surfacing it is telling the receptionist *why*, not just
   * that the save failed.
   */
  async function submitScheduleForm() {
    scheduleFormConflictMessage.value = null;
    if (!validateScheduleForm()) return;
    scheduleFormSubmitting.value = true;
    try {
      // Bug fix (found via live testing, 2026-08-10): a naive
      // "YYYY-MM-DDTHH:mm:00" string with no timezone offset was sent
      // as-is. The backend (config('app.timezone') === 'UTC') stores a
      // naive string literally, then serializes it back labeled as UTC —
      // so "09:15" typed by the receptionist round-tripped as 09:15 UTC,
      // 3 hours off from the 09:15 *local* (Africa/Dar_es_Salaam) time they
      // actually meant. `new Date(...)` with a time component and no offset
      // is spec-defined (ECMA-262) to parse as local time, so converting
      // through a real Date and reading `.toISOString()` sends the correct
      // UTC instant instead of a wrong-by-the-UTC-offset one.
      const scheduledAt = new Date(
        `${scheduleFormDate.value}T${scheduleFormTime.value}:00`,
      ).toISOString();
      const res = await fetch("/api/v1/reception/appointments", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          patientId: scheduleFormPatientId.value,
          scheduledAt,
          durationMinutes: Number(scheduleFormDuration.value),
          clinicianUserId: scheduleFormClinicianUserId.value
            ? Number(scheduleFormClinicianUserId.value)
            : undefined,
          department: scheduleFormDepartment.value || undefined,
          reason: scheduleFormReason.value.trim() || undefined,
        }),
      });
      const body = await res.json().catch(() => null);
      if (res.ok) {
        const bookedPatientId = scheduleFormPatientId.value;
        const consultationType = body?.data?.consultationType as
          | ScheduleAppointment["consultationType"]
          | undefined;
        toast.success(
          t("appointment.booked_success", {
            type: consultationTypeLabel(consultationType ?? "new"),
          }),
        );
        // Soft-warning (2026-08-12, scheduling-duplicate audit): mirrors
        // usePatientRegistration.ts's own non-blocking `warnings` toast for
        // soft patient-duplicate matches — the booking already succeeded,
        // this just tells the receptionist about another unresolved
        // appointment on file rather than silently saying nothing (the gap
        // that prompted this fix: same-day booking already hard-blocks via
        // ActiveAppointmentConflictException above, but a *different* day
        // never surfaced anything at all).
        const warningMessage = (body?.warnings as Array<{ message?: string }> | undefined)?.[0]
          ?.message;
        if (warningMessage) {
          toast.warning(warningMessage);
        }
        showScheduleDialog.value = false;
        refreshScheduleIfLoaded();
        if (bookedPatientId) options.onAppointmentBooked?.(bookedPatientId);
        return;
      }
      if (res.status === 422 && body) {
        const conflict =
          body.context?.clinicianScheduleConflict ??
          body.context?.activeAppointmentConflict ??
          body.context?.activePatientEncounterConflict;
        if (conflict) {
          scheduleFormConflictMessage.value = body.message ?? t("appointment.conflict_generic");
        } else {
          const fieldErrors: Record<string, string> = {};
          for (const [field, messages] of Object.entries(
            (body.errors ?? {}) as Record<string, unknown>,
          )) {
            fieldErrors[field] = Array.isArray(messages) ? String(messages[0]) : String(messages);
          }
          scheduleFormErrors.value = { ...scheduleFormErrors.value, ...fieldErrors };
        }
      } else {
        toast.critical(body?.message ?? t("appointment.booking_failed"));
      }
    } catch {
      toast.critical(t("appointment.booking_failed"));
    } finally {
      scheduleFormSubmitting.value = false;
    }
  }

  return {
    // schedule list
    scheduleView,
    scheduleAnchorDate,
    scheduleNeedsClinicianOnly,
    scheduleAppointments,
    isScheduleLoading,
    scheduleError,
    scheduleRangeLabel,
    consultationTypeLabel,
    clinicianName,
    formatClinicalTime,
    scheduleGoToday,
    scheduleStep,
    refreshScheduleIfLoaded,
    openScheduleAppointmentPatient,
    // create dialog
    showScheduleDialog,
    scheduleFormPatientLocked,
    scheduleFormPatientId,
    scheduleFormPatientLabel,
    scheduleFormPatientQuery,
    scheduleFormPatientResults,
    scheduleFormDate,
    scheduleFormTime,
    scheduleFormClinicianUserId,
    scheduleFormDepartment,
    scheduleFormReason,
    scheduleFormDuration,
    scheduleFormSubmitting,
    scheduleFormErrors,
    scheduleFormConflictMessage,
    clinicianOptions,
    departmentOptions,
    scheduleFormNeedsDepartment,
    openScheduleDialogGeneral,
    openScheduleDialogForPatient,
    closeScheduleDialog,
    onScheduleFormPatientInput,
    selectScheduleFormPatient,
    clearScheduleFormPatient,
    submitScheduleForm,
  };
}
