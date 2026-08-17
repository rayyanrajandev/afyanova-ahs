<?php

namespace App\Modules\PatientFlow\Application\Services;

use App\Modules\PatientFlow\Domain\Events\PatientFlowBoardUpdated;
use App\Modules\PatientFlow\Domain\Repositories\PatientFlowEventRepositoryInterface;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The one door every patient-flow transition goes through.
 *
 * The audit behind this (2026-08-16) found appointments.status being written
 * from two places with different guarantees: UpdateAppointmentStatusUseCase
 * (transition-guarded, audit-logged, event-firing) and a raw
 * $appointment->update() in PatientVitalSetController that had none of those.
 * Downstream, the two were indistinguishable — because flow state was derived
 * at read time, an ungoverned write looked exactly like a governed one, and a
 * transition nobody wrote down left no trace at all.
 *
 * This service makes the transition itself the record. Callers state where the
 * patient is going; the log states, permanently, that they went there and who
 * moved them.
 *
 * Deliberately NOT responsible for:
 *
 *  - Writing appointments.status. That stays in UpdateAppointmentStatusUseCase,
 *    which owns the transition guard and the appointment audit log. This service
 *    records that a step change happened; it does not decide whether the status
 *    change was legal. Merging the two would put a status-machine concern inside
 *    a module that also serves service-request-only walk-ins with no appointment
 *    at all.
 *  - Throwing on an unrecordable transition. A visit that cannot be logged must
 *    not fail the clinical action that triggered it — a doctor starting a
 *    consultation must never see an error because the flow log rejected a write.
 *    Failures are logged and swallowed; see record()'s catch.
 */
class RecordPatientFlowTransitionService
{
    public function __construct(
        private readonly PatientFlowEventRepositoryInterface $repository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    /**
     * Appends one transition, unless it is a no-op.
     *
     * @param  PatientFlowStep  $toStep  Where the patient now is.
     * @param  string|null  $appointmentId  Appointment-based visits. At least one of this
     *   and $serviceRequestId must be present — a direct-service walk-in often has no
     *   appointment at all, so neither can be required alone.
     * @param  int|null  $actorId  Who moved them. Null only for system-driven transitions
     *   (an order completing, a scheduled job) — never for a staff action.
     * @param  string  $source  `module.action` provenance, e.g. `clinician.start_consultation`.
     *   Makes "which code path wrote this" answerable without correlating timestamps
     *   across the ~45 per-module audit tables.
     * @param  bool  $appointmentStatusAlsoChanged  True when the caller is also changing
     *   appointments.status, which fires AppointmentStatusChanged — already translated into
     *   PatientFlowBoardUpdated by BroadcastPatientFlowBoardUpdate. Passing true suppresses
     *   this service's own broadcast so the board is not refreshed twice for one action.
     *   Transitions that change no status (a triage or nursing pickup) leave it false and
     *   rely on this service to broadcast, which is why those steps were previously
     *   invisible to every other workspace.
     * @param  bool  $allowSameStep  Records the event even though it does not move the
     *   patient. Some things worth putting on a patient's timeline are not transitions —
     *   vitals being taken is real, dated, attributable work that leaves the visit exactly
     *   where it was. Default false, because the ordinary case is a transition and the
     *   no-op guard below is what keeps repeated status re-assertions out of the log.
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null The appended event, or null when nothing was recorded.
     */
    public function record(
        PatientFlowStep $toStep,
        string $patientId,
        ?string $appointmentId = null,
        ?string $serviceRequestId = null,
        ?string $encounterId = null,
        ?int $actorId = null,
        ?string $actorRole = null,
        string $source = 'unknown',
        ?string $reason = null,
        array $metadata = [],
        ?string $facilityId = null,
        ?PatientFlowStep $fromStep = null,
        bool $appointmentStatusAlsoChanged = false,
        bool $allowSameStep = false,
    ): ?array {
        $resolvedFacilityId = $facilityId ?? $this->platformScopeContext->facilityId();

        try {
            if ($appointmentId === null && $serviceRequestId === null) {
                throw new InvalidArgumentException(
                    'A patient-flow transition needs an appointment or a service request to belong to.',
                );
            }

            /**
             * Every database statement here runs inside a nested transaction —
             * a SAVEPOINT, since callers are normally already inside their own
             * DB::transaction.
             *
             * This is load-bearing, not defensive habit. On PostgreSQL a single
             * failed statement poisons the *entire* surrounding transaction:
             * every subsequent command dies with SQLSTATE 25P02 ("current
             * transaction is aborted") until it rolls back. Combined with this
             * method's catch-and-continue contract, an unwritable flow log would
             * silently take down the clinical action it was only ever meant to
             * observe — a walk-in check-in failing on its arrival_events insert
             * because a flow-event insert had already aborted the transaction
             * several statements earlier.
             *
             * The savepoint confines that blast radius: a failure here rolls
             * back only this append, and the caller's transaction stays healthy
             * and committable.
             */
            $event = DB::transaction(function () use (
                $toStep, $patientId, $appointmentId, $serviceRequestId, $encounterId,
                $actorId, $actorRole, $source, $reason, $metadata, $resolvedFacilityId, $fromStep,
                $allowSameStep,
            ): ?array {
                $resolvedFromStep = $fromStep ?? $this->resolveCurrentStep($appointmentId, $serviceRequestId);

                // A repeated write of the same step is not a transition. Without
                // this, every board refresh that re-asserts the current status
                // would append a row, and the timeline staff read would fill
                // with noise.
                if ($resolvedFromStep === $toStep && ! $allowSameStep) {
                    return null;
                }

                return $this->repository->append([
                    'tenant_id' => $this->platformScopeContext->tenantId(),
                    'facility_id' => $resolvedFacilityId,
                    'patient_id' => $patientId,
                    'appointment_id' => $appointmentId,
                    'service_request_id' => $serviceRequestId,
                    'encounter_id' => $encounterId ?? $this->resolveEncounterId($appointmentId),
                    'from_step' => $resolvedFromStep?->value,
                    'to_step' => $toStep->value,
                    'actor_user_id' => $actorId,
                    'actor_role' => $actorRole ?? $this->resolveActorRole($actorId),
                    'source' => $source,
                    'reason' => $reason,
                    'metadata' => $metadata === [] ? null : $metadata,
                    'occurred_at' => now(),
                ]);
            });

            if ($event === null) {
                return null;
            }

            if (! $appointmentStatusAlsoChanged && $resolvedFacilityId !== null) {
                DB::afterCommit(static function () use ($resolvedFacilityId): void {
                    event(new PatientFlowBoardUpdated($resolvedFacilityId));
                });
            }

            return $event;
        } catch (\Throwable $exception) {
            // See the class docblock: the flow log must never be the reason a
            // clinical action fails. A missing log entry is a reporting gap; a
            // failed start-consultation is a patient waiting in a corridor.
            //
            // Safe to swallow only because the savepoint above already rolled
            // this append back cleanly — without it, this catch would hide a
            // poisoned transaction and hand the caller a connection on which
            // every later statement is guaranteed to fail.
            Log::warning('Failed to record patient-flow transition', [
                'to_step' => $toStep->value,
                'patient_id' => $patientId,
                'appointment_id' => $appointmentId,
                'service_request_id' => $serviceRequestId,
                'source' => $source,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The role the actor was acting as, denormalised onto the event.
     *
     * The column and the API field existed from the start and nothing ever
     * populated them, so "who they were acting as" was permanently blank
     * (2026-08-16 activity audit). Resolved here, at the single write point,
     * rather than asked of every caller — a caller that forgets is exactly how
     * it ended up null everywhere.
     *
     * Denormalised on purpose: a staff member's role can change, and the log
     * must keep saying what they were when they acted. Memoised per request
     * because a busy triage round records several events for the same actor.
     *
     * @var array<int, string|null>
     */
    private array $actorRoleCache = [];

    private function resolveActorRole(?int $actorId): ?string
    {
        if ($actorId === null) {
            return null;
        }

        if (array_key_exists($actorId, $this->actorRoleCache)) {
            return $this->actorRoleCache[$actorId];
        }

        $role = null;

        try {
            $user = \App\Models\User::query()->find($actorId);
            $codes = $user !== null && method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
            $role = $codes[0] ?? null;
        } catch (\Throwable) {
            // Same contract as the rest of this service: never let logging
            // metadata be the reason a clinical action fails.
            $role = null;
        }

        return $this->actorRoleCache[$actorId] = $role;
    }

    /**
     * Resolves the active or latest encounter for the appointment when the
     * caller did not explicitly supply one.
     */
    private function resolveEncounterId(?string $appointmentId): ?string
    {
        if ($appointmentId === null) {
            return null;
        }

        try {
            return \App\Modules\Encounter\Infrastructure\Models\EncounterModel::query()
                ->where('appointment_id', $appointmentId)
                ->latest('opened_at')
                ->value('id');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The step the visit is currently recorded as being in, or null when this
     * is the first event for the visit (in which case from_step is honestly
     * absent rather than guessed).
     */
    private function resolveCurrentStep(?string $appointmentId, ?string $serviceRequestId): ?PatientFlowStep
    {
        $latest = $this->repository->latestForVisit($appointmentId, $serviceRequestId);

        if ($latest === null) {
            return null;
        }

        return PatientFlowStep::tryFrom((string) ($latest['to_step'] ?? ''));
    }
}
