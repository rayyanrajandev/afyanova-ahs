/**
 * Nursing patient list + selection (Volume 2.3 §4.1, Volume 3.8 Phase 1)
 * =========================================================================
 * Extracted from nursing/Index.vue (2026-08-13, component decomposition —
 * Reception-style separation of concerns). Owns the Patients context-pane
 * tab's list, its search box, the selected patient, the open-encounter
 * context, and the per-patient allergy summary.
 *
 * It is intentionally the nursing-scoped counterpart to Reception's own
 * `usePatientSearch` — this works off `patientStore.fetchNursingPatients`
 * / `nursingPatients`, not Reception's `searchResults`/`fetchPatients`; see
 * `patientStore.ts`'s own docblock on those two for why they're kept
 * separate.
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePatientStore, type Patient, type PatientAllergySummary } from "@/stores/patientStore";
import { useRecentStore } from "@/stores/recentStore";
import type { ReadinessContext, VisitContext } from "@/stores/queueStore";

export interface UseNursingPatientListOptions {
  /**
   * Called when a patient is selected or deselected so the caller can react
   * to context changes (e.g. clear the open form, reset the split ratio).
   */
  onSelectionChange?: (patient: Patient | null) => void;
}

export function useNursingPatientList(options: UseNursingPatientListOptions = {}) {
  const { t, locale } = useI18n({ useScope: "global" });
  const patientStore = usePatientStore();
  const recentStore = useRecentStore();

  const patients = computed(() => patientStore.nursingPatients);
  const isLoading = computed(() => patientStore.isNursingPatientsLoading);
  const error = computed(() => patientStore.nursingPatientsError);

  async function loadNursingPatients(query?: string) {
    const list = await patientStore.fetchNursingPatients(query);
    if (!query?.trim()) {
      if (list.length === 0) {
        recentStore.clearRecent();
      } else {
        recentStore.reconcile(list.map((p) => p.id));
      }
    }
    return list;
  }

  void loadNursingPatients();

  function patientDisplayName(patient: Patient): string {
    return `${patient.name[0]?.given?.join(" ") ?? ""} ${patient.name[0]?.family ?? ""}`.trim();
  }

  function patientInitials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? "")
      .join("");
  }

  // Search — same 200ms-debounce pattern as Reception's usePatientSearch,
  // reusing the same `?q=` filter the patient-list endpoint already supports.
  const patientSearchQuery = ref("");
  let patientSearchDebounce: ReturnType<typeof setTimeout> | undefined;
  function onPatientSearchInput() {
    if (patientSearchDebounce) clearTimeout(patientSearchDebounce);
    patientSearchDebounce = setTimeout(() => {
      void loadNursingPatients(patientSearchQuery.value);
    }, 200);
  }

  const patientColumns = computed<DataTableColumn<Patient>[]>(() => {
    void locale.value;
    return [
      {
        key: "name",
        label: t("patient.name"),
        accessor: (r) => patientDisplayName(r),
        slot: "patient-name",
      sticky: true,
    },
    {
      key: "mrn",
      label: t("patient.mrn"),
      accessor: (r) => r.identifier[0]?.value ?? "",
      clinical: true,
    },
    {
      key: "phone",
      label: t("patient.phone"),
      accessor: (r) => r.telecom?.find((item) => item.system === "phone")?.value ?? "—",
      clinical: true,
    },
    { key: "age", label: t("patient.age"), accessor: (r) => r.meta.extension.age },
    { key: "sex", label: t("patient.sex"), accessor: (r) => t(`patient.gender_${r.gender}`) },
  ];
});

  const selectedPatient = ref<Patient | null>(null);

  // Real open-encounter context (Volume 3.8 Phase 2.5 safety fix, 2026-08-13):
  // selecting a patient from the Patients tab (a general, unscoped lookup) is
  // not the same as that patient having an active nursing encounter. Only set
  // when a patient is opened via the Tasks tab, whose items ARE real open
  // encounters (`NurseQueueController::index`, `QueueTask.id` is the encounter
  // id). `null` when selected via the Patients-tab lookup.
  const selectedEncounterId = ref<string | null>(null);

  // The visit journey context of the open encounter (arrival mode, stage,
  // visit category, ...), surfaced 2026-08-14 so the patient header can show
  // e.g. "Walk-in OPD · In Triage". `null` when selected via the Patients tab
  // or when the encounter carries no appointment context.
  const selectedVisit = ref<VisitContext | null>(null);

  // Reception-to-nursing administrative readiness context (2026-08-14),
  // e.g. insurance verification status, coverage type, verification notes.
  const selectedReadiness = ref<ReadinessContext | null>(null);

  const selectedPatientAllergies = ref<PatientAllergySummary[]>([]);
  const isLoadingAllergies = ref(false);

  function selectPatient(
    patient: Patient,
    encounterId: string | null = null,
    visit: VisitContext | null = null,
    readiness: ReadinessContext | null = null
  ) {
    patientStore.cachePatient(patient);
    patientStore.setCurrentPatient(patient.id);
    selectedPatient.value = patient;
    selectedEncounterId.value = encounterId;
    selectedVisit.value = visit;
    selectedReadiness.value = readiness;
    selectedPatientAllergies.value = [];
    isLoadingAllergies.value = true;
    recentStore.addRecent(patient);
    void patientStore.fetchPatientSummary(patient.id).then((summary) => {
      if (selectedPatient.value?.id !== patient.id) return;
      selectedPatientAllergies.value = summary?.alerts ?? [];
      isLoadingAllergies.value = false;
    });

    // Auto-resolve active visit context & readiness when selected from the Patients tab (encounterId === null)
    if (!encounterId) {
      void fetch(`/api/v1/nursing/active-visit/${encodeURIComponent(patient.id)}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      })
        .then((res) => (res.ok ? (res.json() as Promise<{ data?: { encounterId: string; visit: VisitContext; readiness?: ReadinessContext } | null }>) : null))
        .then((body) => {
          if (selectedPatient.value?.id !== patient.id) return;
          if (body?.data) {
            selectedEncounterId.value = body.data.encounterId;
            selectedVisit.value = body.data.visit;
            if (body.data.readiness) {
              selectedReadiness.value = body.data.readiness;
            }
          }
        })
        .catch(() => null);
    }

    options.onSelectionChange?.(patient);
  }

  function deselectPatient() {
    patientStore.clearCurrentPatient();
    selectedPatient.value = null;
    selectedEncounterId.value = null;
    selectedVisit.value = null;
    selectedReadiness.value = null;
    options.onSelectionChange?.(null);
  }

  function refreshVisitContext(patientId: string) {
    void fetch(`/api/v1/nursing/active-visit/${encodeURIComponent(patientId)}`, {
      headers: { "X-Requested-With": "XMLHttpRequest" },
    })
      .then((res) => (res.ok ? (res.json() as Promise<{ data?: { encounterId: string; visit: VisitContext; readiness?: ReadinessContext } | null }>) : null))
      .then((body) => {
        if (selectedPatient.value?.id !== patientId) return;
        if (body?.data) {
          selectedEncounterId.value = body.data.encounterId;
          selectedVisit.value = body.data.visit;
          if (body.data.readiness) {
            selectedReadiness.value = body.data.readiness;
          }
        }
      })
      .catch(() => null);
  }

  function openRecentPatient(id: string) {
    const p = patientStore.nursingPatients.find((item) => item.id === id) || patientStore.patients.get(id);
    if (p) {
      selectPatient(p);
    } else {
      void patientStore.fetchPatient(id).then((fetched) => {
        if (fetched) {
          selectPatient(fetched);
        } else {
          recentStore.removeRecent(id);
        }
      });
    }
  }

  function togglePin(id: string) {
    if (recentStore.isPinned(id)) {
      recentStore.unpin(id);
    } else {
      recentStore.pin(id);
    }
  }

  return {
    patients,
    isLoading,
    error,
    patientSearchQuery,
    onPatientSearchInput,
    patientColumns,
    patientDisplayName,
    patientInitials,
    selectedPatient,
    selectedEncounterId,
    selectedVisit,
    selectedReadiness,
    selectedPatientAllergies,
    isLoadingAllergies,
    recentItems: computed(() => recentStore.items),
    openRecentPatient,
    togglePin,
    selectPatient,
    deselectPatient,
    refreshVisitContext,
  };
}

export type UseNursingPatientList = ReturnType<typeof useNursingPatientList>;
