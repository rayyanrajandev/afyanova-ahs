/**
 * Nursing assessment (Volume 2.3 §6, Volume 3.8 Phase 3)
 * =========================================================================
 * Extracted from nursing/Index.vue (2026-08-13, component decomposition —
 * Reception-style separation of concerns). Completing an assessment orders
 * downstream services (lab/pharmacy/radiology/procedure) with a clinical
 * note — this is the mechanism that clears an encounter off the Tasks queue
 * (`NurseQueueController::index` filters on `assessed_by_user_id` being set).
 * See `CompleteNurseAssessmentRequest`/the controller on the backend side.
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import { useNursingAssessmentStore, type AssessmentServiceType } from "@/stores/nursingAssessmentStore";

const ASSESSMENT_SERVICE_TYPES: AssessmentServiceType[] = ["laboratory", "pharmacy", "radiology", "clinical_procedure"];

export interface UseNursingAssessmentOptions {
  /** Active encounter id, or null when there is no open encounter. */
  encounterId: () => string | null;
  /** Called after a successful save so the caller can close the form. */
  onSaved?: () => void;
}

export function useNursingAssessment(options: UseNursingAssessmentOptions) {
  const { t } = useI18n();
  const toast = useToast();
  const assessmentStore = useNursingAssessmentStore();

  const assessmentForm = ref<{ clinicalNote: string; items: { itemName: string; serviceType: AssessmentServiceType; quantity: number }[] }>({
    clinicalNote: "",
    items: [],
  });
  const newAssessmentItem = ref<{ itemName: string; serviceType: AssessmentServiceType; quantity: number }>({
    itemName: "",
    serviceType: "laboratory",
    quantity: 1,
  });

  function addAssessmentItem() {
    if (!newAssessmentItem.value.itemName.trim()) return;
    assessmentForm.value.items.push({ ...newAssessmentItem.value });
    newAssessmentItem.value = { itemName: "", serviceType: "laboratory", quantity: 1 };
  }

  function removeAssessmentItem(index: number) {
    assessmentForm.value.items.splice(index, 1);
  }

  async function saveAssessment() {
    const encounterId = options.encounterId();
    if (!encounterId) return;
    // No `items.length` check (Volume 3.8 Phase 5) — a nurse who decides no
    // downstream orders are needed still needs to complete the assessment;
    // an empty items array is a valid, real outcome.
    if (!assessmentForm.value.clinicalNote.trim()) return;
    const ok = await assessmentStore.completeAssessment(encounterId, {
      clinicalNote: assessmentForm.value.clinicalNote,
      items: assessmentForm.value.items,
    });
    if (!ok) {
      toast.critical(t("nursing.assessment_save_failed"));
      return;
    }
    toast.success(t("nursing.assessment_saved"));
    assessmentForm.value = { clinicalNote: "", items: [] };
    options.onSaved?.();
  }

  return {
    ASSESSMENT_SERVICE_TYPES,
    assessmentForm,
    newAssessmentItem,
    addAssessmentItem,
    removeAssessmentItem,
    saveAssessment,
    isSaving: computed(() => assessmentStore.isSaving),
  };
}

export type UseNursingAssessment = ReturnType<typeof useNursingAssessment>;
