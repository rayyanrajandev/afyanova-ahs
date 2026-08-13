/**
 * Patient registration + duplicate-check (Volume 2.1 §6, §6.2/§7.3,
 * Volume 3.7 T2.4/T7.4)
 * =========================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit)
 * — pure extraction, no behavior change.
 *
 * The duplicate-check dialog lives here rather than in its own composable:
 * it only exists as a branch of `submitRegistration` (a 409 response from
 * the backend).
 *
 * Bug found and fixed 2026-08-11 (§16 #7 follow-up): this dialog only ever
 * appears for a *hard* duplicate (409 — same National ID/patient number) —
 * `PatientDuplicateDetectionService::evaluate()` only throws for that case;
 * a *soft* match (name+DOB fuzzy score) is returned as a non-blocking
 * `warnings` array on an otherwise-successful 201, which this file
 * previously read nowhere and silently dropped. The dialog's old "Proceed
 * anyway" button sent `X-Confirm-Duplicate: 1`, a header the backend reads
 * nowhere (`grep`-confirmed) — clicking it just resubmitted the identical
 * payload, which hit the exact same hard duplicate and 409'd again, every
 * time. No override flag was added (a deliberate choice, not an oversight
 * — see the plan doc's §16 #7: a duplicate medical record under the same
 * National ID is a patient-safety issue, not just registration friction,
 * and adding a bypass needs real security review this session doesn't
 * substitute for). Instead: the dead "Proceed" is replaced with "Open
 * existing patient" (the actually-correct action for a real hard
 * duplicate), and soft-match warnings that were always being computed but
 * never shown now surface as a toast.
 */

import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import { clearDraft, loadDraft } from "@/pages/reception/registrationDraft";
import {
  patientFromBackend,
  usePatientStore,
  type BackendPatientRow,
} from "@/stores/patientStore";
import { useRecentStore } from "@/stores/recentStore";
import { useSyncStore } from "@/stores/syncStore";

interface DuplicateMatch {
  id: string | null;
  patientNumber: string | null;
  firstName: string | null;
  lastName: string | null;
  dateOfBirth: string | null;
  phone: string | null;
  duplicateMatchType?: string;
}

interface DuplicateWarning {
  id: string | null;
  firstName: string | null;
  lastName: string | null;
  dateOfBirth: string | null;
  duplicateConfidenceLabel?: "strong" | "possible";
}

export function usePatientRegistration() {
  const { t } = useI18n();
  const toast = useToast();
  const patientStore = usePatientStore();
  const syncStore = useSyncStore();
  const recentStore = useRecentStore();

  // ---- Registration form (Volume 2.1 §6) ----
  const showRegistration = ref(false);
  const registrationInitialValues = ref<Record<string, unknown>>({});
  const draftSavedAt = ref<string | null>(null);
  // Bumped after a "Save & Add Another" (2026-08-12): forces <Form> to
  // remount via :key, which is what actually resets every vee-validate
  // field to fresh empty state — mutating registrationInitialValues alone
  // wouldn't touch fields the receptionist already typed into, since
  // vee-validate only reads initial-values once per mount.
  const formKey = ref(0);

  function openRegistration() {
    const draft = loadDraft();
    registrationInitialValues.value = draft?.values ?? {};
    draftSavedAt.value = draft?.savedAt ?? null;
    showRegistration.value = true;
    patientStore.clearCurrentPatient();
  }

  function closeRegistration() {
    showRegistration.value = false;
  }

  function handleDraftSaved(savedAt: string) {
    draftSavedAt.value = savedAt;
  }

  // ---- Duplicate-check dialog (Volume 2.1 §6.2 / §7.3, Volume 3.7 T2.4) ----
  // Only ever populated for a *hard* duplicate (409) — see file header. No
  // pendingValues/proceed path any more: there is nothing to "proceed"
  // with, the correct action is opening the existing record.
  const showDuplicateDialog = ref(false);
  const duplicateMatches = ref<DuplicateMatch[]>([]);

  function cancelDuplicate() {
    showDuplicateDialog.value = false;
    duplicateMatches.value = [];
  }

  /**
   * Opens the already-registered patient this hard duplicate matched,
   * instead of the dead-end "Proceed anyway" this replaced (see file
   * header). Fetches first (not just setCurrentPatient(id)) since a
   * duplicate match from this response isn't necessarily already in
   * patientStore's cache — `currentPatient` requires the id to resolve
   * against that map, same reasoning as everywhere else in this workspace
   * that opens a patient by id from something other than the main list.
   *
   * Also clears the abandoned registration draft: leaving it would just
   * resurface the exact same doomed submission next time "Register
   * Patient" is opened (same National ID/patient number → the same 409),
   * which is a smaller version of the same "offers an action that leads
   * nowhere" problem this whole fix is closing.
   */
  async function openExistingDuplicate(patientId: string | null) {
    if (!patientId) return;
    showDuplicateDialog.value = false;
    duplicateMatches.value = [];
    showRegistration.value = false;
    clearDraft();
    draftSavedAt.value = null;
    const patient = await patientStore.fetchPatient(patientId);
    if (patient) {
      patientStore.setCurrentPatient(patient.id);
      recentStore.addRecent(patient);
    }
  }

  // Offline registration (Volume 2.1 §12.3, Volume 1.4 §7, Volume 3.7 T7.4).
  // syncStore already auto-replays the queue FIFO on the browser's `online`
  // event (see syncStore.ts) — nothing further to wire here for the reconnect
  // side. The draft (registrationDraft.ts) is deliberately *not* cleared: it's
  // a separate, independent copy, kept in case the queued write ever fails
  // permanently and the receptionist needs to re-enter it.
  function queueRegistrationOffline(values: Record<string, unknown>) {
    syncStore.enqueue({
      method: "POST",
      url: "/api/v1/reception/patients",
      body: values,
    });
    showRegistration.value = false;
    toast.critical(t("registration.queued_offline"));
  }

  /**
   * Builds a short, human-readable summary for the soft-duplicate warning
   * toast — deliberately not the same detailed match list the hard-block
   * Dialog shows (this is a non-blocking heads-up, not a decision the
   * receptionist has to act on before continuing).
   */
  function duplicateWarningSummary(warnings: DuplicateWarning[]): string {
    const first = warnings[0];
    const name = [first?.firstName, first?.lastName].filter(Boolean).join(" ") || "—";
    return warnings.length === 1
      ? t("registration.duplicate_warning_single", { name, dob: first?.dateOfBirth ?? "—" })
      : t("registration.duplicate_warning_multiple", { name, count: warnings.length });
  }

  async function submitRegistration(
    values: Record<string, unknown>,
    options: { andAddAnother?: boolean } = {},
  ) {
    // POST /api/v1/reception/patients (Volume 2.1 §12.2)
    // Payload matches StorePatientRequest exactly (firstName, lastName, dateOfBirth,
    // countryCode, region, district, addressLine — all required by the backend).
    if (!syncStore.isOnline) {
      queueRegistrationOffline(values);
      return;
    }

    try {
      const res = await fetch("/api/v1/reception/patients", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify(values),
      });
      if (res.ok) {
        const body = (await res.json()) as {
          data?: BackendPatientRow;
          warnings?: DuplicateWarning[];
        };
        const patient = patientFromBackend(body.data ?? {});
        patientStore.cachePatient(patient);
        recentStore.addRecent(patient);
        clearDraft();
        draftSavedAt.value = null;

        // "Save & Add Another" (2026-08-12): stay on the registration
        // panel instead of navigating to the patient just created — a
        // receptionist registering several patients back-to-back (e.g. a
        // family arriving together) shouldn't have to reopen "Register
        // Patient" from scratch after every single save. Remounting via
        // formKey is what actually clears the fields; see that ref's own
        // comment for why reassigning registrationInitialValues alone
        // isn't enough.
        if (options.andAddAnother) {
          registrationInitialValues.value = {};
          formKey.value += 1;
          // Two-line title+description (2026-08-12, direct user feedback:
          // "Patient saved / Ready to register another patient" as two
          // distinct lines) — useToast's `description` option renders as a
          // visually secondary line under the title, which is exactly this
          // shape; the single-sentence version this replaced ran both
          // ideas together as one line.
          toast.success(t("registration.saved_title"), {
            description: t("registration.saved_add_another_description"),
          });
        } else {
          patientStore.setCurrentPatient(patient.id);
          showRegistration.value = false;
          const mrn = patient.identifier[0]?.value ?? "";
          toast.success(t("toast.patient_registered", { mrn })); // §6.3 step 2
        }

        // Soft-match warnings (bug fix 2026-08-11): the backend always
        // computed these (PatientDuplicateDetectionService's scored,
        // non-blocking candidates) but this response field was never read
        // before — registration succeeded silently with zero visibility
        // into a possible match. Non-blocking by design (the backend
        // already decided this doesn't warrant stopping registration);
        // `warning` (8s, not persistent) matches that — enough to notice,
        // not enough to demand a dismiss click before continuing work.
        if (body.warnings && body.warnings.length > 0) {
          toast.warning(duplicateWarningSummary(body.warnings));
        }

        // Refresh the patient list so the new patient appears immediately
        await patientStore.fetchPatients();
      } else if (res.status === 409) {
        // Server found a hard duplicate (same National ID / patient number) —
        // show the match(es); the only valid action is opening the existing
        // record (openExistingDuplicate), not retrying this submission.
        const body = await res.json().catch(() => null);
        duplicateMatches.value = (body?.duplicates ?? []) as DuplicateMatch[];
        showDuplicateDialog.value = true;
      } else {
        // Surface the backend validation error so the form isn't "dormant"
        const body = await res.json().catch(() => null);
        const message = body?.message ?? t("toast.registration_failed");
        toast.critical(message);
      }
    } catch {
      // Network failed mid-request (went offline between the isOnline check
      // above and now, or a transient blip) — queue the same way.
      queueRegistrationOffline(values);
    }
  }

  return {
    showRegistration,
    registrationInitialValues,
    draftSavedAt,
    formKey,
    openRegistration,
    closeRegistration,
    handleDraftSaved,
    showDuplicateDialog,
    duplicateMatches,
    cancelDuplicate,
    openExistingDuplicate,
    submitRegistration,
  };
}
