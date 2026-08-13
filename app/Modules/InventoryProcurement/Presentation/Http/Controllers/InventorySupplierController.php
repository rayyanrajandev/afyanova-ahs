<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventorySupplierCodeException;
use App\Modules\InventoryProcurement\Application\UseCases\CreateInventorySupplierUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\GetInventorySupplierUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventorySupplierAuditLogsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventorySupplierStatusCountsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventorySuppliersUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateInventorySupplierStatusUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateInventorySupplierUseCase;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\StoreInventorySupplierRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\UpdateInventorySupplierRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\UpdateInventorySupplierStatusRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventorySupplierAuditLogResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventorySupplierResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventorySupplierController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListInventorySuppliersUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InventorySupplierResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListInventorySupplierStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function store(StoreInventorySupplierRequest $request, CreateInventorySupplierUseCase $useCase): JsonResponse
    {
        try {
            $supplier = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateInventorySupplierCodeException $exception) {
            return $this->validationError('supplierCode', $exception->getMessage());
        }

        return response()->json([
            'data' => InventorySupplierResponseTransformer::transform($supplier),
        ], 201);
    }

    public function show(string $id, GetInventorySupplierUseCase $useCase): JsonResponse
    {
        $supplier = $useCase->execute($id);
        abort_if($supplier === null, 404, 'Supplier not found.');

        return response()->json([
            'data' => InventorySupplierResponseTransformer::transform($supplier),
        ]);
    }

    public function update(
        string $id,
        UpdateInventorySupplierRequest $request,
        UpdateInventorySupplierUseCase $useCase
    ): JsonResponse {
        try {
            $supplier = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateInventorySupplierCodeException $exception) {
            return $this->validationError('supplierCode', $exception->getMessage());
        }

        abort_if($supplier === null, 404, 'Supplier not found.');

        return response()->json([
            'data' => InventorySupplierResponseTransformer::transform($supplier),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateInventorySupplierStatusRequest $request,
        UpdateInventorySupplierStatusUseCase $useCase
    ): JsonResponse {
        try {
            $supplier = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($supplier === null, 404, 'Supplier not found.');

        return response()->json([
            'data' => InventorySupplierResponseTransformer::transform($supplier),
        ]);
    }

    public function auditLogs(
        string $id,
        Request $request,
        ListInventorySupplierAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(inventorySupplierId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Supplier not found.');

        return response()->json([
            'data' => array_map([InventorySupplierAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListInventorySupplierAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            inventorySupplierId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Supplier not found.');

        $safeId = $this->safeExportIdentifier($id, 'inventory-supplier');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('inventory_supplier_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    inventorySupplierId: $id,
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
            'supplierCode' => 'supplier_code',
            'supplierName' => 'supplier_name',
            'tinNumber' => 'tin_number',
            'bankAccountNumber' => 'bank_account_number',
            'lipaNumber' => 'lipa_number',
            'contactPerson' => 'contact_person',
            'phone' => 'phone',
            'email' => 'email',
            'addressLine' => 'address_line',
            'countryCode' => 'country_code',
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
