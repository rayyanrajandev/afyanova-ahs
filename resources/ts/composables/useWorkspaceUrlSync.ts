/**
 * useWorkspaceUrlSync — Enterprise Vue 3 URL & Session State Persistence
 * =====================================================================
 * Synchronizes workspace reactive state (selected patient, encounter, context tab, chart tab)
 * with URL search parameters (`?patient=...&encounter=...&tab=...&chartTab=...`).
 *
 * Benefits:
 * - Full page refresh (F5) restores selected patient, encounter, and active tabs
 * - Deep linking & bookmarking works out of the box
 * - Preserves browser history without page reloads (via replaceState)
 */

import { onMounted, watch, type Ref } from "vue";

export interface WorkspaceUrlSyncOptions {
  activeTab?: Ref<string>;
  activeChartTab?: Ref<string>;
  selectedPatientId?: Ref<string | null | undefined>;
  selectedEncounterId?: Ref<string | null | undefined>;
  onHydratePatient?: (patientId: string, encounterId?: string | null) => void | Promise<void>;
  onHydrateTab?: (tab: string) => void;
  onHydrateChartTab?: (chartTab: string) => void;
}

export function useWorkspaceUrlSync(options: WorkspaceUrlSyncOptions) {
  function getUrlParams(): URLSearchParams {
    return new URLSearchParams(window.location.search);
  }

  function updateUrlParam(key: string, value: string | null) {
    const url = new URL(window.location.href);
    if (value) {
      url.searchParams.set(key, value);
    } else {
      url.searchParams.delete(key);
    }
    window.history.replaceState(window.history.state, "", url.toString());
  }

  // Auto-hydration on mount
  onMounted(async () => {
    const params = getUrlParams();
    const tabParam = params.get("tab");
    const chartTabParam = params.get("chartTab");
    const patientParam = params.get("patient") || params.get("patientId");
    const encounterParam = params.get("encounter") || params.get("encounterId");

    if (tabParam && options.onHydrateTab) {
      options.onHydrateTab(tabParam);
    } else if (tabParam && options.activeTab) {
      options.activeTab.value = tabParam;
    }

    if (chartTabParam && options.onHydrateChartTab) {
      options.onHydrateChartTab(chartTabParam);
    } else if (chartTabParam && options.activeChartTab) {
      options.activeChartTab.value = chartTabParam;
    }

    if (patientParam && options.onHydratePatient) {
      await options.onHydratePatient(patientParam, encounterParam);
    }
  });

  /**
   * Drops the patient/encounter params from the URL.
   *
   * Needed because the watchers below only fire on a *change*: when hydration
   * discovers the linked patient no longer exists, the workspace's selection
   * was already null, so nothing changes and the dead id would otherwise sit
   * in the address bar and be retried on every reload.
   */
  function clearPatientSelectionFromUrl() {
    updateUrlParam("patient", null);
    updateUrlParam("patientId", null);
    updateUrlParam("encounter", null);
    updateUrlParam("encounterId", null);
  }

  // Watch activeTab changes -> update URL
  if (options.activeTab) {
    watch(options.activeTab, (newTab) => {
      if (newTab) {
        updateUrlParam("tab", newTab);
      }
    });
  }

  // Watch activeChartTab changes -> update URL
  if (options.activeChartTab) {
    watch(options.activeChartTab, (newChartTab) => {
      if (newChartTab) {
        updateUrlParam("chartTab", newChartTab);
      }
    });
  }

  // Watch selectedPatientId changes -> update URL
  if (options.selectedPatientId) {
    watch(options.selectedPatientId, (newId) => {
      updateUrlParam("patient", newId ?? null);
    });
  }

  // Watch selectedEncounterId changes -> update URL
  if (options.selectedEncounterId) {
    watch(options.selectedEncounterId, (newEncId) => {
      updateUrlParam("encounter", newEncId ?? null);
    });
  }

  return {
    getUrlParams,
    updateUrlParam,
    clearPatientSelectionFromUrl,
  };
}
