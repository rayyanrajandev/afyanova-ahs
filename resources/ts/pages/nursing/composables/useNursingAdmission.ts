/**
 * Nursing admission composable (Volume 2.3)
 * =========================================================================
 * Form management and submission for escalating a patient to an inpatient
 * admission from the Nursing workspace.
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import { useNursingAdmissionStore, type NursingAdmissionInput } from "@/stores/nursingAdmissionStore";
import type { VisitContext } from "@/stores/queueStore";

export interface UseNursingAdmissionOptions {
  patientId: () => string | null;
  encounterId: () => string | null;
  visit: () => VisitContext | null;
  onSaved?: () => void;
}

export function useNursingAdmission(options: UseNursingAdmissionOptions) {
  const { t } = useI18n();
  const toast = useToast();
  const admissionStore = useNursingAdmissionStore();

  const form = ref<{
    admissionReason: string;
    ward: string;
    bed: string;
    notes: string;
  }>({
    admissionReason: "",
    ward: "",
    bed: "",
    notes: "",
  });

  function resetForm() {
    form.value = {
      admissionReason: "",
      ward: "",
      bed: "",
      notes: "",
    };
  }

  async function saveAdmission() {
    const patientId = options.patientId();
    const encounterId = options.encounterId();
    if (!patientId || !encounterId) {
      toast.warning(t("nursing.admission_no_active_encounter"));
      return;
    }

    const payload: NursingAdmissionInput = {
      patientId,
      encounterId,
      admittedAt: new Date().toISOString(),
      admissionReason: form.value.admissionReason.trim() || undefined,
      ward: form.value.ward.trim() || undefined,
      bed: form.value.bed.trim() || undefined,
      notes: form.value.notes.trim() || undefined,
    };

    const result = await admissionStore.createAdmission(payload);
    if (!result) {
      toast.critical(admissionStore.error || t("nursing.admission_save_failed"));
      return;
    }

    toast.success(t("nursing.admission_saved"));
    resetForm();
    options.onSaved?.();
  }

  return {
    form,
    resetForm,
    saveAdmission,
    isSaving: computed(() => admissionStore.isSaving),
  };
}

export type UseNursingAdmission = ReturnType<typeof useNursingAdmission>;
