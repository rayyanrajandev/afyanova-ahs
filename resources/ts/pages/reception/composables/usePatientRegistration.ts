/**
 * Patient registration + duplicate-check (Volume 2.1 §6, §6.2/§7.3,
 * Volume 3.7 T2.4/T7.4)
 * =========================================================================
 * 2027 Enterprise Enhancements:
 * - Real-time progressive duplicate checking against /api/v1/patients/duplicate-check
 * - Integrated financial coverage creation (Self-Pay vs Insurance)
 * - 1-Click "Save & Check-In" high-velocity intake workflow
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
import { useQueueStore } from "@/stores/queueStore";
import { useRecentStore } from "@/stores/recentStore";
import { useSyncStore } from "@/stores/syncStore";

export interface DuplicateMatch {
  id: string | null;
  patientNumber: string | null;
  firstName: string | null;
  lastName: string | null;
  dateOfBirth: string | null;
  phone: string | null;
  duplicateMatchType?: string;
  duplicateConfidenceScore?: number;
  duplicateConfidenceLabel?: "strong" | "possible";
}

export interface LiveDuplicateState {
  severity: "none" | "possible_warning" | "strong_warning" | "hard_block";
  duplicates: DuplicateMatch[];
}

export interface UsePatientRegistrationOptions {
  onRegistered?: (patientId: string, andCheckedIn: boolean) => void;
}

export function usePatientRegistration(registrationOptions: UsePatientRegistrationOptions = {}) {
  const { t } = useI18n();
  const toast = useToast();
  const patientStore = usePatientStore();
  const syncStore = useSyncStore();
  const recentStore = useRecentStore();
  const queueStore = useQueueStore();

  // ---- Registration form state ----
  const showRegistration = ref(false);
  const registrationInitialValues = ref<Record<string, unknown>>({});
  const draftSavedAt = ref<string | null>(null);
  const formKey = ref(0);
  const isSubmitting = ref(false);

  // ---- Real-time progressive duplicate check ----
  const liveDuplicates = ref<LiveDuplicateState>({
    severity: "none",
    duplicates: [],
  });
  const isCheckingDuplicates = ref(false);
  let liveCheckTimer: ReturnType<typeof setTimeout> | undefined;

  function openRegistration() {
    const draft = loadDraft();
    registrationInitialValues.value = draft?.values ?? {
      countryCode: "TZ",
      coverageType: "self_pay",
    };
    draftSavedAt.value = draft?.savedAt ?? null;
    liveDuplicates.value = { severity: "none", duplicates: [] };
    showRegistration.value = true;
    patientStore.clearCurrentPatient();
  }

  function closeRegistration() {
    showRegistration.value = false;
    liveDuplicates.value = { severity: "none", duplicates: [] };
  }

  function handleDraftSaved(savedAt: string) {
    draftSavedAt.value = savedAt;
  }

  // Debounced live duplicate query
  function checkLiveDuplicates(payload: {
    firstName?: string;
    lastName?: string;
    dateOfBirth?: string;
    phone?: string;
    nationalId?: string;
    gender?: string;
  }) {
    if (liveCheckTimer) clearTimeout(liveCheckTimer);

    // Only query if we have meaningful identifying criteria
    const hasIdentifier =
      (payload.firstName && payload.lastName && payload.dateOfBirth) ||
      (payload.phone && payload.phone.length >= 9) ||
      (payload.nationalId && payload.nationalId.length >= 5);

    if (!hasIdentifier || !syncStore.isOnline) {
      liveDuplicates.value = { severity: "none", duplicates: [] };
      return;
    }

    liveCheckTimer = setTimeout(async () => {
      isCheckingDuplicates.value = true;
      try {
        const res = await fetch("/api/v1/reception/patients/duplicate-check", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: JSON.stringify(payload),
        });
        if (res.ok) {
          const body = (await res.json()) as {
            data?: {
              severity: LiveDuplicateState["severity"];
              duplicates: DuplicateMatch[];
            };
          };
          if (body.data) {
            liveDuplicates.value = {
              severity: body.data.severity,
              duplicates: body.data.duplicates ?? [],
            };
          }
        }
      } catch {
        // Non-blocking background check
      } finally {
        isCheckingDuplicates.value = false;
      }
    }, 350);
  }

  // ---- Hard duplicate modal dialog (on 409) ----
  const showDuplicateDialog = ref(false);
  const duplicateMatches = ref<DuplicateMatch[]>([]);

  function cancelDuplicate() {
    showDuplicateDialog.value = false;
    duplicateMatches.value = [];
  }

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

  function queueRegistrationOffline(values: Record<string, unknown>) {
    syncStore.enqueue({
      method: "POST",
      url: "/api/v1/reception/patients",
      body: values,
    });
    showRegistration.value = false;
    toast.critical(t("registration.queued_offline"));
  }

  function duplicateWarningSummary(warnings: DuplicateMatch[]): string {
    const first = warnings[0];
    const name = [first?.firstName, first?.lastName].filter(Boolean).join(" ") || "—";
    return warnings.length === 1
      ? t("registration.duplicate_warning_single", { name, dob: first?.dateOfBirth ?? "—" })
      : t("registration.duplicate_warning_multiple", { name, count: warnings.length });
  }

  /**
   * Turns a failed POST /reception/walk-ins into something a receptionist can
   * act on.
   *
   * The endpoint already returns good, specific messages — "patient already has
   * an active appointment", an active-admission conflict, a validation error —
   * and they were all being thrown away. A 403 is called out separately because
   * it is the one failure the receptionist cannot fix from this screen: walk-in
   * check-in requires BOTH `appointments.create` and `appointment.check-in`, and
   * a role holding only the first registers the patient and then silently fails
   * the second half.
   */
  async function checkInFailureMessage(res: Response, patientName: string): Promise<string> {
    if (res.status === 403 || res.status === 401) {
      return t("registration.check_in_forbidden", { name: patientName });
    }

    const body = (await res.json().catch(() => null)) as
      | { message?: string; errors?: Record<string, string[]> }
      | null;

    const firstFieldError = body?.errors
      ? Object.values(body.errors).flat().find((m) => typeof m === "string" && m.trim() !== "")
      : undefined;
    const reason = body?.message ?? firstFieldError;

    return reason
      ? t("registration.check_in_failed_because", { name: patientName, reason })
      : t("registration.check_in_failed", { name: patientName });
  }

  async function submitRegistration(
    values: Record<string, unknown>,
    options: {
      andAddAnother?: boolean;
      andCheckIn?: boolean;
      arrivalMode?: "walk_in" | "emergency";
    } = {},
  ) {
    if (!syncStore.isOnline) {
      queueRegistrationOffline(values);
      return;
    }

    isSubmitting.value = true;

    // Extract insurance fields before posting demographics to /patients
    const { coverageType, insuranceProvider, memberNumber, policyType, ...demographics } = values;

    try {
      const res = await fetch("/api/v1/reception/patients", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify(demographics),
      });

      if (res.ok) {
        const body = (await res.json()) as {
          data?: BackendPatientRow;
          warnings?: DuplicateMatch[];
        };
        const patient = patientFromBackend(body.data ?? {});
        patientStore.cachePatient(patient);
        recentStore.addRecent(patient);
        clearDraft();
        draftSavedAt.value = null;

        // 1. Create insurance record if insurance coverage was entered
        if (coverageType === "insurance" && insuranceProvider && memberNumber) {
          try {
            await fetch(`/api/v1/reception/patients/${encodeURIComponent(patient.id)}/insurance`, {
              method: "POST",
              headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
              },
              body: JSON.stringify({
                insuranceProvider: String(insuranceProvider).trim(),
                memberId: String(memberNumber).trim(),
                cardNumber: String(memberNumber).trim(),
                planName: policyType ? String(policyType).trim() : undefined,
                insuranceType: "insurance",
                status: "active",
                verificationStatus: "unverified",
              }),
            });
          } catch {
            // Insurance creation warning
            toast.warning("Patient registered, but insurance record could not be saved. You can add it in profile.");
          }
        }

        // 2. High-Velocity Intake: Auto Check-In Flow (Walk-in or Emergency)
        if (options.andCheckIn) {
          const isEmergency = options.arrivalMode === "emergency";
          const patientName = patient.name[0]?.given?.join(" ") ?? "";

          // The registration response must carry the new patient's id for
          // check-in to reference. Without this guard a malformed payload sent
          // `patientId: ""` and the endpoint answered with a validation error
          // about a required field — technically correct, and useless to a
          // receptionist who just filled the form in.
          if (!patient.id) {
            patientStore.setCurrentPatient(patient.id);
            showRegistration.value = false;
            registrationOptions.onRegistered?.(patient.id, false);
            toast.critical(t("registration.check_in_failed", { name: patientName }), {
              description: t("registration.check_in_failed_next_step"),
            });
            await patientStore.fetchPatients();
            return;
          }

          try {
            const walkInRes = await fetch("/api/v1/reception/walk-ins", {
              method: "POST",
              headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
              },
              body: JSON.stringify({
                patientId: patient.id,
                arrivalMode: isEmergency ? "emergency" : "walk_in",
                reason: isEmergency
                  ? "Emergency arrival & immediate intake"
                  : "Walk-in registration & check-in",
              }),
            });

            if (walkInRes.ok) {
              patientStore.setCurrentPatient(patient.id);
              showRegistration.value = false;
              await queueStore.fetchReceptionQueue();
              await queueStore.fetchStageCounts();
              registrationOptions.onRegistered?.(patient.id, true);
              if (isEmergency) {
                toast.critical(
                  `🚨 ${patientName} registered & dispatched to EMERGENCY queue (CRITICAL)!`,
                );
              } else {
                toast.success(
                  `⚡ ${patientName} registered & checked in to Triage queue!`,
                );
              }
            } else {
              // Registration succeeded, check-in did not. This branch used to
              // show the plain "patient registered" *success* toast and discard
              // the response entirely, so "Save & Check In" silently behaved as
              // "Save" — the patient never reached the queue and nobody was told
              // why. Report the half that failed, with the backend's own reason.
              patientStore.setCurrentPatient(patient.id);
              showRegistration.value = false;
              registrationOptions.onRegistered?.(patient.id, false);
              toast.critical(
                await checkInFailureMessage(walkInRes, patientName),
                { description: t("registration.check_in_failed_next_step") },
              );
            }
          } catch {
            patientStore.setCurrentPatient(patient.id);
            showRegistration.value = false;
            registrationOptions.onRegistered?.(patient.id, false);
            // Previously this swallowed the failure with no message at all.
            toast.critical(
              t("registration.check_in_failed", { name: patientName }),
              { description: t("registration.check_in_failed_next_step") },
            );
          }
        } else if (options.andAddAnother) {
          registrationInitialValues.value = {
            countryCode: "TZ",
            coverageType: "self_pay",
          };
          formKey.value += 1;
          toast.success(t("registration.saved_title"), {
            description: t("registration.saved_add_another_description"),
          });
        } else {
          patientStore.setCurrentPatient(patient.id);
          showRegistration.value = false;
          registrationOptions.onRegistered?.(patient.id, false);
          const mrn = patient.identifier[0]?.value ?? "";
          toast.success(t("toast.patient_registered", { mrn }));
        }

        if (body.warnings && body.warnings.length > 0) {
          toast.warning(duplicateWarningSummary(body.warnings));
        }

        // Refresh the patient list so the new patient appears immediately
        await patientStore.fetchPatients();
      } else if (res.status === 409) {
        const body = await res.json().catch(() => null);
        duplicateMatches.value = (body?.duplicates ?? []) as DuplicateMatch[];
        showDuplicateDialog.value = true;
      } else {
        const body = await res.json().catch(() => null);
        const message = body?.message ?? t("toast.registration_failed");
        toast.critical(message);
      }
    } catch {
      queueRegistrationOffline(values);
    } finally {
      isSubmitting.value = false;
    }
  }

  return {
    showRegistration,
    registrationInitialValues,
    draftSavedAt,
    formKey,
    isSubmitting,
    liveDuplicates,
    isCheckingDuplicates,
    checkLiveDuplicates,
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
