import { usePage } from "@inertiajs/vue3";
import { useEcho } from "@laravel/echo-vue";

interface PlatformScopeProp {
  scope?: {
    facility?: { id: string | null } | null;
  } | null;
}

export interface UseLaboratoryLiveSyncOptions {
  /** Refetch active laboratory worklist when queue updates. */
  onQueueUpdated: () => void;
}

export function useLaboratoryLiveSync(options: UseLaboratoryLiveSyncOptions): void {
  const page = usePage();
  const platform = page.props.platform as PlatformScopeProp | undefined;
  const facilityId = platform?.scope?.facility?.id ?? null;

  if (!import.meta.env.VITE_REVERB_APP_KEY) return;
  if (!facilityId) return;

  try {
    useEcho(`laboratory-queue.${facilityId}`, ".queue.updated", () => {
      options.onQueueUpdated();
    });
  } catch (e) {
    console.warn("Echo subscription skipped:", e);
  }
}
