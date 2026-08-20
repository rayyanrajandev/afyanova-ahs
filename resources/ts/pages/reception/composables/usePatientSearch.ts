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
  gender: string;
  phone: string;
  nationalId?: string | null;
  /** Registration timestamp, used to build the "Recently Registered" list. */
  registeredAt: string | null;
}

export interface UsePatientSearchOptions {
  workspace?: "reception" | "clinician";
}

export function usePatientSearch(options: UsePatientSearchOptions = {}) {
  const { t, locale } = useI18n({ useScope: "global" });
  const patientStore = usePatientStore();
  const recentStore = useRecentStore();

  const workspace = options.workspace ?? "reception";

  // ---- Search (Volume 2.1 §7.2 — debounced 200ms, Volume 1.3 §6.3) ----
  const searchQuery = ref("");
  const searchResults = computed(() => patientStore.searchResults);
  const isSearching = computed(() => patientStore.isLoading);
  const searchError = computed(() => patientStore.error);
  // Patients tab's count badge (2026-08-11) — tracks the current view like
  // Queue/Appointments' own counts do: total registered patients when the
  // search box is empty, matching results while filtering.
  const totalPatients = computed(() => patientStore.totalPatientCount);

  // Drives the "which row is currently open" highlight in PatientSearchPanel
  // (and its Recent patients list) — fast-glance confirmation of which
  // patient is on screen, since the panel doesn't otherwise touch
  // patientStore directly (see this file's own docblock).
  const currentPatientId = computed(() => patientStore.currentPatientId);

  let debounceTimer: ReturnType<typeof setTimeout> | undefined;

  function onSearchInput() {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => handleSearch(), 200);
  }

  async function handleSearch() {
    const fetcher = workspace === "clinician"
      ? (q?: string) => patientStore.fetchClinicianPatients(q)
      : (q?: string) => patientStore.fetchPatients(q);

    if (searchQuery.value.trim()) {
      await fetcher(searchQuery.value);
    } else {
      const loaded = await fetcher();
      // Recents are localStorage-persisted; drop entries for patients that no
      // longer exist in the DB (e.g. deleted) so the list stays truthful.
      recentStore.reconcile(loaded.map((p) => p.id));
    }
  }

  // Load the initial patient list on mount
  void handleSearch();

  function handlePatientRetry() {
    handleSearch();
  }

  // ---- Patient list rendered as a DataTable (Volume 2.1 §4.1, §7.2 / Volume 1.2 §6)
  const patientRows = computed<PatientListRow[]>(() =>
    searchResults.value.map((patient) => {
      const phoneObj = patient.telecom?.find((t) => t.system === "phone");
      return {
        id: patient.id,
        name: `${patient.name[0]?.given?.join(" ") ?? ""} ${patient.name[0]?.family ?? ""}`.trim(),
        mrn: patient.identifier[0]?.value ?? "",
        age: patient.meta.extension.age,
        gender: patient.gender,
        phone: phoneObj?.value ?? "",
        nationalId: patient.nationalId ?? null,
        registeredAt: patient.createdAt ?? null,
      };
    }),
  );

  // computed column definitions
  const patientColumns = computed<DataTableColumn<PatientListRow>[]>(() => {
    void locale.value;
    return [
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
        key: "phone",
        label: t("patient.phone"),
        accessor: (r) => r.phone || "—",
        clinical: true,
      },
      {
        key: "age",
        label: t("patient.age"),
        accessor: (r) => `${r.age}`,
      },
      {
        key: "sex",
        label: t("patient.sex"),
        accessor: (r) => t(`patient.gender_${r.gender}`),
      },
    ];
  });

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

  // ---- Recently Registered (Volume 2.1 §4.1 / §7.2, 2026-08-20) ----
  // The Reception quick-bar shows the patients most recently *registered*,
  // ordered by the backend's created_at (newest first) — not the per-user
  // localStorage "last accessed" history. This is derived from the loaded
  // patient rows so it always reflects the live registration data and
  // refreshes with the list.
  const RECENT_REGISTERED_LIMIT = 8;

  const recentlyRegistered = computed<PatientListRow[]>(() => {
    const withTime = patientRows.value
      .filter((row) => row.registeredAt)
      .map((row) => ({ row, time: new Date(row.registeredAt as string).getTime() }))
      .filter((entry) => !Number.isNaN(entry.time))
      .sort((a, b) => b.time - a.time);
    return withTime.slice(0, RECENT_REGISTERED_LIMIT).map((entry) => entry.row);
  });

  /** Open a patient from the "Recently Registered" list. */
  function openRegisteredPatient(id: string) {
    patientStore.setCurrentPatient(id);
    const patient = patientStore.patients.get(id);
    if (patient) recentStore.addRecent(patient);
  }

  /** Human-readable registration time, e.g. "just now", "12m ago", "09:30". */
  function formatRegisteredTime(iso: string | null | undefined): string {
    if (!iso) return "";
    const time = new Date(iso).getTime();
    if (Number.isNaN(time)) return "";
    const diffMs = Date.now() - time;
    const minutes = Math.floor(diffMs / 60_000);
    if (minutes < 1) return t("recent.just_now", undefined, "just now");
    if (minutes < 60) return t("recent.minutes_ago", { n: minutes }, "{n}m ago");
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return t("recent.hours_ago", { n: hours }, "{n}h ago");
    return new Date(iso).toLocaleDateString(undefined, {
      day: "numeric",
      month: "short",
    });
  }

  return {
    searchQuery,
    isSearching,
    searchError,
    totalPatients,
    currentPatientId,
    onSearchInput,
    handleSearch,
    handlePatientRetry,
    patientRows,
    patientColumns,
    handlePatientRowClick,
    recentItems,
    openRecentPatient,
    togglePin,
    recentlyRegistered,
    openRegisteredPatient,
    formatRegisteredTime,
  };
}
