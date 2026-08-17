/**
 * Reception live queue/appointments sync (Volume 2.1 §10.4)
 * ==============================================================
 * Real-time counterpart to the manual refresh wiring already added to
 * useQueueActions.ts/useAppointmentScheduling.ts (2026-08-11 bug fixes) —
 * those made a check-in/cancel refresh everything *that action itself*
 * touches in *this* browser tab. This closes the remaining gap: a
 * check-in, cancel, or order completion performed from a DIFFERENT
 * session — another receptionist, or another workspace entirely (nursing/
 * lab/pharmacy/radiology all funnel into the same board event, see
 * BroadcastPatientFlowBoardUpdate.php) — never reached this tab without a
 * manual tab reopen or full page reload.
 *
 * Subscribes to the same `patient-flow.{facilityId}` channel / `board.updated`
 * event the Patient-Flow Board already broadcasts on — deliberately not a
 * new, Reception-specific channel. Same trigger events
 * (AppointmentCheckedIn/AppointmentStatusChanged are Reception's own use
 * cases), same authorization (`appointments.read`, which Reception's own
 * queue already requires just to view this workspace) — a parallel channel
 * would just be two broadcasts for the same fact. The event payload is
 * deliberately just facilityId (see PatientFlowBoardUpdated's own
 * docblock); this composable's only job on receipt is invalidate + refetch,
 * the same "never trust a pushed payload as the source of truth" rule the
 * board itself follows — `onBoardUpdated` is the caller's refetch, not a
 * data source.
 */

import { usePage } from "@inertiajs/vue3";
import { useEcho } from "@laravel/echo-vue";

interface PlatformScopeProp {
  scope?: {
    facility?: { id: string | null } | null;
  } | null;
}

interface AppointmentCalledPayload {
  appointmentId: string;
  patientName: string;
}

export interface UseReceptionLiveSyncOptions {
  /** Called whenever the facility's patient-flow board changes — refetch whatever's currently loaded/open. */
  onBoardUpdated: () => void;
  /**
   * Called when ANY session at this facility calls a patient forward
   * (§10.3 "Call", §16 #3 — see AppointmentCalled's own docblock for why
   * this is an ephemeral broadcast, not a persisted status). Unlike
   * onBoardUpdated, the payload here IS the message, not a refetch
   * trigger — there is nothing else to fetch for an ephemeral event.
   * Fires in the *same* tab that triggered the call too (this composable
   * doesn't know or care who triggered it) — deliberately not
   * short-circuited locally, so the on-screen announcement always comes
   * from the one real broadcast path rather than a separate "it was me"
   * special case that could drift from it.
   */
  onPatientCalled: (payload: AppointmentCalledPayload) => void;
  /** Called when Nursing returns a patient to Reception for administrative verification. */
  onPatientReturned?: (payload: { appointmentId: string; patientId?: string; patientName: string; reason: string }) => void;
}

export function useReceptionLiveSync(options: UseReceptionLiveSyncOptions) {
  const page = usePage();
  const platform = page.props.platform as PlatformScopeProp | undefined;
  const facilityId = platform?.scope?.facility?.id ?? null;

  if (!import.meta.env.VITE_REVERB_APP_KEY) return;
  if (!facilityId) return;

  try {
    useEcho(`patient-flow.${facilityId}`, ".board.updated", () => {
      options.onBoardUpdated();
    });

    useEcho(
      `patient-flow.${facilityId}`,
      ".patient.returned",
      (payload: { appointmentId: string; patientId?: string; patientName: string; reason: string }) => {
        options.onBoardUpdated();
        options.onPatientReturned?.(payload);
      },
    );

    useEcho(
      `reception-queue.${facilityId}`,
      ".queue.appointment-called",
      (payload: AppointmentCalledPayload) => {
        options.onPatientCalled(payload);
      },
    );
  } catch (e) {
    console.warn("Echo subscription skipped:", e);
  }
}
