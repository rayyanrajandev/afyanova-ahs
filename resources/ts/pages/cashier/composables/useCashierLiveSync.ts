/**
 * useCashierLiveSync — the counter, kept current
 * ===============================================
 * Two cashiers work two tills against one queue. Without this, each only
 * learns the other served someone by trying to serve them too — and the second
 * one finds out by being refused, in front of the patient.
 *
 * Same contract as usePatientFlowLiveSync: the broadcast is a trigger, never a
 * data source. It carries only the facility, so a listener refetches rather
 * than patching in a payload that could already disagree with the ledger.
 *
 * Silent when Reverb is not configured. A facility running without websockets
 * still has a working counter — it just refreshes on action instead.
 */

import { usePage } from "@inertiajs/vue3";
import { useEcho } from "@laravel/echo-vue";

interface PlatformScopeProp {
  scope?: {
    facility?: { id: string | null } | null;
  } | null;
}

export interface UseCashierLiveSyncOptions {
  /** Refetch whatever the counter currently has loaded. */
  onQueueUpdated: () => void;
}

export function useCashierLiveSync(options: UseCashierLiveSyncOptions): void {
  const page = usePage();
  const platform = page.props.platform as PlatformScopeProp | undefined;
  const facilityId = platform?.scope?.facility?.id ?? null;

  if (!import.meta.env.VITE_REVERB_APP_KEY) return;
  if (!facilityId) return;

  try {
    useEcho(`cashier-queue.${facilityId}`, ".queue.updated", () => {
      options.onQueueUpdated();
    });
  } catch (e) {
    console.warn("Echo subscription skipped:", e);
  }
}
