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
}

export function useReceptionLiveSync(options: UseReceptionLiveSyncOptions) {
  const page = usePage();
  // Shared on every Inertia response (HandleInertiaRequests::share()),
  // present synchronously in the initial page object — not an async
  // fetch — so this is already resolved by the time Index.vue's setup()
  // reaches this call, same timing guarantee AppShell.vue's own
  // `page.props.auth` read relies on.
  const platform = page.props.platform as PlatformScopeProp | undefined;
  const facilityId = platform?.scope?.facility?.id ?? null;

  // No facility scope (e.g. a platform-superadmin session with none
  // selected) — Reception itself has nothing facility-scoped to show in
  // that case either, so there's nothing to subscribe to. Skipping the
  // useEcho() call entirely is safe here: unlike React, Vue's Composition
  // API has no fixed hook-call-order requirement (reactivity is tracked
  // via the active component instance, not a call-index array), so an
  // early return before a composable that itself uses onMounted/onUnmounted
  // internally is not a rules-of-hooks violation.
  if (!facilityId) return;

  // Leading dot on the event name (bug found + fixed 2026-08-11, confirmed
  // live via a raw WebSocket-frame capture): PatientFlowBoardUpdated uses
  // broadcastAs() to send the bare name "board.updated" over the wire, no
  // "App.Events." namespace prefix at all — but Echo's default event
  // formatter auto-prefixes any listener event name that doesn't start
  // with "." (Laravel's own documented convention for custom
  // broadcastAs() names: https://laravel.com/docs/broadcasting#listening-for-events).
  // Without the dot this silently bound to "App\Events\board\updated",
  // which the incoming frame's literal "board.updated" never matched —
  // no error, no console warning, just a subscription that looked
  // successful (pusher_internal:subscription_succeeded fired) but never
  // called this callback.
  useEcho(`patient-flow.${facilityId}`, ".board.updated", () => {
    options.onBoardUpdated();
  });

  // Reception's own reception-queue.{facilityId} channel (not patient-flow
  // — Call is Reception-only signaling with its own content-bearing
  // payload, see AppointmentCalled's docblock for why it isn't reused).
  useEcho(
    `reception-queue.${facilityId}`,
    ".queue.appointment-called",
    (payload: AppointmentCalledPayload) => {
      options.onPatientCalled(payload);
    },
  );
}
