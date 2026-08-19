<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Application\Exceptions\AppointmentConsultationOwnerRequiredException;
use App\Modules\Appointment\Application\Exceptions\InvalidAppointmentStatusTransitionException;
use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;

class UpdateAppointmentStatusUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly RecordPatientFlowTransitionService $recordPatientFlowTransition,
    ) {}

    public function execute(
        string $id,
        string $status,
        ?string $reason,
        ?int $actorId = null,
        array $statusAttributes = [],
        array $auditMetadata = [],
        bool $isFacilitySuperAdmin = false,
        string $flowSource = 'appointment.status_updated',
        ?PatientFlowStep $flowStepOverride = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->appointmentRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $currentStatus = strtolower(trim((string) ($existing['status'] ?? '')));
        $requestedStatus = strtolower(trim($status));

        // Scoped to transitions leaving in_consultation (e.g. cancel/complete via the
        // generic status endpoint) — never to in_consultation itself, since that target
        // is only ever requested by startConsultation()'s own claim/takeover flow, which
        // already performs its own, more specific ownership arbitration before calling
        // here (dialog-confirmed takeover, or claiming a session with no explicit owner).
        if (
            $currentStatus === AppointmentStatus::IN_CONSULTATION->value
            && $requestedStatus !== AppointmentStatus::IN_CONSULTATION->value
            && ! $isFacilitySuperAdmin
            && $actorId !== null
            && ($ownerUserId = $this->resolvedConsultationOwnerUserId($existing)) !== null
            && $ownerUserId !== $actorId
        ) {
            throw new AppointmentConsultationOwnerRequiredException($ownerUserId);
        }

        $currentStatusEnum = AppointmentStatus::tryFrom($currentStatus);
        if ($currentStatusEnum !== null && ! $currentStatusEnum->canTransitionTo($requestedStatus)) {
            throw new InvalidAppointmentStatusTransitionException($currentStatus, $requestedStatus);
        }

        $updated = $this->appointmentRepository->update($id, array_merge([
            'status' => $status,
            'status_reason' => $reason,
            // Volume 3.7 T5.5 — a manual queue reorder only makes sense
            // within the stage it was set in; clear it on every status
            // change so it never silently reappears in a later stage's
            // queue with a meaning nobody there asked for.
            'queue_position' => null,
        ], $statusAttributes, (
            $status === AppointmentStatus::WAITING_TRIAGE->value
                ? ['checked_in_at' => now()]
                : []
        )));

        if (! $updated) {
            return null;
        }

        $reasonRequired = in_array($status, [
            AppointmentStatus::CANCELLED->value,
            AppointmentStatus::NO_SHOW->value,
        ], true);

        $this->auditLogRepository->write(
            appointmentId: $id,
            action: 'appointment.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
            ],
            metadata: array_merge([
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                'triage_handoff' => in_array($status, [
                    AppointmentStatus::WAITING_TRIAGE->value,
                    AppointmentStatus::WAITING_PROVIDER->value,
                ], true),
            ], $auditMetadata),
        );

        // The flow log (2026-08-16). Every status change that reaches this use
        // case is also a step change on the board, and this is where it gets
        // written down rather than re-inferred later. `appointmentStatusAlsoChanged`
        // is true because the AppointmentStatusChanged dispatched just below
        // already triggers the board broadcast — without it the board would
        // refresh twice for one action.
        // The step vocabulary is deliberately richer than the status enum, so a
        // caller may name a step the status alone cannot express. Returning a
        // patient to reception is the case that forced this: the status really
        // is waiting_triage — they are back in reception's queue — but
        // "returned_to_reception" is what happened, and deriving from the status
        // would report it as an ordinary check-in and lose the distinction the
        // step exists to draw.
        $toStep = $flowStepOverride ?? PatientFlowStep::fromAppointmentStatus(
            status: (string) ($updated['status'] ?? ''),
            hasTriageOwner: ($updated['triage_owner_user_id'] ?? null) !== null,
            hasConsultationStarted: ($updated['consultation_started_at'] ?? null) !== null,
        );

        if ($toStep !== null) {
            $this->recordPatientFlowTransition->record(
                toStep: $toStep,
                patientId: (string) $updated['patient_id'],
                appointmentId: (string) $updated['id'],
                actorId: $actorId,
                // Callers name the real action (a doctor starting a consultation,
                // a takeover) so the timeline staff read says what happened
                // rather than "Visit status updated" for every transition.
                source: $flowSource,
                reason: $updated['status_reason'] ?? null,
                metadata: array_filter([
                    'appointmentStatus' => $updated['status'] ?? null,
                    'takeover' => $auditMetadata['consultation_takeover'] ?? null,
                ], static fn ($value) => $value !== null),
                facilityId: $updated['facility_id'] ?? null,
                appointmentStatusAlsoChanged: true,
            );
        }

        DB::afterCommit(function () use ($existing, $updated, $actorId): void {
            event(new AppointmentStatusChanged(
                appointmentId: (string) $updated['id'],
                patientId: (string) $updated['patient_id'],
                oldStatus: (string) ($existing['status'] ?? ''),
                newStatus: (string) ($updated['status'] ?? ''),
                actorId: $actorId,
                facilityId: $updated['facility_id'] ?? null,
            ));
        });

        return $updated;
    }

    private function normalizeOwnerUserId(mixed $value): ?int
    {
        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    /**
     * Mirrors AppointmentController::resolvedConsultationOwnerUserId() — legacy
     * active consultations may not have explicit ownership stored yet, so the
     * assigned clinician is treated as the effective owner until the record is
     * touched again and the ownership field is repaired.
     *
     * @param  array<string, mixed>  $appointment
     */
    private function resolvedConsultationOwnerUserId(array $appointment): ?int
    {
        $explicitOwnerUserId = $this->normalizeOwnerUserId($appointment['consultation_owner_user_id'] ?? null);
        if ($explicitOwnerUserId !== null) {
            return $explicitOwnerUserId;
        }

        $status = strtolower(trim((string) ($appointment['status'] ?? '')));
        if ($status !== AppointmentStatus::IN_CONSULTATION->value) {
            return null;
        }

        return $this->normalizeOwnerUserId($appointment['clinician_user_id'] ?? null);
    }
}
