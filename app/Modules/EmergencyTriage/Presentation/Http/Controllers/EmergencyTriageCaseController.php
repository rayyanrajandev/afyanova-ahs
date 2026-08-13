<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admission\Application\Exceptions\AppointmentNotEligibleForAdmissionException as AppointmentNotEligibleForAdmissionCreationException;
use App\Modules\Admission\Application\Exceptions\InvalidAdmissionPlacementException;
use App\Modules\Admission\Application\Exceptions\PatientNotEligibleForAdmissionException;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\EmergencyTriage\Application\Exceptions\ActiveEmergencyTriageCaseConflictException;
use App\Modules\EmergencyTriage\Application\Exceptions\AdmissionNotEligibleForEmergencyTriageCaseException;
use App\Modules\EmergencyTriage\Application\Exceptions\AppointmentNotEligibleForEmergencyTriageCaseException;
use App\Modules\EmergencyTriage\Application\Exceptions\PatientNotEligibleForEmergencyTriageCaseException;
use App\Modules\EmergencyTriage\Application\UseCases\CreateEmergencyTriageCaseUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\CreateEmergencyTriageCaseTransferUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\GetEmergencyTriageCaseUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\ListEmergencyTriageCaseAuditLogsUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\ListEmergencyTriageCasesUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\ListEmergencyTriageCaseStatusCountsUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\ListEmergencyTriageCaseTransferAuditLogsUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\ListEmergencyTriageCaseTransfersUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\ListEmergencyTriageCaseTransferStatusCountsUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\UpdateEmergencyTriageCaseStatusUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\UpdateEmergencyTriageCaseUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\UpdateEmergencyTriageCaseTransferStatusUseCase;
use App\Modules\EmergencyTriage\Application\UseCases\UpdateEmergencyTriageCaseTransferUseCase;
use App\Modules\EmergencyTriage\Presentation\Http\Requests\StoreEmergencyTriageCaseRequest;
use App\Modules\EmergencyTriage\Presentation\Http\Requests\StoreEmergencyTriageCaseTransferRequest;
use App\Modules\EmergencyTriage\Presentation\Http\Requests\UpdateEmergencyTriageCaseRequest;
use App\Modules\EmergencyTriage\Presentation\Http\Requests\UpdateEmergencyTriageCaseStatusRequest;
use App\Modules\EmergencyTriage\Presentation\Http\Requests\UpdateEmergencyTriageCaseTransferRequest;
use App\Modules\EmergencyTriage\Presentation\Http\Requests\UpdateEmergencyTriageCaseTransferStatusRequest;
use App\Modules\EmergencyTriage\Presentation\Http\Transformers\EmergencyTriageCaseAuditLogResponseTransformer;
use App\Modules\EmergencyTriage\Presentation\Http\Transformers\EmergencyTriageCaseResponseTransformer;
use App\Modules\EmergencyTriage\Presentation\Http\Transformers\EmergencyTriageCaseTransferAuditLogResponseTransformer;
use App\Modules\EmergencyTriage\Presentation\Http\Transformers\EmergencyTriageCaseTransferResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmergencyTriageCaseController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListEmergencyTriageCasesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([EmergencyTriageCaseResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListEmergencyTriageCaseStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function store(StoreEmergencyTriageCaseRequest $request, CreateEmergencyTriageCaseUseCase $useCase): JsonResponse
    {
        try {
            $case = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForEmergencyTriageCaseException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForEmergencyTriageCaseException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForEmergencyTriageCaseException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (ActiveEmergencyTriageCaseConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => ['patientId' => [$exception->getMessage()]],
                'context' => [
                    'activeEmergencyCaseConflict' => EmergencyTriageCaseResponseTransformer::transform($exception->existingCase()),
                ],
            ], 422);
        }

        return response()->json([
            'data' => EmergencyTriageCaseResponseTransformer::transform($case),
        ], 201);
    }

    public function show(string $id, GetEmergencyTriageCaseUseCase $useCase): JsonResponse
    {
        $case = $useCase->execute($id);
        abort_if($case === null, 404, 'Emergency triage case not found.');

        return response()->json([
            'data' => EmergencyTriageCaseResponseTransformer::transform($case),
        ]);
    }

    public function update(string $id, UpdateEmergencyTriageCaseRequest $request, UpdateEmergencyTriageCaseUseCase $useCase): JsonResponse
    {
        try {
            $case = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForEmergencyTriageCaseException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForEmergencyTriageCaseException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForEmergencyTriageCaseException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        }

        abort_if($case === null, 404, 'Emergency triage case not found.');

        return response()->json([
            'data' => EmergencyTriageCaseResponseTransformer::transform($case),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateEmergencyTriageCaseStatusRequest $request,
        UpdateEmergencyTriageCaseStatusUseCase $useCase
    ): JsonResponse {
        try {
            $case = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                dispositionNotes: $request->input('dispositionNotes'),
                actorId: $request->user()?->id,
                bedResourceId: $request->input('bedResourceId'),
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForAdmissionException $exception) {
            return $this->validationError('bedResourceId', $exception->getMessage());
        } catch (AppointmentNotEligibleForAdmissionCreationException $exception) {
            return $this->validationError('bedResourceId', $exception->getMessage());
        } catch (InvalidAdmissionPlacementException $exception) {
            return $this->validationError('bedResourceId', $exception->getMessage());
        }

        abort_if($case === null, 404, 'Emergency triage case not found.');

        return response()->json([
            'data' => EmergencyTriageCaseResponseTransformer::transform($case),
        ]);
    }

    public function transfers(
        string $id,
        Request $request,
        ListEmergencyTriageCaseTransfersUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Emergency triage case not found.');

        return response()->json([
            'data' => array_map([EmergencyTriageCaseTransferResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function transferStatusCounts(
        string $id,
        Request $request,
        ListEmergencyTriageCaseTransferStatusCountsUseCase $useCase
    ): JsonResponse {
        $counts = $useCase->execute($id, $request->all());
        abort_if($counts === null, 404, 'Emergency triage case not found.');

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function storeTransfer(
        string $id,
        StoreEmergencyTriageCaseTransferRequest $request,
        CreateEmergencyTriageCaseTransferUseCase $useCase
    ): JsonResponse {
        try {
            $transfer = $useCase->execute(
                emergencyTriageCaseId: $id,
                payload: $this->toTransferPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($transfer === null, 404, 'Emergency triage case not found.');

        return response()->json([
            'data' => EmergencyTriageCaseTransferResponseTransformer::transform($transfer),
        ], 201);
    }

    public function updateTransfer(
        string $id,
        string $transferId,
        UpdateEmergencyTriageCaseTransferRequest $request,
        UpdateEmergencyTriageCaseTransferUseCase $useCase
    ): JsonResponse {
        try {
            $transfer = $useCase->execute(
                emergencyTriageCaseId: $id,
                transferId: $transferId,
                payload: $this->toTransferPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($transfer === null, 404, 'Emergency triage transfer not found.');

        return response()->json([
            'data' => EmergencyTriageCaseTransferResponseTransformer::transform($transfer),
        ]);
    }

    public function updateTransferStatus(
        string $id,
        string $transferId,
        UpdateEmergencyTriageCaseTransferStatusRequest $request,
        UpdateEmergencyTriageCaseTransferStatusUseCase $useCase
    ): JsonResponse {
        try {
            $transfer = $useCase->execute(
                emergencyTriageCaseId: $id,
                transferId: $transferId,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                clinicalHandoffNotes: $request->input('clinicalHandoffNotes'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($transfer === null, 404, 'Emergency triage transfer not found.');

        return response()->json([
            'data' => EmergencyTriageCaseTransferResponseTransformer::transform($transfer),
        ]);
    }

    public function transferAuditLogs(
        string $id,
        string $transferId,
        Request $request,
        ListEmergencyTriageCaseTransferAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(
            emergencyTriageCaseId: $id,
            transferId: $transferId,
            filters: $request->all(),
        );
        abort_if($result === null, 404, 'Emergency triage transfer not found.');

        return response()->json([
            'data' => array_map([EmergencyTriageCaseTransferAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportTransferAuditLogsCsv(
        string $id,
        string $transferId,
        Request $request,
        ListEmergencyTriageCaseTransferAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            emergencyTriageCaseId: $id,
            transferId: $transferId,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Emergency triage transfer not found.');

        $safeCaseId = $this->safeExportIdentifier($id, 'emergency-triage-case');
        $safeTransferId = $this->safeExportIdentifier($transferId, 'transfer');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('emergency_triage_transfer_audit_%s_%s_%s', $safeCaseId, $safeTransferId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $transferId, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    emergencyTriageCaseId: $id,
                    transferId: $transferId,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function auditLogs(string $id, Request $request, ListEmergencyTriageCaseAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(emergencyTriageCaseId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Emergency triage case not found.');

        return response()->json([
            'data' => array_map([EmergencyTriageCaseAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListEmergencyTriageCaseAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            emergencyTriageCaseId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Emergency triage case not found.');

        $safeId = $this->safeExportIdentifier($id, 'emergency-triage-case');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('emergency_triage_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    emergencyTriageCaseId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'VALIDATION_ERROR',
            'errors' => [
                $field => [$message],
            ],
        ], 422);
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'patientId' => 'patient_id',
            'admissionId' => 'admission_id',
            'appointmentId' => 'appointment_id',
            'assignedClinicianUserId' => 'assigned_clinician_user_id',
            'arrivalAt' => 'arrived_at',
            'triageLevel' => 'triage_level',
            'chiefComplaint' => 'chief_complaint',
            'vitalsSummary' => 'vitals_summary',
            'triagedAt' => 'triaged_at',
            'dispositionNotes' => 'disposition_notes',
        ];

        $payload = [];

        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }

    private function toTransferPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'transferType' => 'transfer_type',
            'priority' => 'priority',
            'sourceLocation' => 'source_location',
            'destinationLocation' => 'destination_location',
            'destinationFacilityName' => 'destination_facility_name',
            'acceptingClinicianUserId' => 'accepting_clinician_user_id',
            'requestedAt' => 'requested_at',
            'status' => 'status',
            'statusReason' => 'status_reason',
            'clinicalHandoffNotes' => 'clinical_handoff_notes',
            'transportMode' => 'transport_mode',
            'metadata' => 'metadata',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }
}
