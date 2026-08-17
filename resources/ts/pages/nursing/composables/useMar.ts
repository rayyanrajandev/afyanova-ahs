/**
 * Nursing MAR (medication administration record) panel (Volume 2.3 §8,
 * Volume 3.8 Phase 6)
 * =========================================================================
 * Extracted from nursing/Index.vue (2026-08-13, component decomposition —
 * Reception-style separation of concerns). Owns the MAR detail pane: whether
 * it's open, fetching the patient's medications, and mapping each status to
 * a badge variant.
 *
 * Administration (marking a medication as given) is deliberately NOT wired
 * here: it needs a permission granted to no role, a controller method that
 * doesn't exist, and there is no administration-status concept anywhere in
 * the Pharmacy domain — see Volume 3.8 §6 for the full reasoning.
 */

import { computed, ref } from "vue";
import { useMedicationStore, type MarMedication } from "@/stores/medicationStore";

export interface UseMarOptions {
  /** Active patient id, or null when none selected. */
  patientId: () => string | null;
}

export function useMar(options: UseMarOptions) {
  const medicationStore = useMedicationStore();

  const mar = computed(() => medicationStore.mar);
  const showMar = ref(false);

  function toggleMar() {
    showMar.value = !showMar.value;
    const patientId = options.patientId();
    if (showMar.value && patientId) {
      void medicationStore.fetchMar(patientId);
    }
  }

  function closeMar() {
    showMar.value = false;
  }

  function marStatusVariant(status: MarMedication["status"]): "critical" | "warning" | "success" | "info" {
    switch (status) {
      case "dispensed":
        return "success";
      case "partially_dispensed":
        return "warning";
      case "in_preparation":
        return "info";
      case "pending":
        return "info";
      case "cancelled":
        return "critical";
    }
  }

  return {
    mar,
    showMar,
    toggleMar,
    closeMar,
    marStatusVariant,
    isLoading: computed(() => medicationStore.isLoading),
  };
}

export type UseMar = ReturnType<typeof useMar>;
