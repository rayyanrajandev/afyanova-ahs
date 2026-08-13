<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\Platform\Application\Exceptions\DuplicateFacilityResourceCodeException;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Application\UseCases\CreateFacilityResourceUseCase;
use App\Modules\Platform\Application\UseCases\GetFacilityResourceUseCase;
use App\Modules\Platform\Application\UseCases\ListFacilityResourceAuditLogsUseCase;
use App\Modules\Platform\Application\UseCases\ListFacilityResourcesUseCase;
use App\Modules\Platform\Application\UseCases\ListFacilityResourceStatusCountsUseCase;
use App\Modules\Platform\Application\UseCases\UpdateFacilityResourceStatusUseCase;
use App\Modules\Platform\Application\UseCases\UpdateFacilityResourceUseCase;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceType;
use App\Modules\Platform\Presentation\Http\Requests\StoreObservationRoomRequest;
use App\Modules\Platform\Presentation\Http\Requests\StoreServicePointRequest;
use App\Modules\Platform\Presentation\Http\Requests\StoreWardBedRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdateFacilityResourceStatusRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdateObservationRoomRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdateServicePointRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdateWardBedRequest;
use App\Modules\Platform\Presentation\Http\Transformers\FacilityResourceAuditLogResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\FacilityResourceResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacilityResourceRegistryController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function servicePoints(Request $request, ListFacilityResourcesUseCase $useCase): JsonResponse
    {
        return $this->listResources(FacilityResourceType::SERVICE_POINT->value, $request, $useCase);
    }

    public function servicePointStatusCounts(
        Request $request,
        ListFacilityResourceStatusCountsUseCase $useCase
    ): JsonResponse {
        return $this->resourceStatusCounts(FacilityResourceType::SERVICE_POINT->value, $request, $useCase);
    }

    public function storeServicePoint(StoreServicePointRequest $request, CreateFacilityResourceUseCase $useCase): JsonResponse
    {
        return $this->storeResource(
            resourceType: FacilityResourceType::SERVICE_POINT->value,
            request: $request,
            useCase: $useCase,
            fieldMap: [
                'code' => 'code',
                'name' => 'name',
                'departmentId' => 'department_id',
                'servicePointType' => 'service_point_type',
                'location' => 'location',
                'notes' => 'notes',
            ],
        );
    }

    public function servicePoint(string $id, GetFacilityResourceUseCase $useCase): JsonResponse
    {
        return $this->showResource(FacilityResourceType::SERVICE_POINT->value, $id, $useCase);
    }

    public function updateServicePoint(
        string $id,
        UpdateServicePointRequest $request,
        UpdateFacilityResourceUseCase $useCase
    ): JsonResponse {
        return $this->updateResource(
            resourceType: FacilityResourceType::SERVICE_POINT->value,
            id: $id,
            request: $request,
            useCase: $useCase,
            fieldMap: [
                'code' => 'code',
                'name' => 'name',
                'departmentId' => 'department_id',
                'servicePointType' => 'service_point_type',
                'location' => 'location',
                'notes' => 'notes',
            ],
        );
    }

    public function updateServicePointStatus(
        string $id,
        UpdateFacilityResourceStatusRequest $request,
        UpdateFacilityResourceStatusUseCase $useCase
    ): JsonResponse {
        return $this->updateResourceStatus(FacilityResourceType::SERVICE_POINT->value, $id, $request, $useCase);
    }

    public function servicePointAuditLogs(
        string $id,
        Request $request,
        ListFacilityResourceAuditLogsUseCase $useCase
    ): JsonResponse {
        return $this->auditLogs(FacilityResourceType::SERVICE_POINT->value, $id, $request, $useCase);
    }

    public function exportServicePointAuditLogsCsv(
        string $id,
        Request $request,
        ListFacilityResourceAuditLogsUseCase $useCase
    ): StreamedResponse {
        return $this->exportAuditLogsCsv(FacilityResourceType::SERVICE_POINT->value, 'service-point', $id, $request, $useCase);
    }

    /**
     * Unlike servicePoints()/listResources(), this doesn't go through the
     * shared listing helper — ward/bed rows are joined with live admission
     * occupancy so a facility admin can see (and, in updateWardBedStatus(),
     * be blocked from deactivating) a bed that currently has a patient in
     * it. Mirrors ListAvailableBedsUseCase's cross-module composition
     * (Admission depending on Platform's FacilityResourceRepository); doing
     * the join here instead keeps that generic use case free of
     * Admission-specific knowledge, and keeps servicePoints() — which has no
     * occupancy concept — on the plain shared path.
     */
    public function wardBeds(
        Request $request,
        ListFacilityResourcesUseCase $useCase,
        AdmissionRepositoryInterface $admissionRepository,
    ): JsonResponse {
        $result = $useCase->execute(FacilityResourceType::WARD_BED->value, $request->all());

        $bedIds = array_values(array_filter(array_map(
            static fn (array $resource): ?string => $resource['id'] ?? null,
            $result['data'],
        )));
        $occupancy = $bedIds === [] ? [] : $admissionRepository->activeAdmissionsByBedResourceIds($bedIds);

        return response()->json([
            'data' => array_map(
                fn (array $resource): array => $this->withBedOccupancy($resource, $occupancy),
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function wardBedStatusCounts(
        Request $request,
        ListFacilityResourceStatusCountsUseCase $useCase
    ): JsonResponse {
        return $this->resourceStatusCounts(FacilityResourceType::WARD_BED->value, $request, $useCase);
    }

    public function storeWardBed(StoreWardBedRequest $request, CreateFacilityResourceUseCase $useCase): JsonResponse
    {
        return $this->storeResource(
            resourceType: FacilityResourceType::WARD_BED->value,
            request: $request,
            useCase: $useCase,
            fieldMap: [
                'code' => 'code',
                'name' => 'name',
                'departmentId' => 'department_id',
                'wardName' => 'ward_name',
                'bedNumber' => 'bed_number',
                'location' => 'location',
                'notes' => 'notes',
                'chargeableItemId' => 'chargeable_item_id',
            ],
        );
    }

    public function wardBed(
        string $id,
        GetFacilityResourceUseCase $useCase,
        AdmissionRepositoryInterface $admissionRepository,
    ): JsonResponse {
        $resource = $useCase->execute($id, FacilityResourceType::WARD_BED->value);
        abort_if($resource === null, 404, 'Resource not found.');

        $occupancy = $admissionRepository->activeAdmissionsByBedResourceIds([$id]);

        return response()->json([
            'data' => $this->withBedOccupancy($resource, $occupancy),
        ]);
    }

    public function updateWardBed(
        string $id,
        UpdateWardBedRequest $request,
        UpdateFacilityResourceUseCase $useCase
    ): JsonResponse {
        return $this->updateResource(
            resourceType: FacilityResourceType::WARD_BED->value,
            id: $id,
            request: $request,
            useCase: $useCase,
            fieldMap: [
                'code' => 'code',
                'name' => 'name',
                'departmentId' => 'department_id',
                'wardName' => 'ward_name',
                'bedNumber' => 'bed_number',
                'location' => 'location',
                'notes' => 'notes',
                'chargeableItemId' => 'chargeable_item_id',
            ],
        );
    }

    /**
     * Blocks deactivating a bed that currently has an active admission in
     * it — unlike the booking conflicts elsewhere in this codebase, there's
     * no legitimate reason to deactivate an occupied bed, so this is a hard
     * block with no override, checked before the generic status use case
     * runs (which has no notion of occupancy at all).
     */
    public function updateWardBedStatus(
        string $id,
        UpdateFacilityResourceStatusRequest $request,
        UpdateFacilityResourceStatusUseCase $useCase,
        AdmissionRepositoryInterface $admissionRepository,
    ): JsonResponse {
        if ($request->string('status')->value() === 'inactive') {
            $occupancy = $admissionRepository->activeAdmissionsByBedResourceIds([$id]);
            $occupyingAdmission = $occupancy[$id] ?? null;
            if ($occupyingAdmission !== null) {
                return $this->validationError(
                    'status',
                    sprintf(
                        'This bed is currently occupied by admission %s. Discharge or transfer the patient before deactivating it.',
                        (string) ($occupyingAdmission['admission_number'] ?? 'an active admission'),
                    ),
                );
            }
        }

        return $this->updateResourceStatus(FacilityResourceType::WARD_BED->value, $id, $request, $useCase);
    }

    public function wardBedAuditLogs(
        string $id,
        Request $request,
        ListFacilityResourceAuditLogsUseCase $useCase
    ): JsonResponse {
        return $this->auditLogs(FacilityResourceType::WARD_BED->value, $id, $request, $useCase);
    }

    public function exportWardBedAuditLogsCsv(
        string $id,
        Request $request,
        ListFacilityResourceAuditLogsUseCase $useCase
    ): StreamedResponse {
        return $this->exportAuditLogsCsv(FacilityResourceType::WARD_BED->value, 'ward-bed', $id, $request, $useCase);
    }

    /**
     * Mirrors wardBeds() — observation rooms are patient-placed and billed
     * the same way beds are (via bed_resource_id + chargeable_item_id), so
     * they need the same occupancy join and occupied-room deactivation
     * guard, unlike servicePoints() which has no occupancy concept.
     */
    public function observationRooms(
        Request $request,
        ListFacilityResourcesUseCase $useCase,
        AdmissionRepositoryInterface $admissionRepository,
    ): JsonResponse {
        $result = $useCase->execute(FacilityResourceType::OBSERVATION_ROOM->value, $request->all());

        $roomIds = array_values(array_filter(array_map(
            static fn (array $resource): ?string => $resource['id'] ?? null,
            $result['data'],
        )));
        $occupancy = $roomIds === [] ? [] : $admissionRepository->activeAdmissionsByBedResourceIds($roomIds);

        return response()->json([
            'data' => array_map(
                fn (array $resource): array => $this->withBedOccupancy($resource, $occupancy),
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function observationRoomStatusCounts(
        Request $request,
        ListFacilityResourceStatusCountsUseCase $useCase
    ): JsonResponse {
        return $this->resourceStatusCounts(FacilityResourceType::OBSERVATION_ROOM->value, $request, $useCase);
    }

    public function storeObservationRoom(StoreObservationRoomRequest $request, CreateFacilityResourceUseCase $useCase): JsonResponse
    {
        return $this->storeResource(
            resourceType: FacilityResourceType::OBSERVATION_ROOM->value,
            request: $request,
            useCase: $useCase,
            fieldMap: [
                'code' => 'code',
                'name' => 'name',
                'departmentId' => 'department_id',
                'roomName' => 'ward_name',
                'roomNumber' => 'bed_number',
                'genderRestriction' => 'gender_restriction',
                'location' => 'location',
                'notes' => 'notes',
                'chargeableItemId' => 'chargeable_item_id',
            ],
        );
    }

    public function observationRoom(
        string $id,
        GetFacilityResourceUseCase $useCase,
        AdmissionRepositoryInterface $admissionRepository,
    ): JsonResponse {
        $resource = $useCase->execute($id, FacilityResourceType::OBSERVATION_ROOM->value);
        abort_if($resource === null, 404, 'Resource not found.');

        $occupancy = $admissionRepository->activeAdmissionsByBedResourceIds([$id]);

        return response()->json([
            'data' => $this->withBedOccupancy($resource, $occupancy),
        ]);
    }

    public function updateObservationRoom(
        string $id,
        UpdateObservationRoomRequest $request,
        UpdateFacilityResourceUseCase $useCase
    ): JsonResponse {
        return $this->updateResource(
            resourceType: FacilityResourceType::OBSERVATION_ROOM->value,
            id: $id,
            request: $request,
            useCase: $useCase,
            fieldMap: [
                'code' => 'code',
                'name' => 'name',
                'departmentId' => 'department_id',
                'roomName' => 'ward_name',
                'roomNumber' => 'bed_number',
                'genderRestriction' => 'gender_restriction',
                'location' => 'location',
                'notes' => 'notes',
                'chargeableItemId' => 'chargeable_item_id',
            ],
        );
    }

    /**
     * Blocks deactivating a room that currently has an active admission in
     * it — same hard-block rule as updateWardBedStatus(), no override.
     */
    public function updateObservationRoomStatus(
        string $id,
        UpdateFacilityResourceStatusRequest $request,
        UpdateFacilityResourceStatusUseCase $useCase,
        AdmissionRepositoryInterface $admissionRepository,
    ): JsonResponse {
        if ($request->string('status')->value() === 'inactive') {
            $occupancy = $admissionRepository->activeAdmissionsByBedResourceIds([$id]);
            $occupyingAdmission = $occupancy[$id] ?? null;
            if ($occupyingAdmission !== null) {
                return $this->validationError(
                    'status',
                    sprintf(
                        'This room is currently occupied by admission %s. Discharge or transfer the patient before deactivating it.',
                        (string) ($occupyingAdmission['admission_number'] ?? 'an active admission'),
                    ),
                );
            }
        }

        return $this->updateResourceStatus(FacilityResourceType::OBSERVATION_ROOM->value, $id, $request, $useCase);
    }

    public function observationRoomAuditLogs(
        string $id,
        Request $request,
        ListFacilityResourceAuditLogsUseCase $useCase
    ): JsonResponse {
        return $this->auditLogs(FacilityResourceType::OBSERVATION_ROOM->value, $id, $request, $useCase);
    }

    public function exportObservationRoomAuditLogsCsv(
        string $id,
        Request $request,
        ListFacilityResourceAuditLogsUseCase $useCase
    ): StreamedResponse {
        return $this->exportAuditLogsCsv(FacilityResourceType::OBSERVATION_ROOM->value, 'observation-room', $id, $request, $useCase);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, array<string, mixed>>  $occupancyByBedId
     * @return array<string, mixed>
     */
    private function withBedOccupancy(array $resource, array $occupancyByBedId): array
    {
        $transformed = FacilityResourceResponseTransformer::transform($resource);
        $occupyingAdmission = $occupancyByBedId[(string) ($resource['id'] ?? '')] ?? null;

        $transformed['isOccupied'] = $occupyingAdmission !== null;
        $transformed['occupiedByAdmissionId'] = $occupyingAdmission['id'] ?? null;
        $transformed['occupiedByAdmissionNumber'] = $occupyingAdmission['admission_number'] ?? null;

        return $transformed;
    }

    private function listResources(string $resourceType, Request $request, ListFacilityResourcesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($resourceType, $request->all());

        return response()->json([
            'data' => array_map([FacilityResourceResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    private function resourceStatusCounts(
        string $resourceType,
        Request $request,
        ListFacilityResourceStatusCountsUseCase $useCase
    ): JsonResponse {
        $counts = $useCase->execute($resourceType, $request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    private function storeResource(
        string $resourceType,
        Request $request,
        CreateFacilityResourceUseCase $useCase,
        array $fieldMap
    ): JsonResponse {
        try {
            $resource = $useCase->execute(
                resourceType: $resourceType,
                payload: $this->toPersistencePayload($request->validated(), $fieldMap),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateFacilityResourceCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        }

        return response()->json([
            'data' => FacilityResourceResponseTransformer::transform($resource),
        ], 201);
    }

    private function showResource(string $resourceType, string $id, GetFacilityResourceUseCase $useCase): JsonResponse
    {
        $resource = $useCase->execute($id, $resourceType);
        abort_if($resource === null, 404, 'Resource not found.');

        return response()->json([
            'data' => FacilityResourceResponseTransformer::transform($resource),
        ]);
    }

    private function updateResource(
        string $resourceType,
        string $id,
        Request $request,
        UpdateFacilityResourceUseCase $useCase,
        array $fieldMap
    ): JsonResponse {
        try {
            $resource = $useCase->execute(
                id: $id,
                resourceType: $resourceType,
                payload: $this->toPersistencePayload($request->validated(), $fieldMap),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateFacilityResourceCodeException $exception) {
            return $this->validationError('code', $exception->getMessage());
        }

        abort_if($resource === null, 404, 'Resource not found.');

        return response()->json([
            'data' => FacilityResourceResponseTransformer::transform($resource),
        ]);
    }

    private function updateResourceStatus(
        string $resourceType,
        string $id,
        UpdateFacilityResourceStatusRequest $request,
        UpdateFacilityResourceStatusUseCase $useCase
    ): JsonResponse {
        try {
            $resource = $useCase->execute(
                id: $id,
                resourceType: $resourceType,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($resource === null, 404, 'Resource not found.');

        return response()->json([
            'data' => FacilityResourceResponseTransformer::transform($resource),
        ]);
    }

    private function auditLogs(
        string $resourceType,
        string $id,
        Request $request,
        ListFacilityResourceAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(
            resourceId: $id,
            resourceType: $resourceType,
            filters: $request->all(),
        );
        abort_if($result === null, 404, 'Resource not found.');

        return response()->json([
            'data' => array_map([FacilityResourceAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    private function exportAuditLogsCsv(
        string $resourceType,
        string $resourceLabel,
        string $id,
        Request $request,
        ListFacilityResourceAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            resourceId: $id,
            resourceType: $resourceType,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Resource not found.');

        $safeId = $this->safeExportIdentifier($id, $resourceLabel);

        return $this->streamAuditLogCsvExport(
            baseName: sprintf(
                '%s_audit_%s_%s',
                str_replace('-', '_', $resourceLabel),
                $safeId,
                now()->format('Ymd_His'),
            ),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $resourceType, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    resourceId: $id,
                    resourceType: $resourceType,
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

    private function toPersistencePayload(array $validated, array $fieldMap): array
    {
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
