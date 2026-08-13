/**
 * Patient search + recent patients (Volume 2.1 §7.2, Volume 1.3 §6.3/§9.1,
 * Volume 1.2 §6, Volume 3.7 T2.8)
 * =========================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit)
 * — pure extraction, no behavior change.
 *
 * Owns the "Patients" context-pane tab: the debounced search box, the
 * results DataTable, and the recent/pinned patients quick-list above it —
 * all three are one cohesive tab, not three separate features.
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import type { DataTableColumn } from "@/components/common/DataTable.vue";
import { usePatientStore } from "@/stores/patientStore";
import { useRecentStore } from "@/stores/recentStore";

interface PatientListRow {
  id: string;
  name: string;
  mrn: string;
  age: number;
}

export function usePatientSearch() {
  const { t } = useI18n();
  const patientStore = usePatientStore();
  const recentStore = useRecentStore();

  // ---- Search (Volume 2.1 §7.2 — debounced 200ms, Volume 1.3 §6.3) ----
  const searchQuery = ref("");
  const searchResults = computed(() => patientStore.searchResults);
  const isSearching = computed(() => patientStore.isLoading);
  const searchError = computed(() => patientStore.error);
  // Patients tab's count badge (2026-08-11) — tracks the current view like
  // Queue/Appointments' own counts do: total registered patients when the
  // search box is empty, matching results while filtering.
  const totalPatients = computed(() => patientStore.totalPatientCount);

  let debounceTimer: ReturnType<typeof setTimeout> | undefined;

  function onSearchInput() {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => handleSearch(), 200);
  }

  async function handleSearch() {
    if (searchQuery.value.trim()) {
      await patientStore.fetchPatients(searchQuery.value);
    } else {
      const loaded = await patientStore.fetchPatients();
      // Recents are localStorage-persisted; drop entries for patients that no
      // longer exist in the DB (e.g. deleted) so the list stays truthful.
      recentStore.reconcile(loaded.map((p) => p.id));
    }
  }

  // Load the initial patient list on mount (Volume 2.1 §12.2: GET /api/v1/reception/patients)
  void handleSearch();

  function handlePatientRetry() {
    handleSearch();
  }

  // ---- Patient list rendered as a DataTable (Volume 2.1 §4.1, §7.2 / Volume 1.2 §6)
  const patientRows = computed<PatientListRow[]>(() =>
    searchResults.value.map((patient) => ({
      id: patient.id,
      name: `${patient.name[0]?.given?.join(" ") ?? ""} ${patient.name[0]?.family ?? ""}`.trim(),
      mrn: patient.identifier[0]?.value ?? "",
      age: patient.meta.extension.age,
    })),
  );

  // computed, not a plain array (bug fixed 2026-08-11): `t("patient.name")`
  // etc. were being called once, here, at composable-setup time, baking
  // that moment's locale's strings into the array permanently — switching
  // locale later (setLocale()) never touched this array again, so the
  // DataTable headers stayed frozen in whichever language was active on
  // first load while every other label on the page correctly followed the
  // switch (they call t() live, in their own templates, on every render).
  // Wrapping in computed() re-derives `label` whenever vue-i18n's reactive
  // locale ref changes, since t() reads it internally.
  const patientColumns = computed<DataTableColumn<PatientListRow>[]>(() => [
    {
      key: "name",
      label: t("patient.name"),
      accessor: (r) => r.name,
      slot: "patient-name",
      sticky: true,
    },
    {
      key: "mrn",
      label: t("patient.mrn"),
      accessor: (r) => r.mrn,
      clinical: true,
    },
    {
      key: "age",
      label: t("patient.age"),
      accessor: (r) => `${r.age}y`,
    },
  ]);

  function handlePatientRowClick(row: PatientListRow) {
    patientStore.setCurrentPatient(row.id);
    const patient = patientStore.patients.get(row.id);
    if (patient) recentStore.addRecent(patient);
  }

  // ---- Recent patients (Volume 1.3 §9.1 — Volume 3.7 T2.8) ----
  const recentItems = computed(() => recentStore.items);

  /** Open a patient from the recent list (Volume 1.3 §9.1 — T2.8). */
  function openRecentPatient(id: string) {
    patientStore.setCurrentPatient(id);
  }

  function togglePin(patientId: string) {
    if (recentStore.isPinned(patientId)) {
      recentStore.unpin(patientId);
    } else {
      recentStore.pin(patientId);
    }
  }

  return {
    searchQuery,
    isSearching,
    searchError,
    totalPatients,
    onSearchInput,
    handleSearch,
    handlePatientRetry,
    patientRows,
    patientColumns,
    handlePatientRowClick,
    recentItems,
    openRecentPatient,
    togglePin,
  };
}
