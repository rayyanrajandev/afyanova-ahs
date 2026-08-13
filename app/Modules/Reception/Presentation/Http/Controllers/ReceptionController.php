<?php

namespace App\Modules\Reception\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admission\Presentation\Http\Transformers\AdmissionResponseTransformer;
use App\Modules\Appointment\Application\Exceptions\ActiveAppointmentConflictException;
use App\Modules\Appointment\Application\Exceptions\InvalidAppointmentStatusTransitionException;
use App\Modules\Appointment\Application\Exceptions\PatientActiveEncounterConflictException;
use App\Modules\Appointment\Application\Exceptions\PatientNotEligibleForAppointmentException;
use App\Modules\Appointment\Application\UseCases\ListAppointmentsUseCase;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Presentation\Http\Transformers\AppointmentResponseTransformer;
use App\Modules\EmergencyTriage\Presentation\Http\Transformers\EmergencyTriageCaseResponseTransformer;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Reception\Application\Exceptions\QueueReorderCrossesTierException;
use App\Modules\Reception\Application\UseCases\CallQueueItemUseCase;
use App\Modules\Reception\Application\UseCases\CancelQueueItemUseCase;
use App\Modules\Reception\Application\UseCases\CheckInUseCase;
use App\Modules\Reception\Application\UseCases\GetClinicianQueueStatusCountsUseCase;
use App\Modules\Reception\Application\UseCases\GetReceptionQueueStatusCountsUseCase;
use App\Modules\Reception\Application\UseCases\GetReceptionQueueUseCase;
use App\Modules\Reception\Application\UseCases\GetRegistrationWorkspaceSnapshotUseCase;
use App\Modules\Reception\Application\UseCases\GetTriageCompletedTodayUseCase;
use App\Modules\Reception\Application\UseCases\GetTriageQueueStatusCountsUseCase;
use App\Modules\Reception\Application\UseCases\RegisterWalkInAndCheckInUseCase;
use App\Modules\Reception\Application\UseCases\ReorderReceptionQueueUseCase;
use App\Modules\Reception\Domain\ValueObjects\ArrivalMode;
use App\Modules\Reception\Presentation\Http\Requests\CancelQueueItemRequest;
use App\Modules\Reception\Presentation\Http\Requests\CheckInAppointmentRequest;
use App\Modules\Reception\Presentation\Http\Requests\RegisterWalkInRequest;
use App\Modules\Reception\Presentation\Http\Requests\ReorderQueueRequest;
use App\Modules\Reception\Presentation\Http\Transformers\ReceptionQueueEntryResponseTransformer;
use App\Modules\Reception\Presentation\Http\Transformers\RegistrationWorkspaceSnapshotResponseTransformer;
use App\Modules\Reception\Presentation\Http\Transformers\TriageCompletedEntryResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReceptionController extends Controller
{
    public function checkIn(
        string $id,
        CheckInAppointmentRequest $request,
        CheckInUseCase $useCase,
    ): JsonResponse {
        try {
            $appointment = $useCase->execute(
                appointmentId: $id,
                arrivalMode: ArrivalMode::SCHEDULED_CHECKIN->value,
                verificationNotes: $request->input('verificationNotes'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (InvalidAppointmentStatusTransitionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'APPOINTMENT_STATUS_TRANSITION_INVALID',
                'errors' => ['status' => [$exception->getMessage()]],
            ], 422);
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }

    /**
     * Cancel a reception queue item (Volume 2.1 §10.3 "Cancel").
     *
     * Delegates to CancelQueueItemUseCase (bug fix, 2026-08-11) rather than
     * calling UpdateAppointmentStatusUseCase directly — the appointment
     * status flip is only part of "cancel a visit"; the Reception-owned use
     * case also closes out the visit's Encounter (if still untouched) and
     * writes the patient's own audit trail, the same two side effects
     * CheckInUseCase already handles for the opposite action. The endpoint
     * stays purpose-scoped to cancellation (status is fixed to CANCELLED,
     * not accepted from the request) so the reception workspace's frontend
     * never has to reach into the generic `/appointments/{id}/status`
     * contract directly (Volume 3.7 audit, 2026-08-10).
     */
    public function cancelQueueItem(
        string $id,
        CancelQueueItemRequest $request,
        CancelQueueItemUseCase $useCase,
    ): JsonResponse {
        try {
            $appointment = $useCase->execute(
                appointmentId: $id,
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
                isFacilitySuperAdmin: $request->user()?->isFacilitySuperAdminAccess() ?? false,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (InvalidAppointmentStatusTransitionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'APPOINTMENT_STATUS_TRANSITION_INVALID',
                'errors' => ['status' => [$exception->getMessage()]],
            ], 422);
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }

    /**
     * Call a reception queue item (Volume 2.1 §10.3 "Call") — decided
     * 2026-08-11 (§16 #3): ephemeral broadcast only, no status change, no
     * audit-trail write. See AppointmentCalled/CallQueueItemUseCase's own
     * docblocks for the full reasoning. No FormRequest: unlike check-in/
     * cancel, there's no body to validate — just the appointment id.
     */
    public function callAppointment(string $id, CallQueueItemUseCase $useCase): JsonResponse
    {
        $appointment = $useCase->execute($id);

        abort_if($appointment === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }

    /**
     * Reorder the reception queue (Volume 2.1 §10.3 "Reorder", Volume 3.7
     * T5.5). Tier (emergency > scheduled > walk-in) is a hard floor —
     * ReorderReceptionQueueUseCase rejects any submitted order that would
     * cross it; a drag can only reshuffle patients within the same tier.
     */
    public function reorderQueue(
        ReorderQueueRequest $request,
        ReorderReceptionQueueUseCase $useCase,
    ): JsonResponse {
        try {
            $reordered = $useCase->execute(
                orderedAppointmentIds: $request->input('appointmentIds'),
                actorId: $request->user()?->id,
            );
        } catch (QueueReorderCrossesTierException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'QUEUE_REORDER_CROSSES_TIER',
                'errors' => ['appointmentIds' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json([
            'data' => ['reordered' => $reordered],
        ]);
    }

    /**
     * Added (2026-08-10, Volume 2.1 §9.1) for the Appointment Scheduling
     * day/week view. Reuses ListAppointmentsUseCase and
     * AppointmentResponseTransformer as-is (same filters, same shape every
     * other consumer of GET /appointments gets) and adds one
     * reception-only, presentation-layer field on top: `patientName` +
     * `patientNumber`. AppointmentModel carries no patient relation and
     * AppointmentResponseTransformer deliberately doesn't join one (it's
     * shared by billing/clinician/etc., which mostly don't need it) — the
     * batch-load-by-id technique here is the same one
     * GetReceptionQueueUseCase already uses for the same reason, kept a
     * presentation concern rather than pushed into the shared use case.
     */
    public function listAppointments(Request $request, ListAppointmentsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        $transformed = array_map([AppointmentResponseTransformer::class, 'transform'], $result['data']);

        $patientIds = array_values(array_unique(array_filter(
            array_map(static fn (array $appointment): ?string => $appointment['patientId'] ?? null, $transformed),
        )));

        $patientsById = PatientModel::query()
            ->whereIn('id', $patientIds)
            ->get(['id', 'patient_number', 'first_name', 'middle_name', 'last_name'])
            ->keyBy('id');

        foreach ($transformed as &$appointment) {
            $patient = $patientsById->get($appointment['patientId']);
            // Same implode/array_filter shape as GetReceptionQueueUseCase's
            // identical patientName build — middle_name is routinely '' or
            // null, and a naive "%s %s %s" sprintf leaves a double space.
            $patientName = $patient !== null
                ? implode(' ', array_filter([
                    $patient->first_name,
                    $patient->middle_name,
                    $patient->last_name,
                ], static fn (?string $part): bool => $part !== null && trim($part) !== ''))
                : null;
            $appointment['patientName'] = $patientName !== '' ? $patientName : null;
            $appointment['patientNumber'] = $patient?->patient_number;
        }
        unset($appointment);

        return response()->json([
            'data' => $transformed,
            'meta' => $result['meta'],
        ]);
    }

    public function registerWalkIn(
        RegisterWalkInRequest $request,
        RegisterWalkInAndCheckInUseCase $useCase,
    ): JsonResponse {
        try {
            $appointment = $useCase->execute(
                patientId: (string) $request->input('patientId'),
                arrivalMode: (string) $request->input('arrivalMode'),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (PatientNotEligibleForAppointmentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => ['patientId' => [$exception->getMessage()]],
            ], 422);
        } catch (ActiveAppointmentConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => ['patientId' => [$exception->getMessage()]],
                'data' => [
                    'activeAppointmentConflict' => AppointmentResponseTransformer::transform(
                        $exception->existingAppointment(),
                    ),
                ],
            ], 422);
        } catch (PatientActiveEncounterConflictException $exception) {
            $conflictType = $exception->conflictType();

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => ['patientId' => [$exception->getMessage()]],
                'data' => [
                    'activePatientEncounterConflict' => [
                        'conflictType' => $conflictType,
                        'record' => $conflictType === 'emergency_case'
                            ? EmergencyTriageCaseResponseTransformer::transform($exception->existingRecord())
                            : AdmissionResponseTransformer::transform($exception->existingRecord()),
                    ],
                ],
            ], 422);
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ], 201);
    }

    public function queue(Request $request, GetReceptionQueueUseCase $useCase): JsonResponse
    {
        $request->validate([
            'stage' => [
                'required',
                Rule::in([
                    AppointmentStatus::WAITING_TRIAGE->value,
                    AppointmentStatus::WAITING_PROVIDER->value,
                    AppointmentStatus::IN_CONSULTATION->value,
                ]),
            ],
        ]);

        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([ReceptionQueueEntryResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function queueStatusCounts(Request $request, GetReceptionQueueStatusCountsUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute($request->all()),
        ]);
    }

    public function triageQueueStatusCounts(GetTriageQueueStatusCountsUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute(),
        ]);
    }

    public function triageCompletedToday(Request $request, GetTriageCompletedTodayUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([TriageCompletedEntryResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function clinicianQueueStatusCounts(GetClinicianQueueStatusCountsUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute(),
        ]);
    }

    public function workspaceSnapshot(
        string $id,
        GetRegistrationWorkspaceSnapshotUseCase $useCase,
    ): JsonResponse {
        $snapshot = $useCase->execute($id);
        abort_if($snapshot === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => RegistrationWorkspaceSnapshotResponseTransformer::transform($snapshot),
        ]);
    }

    private function tenantScopeRequiredResponse(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }
}
