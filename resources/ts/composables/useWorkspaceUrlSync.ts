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

/**
 * Any other named selection a workspace wants restored on refresh. The key
 * becomes the query parameter.
 *
 * `isValid` is not optional politeness: the URL is user-editable, and without a
 * guard a hand-typed value would be written straight into the ref and render a
 * view the workspace has no branch for.
 */
export interface SyncedWorkspaceParam {
  ref: Ref<string>;
  isValid?: (value: string) => boolean;
}

export interface WorkspaceUrlSyncOptions {
  activeTab?: Ref<string>;
  activeChartTab?: Ref<string>;
  params?: Record<string, SyncedWorkspaceParam>;
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

  /**
   * The address bar as it was when this workspace opened, captured during setup
   * rather than read again in onMounted.
   *
   * The watchers below now write on registration so the URL reflects state from
   * the first render. Those writes run *before* onMounted, so anything that read
   * `window.location` at hydration time would be reading values the watchers had
   * just overwritten — the deep link would restore whatever the defaults happened
   * to be instead of what the link actually said.
   */
  const initialParams = getUrlParams();

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
    const params = initialParams;
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

    for (const [key, param] of Object.entries(options.params ?? {})) {
      const value = params.get(key);
      if (value && (param.isValid?.(value) ?? true)) {
        param.ref.value = value;
      }
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

  // Tab -> URL, immediately and on every change.
  //
  // These fired only on *change*, so a workspace restored from localStorage sat
  // on an internal tab the address bar knew nothing about: refreshing fell back
  // to whatever localStorage held, and copying the link handed someone else a
  // different view than the one on screen. Writing immediately keeps the URL a
  // faithful record of what is open.
  //
  // Safe to run before hydration because the deep link was already captured in
  // `initialParams`.
  if (options.activeTab) {
    watch(
      options.activeTab,
      (newTab) => {
        if (newTab) {
          updateUrlParam("tab", newTab);
        }
      },
      { immediate: true },
    );
  }

  if (options.activeChartTab) {
    watch(
      options.activeChartTab,
      (newChartTab) => {
        if (newChartTab) {
          updateUrlParam("chartTab", newChartTab);
        }
      },
      { immediate: true },
    );
  }

  for (const [key, param] of Object.entries(options.params ?? {})) {
    watch(
      param.ref,
      (value) => {
        if (value) {
          updateUrlParam(key, value);
        }
      },
      { immediate: true },
    );
  }

  // Deliberately not immediate: selection is null during setup, so writing now
  // would delete the patient/encounter ids before onHydratePatient restores them.
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
