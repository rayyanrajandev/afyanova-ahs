<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventoryItemCodeException;
use App\Modules\InventoryProcurement\Application\Exceptions\InsufficientInventoryStockException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryProcurementReceiptValidationException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryProcurementWorkflowException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryWarehouseNotFoundException;
use App\Modules\InventoryProcurement\Application\UseCases\BulkCreateInventoryItemsFromCatalogUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\CreateInventoryItemUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\CreateInventoryProcurementRequestUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ImportInventoryItemsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\CorrectInventoryStockMovementUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\CreateInventoryStockMovementUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\GetInventoryItemUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ShowInventoryStockMovementUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\GetInventoryStockMovementSummaryUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\GetOpeningStockReportUseCase;
use App\Modules\InventoryProcurement\Application\Services\DepartmentRequisitionScopeResolver;
use App\Modules\InventoryProcurement\Application\UseCases\GetInventoryStockAlertCountsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryItemAuditLogsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryItemsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryProcurementRequestAuditLogsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ExportInventoryProcurementRequestsCsvUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryProcurementRequestsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryStockMovementsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\PlaceInventoryProcurementOrderUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ReceiveInventoryProcurementRequestUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ReconcileInventoryStockUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateInventoryItemStatusUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateInventoryItemUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateInventoryProcurementRequestStatusUseCase;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\PlaceInventoryProcurementOrderRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\ReconcileInventoryStockRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\ReceiveInventoryProcurementRequestRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\ImportInventoryItemsRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\StoreInventoryItemRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\StoreInventoryProcurementRequestRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\CorrectInventoryStockMovementRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\StoreInventoryStockMovementRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\UpdateInventoryItemRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\UpdateInventoryItemStatusRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\UpdateInventoryProcurementRequestStatusRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryItemAuditLogResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryItemResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryProcurementRequestAuditLogResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryProcurementRequestResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryStockMovementResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Support\InventoryStockMovementSourcePresenter;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryProcurementController extends Controller
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    private const STOCK_LEDGER_CSV_SCHEMA_VERSION = 'inventory-stock-ledger-csv.v1';

    private const STOCK_LEDGER_CSV_COLUMNS = [
        'occurredAt',
        'movementType',
        'adjustmentDirection',
        'itemId',
        'itemCode',
        'itemName',
        'sourceKey',
        'sourceLabel',
        'sourceReference',
        'sourceDetail',
        'sourceType',
        'sourceId',
        'procurementRequestId',
        'quantity',
        'quantityDelta',
        'stockBefore',
        'stockAfter',
        'actorType',
        'actorId',
        'reason',
        'notes',
        'metadata',
        'reconciliationSessionReference',
        'reconciliationExpectedStock',
        'reconciliationCountedStock',
        'reconciliationVarianceQuantity',
        'createdAt',
    ];

    public function items(Request $request, ListInventoryItemsUseCase $useCase, DepartmentRequisitionScopeResolver $departmentScopeResolver): JsonResponse
    {
        $filters = $request->all();
        $context = $departmentScopeResolver->contextForUser($request->user());
        if (! (bool) ($context['canSelectAnyDepartment'] ?? false)) {
            $lockedDepartmentId = $context['lockedDepartment']['id'] ?? null;
            if (!$lockedDepartmentId) {
                return response()->json([
                    'data' => [],
                    'meta' => ['currentPage' => 1, 'lastPage' => 1, 'total' => 0],
                ]);
            }

            $filters['requestingDepartmentId'] = $lockedDepartmentId;
        }

        $result = $useCase->execute($filters);

        return response()->json([
            'data' => array_map([InventoryItemResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function storeItem(StoreInventoryItemRequest $request, CreateInventoryItemUseCase $useCase): JsonResponse
    {
        try {
            $item = $useCase->execute(
                payload: $this->toItemPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateInventoryItemCodeException $exception) {
            return $this->validationError('itemCode', $exception->getMessage());
        }

        return response()->json([
            'data' => InventoryItemResponseTransformer::transform($item),
        ], 201);
    }

    public function downloadInventoryItemsImportTemplate(): StreamedResponse
    {
        $filename = 'inventory-items-import-template.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $columns = [
            'itemCode',
            'itemName',
            'genericName',
            'category',
            'subcategory',
            'unit',
            'dispensingUnit',
            'conversionFactor',
            'dosageForm',
            'strength',
            'manufacturer',
            'storageConditions',
            'requiresColdChain',
            'isControlledSubstance',
            'controlledSubstanceSchedule',
            'reorderLevel',
            'maxStockLevel',
            'binLocation',
            'msdCode',
            'nhifCode',
            'barcode',
            'defaultWarehouseId',
            'defaultSupplierId',
        ];

        return response()->stream(function () use ($columns) {
            $output = fopen('php://output', 'w');
            fputcsv($output, $columns);
            fputcsv($output, [
                'ITEM-001',
                'Paracetamol 500mg Tab',
                'Paracetamol',
                'Pharmaceutical',
                'Analgesics',
                'Tablet',
                'Strip',
                '10',
                'Tablet',
                '500mg',
                'PharmaCo',
                'Room temperature',
                'false',
                'false',
                '',
                '100',
                '500',
                'A-01-01',
                'MSD-001',
                'NHIF-001',
                '123456789',
                'replace-with-active-warehouse-uuid',
                '',
            ]);
            fclose($output);
        }, 200, $headers);
    }

    public function importItems(ImportInventoryItemsRequest $request, ImportInventoryItemsUseCase $useCase): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $csvFile = new SplFileObject($file->getPathname(), 'r');

        $results = $useCase->execute(
            csvFile: $csvFile,
            actorId: $request->user()?->id,
        );

        return response()->json($results, count($results['failed']) > 0 ? 422 : 200);
    }

    public function bulkSyncFromCatalog(Request $request, BulkCreateInventoryItemsFromCatalogUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'catalogItemIds' => ['nullable', 'array'],
            'catalogItemIds.*' => ['uuid'],
            'catalogTypes' => ['nullable', 'array'],
            'catalogTypes.*' => ['string', 'in:formulary_item'],
            'defaultWarehouseId' => ['required', 'uuid'],
            'defaultSupplierId' => ['nullable', 'uuid'],
        ]);

        $result = $useCase->execute(
            catalogItemIds: $validated['catalogItemIds'] ?? null,
            defaultWarehouseId: $validated['defaultWarehouseId'],
            defaultSupplierId: $validated['defaultSupplierId'] ?? null,
            actorId: $request->user()?->id,
            catalogTypes: $validated['catalogTypes'] ?? null,
        );

        return response()->json($result, count($result['errors']) > 0 ? 422 : 200);
    }

    public function showItem(string $id, GetInventoryItemUseCase $useCase): JsonResponse
    {
        $item = $useCase->execute($id);
        abort_if($item === null, 404, 'Inventory item not found.');

        return response()->json([
            'data' => InventoryItemResponseTransformer::transform($item),
        ]);
    }

    public function updateItem(
        string $id,
        UpdateInventoryItemRequest $request,
        UpdateInventoryItemUseCase $useCase
    ): JsonResponse {
        try {
            $item = $useCase->execute(
                id: $id,
                payload: $this->toItemPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateInventoryItemCodeException $exception) {
            return $this->validationError('itemCode', $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        abort_if($item === null, 404, 'Inventory item not found.');

        return response()->json([
            'data' => InventoryItemResponseTransformer::transform($item),
        ]);
    }

    public function updateItemStatus(
        string $id,
        UpdateInventoryItemStatusRequest $request,
        UpdateInventoryItemStatusUseCase $useCase
    ): JsonResponse {
        try {
            $item = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($item === null, 404, 'Inventory item not found.');

        return response()->json([
            'data' => InventoryItemResponseTransformer::transform($item),
        ]);
    }

    public function itemAuditLogs(
        string $id,
        Request $request,
        ListInventoryItemAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(inventoryItemId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Inventory item not found.');

        return response()->json([
            'data' => array_map([InventoryItemAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportItemAuditLogsCsv(
        string $id,
        Request $request,
        ListInventoryItemAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            inventoryItemId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Inventory item not found.');

        $safeId = $this->safeExportIdentifier($id, 'inventory-item');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('inventory_item_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    inventoryItemId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function stockAlertCounts(Request $request, GetInventoryStockAlertCountsUseCase $useCase, DepartmentRequisitionScopeResolver $departmentScopeResolver): JsonResponse
    {
        $filters = $request->all();
        $context = $departmentScopeResolver->contextForUser($request->user());
        if (! (bool) ($context['canSelectAnyDepartment'] ?? false)) {
            $lockedDepartmentId = $context['lockedDepartment']['id'] ?? null;
            if (!$lockedDepartmentId) {
                return response()->json([
                    'data' => ['outOfStock' => 0, 'lowStock' => 0, 'healthy' => 0, 'total' => 0],
                ]);
            }

            $filters['requestingDepartmentId'] = $lockedDepartmentId;
        }

        $counts = $useCase->execute($filters);

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function openingStockReport(Request $request, GetOpeningStockReportUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json($result);
    }

    public function stockMovements(Request $request, ListInventoryStockMovementsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InventoryStockMovementResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function stockMovementSummary(Request $request, GetInventoryStockMovementSummaryUseCase $useCase): JsonResponse
    {
        $summary = $useCase->execute($request->all());

        return response()->json([
            'data' => $summary,
        ]);
    }

    public function exportStockMovementsCsv(
        Request $request,
        ListInventoryStockMovementsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 200;

        $firstPage = $useCase->execute($filters);

        return $this->streamCsvExport(
            baseName: sprintf('inventory_stock_ledger_%s', now()->format('Ymd_His')),
            columns: self::STOCK_LEDGER_CSV_COLUMNS,
            writeRows: function ($output) use ($useCase, $filters, $firstPage): void {
                $writeRows = function (array $rows) use ($output): void {
                    foreach ($rows as $movement) {
                        fputcsv($output, $this->stockLedgerCsvRow($movement));
                    }
                };

                $writeRows($firstPage['data'] ?? []);
                $lastPage = max((int) ($firstPage['meta']['lastPage'] ?? 1), 1);

                for ($page = 2; $page <= $lastPage; $page++) {
                    $pageFilters = $filters;
                    $pageFilters['page'] = $page;
                    $pageResult = $useCase->execute($pageFilters);
                    $writeRows($pageResult['data'] ?? []);
                }
            },
            schemaHeaderName: 'X-Inventory-Stock-Ledger-CSV-Schema-Version',
            schemaVersion: self::STOCK_LEDGER_CSV_SCHEMA_VERSION,
        );
    }

    public function showStockMovement(
        string $id,
        ShowInventoryStockMovementUseCase $useCase
    ): JsonResponse {
        try {
            $movement = $useCase->execute($id);
        } catch (InventoryItemNotFoundException $exception) {
            return $this->validationError('id', $exception->getMessage());
        }

        return response()->json([
            'data' => InventoryStockMovementResponseTransformer::transform($movement),
        ]);
    }

    public function storeStockMovement(StoreInventoryStockMovementRequest $request, CreateInventoryStockMovementUseCase $useCase): JsonResponse
    {
        $validated = $request->validated();

        if (($validated['isOpeningStock'] ?? false) && ! $request->user()?->can('inventory.procurement.set-opening-stock')) {
            return response()->json([
                'code' => 'INSUFFICIENT_PERMISSIONS',
                'message' => 'You do not have permission to set opening stock.',
            ], 403);
        }

        try {
            $movement = $useCase->execute(
                payload: $this->toStockMovementPayload($validated),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InventoryItemNotFoundException $exception) {
            return $this->validationError('itemId', $exception->getMessage());
        } catch (InventoryStockOperationValidationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        } catch (InsufficientInventoryStockException $exception) {
            return $this->validationError('quantity', $exception->getMessage());
        }

        return response()->json([
            'data' => InventoryStockMovementResponseTransformer::transform($movement),
        ], 201);
    }

    public function correctStockMovement(
        string $id,
        CorrectInventoryStockMovementRequest $request,
        CorrectInventoryStockMovementUseCase $useCase
    ): JsonResponse {
        try {
            $movement = $useCase->execute(
                movementId: $id,
                payload: $request->validated(),
                actorId: $request->user()?->id,
            );
        } catch (InventoryItemNotFoundException $exception) {
            return $this->validationError('id', $exception->getMessage());
        } catch (InventoryStockOperationValidationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        }

        return response()->json([
            'data' => InventoryStockMovementResponseTransformer::transform($movement),
        ]);
    }

    public function reconcileStock(
        ReconcileInventoryStockRequest $request,
        ReconcileInventoryStockUseCase $useCase
    ): JsonResponse {
        try {
            $movement = $useCase->execute(
                payload: $this->toStockReconciliationPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InventoryItemNotFoundException $exception) {
            return $this->validationError('itemId', $exception->getMessage());
        } catch (InventoryStockOperationValidationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        } catch (InventoryProcurementWorkflowException $exception) {
            return $this->validationError('countedStock', $exception->getMessage());
        }

        return response()->json([
            'data' => InventoryStockMovementResponseTransformer::transform($movement),
        ], 201);
    }

    public function procurementRequests(Request $request, ListInventoryProcurementRequestsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InventoryProcurementRequestResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportProcurementRequestsCsv(Request $request, ExportInventoryProcurementRequestsCsvUseCase $useCase): StreamedResponse
    {
        $export = $useCase->execute($request->all());
        $columns = $export['columns'];
        $rows = $export['rows'];

        return $this->streamCsvExport(
            baseName: sprintf('inventory_procurement_requests_%s', now()->format('Ymd_His')),
            columns: $columns,
            writeRows: static function ($output) use ($columns, $rows): void {
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($columns as $column) {
                        $line[] = $row[$column] ?? '';
                    }
                    fputcsv($output, $line);
                }
            },
            schemaHeaderName: 'X-Inventory-Procurement-Requests-Csv-Schema',
            schemaVersion: '1',
        );
    }

    /**
     * Get active procurement requests grouped by item and department
     * Used by workspace to prevent duplicate requests and show what's pending
     */
    public function activeProcurementRequests(Request $request): JsonResponse
    {
        $facilityId = $request->attributes->get('facility') ?? $this->platformScopeContext->facilityId();
        $tenantId = $request->attributes->get('tenant') ?? $this->platformScopeContext->tenantId();
        
        $activeStatuses = ['pending_approval', 'approved', 'ordered'];
        
        $requests = DB::table('inventory_procurement_requests')
            ->select(
                'item_id',
                'requesting_department_id',
                'status',
                'requested_quantity',
                'request_number',
                'created_at',
                'requested_by_user_id'
            )
            ->when($facilityId !== null, fn($query) => $query->where('facility_id', $facilityId))
            ->when($tenantId !== null, fn($query) => $query->where('tenant_id', $tenantId))
            ->whereIn('status', $activeStatuses)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(static fn($req) => "{$req->requesting_department_id}:{$req->item_id}");

        $grouped = [];
        foreach ($requests as $key => $items) {
            $latest = $items[0]; // Most recent request for this dept+item combo
            $grouped[$key] = [
                'requestingDepartmentId' => $latest->requesting_department_id,
                'itemId' => $latest->item_id,
                'status' => $latest->status,
                'quantity' => $latest->requested_quantity,
                'requestNumber' => $latest->request_number,
                'createdAt' => $latest->created_at,
                'totalRequests' => count($items), // Show if there are multiple active requests
            ];
        }

        return response()->json([
            'data' => array_values($grouped),
            'meta' => [
                'total' => count($grouped),
            ],
        ]);
    }

    public function storeProcurementRequest(
        StoreInventoryProcurementRequestRequest $request,
        CreateInventoryProcurementRequestUseCase $useCase
    ): JsonResponse {
        try {
            $procurementRequest = $useCase->execute(
                payload: $this->toProcurementRequestPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InventoryItemNotFoundException $exception) {
            return $this->validationError('itemId', $exception->getMessage());
        } catch (InventoryProcurementWorkflowException $exception) {
            return $this->validationError('sourceDepartmentRequisitionLineId', $exception->getMessage());
        }

        return response()->json([
            'data' => InventoryProcurementRequestResponseTransformer::transform($procurementRequest),
        ], 201);
    }

    public function updateProcurementRequestStatus(
        string $id,
        UpdateInventoryProcurementRequestStatusRequest $request,
        UpdateInventoryProcurementRequestStatusUseCase $useCase
    ): JsonResponse {
        try {
            $procurementRequest = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($procurementRequest === null, 404, 'Procurement request not found.');

        return response()->json([
            'data' => InventoryProcurementRequestResponseTransformer::transform($procurementRequest),
        ]);
    }

    public function placeProcurementOrder(
        string $id,
        PlaceInventoryProcurementOrderRequest $request,
        PlaceInventoryProcurementOrderUseCase $useCase
    ): JsonResponse {
        try {
            $procurementRequest = $useCase->execute(
                id: $id,
                payload: $this->toProcurementOrderPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InventoryProcurementWorkflowException $exception) {
            return $this->validationError('status', $exception->getMessage());
        }

        abort_if($procurementRequest === null, 404, 'Procurement request not found.');

        return response()->json([
            'data' => InventoryProcurementRequestResponseTransformer::transform($procurementRequest),
        ]);
    }

    public function receiveProcurementRequest(
        string $id,
        ReceiveInventoryProcurementRequestRequest $request,
        ReceiveInventoryProcurementRequestUseCase $useCase
    ): JsonResponse {
        try {
            $procurementRequest = $useCase->execute(
                id: $id,
                payload: $this->toProcurementReceiptPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InventoryProcurementReceiptValidationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        } catch (InventoryStockOperationValidationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        } catch (InventoryProcurementWorkflowException $exception) {
            return $this->validationError('status', $exception->getMessage());
        } catch (InventoryItemNotFoundException $exception) {
            return $this->validationError('itemId', $exception->getMessage());
        } catch (InventoryWarehouseNotFoundException $exception) {
            return $this->validationError('warehouseId', $exception->getMessage());
        }

        abort_if($procurementRequest === null, 404, 'Procurement request not found.');

        // Count partially-issued requisition lines for this item that still have
        // pending quantity > 0, so the frontend can surface the shortage queue.
        $itemId = $procurementRequest['item_id'] ?? null;
        $pendingLineCount = 0;
        if ($itemId) {
            $pendingLineCount = (int) DB::table('inventory_department_requisition_lines as l')
                ->join('inventory_department_requisitions as r', 'l.requisition_id', '=', 'r.id')
                ->where('r.status', 'partially_issued')
                ->where('l.item_id', $itemId)
                ->whereRaw('(COALESCE(l.approved_quantity, 0) - COALESCE(l.issued_quantity, 0)) > 0')
                ->count();
        }

        return response()->json([
            'data' => InventoryProcurementRequestResponseTransformer::transform($procurementRequest),
            'meta' => [
                'replenishment' => [
                    'itemId'           => $itemId,
                    'pendingLineCount' => $pendingLineCount,
                ],
            ],
        ]);
    }

    public function procurementRequestAuditLogs(
        string $id,
        Request $request,
        ListInventoryProcurementRequestAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(inventoryProcurementRequestId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Procurement request not found.');

        return response()->json([
            'data' => array_map([InventoryProcurementRequestAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportProcurementRequestAuditLogsCsv(
        string $id,
        Request $request,
        ListInventoryProcurementRequestAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            inventoryProcurementRequestId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Procurement request not found.');

        $safeId = $this->safeExportIdentifier($id, 'inventory-procurement-request');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('inventory_procurement_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    inventoryProcurementRequestId: $id,
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

    private function toStockMovementPayload(array $validated): array
    {
        $fieldMap = [
            'itemId' => 'item_id',
            'movementType' => 'movement_type',
            'adjustmentDirection' => 'adjustment_direction',
            'batchId' => 'batch_id',
            'batchNumber' => 'batch_number',
            'lotNumber' => 'lot_number',
            'manufactureDate' => 'manufacture_date',
            'expiryDate' => 'expiry_date',
            'binLocation' => 'bin_location',
            'manufacturer' => 'manufacturer',
            'sourceSupplierId' => 'source_supplier_id',
            'sourceWarehouseId' => 'source_warehouse_id',
            'destinationWarehouseId' => 'destination_warehouse_id',
            'destinationDepartmentId' => 'destination_department_id',
            'quantity' => 'quantity',
            'reason' => 'reason',
            'reasonCode' => 'reason_code',
            'isOpeningStock' => 'is_opening_stock',
            'notes' => 'notes',
            'metadata' => 'metadata',
            'occurredAt' => 'occurred_at',
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

    private function toProcurementRequestPayload(array $validated): array
    {
        $fieldMap = [
            'itemId' => 'item_id',
            'itemName' => 'item_name',
            'category' => 'category',
            'unit' => 'unit',
            'reorderLevel' => 'reorder_level',
            'requestedQuantity' => 'requested_quantity',
            'unitCostEstimate' => 'unit_cost_estimate',
            'neededBy' => 'needed_by',
            'supplierId' => 'supplier_id',
            'supplierName' => 'supplier_name',
            'sourceDepartmentRequisitionId' => 'source_department_requisition_id',
            'sourceDepartmentRequisitionLineId' => 'source_department_requisition_line_id',
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

    private function toStockReconciliationPayload(array $validated): array
    {
        $fieldMap = [
            'itemId' => 'item_id',
            'batchId' => 'batch_id',
            'countedStock' => 'counted_stock',
            'countedBatchQuantity' => 'counted_batch_quantity',
            'reason' => 'reason',
            'notes' => 'notes',
            'occurredAt' => 'occurred_at',
            'sessionReference' => 'session_reference',
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

    private function toItemPayload(array $validated): array
    {
        $fieldMap = [
            'itemCode' => 'item_code',
            'msdCode' => 'msd_code',
            'nhifCode' => 'nhif_code',
            'barcode' => 'barcode',
            'codes' => 'codes',
            'clinicalCatalogItemId' => 'clinical_catalog_item_id',
            'itemName' => 'item_name',
            // genericName/dosageForm/strength/isControlledSubstance/controlledSubstanceSchedule
            // dropped from inventory_items in Phase 3 (Inventory_MasterData_Alignment_Plan.md):
            // Pharmaceutical-only and always catalog-linked, so the use cases no longer
            // persist them regardless -- removed here too so the mapping doesn't imply
            // they're still writable.
            'category' => 'category',
            'subcategory' => 'subcategory',
            'venClassification' => 'ven_classification',
            'abcClassification' => 'abc_classification',
            'unit' => 'unit',
            'dispensingUnit' => 'dispensing_unit',
            'conversionFactor' => 'conversion_factor',
            'binLocation' => 'bin_location',
            'manufacturer' => 'manufacturer',
            'storageConditions' => 'storage_conditions',
            'requiresColdChain' => 'requires_cold_chain',
            'reorderLevel' => 'reorder_level',
            'maxStockLevel' => 'max_stock_level',
            'defaultWarehouseId' => 'default_warehouse_id',
            'defaultSupplierId' => 'default_supplier_id',
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

    private function toProcurementOrderPayload(array $validated): array
    {
        $fieldMap = [
            'purchaseOrderNumber' => 'purchase_order_number',
            'orderedQuantity' => 'ordered_quantity',
            'unitCostEstimate' => 'unit_cost_estimate',
            'neededBy' => 'needed_by',
            'supplierId' => 'supplier_id',
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

    private function toProcurementReceiptPayload(array $validated): array
    {
        $fieldMap = [
            'receivedQuantity' => 'received_quantity',
            'receivedUnitCost' => 'received_unit_cost',
            'receivedUnit' => 'unit',
            'warehouseId' => 'receiving_warehouse_id',
            'batchNumber' => 'batch_number',
            'lotNumber' => 'lot_number',
            'manufactureDate' => 'manufacture_date',
            'expiryDate' => 'expiry_date',
            'binLocation' => 'bin_location',
            'reason' => 'reason',
            'notes' => 'notes',
            'occurredAt' => 'occurred_at',
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

    /**
     * @return array<int, string>
     */
    private function stockLedgerCsvRow(array $movement): array
    {
        $actorId = $movement['actor_id'] ?? null;
        $item = is_array($movement['item'] ?? null) ? $movement['item'] : [];
        $metadata = is_array($movement['metadata'] ?? null) ? $movement['metadata'] : [];
        $isReconciliation = ($metadata['source'] ?? null) === 'stock_reconciliation';
        $source = InventoryStockMovementSourcePresenter::describe($movement);

        return [
            (string) ($movement['occurred_at'] ?? ''),
            (string) ($movement['movement_type'] ?? ''),
            (string) ($movement['adjustment_direction'] ?? ''),
            (string) ($movement['item_id'] ?? ''),
            (string) ($item['item_code'] ?? ''),
            (string) ($item['item_name'] ?? ''),
            (string) ($source['key'] ?? ''),
            (string) ($source['label'] ?? ''),
            (string) ($source['reference'] ?? ''),
            (string) ($source['detail'] ?? ''),
            (string) ($movement['source_type'] ?? ''),
            (string) ($movement['source_id'] ?? ''),
            (string) ($movement['procurement_request_id'] ?? ''),
            (string) ($movement['quantity'] ?? ''),
            (string) ($movement['quantity_delta'] ?? ''),
            (string) ($movement['stock_before'] ?? ''),
            (string) ($movement['stock_after'] ?? ''),
            $actorId === null ? 'system' : 'user',
            $actorId === null ? '' : (string) $actorId,
            (string) ($movement['reason'] ?? ''),
            (string) ($movement['notes'] ?? ''),
            $this->jsonForCsv($movement['metadata'] ?? []),
            $isReconciliation ? (string) ($metadata['sessionReference'] ?? '') : '',
            $isReconciliation ? (string) ($metadata['expectedStock'] ?? '') : '',
            $isReconciliation ? (string) ($metadata['countedStock'] ?? '') : '',
            $isReconciliation ? (string) ($metadata['varianceQuantity'] ?? '') : '',
            (string) ($movement['created_at'] ?? ''),
        ];
    }

    private function jsonForCsv(mixed $value): string
    {
        $encoded = json_encode($value ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }
}
