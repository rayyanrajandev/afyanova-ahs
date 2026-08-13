/**
 * Insurance add/edit/verify (Volume 2.1 §8.1, Volume 3.7 §16 #10) — decided
 * + built 2026-08-11. Display already worked (GET /reception/patients/{id}/
 * summary, unchanged); this closes the write-side gap. Reuses
 * PatientInsuranceController directly through new reception-scoped routes
 * (routes/api-workspaces.php) — same pattern as every other reception/*
 * route that wraps a generic controller, not a new Reception-owned use
 * case, since the generic create/update/verify logic (duplicate member-ID
 * detection, audit events) is already correct and shared with Billing.
 *
 * Deliberately a compact field set (provider, member ID, policy number,
 * plan name) — the full backend record has ~19 fields (coverage limits,
 * billing-payer-contract linkage, copay percent), but those are Billing/
 * Finance back-office concerns, not what a registration desk captures at
 * the point of arrival. Same "just enough for this workspace" scoping as
 * the Duplicate dialog's warning summary, not an attempt to reproduce a
 * full billing-admin form here.
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import type { PatientInsuranceSummary } from "@/stores/patientStore";

export interface UseInsuranceFormOptions {
  /** Called after a successful save/verify so the caller can refresh the summary card. */
  onSaved: (patientId: string) => void;
}

export function useInsuranceForm(options: UseInsuranceFormOptions) {
  const { t } = useI18n();
  const toast = useToast();

  const showInsuranceDialog = ref(false);
  const insuranceFormPatientId = ref<string | null>(null);
  const insuranceFormRecordId = ref<string | null>(null);
  const insuranceProvider = ref("");
  const memberId = ref("");
  const policyNumber = ref("");
  const planName = ref("");
  const insuranceFormSubmitting = ref(false);
  const insuranceFormError = ref<string | null>(null);

  const isEditing = computed(() => insuranceFormRecordId.value !== null);

  /** Opens the dialog. `existing` pre-fills for edit; omitted/null means "Add insurance" for a patient with none yet. */
  function openInsuranceForm(patientId: string, existing?: PatientInsuranceSummary | null) {
    insuranceFormPatientId.value = patientId;
    insuranceFormRecordId.value = existing?.id ?? null;
    insuranceProvider.value = existing?.insuranceProvider ?? "";
    memberId.value = existing?.memberId ?? "";
    policyNumber.value = existing?.policyNumber ?? "";
    planName.value = existing?.planName ?? "";
    insuranceFormError.value = null;
    showInsuranceDialog.value = true;
  }

  function closeInsuranceForm() {
    showInsuranceDialog.value = false;
  }

  /** POST (new record) or PATCH (existing) — reception-scoped route either way. */
  async function submitInsuranceForm() {
    const patientId = insuranceFormPatientId.value;
    if (!patientId) return;
    if (!memberId.value.trim()) {
      insuranceFormError.value = t("insurance.error_member_id_required");
      return;
    }

    insuranceFormSubmitting.value = true;
    insuranceFormError.value = null;
    try {
      const recordId = insuranceFormRecordId.value;
      const url = recordId
        ? `/api/v1/reception/patients/${encodeURIComponent(patientId)}/insurance/${encodeURIComponent(recordId)}`
        : `/api/v1/reception/patients/${encodeURIComponent(patientId)}/insurance`;
      const res = await fetch(url, {
        method: recordId ? "PATCH" : "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          insuranceProvider: insuranceProvider.value.trim() || undefined,
          memberId: memberId.value.trim(),
          policyNumber: policyNumber.value.trim() || undefined,
          planName: planName.value.trim() || undefined,
        }),
      });
      if (res.ok) {
        showInsuranceDialog.value = false;
        toast.success(t(recordId ? "insurance.updated" : "insurance.added"));
        options.onSaved(patientId);
        return;
      }
      const body = await res.json().catch(() => null);
      insuranceFormError.value = body?.message ?? t("insurance.save_failed");
    } catch {
      insuranceFormError.value = t("insurance.save_failed");
    } finally {
      insuranceFormSubmitting.value = false;
    }
  }

  /**
   * One-click verify (§16 #10) — no separate dialog: unlike add/edit, there
   * are no fields to fill in, just a status flip an authorized user is
   * attesting to (the same reasoning Call's no-confirm-dialog choice used —
   * see QueuePanel.vue's docblock).
   */
  async function verifyInsurance(patientId: string, recordId: string) {
    try {
      const res = await fetch(
        `/api/v1/reception/patients/${encodeURIComponent(patientId)}/insurance/${encodeURIComponent(recordId)}/verify`,
        {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: JSON.stringify({ verificationStatus: "verified" }),
        },
      );
      if (res.ok) {
        toast.success(t("insurance.verified"));
        options.onSaved(patientId);
        return;
      }
      const body = await res.json().catch(() => null);
      toast.critical(body?.message ?? t("insurance.verify_failed"));
    } catch {
      toast.critical(t("insurance.verify_failed"));
    }
  }

  return {
    showInsuranceDialog,
    insuranceProvider,
    memberId,
    policyNumber,
    planName,
    insuranceFormSubmitting,
    insuranceFormError,
    isEditing,
    openInsuranceForm,
    closeInsuranceForm,
    submitInsuranceForm,
    verifyInsurance,
  };
}
