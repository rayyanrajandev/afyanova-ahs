/**
 * Patient-flow live sync for any workspace (2026-08-16 flow audit, finding 03)
 * ===========================================================================
 * The board channel, its authorizer and the single `board.updated` event have
 * existed since Phase 2 and are correct. Only Reception ever subscribed
 * (useReceptionLiveSync.ts), so a transition performed anywhere — including a
 * doctor starting a consultation — never reached the clinician or nursing
 * workspaces. Those two screens are exactly where "who is with a patient right
 * now" matters most, and they were the two that never found out.
 *
 * This is the workspace-agnostic half of useReceptionLiveSync: same channel,
 * same event, same "the payload is a trigger, never a data source" rule (see
 * PatientFlowBoardUpdated's own docblock for why the event carries only
 * facilityId). Reception keeps its own composable because it also listens for
 * two Reception-specific broadcasts — the Call announcement and the
 * return-to-reception hand-back — which have no meaning in the other
 * workspaces.
 */

import { usePage } from "@inertiajs/vue3";
import { useEcho } from "@laravel/echo-vue";

interface PlatformScopeProp {
  scope?: {
    facility?: { id: string | null } | null;
  } | null;
}

export interface UsePatientFlowLiveSyncOptions {
  /**
   * Called whenever anything on the facility's patient-flow board changes —
   * refetch whatever this workspace currently has loaded. Deliberately a
   * refetch callback rather than a payload handler: the broadcast carries no
   * board data, so treating it as one would reintroduce the second,
   * potentially-drifting copy the board's design avoids.
   */
  onBoardUpdated: () => void;
}

export function usePatientFlowLiveSync(options: UsePatientFlowLiveSyncOptions) {
  const page = usePage();
  const platform = page.props.platform as PlatformScopeProp | undefined;
  const facilityId = platform?.scope?.facility?.id ?? null;

  // No facility in scope (platform-admin context, or an unmapped session) means
  // there is no facility board to listen to — not an error, just nothing to do.
  if (!facilityId) return;

  useEcho(`patient-flow.${facilityId}`, ".board.updated", () => {
    options.onBoardUpdated();
  });
}
