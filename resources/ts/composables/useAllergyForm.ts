import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import type { PatientAllergySummary } from "@/stores/patientStore";

export interface UseAllergyFormOptions {
  workspace: string; // e.g. "reception", "nursing", "clinician"
  onSaved: (patientId: string) => void;
}

export function useAllergyForm(options: UseAllergyFormOptions) {
  const { t } = useI18n();
  const toast = useToast();

  const showAllergyDialog = ref(false);
  const allergyFormPatientId = ref<string | null>(null);
  const allergyFormRecordId = ref<string | null>(null);
  
  const substanceName = ref("");
  const reaction = ref("");
  const severity = ref<"mild" | "moderate" | "severe" | "life_threatening" | "unknown">("unknown");
  const clinicalStatus = ref<"active" | "inactive" | "resolved">("active");
  const verificationStatus = ref<"unconfirmed" | "provisional" | "confirmed" | "refuted" | "entered_in_error">("unconfirmed");
  const category = ref<"medication" | "food" | "environment" | "biologic">("medication");
  const type = ref<"allergy" | "intolerance">("allergy");
  const notes = ref("");

  const allergyFormSubmitting = ref(false);
  const allergyFormError = ref<string | null>(null);

  const isEditing = computed(() => allergyFormRecordId.value !== null);

  function openAllergyForm(patientId: string, existing?: any) {
    allergyFormPatientId.value = patientId;
    allergyFormRecordId.value = existing?.id ?? null;
    
    substanceName.value = existing?.substanceName ?? "";
    reaction.value = existing?.reaction ?? "";
    severity.value = (existing?.severity as any) ?? "unknown";
    clinicalStatus.value = (existing?.clinicalStatus as any) ?? "active";
    verificationStatus.value = (existing?.verificationStatus as any) ?? "unconfirmed";
    category.value = (existing?.category as any) ?? "medication";
    type.value = (existing?.type as any) ?? "allergy";
    notes.value = existing?.notes ?? "";
    
    allergyFormError.value = null;
    showAllergyDialog.value = true;
  }

  function closeAllergyForm() {
    showAllergyDialog.value = false;
  }

  async function submitAllergyForm() {
    const patientId = allergyFormPatientId.value;
    if (!patientId) return;
    if (!substanceName.value.trim()) {
      allergyFormError.value = t("patient.allergy_substance_required", "Substance Name is required");
      return;
    }

    allergyFormSubmitting.value = true;
    allergyFormError.value = null;
    
    try {
      const recordId = allergyFormRecordId.value;
      const url = recordId
        ? `/api/v1/${options.workspace}/patients/${encodeURIComponent(patientId)}/allergies/${encodeURIComponent(recordId)}`
        : `/api/v1/${options.workspace}/patients/${encodeURIComponent(patientId)}/allergies`;
        
      const res = await fetch(url, {
        method: recordId ? "PATCH" : "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          substanceName: substanceName.value.trim(),
          reaction: reaction.value.trim() || undefined,
          severity: severity.value !== "unknown" ? severity.value : undefined,
          clinicalStatus: clinicalStatus.value,
          verificationStatus: verificationStatus.value,
          category: category.value || undefined,
          type: type.value || undefined,
          notes: notes.value.trim() || undefined,
        }),
      });
      
      if (res.ok) {
        showAllergyDialog.value = false;
        toast.success(t(recordId ? "patient.allergy_updated" : "patient.allergy_added", recordId ? "Allergy updated" : "Allergy added"));
        options.onSaved(patientId);
        return;
      }
      
      const body = await res.json().catch(() => null);
      allergyFormError.value = body?.message ?? t("patient.allergy_save_failed", "Failed to save allergy");
    } catch {
      allergyFormError.value = t("patient.allergy_save_failed", "Failed to save allergy");
    } finally {
      allergyFormSubmitting.value = false;
    }
  }

  return {
    showAllergyDialog,
    allergyFormPatientId,
    allergyFormRecordId,
    substanceName,
    reaction,
    severity,
    clinicalStatus,
    verificationStatus,
    category,
    type,
    notes,
    allergyFormSubmitting,
    allergyFormError,
    isEditing,
    openAllergyForm,
    closeAllergyForm,
    submitAllergyForm,
  };
}
