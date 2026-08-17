/**
 * Nursing vitals collection (Volume 2.3 §7, Volume 3.8 Phase 2)
 * =========================================================================
 * Extracted from nursing/Index.vue (2026-08-13, component decomposition —
 * Reception-style separation of concerns). Owns the vitals form state, the
 * out-of-range flagging rules, recording a new set, and loading the latest
 * recorded set for the selected patient.
 *
 * `painScore`/height/BMI added 2026-08-14: the backend (StorePatientVitalSet
 * Request) now accepts and stores them. BMI is derived from height + weight
 * and computed client-side for display, then sent to the backend.
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import { useVitalsStore } from "@/stores/vitalsStore";

export type VitalField =
  | "temperature"
  | "heartRate"
  | "respiratoryRate"
  | "sbp"
  | "dbp"
  | "spo2"
  | "weight"
  | "height"
  | "painScore";

export interface UseVitalsOptions {
  /** Active patient id, or null when none selected. */
  patientId: () => string | null;
  /** Called after a successful save so the caller can close the form. */
  onSaved?: () => void;
}

export interface DepartmentOption {
  value: string;
  label: string;
  id?: string | null;
}

export function useVitals(options: UseVitalsOptions) {
  const { t } = useI18n();
  const toast = useToast();
  const vitalsStore = useVitalsStore();

  /**
   * Routing target for triage completion (2026-08-16).
   *
   * Walk-in registration sets no department by design, and nothing downstream
   * ever asked for one — so walk-ins reached the provider queue belonging to no
   * clinic, invisible to every department-filtered board. Recording vitals is
   * the moment the nurse knows which clinic the patient actually needs, so the
   * routing choice belongs on this form rather than in a separate step nobody
   * would remember to take.
   */
  const departmentOptions = ref<DepartmentOption[]>([]);
  const selectedDepartmentId = ref<string | null>(null);
  const isLoadingDepartments = ref(false);

  async function loadDepartmentOptions() {
    if (departmentOptions.value.length > 0 || isLoadingDepartments.value) return;
    isLoadingDepartments.value = true;
    try {
      const res = await fetch("/api/v1/nursing/department-options", {
        headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
      });
      if (!res.ok) return;
      const body = (await res.json()) as { data?: DepartmentOption[] };
      departmentOptions.value = body.data ?? [];
    } catch {
      // A routing list that fails to load must not block recording vitals —
      // the observations matter more than the routing, and the visit can be
      // routed later.
    } finally {
      isLoadingDepartments.value = false;
    }
  }

  // Blank to start — each field shows a placeholder rather than a pre-filled
  // value. `null` means "not entered"; a field is only sent to the backend
  // once a real value has been typed (see saveVitals).
  const vitalForm = ref<Record<VitalField, number | null>>({
    temperature: null,
    heartRate: null,
    respiratoryRate: null,
    sbp: null,
    dbp: null,
    spo2: null,
    weight: null,
    height: null,
    painScore: null,
  });

  // BMI (kg/m²) derived from height (cm) + weight (kg). Kept out of
  // `vitalForm` because it isn't entered directly — it's a read-only value
  // shown on the form and sent to the backend on save.
  const computedBmi = computed<number | null>(() => {
    const height = vitalForm.value.height;
    const weight = vitalForm.value.weight;
    if (isBlank(height) || isBlank(weight)) return null;
    const heightM = Number(height) / 100;
    if (heightM <= 0) return null;
    return Math.round((Number(weight) / (heightM * heightM)) * 100) / 100;
  });

  // Out-of-range flags (Volume 2.3 §7.2 — icon + label, never color alone).
  // Only returns a status for out-of-range values; blank and normal values
  // return null (no badge).
  function isBlank(value: number | string | null | undefined): boolean {
    return (
      value == null ||
      value === "" ||
      (typeof value === "number" && Number.isNaN(value))
    );
  }

  function vitalFlag(
    vital: VitalField,
    value: number | string | null | undefined,
  ): "warning" | "critical" | null {
    if (isBlank(value)) return null;
    if (typeof value !== "number") value = Number(value);
    if (Number.isNaN(value)) return null;
    switch (vital) {
      case "temperature":
        if (value < 35 || value > 38.5) return "critical";
        if (value < 36.1 || value > 37.2) return "warning";
        return null;
      case "heartRate":
        if (value < 40 || value > 130) return "critical";
        if (value < 60 || value > 100) return "warning";
        return null;
      case "respiratoryRate":
        if (value < 8 || value > 30) return "critical";
        if (value < 12 || value > 20) return "warning";
        return null;
      case "sbp":
        if (value < 80 || value > 180) return "critical";
        if (value < 90 || value > 120) return "warning";
        return null;
      case "dbp":
        if (value < 50 || value > 110) return "critical";
        if (value < 60 || value > 80) return "warning";
        return null;
      case "spo2":
        if (value < 90) return "critical";
        if (value < 95) return "warning";
        return null;
      case "height":
        // Height is a measurement with no clinical out-of-range flag.
        return null;
      case "painScore":
        // 0-10 numeric rating scale: mild 1-3, moderate 4-6, severe 7-10.
        if (value >= 7) return "critical";
        if (value >= 4) return "warning";
        return null;
      default:
        return null;
    }
  }

  const isSavingVitals = ref(false);

  async function saveVitals() {
    const patientId = options.patientId();
    if (!patientId) return;
    isSavingVitals.value = true;
    const saved = await vitalsStore.recordVitals({
      patientId,
      ...(!isBlank(vitalForm.value.temperature) ? { temperatureC: vitalForm.value.temperature as number } : {}),
      ...(!isBlank(vitalForm.value.heartRate) ? { heartRateBpm: vitalForm.value.heartRate as number } : {}),
      ...(!isBlank(vitalForm.value.respiratoryRate) ? { respiratoryRateBpm: vitalForm.value.respiratoryRate as number } : {}),
      ...(!isBlank(vitalForm.value.sbp) ? { systolicBpMmhg: vitalForm.value.sbp as number } : {}),
      ...(!isBlank(vitalForm.value.dbp) ? { diastolicBpMmhg: vitalForm.value.dbp as number } : {}),
      ...(!isBlank(vitalForm.value.spo2) ? { oxygenSaturationPct: vitalForm.value.spo2 as number } : {}),
      ...(!isBlank(vitalForm.value.weight) ? { weightKg: vitalForm.value.weight as number } : {}),
      ...(!isBlank(vitalForm.value.height) ? { heightCm: vitalForm.value.height as number } : {}),
      ...(computedBmi.value != null ? { bmi: computedBmi.value } : {}),
      ...(!isBlank(vitalForm.value.painScore) ? { painScore: vitalForm.value.painScore as number } : {}),
      ...(selectedDepartmentId.value ? { departmentId: selectedDepartmentId.value } : {}),
    });
    isSavingVitals.value = false;

    if (!saved) {
      toast.critical(t("nursing.vitals_save_failed"));
      return;
    }

    // Re-fetch rather than locally append — pulls back the actual persisted
    // record instead of assuming `vitalForm`'s values round-tripped exactly.
    await vitalsStore.fetchLatest(saved.patientId);

    // Reset input form
    vitalForm.value = {
      temperature: null,
      heartRate: null,
      respiratoryRate: null,
      sbp: null,
      dbp: null,
      spo2: null,
      weight: null,
      height: null,
      painScore: null,
    };

    toast.success(t("nursing.vitals_saved"));
    options.onSaved?.();
  }

  /**
   * Load the latest recorded vitals for a patient (Volume 3.8 Phase 2). Wired
   * by the parent on patient selection, so the Recent-vitals view reflects the
   * currently open patient rather than whatever was last fetched.
   */
  function loadLatest(patientId: string) {
    void vitalsStore.fetchLatest(patientId);
  }

  return {
    vitalForm,
    computedBmi,
    vitalFlag,
    isSavingVitals,
    saveVitals,
    departmentOptions,
    selectedDepartmentId,
    isLoadingDepartments,
    loadDepartmentOptions,
    loadLatest,
    latest: computed(() => vitalsStore.latest),
    isLoading: computed(() => vitalsStore.isLoading),
  };
}

export type UseVitals = ReturnType<typeof useVitals>;
