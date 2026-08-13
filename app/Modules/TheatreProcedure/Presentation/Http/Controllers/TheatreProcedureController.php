<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Domain\Repositories\UserFacilityAssignmentRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceType;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use App\Modules\Platform\Presentation\Http\Transformers\FacilityResourceResponseTransformer;
use App\Modules\Staff\Application\UseCases\ListStaffProfilesUseCase;
use App\Modules\Staff\Presentation\Http\Transformers\StaffProfileResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\TheatreProcedure\Application\UseCases\ApplyTheatreProcedureLifecycleActionUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\CheckTheatreProcedureDuplicatesUseCase;
use App\Modules\TheatreProcedure\Application\Exceptions\TheatreProcedureCatalogItemNotEligibleException;
use App\Modules\TheatreProcedure\Application\Exceptions\TheatreRoomServicePointNotEligibleException;
use App\Modules\TheatreProcedure\Application\Exceptions\TheatreProcedureResourceAllocationConflictException;
use App\Modules\TheatreProcedure\Application\UseCases\CreateTheatreProcedureUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\DiscardTheatreProcedureDraftUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\CreateTheatreProcedureResourceAllocationUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\GetTheatreProcedureUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\ListTheatreProcedureAuditLogsUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\ListTheatreProceduresUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\ListTheatreProcedureResourceAllocationAuditLogsUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\ListTheatreProcedureResourceAllocationsUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\ListTheatreProcedureResourceAllocationStatusCountsUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\ListTheatreProcedureStatusCountsUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\SignTheatreProcedureUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\UpdateTheatreProcedureResourceAllocationStatusUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\UpdateTheatreProcedureResourceAllocationUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\UpdateTheatreProcedureStatusUseCase;
use App\Modules\TheatreProcedure\Application\UseCases\UpdateTheatreProcedureUseCase;
use App\Modules\TheatreProcedure\Presentation\Http\Requests\StoreTheatreProcedureRequest;
use App\Modules\TheatreProcedure\Presentation\Http\Requests\StoreTheatreProcedureResourceAllocationRequest;
use App\Modules\TheatreProcedure\Presentation\Http\Requests\UpdateTheatreProcedureResourceAllocationRequest;
use App\Modules\TheatreProcedure\Presentation\Http\Requests\UpdateTheatreProcedureResourceAllocationStatusRequest;
use App\Modules\TheatreProcedure\Presentation\Http\Requests\UpdateTheatreProcedureRequest;
use App\Modules\TheatreProcedure\Presentation\Http\Requests\UpdateTheatreProcedureStatusRequest;
use App\Modules\TheatreProcedure\Presentation\Http\Transformers\TheatreProcedureAuditLogResponseTransformer;
use App\Modules\TheatreProcedure\Presentation\Http\Transformers\TheatreProcedureResourceAllocationAuditLogResponseTransformer;
use App\Modules\TheatreProcedure\Presentation\Http\Transformers\TheatreProcedureResourceAllocationResponseTransformer;
use App\Modules\TheatreProcedure\Presentation\Http\Transformers\TheatreProcedureResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TheatreProcedureController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListTheatreProceduresUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([TheatreProcedureResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListTheatreProcedureStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function roomRegistry(
        Request $request,
        UserFacilityAssignmentRepositoryInterface $userFacilityAssignmentRepository
    ): JsonResponse
    {
        abort_unless(
            Gate::any(['theatre.procedures.read', 'theatre.procedures.create', 'theatre.procedures.update']),
            403,
            'Theatre room registry access is restricted.'
        );

        $searchTerm = trim((string) $request->input('q', ''));
        $perPage = min(max((int) $request->input('perPage', 100), 1), 200);

        $scope = $request->attributes->get('platform.scope');
        $scopedFacilityId = is_array($scope)
            ? trim((string) data_get($scope, 'facility.id', ''))
            : '';
        $scopedTenantId = is_array($scope)
            ? trim((string) data_get($scope, 'tenant.id', ''))
            : '';

        $rooms = $this->findEligibleTheatreRooms(
            searchTerm: $searchTerm !== '' ? $searchTerm : null,
            facilityIds: $scopedFacilityId !== '' ? [$scopedFacilityId] : [],
            tenantIds: $scopedFacilityId === '' && $scopedTenantId !== '' ? [$scopedTenantId] : [],
            limit: $perPage,
        );

        if ($rooms === []) {
            $assignments = $request->user() !== null
                ? $userFacilityAssignmentRepository->listActiveFacilityScopesByUserId((int) $request->user()->id)
                : [];

            $accessibleFacilityIds = array_values(array_filter(array_unique(array_map(
                static fn (array $assignment): string => trim((string) ($assignment['facility_id'] ?? '')),
                $assignments,
            ))));
            $accessibleTenantIds = array_values(array_filter(array_unique(array_map(
                static fn (array $assignment): string => trim((string) ($assignment['tenant_id'] ?? '')),
                $assignments,
            ))));

            $rooms = $this->findEligibleTheatreRooms(
                searchTerm: $searchTerm !== '' ? $searchTerm : null,
                facilityIds: $accessibleFacilityIds,
                tenantIds: $accessibleFacilityIds === [] ? $accessibleTenantIds : [],
                limit: $perPage,
            );
        }

        return response()->json([
            'data' => array_map([FacilityResourceResponseTransformer::class, 'transform'], $rooms),
            'meta' => [
                'currentPage' => 1,
                'perPage' => count($rooms),
                'total' => count($rooms),
                'lastPage' => 1,
            ],
        ]);
    }

    public function clinicianDirectory(Request $request, ListStaffProfilesUseCase $useCase): JsonResponse
    {
        abort_unless(
            Gate::any(['theatre.procedures.read', 'theatre.procedures.create', 'theatre.procedures.update']),
            403,
            'Theatre clinician directory access is restricted.'
        );

        $filters = array_merge($request->all(), [
            'status' => 'active',
            'clinicalOnly' => true,
            'page' => max(1, (int) $request->integer('page', 1)),
            'perPage' => min(max((int) $request->integer('perPage', 200), 1), 200),
        ]);

        $result = $useCase->execute($filters);

        return response()->json([
            'data' => array_map([StaffProfileResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(StoreTheatreProcedureRequest $request, CreateTheatreProcedureUseCase $useCase): JsonResponse
    {
        try {
            $procedure = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (TheatreProcedureCatalogItemNotEligibleException $exception) {
            return $this->validationError(
                $request->filled('theatreProcedureCatalogItemId') ? 'theatreProcedureCatalogItemId' : 'procedureType',
                $exception->getMessage(),
            );
        } catch (TheatreRoomServicePointNotEligibleException $exception) {
            return $this->validationError('theatreRoomServicePointId', $exception->getMessage());
        }

        return response()->json([
            'data' => TheatreProcedureResponseTransformer::transform($procedure),
        ], 201);
    }

    public function show(string $id, GetTheatreProcedureUseCase $useCase): JsonResponse
    {
        $procedure = $useCase->execute($id);
        abort_if($procedure === null, 404, 'Theatre procedure not found.');

        return response()->json([
            'data' => TheatreProcedureResponseTransformer::transform($procedure, true),
        ]);
    }

    public function update(
        string $id,
        UpdateTheatreProcedureRequest $request,
        UpdateTheatreProcedureUseCase $useCase
    ): JsonResponse {
        try {
            $procedure = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (TheatreProcedureCatalogItemNotEligibleException $exception) {
            return $this->validationError(
                $request->filled('theatreProcedureCatalogItemId') ? 'theatreProcedureCatalogItemId' : 'procedureType',
                $exception->getMessage(),
            );
        } catch (TheatreRoomServicePointNotEligibleException $exception) {
            return $this->validationError('theatreRoomServicePointId', $exception->getMessage());
        }

        abort_if($procedure === null, 404, 'Theatre procedure not found.');

        return response()->json([
            'data' => TheatreProcedureResponseTransformer::transform($procedure, true),
        ]);
    }

    public function sign(string $id, Request $request, SignTheatreProcedureUseCase $useCase): JsonResponse
    {
        try {
            $procedure = $useCase->execute(
                id: $id,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->validationExceptionResponse($exception);
        }

        abort_if($procedure === null, 404, 'Theatre procedure not found.');

        return response()->json([
            'data' => TheatreProcedureResponseTransformer::transform($procedure, true),
        ]);
    }

    public function discardDraft(
        string $id,
        Request $request,
        DiscardTheatreProcedureDraftUseCase $useCase
    ): JsonResponse {
        try {
            $discarded = $useCase->execute(
                id: $id,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->validationExceptionResponse($exception);
        }

        abort_if(! $discarded, 404, 'Theatre procedure not found.');

        return response()->json(null, 204);
    }

    public function updateStatus(
        string $id,
        UpdateTheatreProcedureStatusRequest $request,
        UpdateTheatreProcedureStatusUseCase $useCase
    ): JsonResponse {
        try {
            $procedure = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                startedAt: $request->input('startedAt'),
                completedAt: $request->input('completedAt'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($procedure === null, 404, 'Theatre procedure not found.');

        return response()->json([
            'data' => TheatreProcedureResponseTransformer::transform($procedure),
        ]);
    }

    public function applyLifecycleAction(
        string $id,
        Request $request,
        ApplyTheatreProcedureLifecycleActionUseCase $useCase
    ): JsonResponse {
        $payload = $request->validate([
            'action' => ['required', Rule::in(['cancel', 'entered_in_error'])],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $procedure = $useCase->execute(
                id: $id,
                action: (string) $payload['action'],
                reason: (string) $payload['reason'],
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($procedure === null, 404, 'Theatre procedure not found.');

        return response()->json([
            'data' => TheatreProcedureResponseTransformer::transform($procedure),
        ]);
    }

    public function duplicateCheck(
        Request $request,
        CheckTheatreProcedureDuplicatesUseCase $useCase
    ): JsonResponse {
        $payload = $request->validate([
            'patientId' => ['required', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'theatreProcedureCatalogItemId' => ['nullable', 'uuid', 'required_without:procedureType'],
            'procedureType' => ['nullable', 'string', 'max:120', 'required_without:theatreProcedureCatalogItemId'],
        ]);

        $result = $useCase->execute([
            'patient_id' => $payload['patientId'],
            'appointment_id' => $payload['appointmentId'] ?? null,
            'admission_id' => $payload['admissionId'] ?? null,
            'theatre_procedure_catalog_item_id' => $payload['theatreProcedureCatalogItemId'] ?? null,
            'procedure_type' => $payload['procedureType'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'severity' => $result['severity'],
                'messages' => $result['messages'],
                'sameEncounterDuplicates' => array_map(
                    [TheatreProcedureResponseTransformer::class, 'transform'],
                    $result['sameEncounterDuplicates'],
                ),
                'recentPatientDuplicates' => array_map(
                    [TheatreProcedureResponseTransformer::class, 'transform'],
                    $result['recentPatientDuplicates'],
                ),
            ],
        ]);
    }

    public function resourceAllocations(
        string $id,
        Request $request,
        ListTheatreProcedureResourceAllocationsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Theatre procedure not found.');

        return response()->json([
            'data' => array_map([TheatreProcedureResourceAllocationResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function resourceAllocationStatusCounts(
        string $id,
        Request $request,
        ListTheatreProcedureResourceAllocationStatusCountsUseCase $useCase
    ): JsonResponse {
        $counts = $useCase->execute($id, $request->all());
        abort_if($counts === null, 404, 'Theatre procedure not found.');

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function storeResourceAllocation(
        string $id,
        StoreTheatreProcedureResourceAllocationRequest $request,
        CreateTheatreProcedureResourceAllocationUseCase $useCase
    ): JsonResponse {
        try {
            $allocation = $useCase->execute(
                theatreProcedureId: $id,
                payload: $this->toResourceAllocationPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (TheatreProcedureResourceAllocationConflictException $exception) {
            return $this->validationError('resourceReference', $exception->getMessage());
        }

        abort_if($allocation === null, 404, 'Theatre procedure not found.');

        return response()->json([
            'data' => TheatreProcedureResourceAllocationResponseTransformer::transform($allocation),
        ], 201);
    }

    public function updateResourceAllocation(
        string $id,
        string $allocationId,
        UpdateTheatreProcedureResourceAllocationRequest $request,
        UpdateTheatreProcedureResourceAllocationUseCase $useCase
    ): JsonResponse {
        try {
            $allocation = $useCase->execute(
                theatreProcedureId: $id,
                allocationId: $allocationId,
                payload: $this->toResourceAllocationPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (TheatreProcedureResourceAllocationConflictException $exception) {
            return $this->validationError('resourceReference', $exception->getMessage());
        }

        abort_if($allocation === null, 404, 'Theatre resource allocation not found.');

        return response()->json([
            'data' => TheatreProcedureResourceAllocationResponseTransformer::transform($allocation),
        ]);
    }

    public function updateResourceAllocationStatus(
        string $id,
        string $allocationId,
        UpdateTheatreProcedureResourceAllocationStatusRequest $request,
        UpdateTheatreProcedureResourceAllocationStatusUseCase $useCase
    ): JsonResponse {
        try {
            $allocation = $useCase->execute(
                theatreProcedureId: $id,
                allocationId: $allocationId,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actualStartAt: $request->input('actualStartAt'),
                actualEndAt: $request->input('actualEndAt'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (TheatreProcedureResourceAllocationConflictException $exception) {
            return $this->validationError('status', $exception->getMessage());
        }

        abort_if($allocation === null, 404, 'Theatre resource allocation not found.');

        return response()->json([
            'data' => TheatreProcedureResourceAllocationResponseTransformer::transform($allocation),
        ]);
    }

    public function auditLogs(string $id, Request $request, ListTheatreProcedureAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(theatreProcedureId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Theatre procedure not found.');

        return response()->json([
            'data' => array_map([TheatreProcedureAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListTheatreProcedureAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            theatreProcedureId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Theatre procedure not found.');

        $safeId = $this->safeExportIdentifier($id, 'theatre-procedure');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('theatre_procedure_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    theatreProcedureId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function resourceAllocationAuditLogs(
        string $id,
        string $allocationId,
        Request $request,
        ListTheatreProcedureResourceAllocationAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(
            theatreProcedureId: $id,
            allocationId: $allocationId,
            filters: $request->all(),
        );
        abort_if($result === null, 404, 'Theatre resource allocation not found.');

        return response()->json([
            'data' => array_map([TheatreProcedureResourceAllocationAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportResourceAllocationAuditLogsCsv(
        string $id,
        string $allocationId,
        Request $request,
        ListTheatreProcedureResourceAllocationAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            theatreProcedureId: $id,
            allocationId: $allocationId,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Theatre resource allocation not found.');

        $safeId = $this->safeExportIdentifier($id, 'theatre-procedure');
        $safeAllocationId = $this->safeExportIdentifier($allocationId, 'resource-allocation');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('theatre_procedure_resource_allocation_audit_%s_%s_%s', $safeId, $safeAllocationId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $allocationId, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    theatreProcedureId: $id,
                    allocationId: $allocationId,
                    filters: $pageFilters,
                );
            },
        );
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
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

    private function validationExceptionResponse(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        $field = array_key_first($errors) ?? 'order';
        $message = $errors[$field][0] ?? $exception->getMessage();

        return response()->json([
            'message' => $message,
            'code' => 'VALIDATION_ERROR',
            'errors' => $errors,
        ], 422);
    }

    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'patientId' => 'patient_id',
            'admissionId' => 'admission_id',
            'appointmentId' => 'appointment_id',
            'entryMode' => 'entry_mode',
            'orderSessionId' => 'clinical_order_session_id',
            'serviceRequestId' => 'service_request_id',
            'replacesOrderId' => 'replaces_order_id',
            'addOnToOrderId' => 'add_on_to_order_id',
            'theatreProcedureCatalogItemId' => 'theatre_procedure_catalog_item_id',
            'procedureType' => 'procedure_type',
            'procedureName' => 'procedure_name',
            'operatingClinicianUserId' => 'operating_clinician_user_id',
            'anesthetistUserId' => 'anesthetist_user_id',
            'theatreRoomServicePointId' => 'theatre_room_service_point_id',
            'theatreRoomName' => 'theatre_room_name',
            'scheduledAt' => 'scheduled_at',
            'startedAt' => 'started_at',
            'completedAt' => 'completed_at',
            'status' => 'status',
            'statusReason' => 'status_reason',
            'notes' => 'notes',
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

    private function toResourceAllocationPayload(array $validated): array
    {
        $fieldMap = [
            'resourceType' => 'resource_type',
            'resourceReference' => 'resource_reference',
            'roleLabel' => 'role_label',
            'plannedStartAt' => 'planned_start_at',
            'plannedEndAt' => 'planned_end_at',
            'actualStartAt' => 'actual_start_at',
            'actualEndAt' => 'actual_end_at',
            'status' => 'status',
            'statusReason' => 'status_reason',
            'notes' => 'notes',
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

    private static function isEligibleTheatreRoomResource(array $resource): bool
    {
        $servicePointType = strtolower(trim((string) ($resource['service_point_type'] ?? '')));
        if (in_array($servicePointType, [
            'operating_theatre',
            'emergency_theatre',
            'obstetric_theatre',
            'procedure_room',
            'dressing_room',
        ], true)) {
            return true;
        }

        $haystack = strtolower(trim(implode(' ', array_filter([
            (string) ($resource['code'] ?? ''),
            (string) ($resource['name'] ?? ''),
            (string) ($resource['service_point_type'] ?? ''),
            (string) ($resource['location'] ?? ''),
        ]))));

        if ($haystack === '') {
            return false;
        }

        foreach (['theatre', 'operating', 'surgery', 'surgical', 'procedure', 'dressing'] as $token) {
            if (str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $facilityIds
     * @param  array<int, string>  $tenantIds
     * @return array<int, array<string, mixed>>
     */
    private function findEligibleTheatreRooms(
        ?string $searchTerm,
        array $facilityIds,
        array $tenantIds,
        int $limit,
    ): array {
        $query = FacilityResourceModel::query()
            ->where('resource_type', FacilityResourceType::SERVICE_POINT->value)
            ->where('status', 'active');

        if ($facilityIds !== []) {
            $query->whereIn('facility_id', $facilityIds);
        } elseif ($tenantIds !== []) {
            $query->where(function (Builder $builder) use ($tenantIds): void {
                $builder
                    ->whereIn('tenant_id', $tenantIds)
                    ->orWhereNull('tenant_id');
            });
        }

        if ($searchTerm !== null && $searchTerm !== '') {
            $like = '%'.$searchTerm.'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('service_point_type', 'like', $like)
                    ->orWhere('location', 'like', $like);
            });
        }

        return $query
            ->orderBy('name')
            ->get()
            ->map(static fn (FacilityResourceModel $resource): array => $resource->toArray())
            ->filter(static fn (array $resource): bool => self::isEligibleTheatreRoomResource($resource))
            ->take($limit)
            ->values()
            ->all();
    }
}
