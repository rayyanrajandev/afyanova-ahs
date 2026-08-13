<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventoryWarehouseCodeException;
use App\Modules\InventoryProcurement\Application\UseCases\CreateInventoryWarehouseUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\GetInventoryWarehouseUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryWarehouseAuditLogsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryWarehouseStatusCountsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryWarehousesUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateInventoryWarehouseStatusUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateInventoryWarehouseUseCase;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\StoreInventoryWarehouseRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\UpdateInventoryWarehouseRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\UpdateInventoryWarehouseStatusRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryWarehouseAuditLogResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryWarehouseResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryWarehouseController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListInventoryWarehousesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InventoryWarehouseResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListInventoryWarehouseStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function store(StoreInventoryWarehouseRequest $request, CreateInventoryWarehouseUseCase $useCase): JsonResponse
    {
        try {
            $warehouse = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateInventoryWarehouseCodeException $exception) {
            return $this->validationError('warehouseCode', $exception->getMessage());
        }

        return response()->json([
            'data' => InventoryWarehouseResponseTransformer::transform($warehouse),
        ], 201);
    }

    public function show(string $id, GetInventoryWarehouseUseCase $useCase): JsonResponse
    {
        $warehouse = $useCase->execute($id);
        abort_if($warehouse === null, 404, 'Warehouse not found.');

        return response()->json([
            'data' => InventoryWarehouseResponseTransformer::transform($warehouse),
        ]);
    }

    public function update(
        string $id,
        UpdateInventoryWarehouseRequest $request,
        UpdateInventoryWarehouseUseCase $useCase
    ): JsonResponse {
        try {
            $warehouse = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateInventoryWarehouseCodeException $exception) {
            return $this->validationError('warehouseCode', $exception->getMessage());
        }

        abort_if($warehouse === null, 404, 'Warehouse not found.');

        return response()->json([
            'data' => InventoryWarehouseResponseTransformer::transform($warehouse),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateInventoryWarehouseStatusRequest $request,
        UpdateInventoryWarehouseStatusUseCase $useCase
    ): JsonResponse {
        try {
            $warehouse = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($warehouse === null, 404, 'Warehouse not found.');

        return response()->json([
            'data' => InventoryWarehouseResponseTransformer::transform($warehouse),
        ]);
    }

    public function auditLogs(
        string $id,
        Request $request,
        ListInventoryWarehouseAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(inventoryWarehouseId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Warehouse not found.');

        return response()->json([
            'data' => array_map([InventoryWarehouseAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListInventoryWarehouseAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            inventoryWarehouseId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Warehouse not found.');

        $safeId = $this->safeExportIdentifier($id, 'inventory-warehouse');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('inventory_warehouse_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    inventoryWarehouseId: $id,
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
            'warehouseCode' => 'warehouse_code',
            'warehouseName' => 'warehouse_name',
            'warehouseType' => 'warehouse_type',
            'location' => 'location',
            'contactPerson' => 'contact_person',
            'phone' => 'phone',
            'email' => 'email',
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
}
