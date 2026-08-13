<?php

namespace App\Modules\ServiceRequest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\ServiceRequest\Application\Exceptions\ActiveServiceRequestAlreadyExistsException;
use App\Modules\ServiceRequest\Application\Exceptions\CatalogItemNotEligibleForDirectServiceException;
use App\Modules\ServiceRequest\Application\Exceptions\MedicationReferenceNotEligibleForDirectServiceException;
use App\Modules\ServiceRequest\Application\Exceptions\PatientNotEligibleForServiceRequestException;
use App\Modules\ServiceRequest\Application\Exceptions\ServiceRequestDepartmentScopeException;
use App\Modules\ServiceRequest\Application\Exceptions\ServiceRequestStatusTransitionException;
use App\Modules\ServiceRequest\Application\Services\ServiceRequestDepartmentScopeResolver;
use App\Modules\ServiceRequest\Application\UseCases\AddServiceRequestItemsUseCase;
use App\Modules\ServiceRequest\Application\UseCases\CreateServiceRequestUseCase;
use App\Modules\ServiceRequest\Application\UseCases\ExportServiceRequestsCsvUseCase;
use App\Modules\ServiceRequest\Application\UseCases\GetServiceRequestUseCase;
use App\Modules\ServiceRequest\Application\UseCases\ListServiceRequestAuditEventsUseCase;
use App\Modules\ServiceRequest\Application\UseCases\ListServiceRequestStatusCountsUseCase;
use App\Modules\ServiceRequest\Application\UseCases\ListServiceRequestsUseCase;
use App\Modules\ServiceRequest\Application\UseCases\ListWalkInDepartmentOptionsUseCase;
use App\Modules\ServiceRequest\Application\UseCases\UpdateServiceRequestStatusUseCase;
use App\Modules\ServiceRequest\Presentation\Http\Requests\AddServiceRequestItemsRequest;
use App\Modules\ServiceRequest\Presentation\Http\Requests\StoreServiceRequestRequest;
use App\Modules\ServiceRequest\Presentation\Http\Requests\UpdateServiceRequestStatusRequest;
use App\Modules\ServiceRequest\Presentation\Http\Transformers\ServiceRequestResponseTransformer;
use App\Support\Audit\AuditLogPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceRequestController extends Controller
{
    private const AUDIT_ACTION_LABELS = [
        'service_request.created' => 'Request created',
        'service_request.status_updated' => 'Status updated',
        'service_request.linked_order_created' => 'Clinical order linked',
    ];

    public function index(
        Request $request,
        ListServiceRequestsUseCase $useCase,
        ServiceRequestDepartmentScopeResolver $scopeResolver,
    ): JsonResponse {
        $scope = $scopeResolver->resolve($request->user());
        $result = $useCase->execute($request->all(), $scope);

        return response()->json([
            'data' => array_map([ServiceRequestResponseTransformer::class, 'transform'], $result['data']),
            'meta' => array_merge($result['meta'], ['departmentScopeMissing' => $scope->hasNoAssignedDepartment()]),
        ]);
    }

    public function exportCsv(Request $request, ExportServiceRequestsCsvUseCase $useCase): StreamedResponse
    {
        return $useCase->execute($request->all());
    }

    public function auditEvents(
        string $id,
        GetServiceRequestUseCase $getServiceRequest,
        ListServiceRequestAuditEventsUseCase $useCase,
    ): JsonResponse {
        abort_if($getServiceRequest->execute($id) === null, 404, 'Service request not found.');

        $events = $useCase->execute($id);

        return response()->json([
            'data' => array_map(static function (array $row): array {
                return AuditLogPresenter::enrich([
                    'id' => $row['id'] ?? null,
                    'action' => $row['action'] ?? null,
                    'actorId' => $row['actor_user_id'] ?? null,
                    'actorUserId' => $row['actor_user_id'] ?? null,
                    'fromStatus' => $row['from_status'] ?? null,
                    'toStatus' => $row['to_status'] ?? null,
                    'metadata' => $row['metadata'] ?? null,
                    'createdAt' => isset($row['created_at'])
                        ? (is_string($row['created_at'])
                            ? $row['created_at']
                            : optional($row['created_at'])->toISOString())
                        : null,
                ], ['actor_id' => $row['actor_user_id'] ?? null], self::AUDIT_ACTION_LABELS);
            }, $events),
        ]);
    }

    public function statusCounts(
        Request $request,
        ListServiceRequestStatusCountsUseCase $useCase,
        ServiceRequestDepartmentScopeResolver $scopeResolver,
    ): JsonResponse {
        $scope = $scopeResolver->resolve($request->user());
        $counts = $useCase->execute($request->all(), $scope);

        return response()->json([
            'data' => $counts,
            'meta' => ['departmentScopeMissing' => $scope->hasNoAssignedDepartment()],
        ]);
    }

    public function departmentOptions(Request $request, ListWalkInDepartmentOptionsUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute(
                serviceType: $request->query('serviceType') !== null
                    ? (string) $request->query('serviceType')
                    : null,
            ),
        ]);
    }

    public function store(StoreServiceRequestRequest $request, CreateServiceRequestUseCase $useCase): JsonResponse
    {
        try {
            $serviceRequest = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForServiceRequestException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (CatalogItemNotEligibleForDirectServiceException $exception) {
            return $this->validationError('items', $exception->getMessage());
        } catch (MedicationReferenceNotEligibleForDirectServiceException $exception) {
            return $this->validationError('items', $exception->getMessage());
        } catch (ActiveServiceRequestAlreadyExistsException $exception) {
            $existing = $exception->existingRequest();
            $requestNumber = is_string($existing['request_number'] ?? null)
                ? (string) $existing['request_number']
                : 'existing ticket';

            return $this->validationError(
                'serviceType',
                sprintf('This patient already has an active %s direct service ticket.', $requestNumber),
            );
        }

        return response()->json([
            'data' => ServiceRequestResponseTransformer::transform($serviceRequest),
        ], 201);
    }

    public function storeItems(
        string $id,
        AddServiceRequestItemsRequest $request,
        AddServiceRequestItemsUseCase $useCase,
    ): JsonResponse {
        $validated = $request->validated();

        $items = $useCase->execute(
            serviceRequestId: $id,
            items: $validated['items'],
            actorId: $request->user()?->id,
        );

        return response()->json([
            'data' => $items,
        ], 201);
    }

    public function show(string $id, GetServiceRequestUseCase $useCase): JsonResponse
    {
        $serviceRequest = $useCase->execute($id);
        abort_if($serviceRequest === null, 404, 'Service request not found.');

        return response()->json([
            'data' => ServiceRequestResponseTransformer::transform($serviceRequest),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateServiceRequestStatusRequest $request,
        UpdateServiceRequestStatusUseCase $useCase,
        ServiceRequestDepartmentScopeResolver $scopeResolver,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $serviceRequest = $useCase->execute(
                id: $id,
                newStatus: (string) $validated['status'],
                scope: $scopeResolver->resolve($request->user()),
                actorId: $request->user()?->id,
                statusReason: isset($validated['statusReason']) ? (string) $validated['statusReason'] : null,
                linkedOrderNumber: isset($validated['linkedOrderNumber']) ? (string) $validated['linkedOrderNumber'] : null,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (ServiceRequestDepartmentScopeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'DEPARTMENT_SCOPE_FORBIDDEN',
            ], 403);
        } catch (ServiceRequestStatusTransitionException $exception) {
            return $this->validationError('status', $exception->getMessage());
        }

        abort_if($serviceRequest === null, 404, 'Service request not found.');

        return response()->json([
            'data' => ServiceRequestResponseTransformer::transform($serviceRequest),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'patientId' => 'patient_id',
            'appointmentId' => 'appointment_id',
            'departmentId' => 'department_id',
            'serviceType' => 'service_type',
            'priority' => 'priority',
            'notes' => 'notes',
            'items' => 'items',
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
}
