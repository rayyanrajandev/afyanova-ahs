<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryProcurementWorkflowException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Application\Services\DepartmentItemCatalogService;
use App\Modules\InventoryProcurement\Application\Services\DepartmentRequisitionScopeResolver;
use App\Modules\InventoryProcurement\Application\Services\DepartmentStockService;
use App\Modules\InventoryProcurement\Application\UseCases\BulkCreateInventoryItemsFromCatalogUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\CreateDispensingClaimLinkUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\CreateInventoryBatchUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\CreateInventoryDepartmentRequisitionUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\CreateMsdOrderUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\CreateWarehouseTransferUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\GetInventoryDepartmentRequisitionUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\GetShortageQueueUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\GetSupplierPerformanceUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryBatchesUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\ListInventoryDepartmentRequisitionsUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\RecordSupplierDeliveryUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\RecordSupplierLeadTimeUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\SyncMsdOrderStatusUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateDispensingClaimStatusUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateInventoryDepartmentRequisitionStatusUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateWarehouseTransferStatusUseCase;
use App\Modules\InventoryProcurement\Application\UseCases\UpdateWarehouseTransferVarianceReviewUseCase;
use App\Modules\InventoryProcurement\Domain\Repositories\DepartmentStockMovementRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDispensingClaimLinkRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryMsdOrderRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierLeadTimeRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseTransferRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Services\MsdApiClientInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryDispensingClaimStatus;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryMsdOrderStatus;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryVenClassification;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseTransferReceiptVarianceType;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseTransferStatus;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseTransferVarianceReviewStatus;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryCategoryModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\StoreInventoryBatchRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\StoreInventoryDepartmentRequisitionRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\UpdateInventoryDepartmentRequisitionStatusRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryBatchResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryDepartmentRequisitionResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryProcurementRequestResponseTransformer;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryWarehouseTransferResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryExtendedController extends Controller
{
    // ─── Batches ──────────────────────────────────────────────

    public function batches(Request $request, ListInventoryBatchesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InventoryBatchResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function storeBatch(StoreInventoryBatchRequest $request, CreateInventoryBatchUseCase $useCase): JsonResponse
    {
        try {
            $batch = $useCase->execute(
                payload: $this->toBatchPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (InventoryItemNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (InventoryStockOperationValidationException $exception) {
            // Same shape the procurement receipt path returns, so the field
            // lands on the right input rather than as an unhandled 500.
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    $exception->field() => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'data' => InventoryBatchResponseTransformer::transform($batch),
        ], 201);
    }

    // ─── Department Requisitions ──────────────────────────────

    public function departmentRequisitions(
        Request $request,
        ListInventoryDepartmentRequisitionsUseCase $useCase,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
    ): JsonResponse {
        $filters = $request->all();
        $context = $departmentScopeResolver->contextForUser($request->user());
        if (! (bool) ($context['canSelectAnyDepartment'] ?? false)) {
            $lockedDepartmentId = $context['lockedDepartment']['id'] ?? null;
            if (! $lockedDepartmentId) {
                return response()->json($this->emptyDepartmentRequisitionListPayload($request));
            }

            $filters['departmentId'] = $lockedDepartmentId;
            unset($filters['department']);
        }

        $result = $useCase->execute($filters);

        return response()->json([
            'data' => array_map([InventoryDepartmentRequisitionResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function storeDepartmentRequisition(
        StoreInventoryDepartmentRequisitionRequest $request,
        CreateInventoryDepartmentRequisitionUseCase $useCase,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
    ): JsonResponse {
        try {
            $payload = $this->toRequisitionPayload($request->validated());
            $resolvedDepartment = $departmentScopeResolver->resolveForStorePayload($payload, $request->user());
            $payload['requesting_department_id'] = $resolvedDepartment['id'];
            $payload['requesting_department'] = $resolvedDepartment['name'];

            $requisition = $useCase->execute(
                payload: $payload,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json([
            'data' => InventoryDepartmentRequisitionResponseTransformer::transform($requisition),
        ], 201);
    }

    public function departmentRequisitionContext(Request $request, DepartmentRequisitionScopeResolver $departmentScopeResolver): JsonResponse
    {
        return response()->json([
            'data' => $departmentScopeResolver->contextForUser($request->user()),
        ]);
    }

    public function departmentRequisition(
        string $id,
        Request $request,
        GetInventoryDepartmentRequisitionUseCase $useCase,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
    ): JsonResponse {
        $requisition = $useCase->execute($id);
        if (! $requisition) {
            return response()->json(['message' => 'Department requisition was not found.'], 404);
        }

        $context = $departmentScopeResolver->contextForUser($request->user());
        if (! (bool) ($context['canSelectAnyDepartment'] ?? false)) {
            $lockedDepartmentId = $context['lockedDepartment']['id'] ?? null;
            if (! $lockedDepartmentId || ($requisition['requesting_department_id'] ?? null) !== $lockedDepartmentId) {
                return response()->json(['message' => 'Department requisition was not found.'], 404);
            }
        }

        return response()->json([
            'data' => InventoryDepartmentRequisitionResponseTransformer::transform($requisition),
        ]);
    }

    public function shortageQueue(
        Request $request,
        GetShortageQueueUseCase $useCase,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
    ): JsonResponse {
        $filters = $request->all();
        $context = $departmentScopeResolver->contextForUser($request->user());
        if (! (bool) ($context['canSelectAnyDepartment'] ?? false)) {
            $lockedDepartmentId = $context['lockedDepartment']['id'] ?? null;
            if (! $lockedDepartmentId) {
                return response()->json($this->emptyShortageQueuePayload($request));
            }

            $filters['departmentId'] = $lockedDepartmentId;
        }

        $result = $useCase->execute($filters);

        return response()->json([
            'data' => array_map(
                static function (array $requisition): array {
                    $transformed = InventoryDepartmentRequisitionResponseTransformer::transform($requisition);
                    // Preserve enrichment fields added by the use case.
                    $transformed['pendingLines'] = array_map(
                        static function (array $line): array {
                            $procurementRequest = $line['procurementRequest'] ?? null;
                            $transformedLine = InventoryDepartmentRequisitionResponseTransformer::transformLine($line);

                            $transformedLine['pendingQuantity'] = $line['pendingQuantity'] ?? null;
                            $transformedLine['availableQuantity'] = $line['availableQuantity'] ?? null;
                            $transformedLine['stockState'] = $line['stockState'] ?? null;
                            $transformedLine['canIssueNow'] = (bool) ($line['canIssueNow'] ?? false);
                            $transformedLine['procurementRequest'] = is_array($procurementRequest)
                                ? InventoryProcurementRequestResponseTransformer::transform($procurementRequest)
                                : null;

                            return $transformedLine;
                        },
                        $requisition['pendingLines'] ?? [],
                    );
                    $transformed['readyLineCount'] = $requisition['readyLineCount'] ?? 0;
                    $transformed['waitingLineCount'] = $requisition['waitingLineCount'] ?? 0;

                    return $transformed;
                },
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function departmentStock(
        Request $request,
        PlatformScopeQueryApplier $platformScopeQueryApplier,
        FeatureFlagResolverInterface $featureFlagResolver,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
    ): JsonResponse {
        $page = max((int) $request->query('page', 1), 1);
        $perPage = min(max((int) $request->query('perPage', 20), 1), 100);
        $searchTerm = trim((string) $request->query('q', ''));
        $itemId = trim((string) $request->query('itemId', ''));
        $departmentId = trim((string) $request->query('departmentId', ''));
        $context = $departmentScopeResolver->contextForUser($request->user());

        if (! (bool) ($context['canSelectAnyDepartment'] ?? false)) {
            $lockedDepartmentId = $context['lockedDepartment']['id'] ?? null;
            if (! $lockedDepartmentId) {
                return response()->json($this->emptyDepartmentStockPayload($page, $perPage));
            }

            $departmentId = $lockedDepartmentId;
        }

        $movementQuery = InventoryStockMovementModel::query()
            ->with('item')
            ->where('movement_type', 'issue')
            ->where(function ($query): void {
                $query
                    ->whereNotNull('destination_department_id')
                    ->orWhere('source_type', 'inventory_department_requisition')
                    ->orWhere('metadata->source', 'department_requisition');
            })
            ->when($itemId !== '', fn ($query) => $query->where('item_id', $itemId))
            ->when($departmentId !== '', function ($query) use ($departmentId): void {
                $query->where(function ($nestedQuery) use ($departmentId): void {
                    $nestedQuery
                        ->where('destination_department_id', $departmentId)
                        ->orWhere('metadata->requesting_department_id', $departmentId);
                });
            })
            ->orderByDesc('occurred_at')
            ->limit(5000);

        if ($this->isPlatformScopingEnabled($featureFlagResolver)) {
            $platformScopeQueryApplier->apply($movementQuery);
        }

        $movements = $movementQuery->get();

        $departmentIds = $movements
            ->map(static function (InventoryStockMovementModel $movement): ?string {
                $metadata = is_array($movement->metadata) ? $movement->metadata : [];

                return $movement->destination_department_id ?: ($metadata['requesting_department_id'] ?? null);
            })
            ->filter()
            ->unique()
            ->values();

        $warehouseIds = $movements
            ->pluck('source_warehouse_id')
            ->filter()
            ->unique()
            ->values();

        $departmentQuery = DepartmentModel::query()->whereIn('id', $departmentIds);
        $warehouseQuery = InventoryWarehouseModel::query()->whereIn('id', $warehouseIds);

        if ($this->isPlatformScopingEnabled($featureFlagResolver)) {
            $platformScopeQueryApplier->apply($departmentQuery);
            $platformScopeQueryApplier->apply($warehouseQuery);
        }

        $departments = $departmentQuery->get()->keyBy('id');
        $warehouses = $warehouseQuery->get()->keyBy('id');
        $rows = [];

        foreach ($movements as $movement) {
            $item = $movement->item;
            if ($item === null) {
                continue;
            }

            $metadata = is_array($movement->metadata) ? $movement->metadata : [];
            $resolvedDepartmentId = $movement->destination_department_id ?: ($metadata['requesting_department_id'] ?? null);
            $department = $resolvedDepartmentId ? $departments->get($resolvedDepartmentId) : null;
            $metadataDepartmentName = trim((string) ($metadata['department'] ?? ''));
            $departmentName = $department?->name ?: ($metadataDepartmentName !== '' ? $metadataDepartmentName : 'Unassigned department');
            $departmentCode = $department?->code;
            $key = ($resolvedDepartmentId ?: 'legacy:'.md5($departmentName)).'|'.$movement->item_id;
            $warehouse = $movement->source_warehouse_id ? $warehouses->get($movement->source_warehouse_id) : null;
            $occurredAt = $movement->occurred_at?->toJSON();

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'id' => $key,
                    'departmentId' => $resolvedDepartmentId,
                    'departmentName' => $departmentName,
                    'departmentCode' => $departmentCode,
                    'itemId' => (string) $movement->item_id,
                    'itemCode' => $item->item_code,
                    'itemName' => $item->item_name,
                    'category' => $item->category,
                    'subcategory' => $item->subcategory,
                    'unit' => $item->unit,
                    'issuedQuantity' => 0.0,
                    'movementCount' => 0,
                    'lastIssuedAt' => $occurredAt,
                    'sourceWarehouseId' => $movement->source_warehouse_id,
                    'sourceWarehouseCode' => $warehouse?->warehouse_code,
                    'sourceWarehouseName' => $warehouse?->warehouse_name,
                ];
            }

            $rows[$key]['issuedQuantity'] += (float) $movement->quantity;
            $rows[$key]['movementCount']++;

            if ($occurredAt !== null && ($rows[$key]['lastIssuedAt'] === null || $occurredAt > $rows[$key]['lastIssuedAt'])) {
                $rows[$key]['lastIssuedAt'] = $occurredAt;
                $rows[$key]['sourceWarehouseId'] = $movement->source_warehouse_id;
                $rows[$key]['sourceWarehouseCode'] = $warehouse?->warehouse_code;
                $rows[$key]['sourceWarehouseName'] = $warehouse?->warehouse_name;
            }
        }

        $filteredRows = collect(array_values($rows))
            ->filter(static function (array $row) use ($searchTerm): bool {
                if ($searchTerm === '') {
                    return true;
                }

                $haystack = strtolower(implode(' ', array_filter([
                    $row['departmentName'] ?? null,
                    $row['departmentCode'] ?? null,
                    $row['itemName'] ?? null,
                    $row['itemCode'] ?? null,
                    $row['category'] ?? null,
                    $row['subcategory'] ?? null,
                    $row['sourceWarehouseName'] ?? null,
                    $row['sourceWarehouseCode'] ?? null,
                ])));

                return str_contains($haystack, strtolower($searchTerm));
            })
            ->sortByDesc('lastIssuedAt')
            ->values();

        $total = $filteredRows->count();
        $pageRows = $filteredRows->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $pageRows->all(),
            'summary' => [
                'totalRows' => $total,
                'departments' => $filteredRows
                    ->map(static fn (array $row): ?string => $row['departmentId'] ?: ($row['departmentName'] ?? null))
                    ->filter(static fn (?string $value): bool => $value !== null && $value !== '')
                    ->unique()
                    ->count(),
                'items' => $filteredRows->pluck('itemId')->filter()->unique()->count(),
                'totalIssuedQuantity' => round((float) $filteredRows->sum('issuedQuantity'), 3),
                'lastIssuedAt' => $filteredRows->pluck('lastIssuedAt')->filter()->max(),
            ],
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => max((int) ceil($total / $perPage), 1),
            ],
        ]);
    }

    public function departmentStockBalances(
        Request $request,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
        DepartmentStockService $departmentStockService,
    ): JsonResponse {
        $page = max((int) $request->query('page', 1), 1);
        $perPage = min(max((int) $request->query('perPage', 20), 1), 100);
        $searchTerm = trim((string) $request->query('q', ''));
        $departmentId = trim((string) $request->query('departmentId', ''));
        $context = $departmentScopeResolver->contextForUser($request->user());

        if (! (bool) ($context['canSelectAnyDepartment'] ?? false)) {
            $lockedDepartmentId = $context['lockedDepartment']['id'] ?? null;
            if (! $lockedDepartmentId) {
                return response()->json([
                    'data' => [],
                    'summary' => [
                        'totalItems' => 0,
                        'totalOnHand' => 0,
                        'totalConsumed' => 0,
                        'totalReturned' => 0,
                        'totalWasted' => 0,
                        'lowStockItems' => 0,
                    ],
                    'meta' => [
                        'currentPage' => $page,
                        'perPage' => $perPage,
                        'total' => 0,
                        'lastPage' => 1,
                    ],
                ]);
            }

            $departmentId = $lockedDepartmentId;
        }

        if ($departmentId === '') {
            return response()->json([
                'data' => [],
                'summary' => [
                    'totalItems' => 0,
                    'totalOnHand' => 0,
                    'totalConsumed' => 0,
                    'totalReturned' => 0,
                    'totalWasted' => 0,
                    'lowStockItems' => 0,
                ],
                'meta' => [
                    'currentPage' => $page,
                    'perPage' => $perPage,
                    'total' => 0,
                    'lastPage' => 1,
                ],
            ]);
        }

        $result = $departmentStockService->listDepartmentStock(
            departmentId: $departmentId,
            search: $searchTerm !== '' ? $searchTerm : null,
            page: $page,
            perPage: $perPage,
        );

        $summary = $departmentStockService->departmentSummary($departmentId);

        $transformedData = array_map(function (mixed $balance): array {
            if (is_object($balance) && method_exists($balance, 'toArray')) {
                $balance = $balance->toArray();
            } elseif (! is_array($balance)) {
                $balance = [];
            }
            $item = $balance['item'] ?? null;
            if (is_object($item) && method_exists($item, 'toArray')) {
                $item = $item->toArray();
            } elseif (is_object($item)) {
                $item = (array) $item;
            }

            return [
                'id' => $balance['id'] ?? null,
                'departmentId' => $balance['department_id'] ?? null,
                'itemId' => $balance['item_id'] ?? null,
                'itemCode' => $item['item_code'] ?? null,
                'itemName' => $item['item_name'] ?? null,
                'category' => $item['category'] ?? null,
                'subcategory' => $item['subcategory'] ?? null,
                'batchId' => $balance['batch_id'] ?? null,
                'unit' => $balance['unit'] ?? null,
                'quantityOnHand' => (float) ($balance['quantity_on_hand'] ?? 0),
                'quantityConsumed' => (float) ($balance['quantity_consumed'] ?? 0),
                'quantityReturned' => (float) ($balance['quantity_returned'] ?? 0),
                'quantityWasted' => (float) ($balance['quantity_wasted'] ?? 0),
                'lastIssuedAt' => $balance['last_issued_at'] ?? null,
                'lastConsumedAt' => $balance['last_consumed_at'] ?? null,
            ];
        }, $result['data']);

        return response()->json([
            'data' => $transformedData,
            'summary' => $summary,
            'meta' => $result['meta'],
        ]);
    }

    public function departmentStockMovements(
        string $departmentId,
        Request $request,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
        DepartmentStockMovementRepositoryInterface $movementRepository,
    ): JsonResponse {
        $page = max((int) $request->query('page', 1), 1);
        $perPage = min(max((int) $request->query('perPage', 20), 1), 100);
        $itemId = trim((string) $request->query('itemId', ''));
        $movementType = trim((string) $request->query('movementType', ''));

        $result = $movementRepository->listByDepartment(
            departmentId: $departmentId,
            itemId: $itemId !== '' ? $itemId : null,
            movementType: $movementType !== '' ? $movementType : null,
            page: $page,
            perPage: $perPage,
        );

        $transformedData = array_map(function (mixed $movement): array {
            if (! is_array($movement)) {
                $movement = is_object($movement) ? (array) $movement : [];
            }
            $item = $movement['item'] ?? null;
            if (is_object($item)) {
                $item = (array) $item;
            }

            return [
                'id' => $movement['id'] ?? null,
                'movementType' => $movement['movement_type'] ?? null,
                'quantity' => (float) ($movement['quantity'] ?? 0),
                'quantityBefore' => (float) ($movement['quantity_before'] ?? 0),
                'quantityAfter' => (float) ($movement['quantity_after'] ?? 0),
                'itemId' => $movement['item_id'] ?? null,
                'itemCode' => $item['item_code'] ?? null,
                'itemName' => $item['item_name'] ?? null,
                'source' => $movement['source'] ?? null,
                'sourceId' => $movement['source_id'] ?? null,
                'notes' => $movement['notes'] ?? null,
                'occurredAt' => $movement['occurred_at'] ?? null,
            ];
        }, $result['data']);

        return response()->json([
            'data' => $transformedData,
            'meta' => $result['meta'],
        ]);
    }

    public function departmentStockReturn(
        string $departmentId,
        Request $request,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
        DepartmentStockService $departmentStockService,
        CurrentPlatformScopeContextInterface $platformScopeContext,
    ): JsonResponse {
        $request->validate([
            'item_id' => 'required|uuid',
            'quantity' => 'required|numeric|min:0.001',
            'batch_id' => 'nullable|uuid',
            'notes' => 'nullable|string|max:1000',
        ]);

        $result = $departmentStockService->recordReturn(
            tenantId: $platformScopeContext->tenantId(),
            facilityId: $platformScopeContext->facilityId(),
            departmentId: $departmentId,
            itemId: $request->input('item_id'),
            quantity: (float) $request->input('quantity'),
            batchId: $request->input('batch_id'),
            actorId: $request->user()?->id,
            notes: $request->input('notes'),
        );

        if ($result === null) {
            return response()->json(['message' => 'No department stock found to return.'], 404);
        }

        return response()->json(['data' => $result]);
    }

    public function departmentStockWastage(
        string $departmentId,
        Request $request,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
        DepartmentStockService $departmentStockService,
        CurrentPlatformScopeContextInterface $platformScopeContext,
    ): JsonResponse {
        $request->validate([
            'item_id' => 'required|uuid',
            'quantity' => 'required|numeric|min:0.001',
            'batch_id' => 'nullable|uuid',
            'notes' => 'nullable|string|max:1000',
        ]);

        $result = $departmentStockService->recordWastage(
            tenantId: $platformScopeContext->tenantId(),
            facilityId: $platformScopeContext->facilityId(),
            departmentId: $departmentId,
            itemId: $request->input('item_id'),
            quantity: (float) $request->input('quantity'),
            batchId: $request->input('batch_id'),
            actorId: $request->user()?->id,
            notes: $request->input('notes'),
        );

        if ($result === null) {
            return response()->json(['message' => 'No department stock found to record wastage.'], 404);
        }

        return response()->json(['data' => $result]);
    }

    public function updateDepartmentRequisitionStatus(
        string $id,
        UpdateInventoryDepartmentRequisitionStatusRequest $request,
        UpdateInventoryDepartmentRequisitionStatusUseCase $useCase
    ): JsonResponse {
        try {
            $requisition = $useCase->execute(
                id: $id,
                newStatus: $request->string('status')->value(),
                payload: $this->toRequisitionStatusPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (InventoryProcurementWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        abort_if($requisition === null, 404, 'Department requisition not found.');

        return response()->json([
            'data' => InventoryDepartmentRequisitionResponseTransformer::transform($requisition),
        ]);
    }

    // ─── Reference Data ───────────────────────────────────────

    public function referenceData(
        PlatformScopeQueryApplier $platformScopeQueryApplier,
        FeatureFlagResolverInterface $featureFlagResolver,
        DepartmentRequisitionScopeResolver $departmentScopeResolver,
        Request $request,
    ): JsonResponse {
        $userDepartmentId = $departmentScopeResolver->contextForUser($request->user())['lockedDepartment']['id'] ?? null;

        // Super admin / warehouse managers see all categories in reference data
        // regardless of which department their staff profile is in. Department-
        // scoped users get only the categories relevant to their role.
        $canSelectAnyDepartment = $departmentScopeResolver->canSelectAnyDepartment($request->user());
        $allowedCategoryValues = $canSelectAnyDepartment
            ? null
            : $departmentScopeResolver->allowedCategoriesForDepartmentId($userDepartmentId);

        // Inventory_MasterData_Alignment_Plan.md Phase 5: categoryOptions now reads
        // from inventory_categories (configurable master data) instead of the
        // InventoryItemCategory enum directly -- same response shape as before, so
        // this is a transparent swap for every existing consumer. The enum itself is
        // untouched and still backs Domain-layer behavior (guards, validation).
        [$allCategories, $allOptions, $subcategoryOptionsByCategory] = $this->loadCategoryMasterData();

        if ($allowedCategoryValues !== null) {
            $allowedCategoryValues = array_flip($allowedCategoryValues);
            $categories = array_intersect_key($allCategories, $allowedCategoryValues);
            $categoryOptions = array_values(array_filter(
                $allOptions,
                static fn (array $option): bool => isset($allowedCategoryValues[$option['value']]),
            ));
            $subcategoryOptions = array_intersect_key($subcategoryOptionsByCategory, $allowedCategoryValues);
        } else {
            $categories = $allCategories;
            $categoryOptions = $allOptions;
            $subcategoryOptions = $subcategoryOptionsByCategory;
        }

        return response()->json([
            'categories' => $categories,
            'categoryOptions' => $categoryOptions,
            'subcategoryOptions' => $subcategoryOptions,
            'venClassifications' => array_map(
                static fn ($case) => ['value' => $case->value, 'label' => $case->label()],
                InventoryVenClassification::cases(),
            ),
            'abcClassifications' => [
                ['value' => 'A', 'label' => 'A – High Value'],
                ['value' => 'B', 'label' => 'B – Medium Value'],
                ['value' => 'C', 'label' => 'C – Low Value'],
            ],
            'storageConditions' => [
                'room_temperature',
                'cool_dry_place',
                'refrigerated_2_8c',
                'frozen_minus_20c',
                'frozen_minus_70c',
                'protect_from_light',
            ],
            'storageConditionOptions' => [
                ['value' => 'room_temperature', 'label' => 'Room Temperature'],
                ['value' => 'cool_dry_place', 'label' => 'Cool & Dry Place'],
                ['value' => 'refrigerated_2_8c', 'label' => 'Refrigerated (2–8°C)'],
                ['value' => 'frozen_minus_20c', 'label' => 'Frozen (−20°C)'],
                ['value' => 'frozen_minus_70c', 'label' => 'Frozen (−70°C)'],
                ['value' => 'protect_from_light', 'label' => 'Protect from Light'],
            ],
            'controlledSubstanceSchedules' => [
                'schedule_I',
                'schedule_II',
                'schedule_III',
                'schedule_IV',
            ],
            'controlledSubstanceScheduleOptions' => [
                ['value' => 'schedule_I', 'label' => 'Schedule I'],
                ['value' => 'schedule_II', 'label' => 'Schedule II'],
                ['value' => 'schedule_III', 'label' => 'Schedule III'],
                ['value' => 'schedule_IV', 'label' => 'Schedule IV'],
            ],
            'transferStatuses' => array_map(
                static fn ($case) => ['value' => $case->value, 'label' => $case->label()],
                InventoryWarehouseTransferStatus::cases(),
            ),
            'dispensingClaimStatuses' => array_map(
                static fn ($case) => ['value' => $case->value, 'label' => $case->label()],
                InventoryDispensingClaimStatus::cases(),
            ),
            'msdOrderStatuses' => array_map(
                static fn ($case) => ['value' => $case->value, 'label' => $case->label()],
                InventoryMsdOrderStatus::cases(),
            ),
            'clinicalCatalogItems' => $this->clinicalCatalogItems(
                platformScopeQueryApplier: $platformScopeQueryApplier,
                featureFlagResolver: $featureFlagResolver,
            ),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @return array{0: array<string, string>, 1: array<int, array<string, mixed>>, 2: array<string, array<int, array<string, string>>>}
     */
    private function loadCategoryMasterData(): array
    {
        $categoryRows = InventoryCategoryModel::query()
            ->with(['subcategories' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $categories = [];
        $categoryOptions = [];
        $subcategoryOptions = [];

        foreach ($categoryRows as $row) {
            $categories[$row->code] = $row->label;
            $categoryOptions[] = [
                'value' => $row->code,
                'label' => $row->label,
                'template' => $row->form_template,
                'description' => $row->description,
                'requiresExpiryTracking' => $row->requires_expiry_tracking,
                'requiresColdChain' => $row->requires_cold_chain,
                'controlledSubstanceEligible' => $row->controlled_substance_eligible,
                'supportsMedicineDetails' => $row->supports_medicine_details,
                'supportsStorageFields' => $row->supports_storage_fields,
                'supportsClinicalClassification' => $row->supports_clinical_classification,
            ];
            $subcategoryOptions[$row->code] = $row->subcategories
                ->map(static fn ($subcategory): array => ['value' => $subcategory->code, 'label' => $subcategory->label])
                ->values()
                ->all();
        }

        return [$categories, $categoryOptions, $subcategoryOptions];
    }

    private function clinicalCatalogItems(
        PlatformScopeQueryApplier $platformScopeQueryApplier,
        FeatureFlagResolverInterface $featureFlagResolver,
    ): array {
        $query = ClinicalCatalogItemModel::query()
            ->select([
                'id', 'catalog_type', 'code', 'name', 'category', 'unit', 'description', 'metadata', 'codes', 'status',
                // Inventory_MasterData_Alignment_Plan.md Phase 1: structured clinical descriptors --
                // the authoritative source for a catalog-linked item's clinical fields as of Phase 3.
                'generic_name', 'dosage_form', 'strength', 'storage_conditions',
                'requires_cold_chain', 'is_controlled_substance', 'controlled_substance_schedule',
            ])
            ->where('catalog_type', ClinicalCatalogType::FORMULARY_ITEM->value)
            ->where('status', ClinicalCatalogItemStatus::ACTIVE->value)
            ->orderBy('name');

        if ($this->isPlatformScopingEnabled($featureFlagResolver)) {
            $platformScopeQueryApplier->apply($query);
        }

        $items = $query->limit(500)->get();

        // Inventory_MasterData_Alignment_Plan.md: CatalogDownstreamSyncService::
        // syncToInventory() auto-provisions a bare inventory item (no warehouse yet)
        // for every formulary item the moment it's created in Clinical Catalog --
        // nothing previously told the Create Item picker this already happened, so
        // picking that same medicine here would silently create a second, duplicate
        // inventory record for the same drug. Surface the existing link instead so
        // the frontend can send the user to finish that record rather than fork it.
        $linkedInventoryItemIdsByCatalogId = InventoryItemModel::query()
            ->whereIn('clinical_catalog_item_id', $items->pluck('id'))
            ->pluck('id', 'clinical_catalog_item_id');

        return $items
            ->map(static fn (ClinicalCatalogItemModel $item): array => [
                'id' => (string) $item->id,
                'catalogType' => $item->catalog_type,
                'code' => $item->code,
                'name' => $item->name,
                'category' => $item->category,
                'unit' => $item->unit,
                'description' => $item->description,
                'metadata' => is_array($item->metadata) ? $item->metadata : [],
                'codes' => is_array($item->codes) ? $item->codes : [],
                'status' => $item->status,
                'genericName' => $item->generic_name,
                'dosageForm' => $item->dosage_form,
                'strength' => $item->strength,
                'storageConditions' => $item->storage_conditions,
                'requiresColdChain' => (bool) $item->requires_cold_chain,
                'isControlledSubstance' => (bool) $item->is_controlled_substance,
                'linkedInventoryItemId' => isset($linkedInventoryItemIdsByCatalogId[$item->id])
                    ? (string) $linkedInventoryItemIdsByCatalogId[$item->id]
                    : null,
                'controlledSubstanceSchedule' => $item->controlled_substance_schedule,
            ])
            ->values()
            ->all();
    }

    private function isPlatformScopingEnabled(FeatureFlagResolverInterface $featureFlagResolver): bool
    {
        return $featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    // ─── Supplier Lead Times ──────────────────────────────────

    public function supplierLeadTimes(
        Request $request,
        InventorySupplierLeadTimeRepositoryInterface $repository
    ): JsonResponse {
        $supplierId = $request->query('supplierId');
        if (! $supplierId) {
            return response()->json(['message' => 'supplierId is required.'], 422);
        }

        $result = $repository->listBySupplier(
            $supplierId,
            (int) $request->query('page', 1),
            (int) $request->query('perPage', 15),
        );

        return response()->json($result);
    }

    public function storeSupplierLeadTime(
        Request $request,
        RecordSupplierLeadTimeUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'supplierId' => 'required|uuid',
            'itemId' => 'nullable|uuid',
            'procurementRequestId' => 'nullable|uuid',
            'orderDate' => 'required|date',
            'expectedDeliveryDate' => 'nullable|date|after_or_equal:orderDate',
            'quantityOrdered' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $record = $useCase->execute([
                'supplier_id' => $validated['supplierId'],
                'item_id' => $validated['itemId'] ?? null,
                'procurement_request_id' => $validated['procurementRequestId'] ?? null,
                'order_date' => $validated['orderDate'],
                'expected_delivery_date' => $validated['expectedDeliveryDate'] ?? null,
                'quantity_ordered' => $validated['quantityOrdered'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json(['data' => $record], 201);
    }

    public function recordSupplierDelivery(
        string $id,
        Request $request,
        RecordSupplierDeliveryUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'actualDeliveryDate' => 'required|date',
            'quantityReceived' => 'nullable|numeric|min:0',
        ]);

        try {
            $record = $useCase->execute($id, [
                'actual_delivery_date' => $validated['actualDeliveryDate'],
                'quantity_received' => $validated['quantityReceived'] ?? null,
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(['data' => $record]);
    }

    public function supplierPerformance(
        string $supplierId,
        Request $request,
        GetSupplierPerformanceUseCase $useCase
    ): JsonResponse {
        $itemId = $request->query('itemId');
        $result = $useCase->execute($supplierId, $itemId);

        return response()->json(['data' => $result]);
    }

    // ─── Warehouse Transfers ──────────────────────────────────

    public function warehouseTransfers(
        Request $request,
        InventoryWarehouseTransferRepositoryInterface $repository
    ): JsonResponse {
        $result = $repository->search(
            query: $request->query('query'),
            status: $request->query('status'),
            varianceReviewStatus: $request->query('varianceReview'),
            sourceWarehouseId: $request->query('sourceWarehouseId'),
            destinationWarehouseId: $request->query('destinationWarehouseId'),
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('perPage', 15),
        );

        return response()->json([
            'data' => array_map(
                [InventoryWarehouseTransferResponseTransformer::class, 'transform'],
                $result['data'] ?? [],
            ),
            'meta' => $result['meta'] ?? [],
        ]);
    }

    public function showWarehouseTransfer(
        string $id,
        InventoryWarehouseTransferRepositoryInterface $repository
    ): JsonResponse {
        $transfer = $repository->findById($id);
        abort_if($transfer === null, 404, 'Transfer not found.');

        return response()->json([
            'data' => InventoryWarehouseTransferResponseTransformer::transform($transfer),
        ]);
    }

    public function updateWarehouseTransferVarianceReview(
        string $id,
        Request $request,
        UpdateWarehouseTransferVarianceReviewUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'reviewStatus' => 'required|in:'.implode(',', InventoryWarehouseTransferVarianceReviewStatus::values()),
            'reviewNotes' => 'nullable|string|max:1000',
        ]);

        try {
            $transfer = $useCase->execute(
                transferId: $id,
                reviewStatus: $validated['reviewStatus'],
                userId: (string) ($request->user()?->id ?? ''),
                reviewNotes: $validated['reviewNotes'] ?? null,
            );
        } catch (InventoryStockOperationValidationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'data' => InventoryWarehouseTransferResponseTransformer::transform($transfer),
        ]);
    }

    public function storeWarehouseTransfer(
        Request $request,
        CreateWarehouseTransferUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'sourceWarehouseId' => 'required|uuid',
            'destinationWarehouseId' => 'required|uuid|different:sourceWarehouseId',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.itemId' => 'required|uuid',
            'lines.*.batchId' => 'nullable|uuid',
            'lines.*.requestedQuantity' => 'required|numeric|min:0.001',
            'lines.*.unit' => 'nullable|string|max:50',
            'lines.*.notes' => 'nullable|string|max:300',
        ]);

        try {
            $transfer = $useCase->execute(
                [
                    'source_warehouse_id' => $validated['sourceWarehouseId'],
                    'destination_warehouse_id' => $validated['destinationWarehouseId'],
                    'priority' => $validated['priority'] ?? 'normal',
                    'reason' => $validated['reason'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'lines' => array_map(static fn (array $line) => [
                        'item_id' => $line['itemId'],
                        'batch_id' => $line['batchId'] ?? null,
                        'requested_quantity' => $line['requestedQuantity'],
                        'unit' => $line['unit'] ?? null,
                        'notes' => $line['notes'] ?? null,
                    ], $validated['lines']),
                ],
                $request->user()?->id,
            );
        } catch (InventoryStockOperationValidationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json([
            'data' => InventoryWarehouseTransferResponseTransformer::transform($transfer),
        ], 201);
    }

    public function updateWarehouseTransferStatus(
        string $id,
        Request $request,
        UpdateWarehouseTransferStatusUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', InventoryWarehouseTransferStatus::values()),
            'rejectionReason' => 'nullable|required_if:status,rejected|string|max:500',
            'packNotes' => 'nullable|string|max:1000',
            'receivingNotes' => 'nullable|string|max:1000',
            'revalidateReservation' => 'nullable|boolean',
            'packedQuantities' => 'nullable|array',
            'packedQuantities.*' => 'numeric|min:0',
            'dispatchedQuantities' => 'nullable|array',
            'dispatchedQuantities.*' => 'numeric|min:0',
            'receivedQuantities' => 'nullable|array',
            'receivedQuantities.*' => 'numeric|min:0',
            'receiptVarianceTypes' => 'nullable|array',
            'receiptVarianceTypes.*' => 'in:'.implode(',', InventoryWarehouseTransferReceiptVarianceType::values()),
            'receiptVarianceQuantities' => 'nullable|array',
            'receiptVarianceQuantities.*' => 'numeric|min:0',
            'receiptVarianceReasons' => 'nullable|array',
            'receiptVarianceReasons.*' => 'nullable|string|max:500',
        ]);

        try {
            $transfer = $useCase->execute(
                $id,
                $validated['status'],
                $request->user()?->id,
                [
                    'rejection_reason' => $validated['rejectionReason'] ?? null,
                    'pack_notes' => $validated['packNotes'] ?? null,
                    'receiving_notes' => $validated['receivingNotes'] ?? null,
                    'revalidate_reservation' => (bool) ($validated['revalidateReservation'] ?? false),
                    'packed_quantities' => $validated['packedQuantities'] ?? [],
                    'dispatched_quantities' => $validated['dispatchedQuantities'] ?? [],
                    'received_quantities' => $validated['receivedQuantities'] ?? [],
                    'receipt_variance_types' => $validated['receiptVarianceTypes'] ?? [],
                    'receipt_variance_quantities' => $validated['receiptVarianceQuantities'] ?? [],
                    'receipt_variance_reasons' => $validated['receiptVarianceReasons'] ?? [],
                ],
            );
        } catch (InventoryStockOperationValidationException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'data' => InventoryWarehouseTransferResponseTransformer::transform($transfer),
        ]);
    }

    // ─── Dispensing Claim Links ─────────────────────────────

    public function dispensingClaimLinks(
        Request $request,
        InventoryDispensingClaimLinkRepositoryInterface $repository
    ): JsonResponse {
        $result = $repository->search(
            patientId: $request->query('patientId'),
            claimStatus: $request->query('claimStatus'),
            insuranceClaimId: $request->query('insuranceClaimId'),
            query: $request->query('query'),
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('perPage', 15),
        );

        return response()->json($result);
    }

    public function storeDispensingClaimLink(
        Request $request,
        CreateDispensingClaimLinkUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'stockMovementId' => 'nullable|uuid',
            'pharmacyOrderId' => 'nullable|uuid',
            'itemId' => 'required|uuid',
            'batchId' => 'nullable|uuid',
            'quantityDispensed' => 'required|numeric|min:0.001',
            'unit' => 'nullable|string|max:50',
            'unitCost' => 'nullable|numeric|min:0',
            'totalCost' => 'nullable|numeric|min:0',
            'patientId' => 'required|uuid',
            'admissionId' => 'nullable|uuid',
            'appointmentId' => 'nullable|uuid',
            'insuranceClaimId' => 'nullable|uuid',
            'billingInvoiceId' => 'nullable|uuid',
            'nhifCode' => 'nullable|string|max:50',
            'payerType' => 'nullable|string|max:50',
            'payerName' => 'nullable|string|max:255',
            'payerReference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $link = $useCase->execute([
                'stock_movement_id' => $validated['stockMovementId'] ?? null,
                'pharmacy_order_id' => $validated['pharmacyOrderId'] ?? null,
                'item_id' => $validated['itemId'],
                'batch_id' => $validated['batchId'] ?? null,
                'quantity_dispensed' => $validated['quantityDispensed'],
                'unit' => $validated['unit'] ?? null,
                'unit_cost' => $validated['unitCost'] ?? null,
                'total_cost' => $validated['totalCost'] ?? null,
                'patient_id' => $validated['patientId'],
                'admission_id' => $validated['admissionId'] ?? null,
                'appointment_id' => $validated['appointmentId'] ?? null,
                'insurance_claim_id' => $validated['insuranceClaimId'] ?? null,
                'billing_invoice_id' => $validated['billingInvoiceId'] ?? null,
                'nhif_code' => $validated['nhifCode'] ?? null,
                'payer_type' => $validated['payerType'] ?? null,
                'payer_name' => $validated['payerName'] ?? null,
                'payer_reference' => $validated['payerReference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ], $request->user()?->id);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json(['data' => $link], 201);
    }

    public function updateDispensingClaimStatus(
        string $id,
        Request $request,
        UpdateDispensingClaimStatusUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', InventoryDispensingClaimStatus::values()),
            'insuranceClaimId' => 'nullable|uuid',
            'billingInvoiceId' => 'nullable|uuid',
            'approvedAmount' => 'nullable|numeric|min:0',
            'rejectedAmount' => 'nullable|numeric|min:0',
            'rejectionReason' => 'nullable|string|max:500',
        ]);

        try {
            $link = $useCase->execute($id, $validated['status'], [
                'insurance_claim_id' => $validated['insuranceClaimId'] ?? null,
                'billing_invoice_id' => $validated['billingInvoiceId'] ?? null,
                'approved_amount' => $validated['approvedAmount'] ?? null,
                'rejected_amount' => $validated['rejectedAmount'] ?? null,
                'rejection_reason' => $validated['rejectionReason'] ?? null,
            ]);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(['data' => $link]);
    }

    // ─── MSD E-Ordering ──────────────────────────────────────

    public function msdOrders(
        Request $request,
        InventoryMsdOrderRepositoryInterface $repository
    ): JsonResponse {
        $result = $repository->search(
            query: $request->query('query'),
            status: $request->query('status'),
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('perPage', 15),
        );

        return response()->json($result);
    }

    public function storeMsdOrder(
        Request $request,
        CreateMsdOrderUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'facilityMsdCode' => 'nullable|string|max:50',
            'procurementRequestId' => 'nullable|uuid',
            'supplierId' => 'nullable|uuid',
            'orderLines' => 'required|array|min:1',
            'orderLines.*.msdCode' => 'required|string|max:50',
            'orderLines.*.itemName' => 'required|string|max:255',
            'orderLines.*.quantity' => 'required|numeric|min:0.001',
            'orderLines.*.unit' => 'required|string|max:50',
            'orderLines.*.unitCost' => 'nullable|numeric|min:0',
            'currencyCode' => 'nullable|string|max:10',
            'totalAmount' => 'nullable|numeric|min:0',
            'orderDate' => 'required|date',
            'expectedDeliveryDate' => 'nullable|date|after_or_equal:orderDate',
            'notes' => 'nullable|string|max:1000',
            'submitImmediately' => 'nullable|boolean',
        ]);

        try {
            $order = $useCase->execute([
                'facility_msd_code' => $validated['facilityMsdCode'] ?? null,
                'procurement_request_id' => $validated['procurementRequestId'] ?? null,
                'supplier_id' => $validated['supplierId'] ?? null,
                'order_lines' => array_map(static fn (array $line) => [
                    'msd_code' => $line['msdCode'],
                    'item_name' => $line['itemName'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_cost' => $line['unitCost'] ?? null,
                ], $validated['orderLines']),
                'currency_code' => $validated['currencyCode'] ?? 'TZS',
                'total_amount' => $validated['totalAmount'] ?? null,
                'order_date' => $validated['orderDate'],
                'expected_delivery_date' => $validated['expectedDeliveryDate'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ], $request->user()?->id, $validated['submitImmediately'] ?? false);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json(['data' => $order], 201);
    }

    public function syncMsdOrderStatus(
        string $id,
        SyncMsdOrderStatusUseCase $useCase
    ): JsonResponse {
        try {
            $order = $useCase->execute($id);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(['data' => $order]);
    }

    public function msdHealthCheck(MsdApiClientInterface $msdApiClient): JsonResponse
    {
        $result = $msdApiClient->healthCheck();

        return response()->json($result);
    }

    // ─── Barcode Lookup ──────────────────────────────────────

    public function lookupByBarcode(
        Request $request,
        PlatformScopeQueryApplier $platformScopeQueryApplier,
        FeatureFlagResolverInterface $featureFlagResolver,
    ): JsonResponse {
        $barcode = $request->validate([
            'barcode' => 'required|string|max:100',
        ])['barcode'];

        $query = InventoryItemModel::query()
            ->where('barcode', $barcode)
            ->where('status', 'active');

        if ($this->isPlatformScopingEnabled($featureFlagResolver)) {
            $platformScopeQueryApplier->apply($query);
        }

        $item = $query->first();
        if (! $item) {
            return response()->json(['message' => 'No active item found for barcode.', 'data' => null], 404);
        }

        return response()->json(['data' => $item->toArray()]);
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

    // ─── Payload Mappers ──────────────────────────────────────

    private function toBatchPayload(array $validated): array
    {
        return [
            'item_id' => $validated['itemId'],
            'batch_number' => $validated['batchNumber'],
            'lot_number' => $validated['lotNumber'] ?? null,
            'manufacture_date' => $validated['manufactureDate'] ?? null,
            'expiry_date' => $validated['expiryDate'] ?? null,
            'quantity' => $validated['quantity'],
            'warehouse_id' => $validated['warehouseId'] ?? null,
            'bin_location' => $validated['binLocation'] ?? null,
            'supplier_id' => $validated['supplierId'] ?? null,
            'manufacturer' => $validated['manufacturer'] ?? null,
            'unit_cost' => $validated['unitCost'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function toRequisitionPayload(array $validated): array
    {
        $lines = [];
        foreach (($validated['lines'] ?? []) as $line) {
            $lines[] = [
                'item_id' => $line['itemId'],
                'batch_id' => $line['batchId'] ?? null,
                'requested_quantity' => $line['requestedQuantity'],
                'unit' => $line['unit'],
                'notes' => $line['notes'] ?? null,
            ];
        }

        return [
            'requesting_department' => $validated['requestingDepartment'],
            'requesting_department_id' => $validated['requestingDepartmentId'] ?? null,
            'issuing_store' => $validated['issuingStore'] ?? null,
            'issuing_warehouse_id' => $validated['issuingWarehouseId'] ?? null,
            'priority' => $validated['priority'] ?? 'normal',
            'needed_by' => $validated['neededBy'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'lines' => $lines,
        ];
    }

    private function toRequisitionStatusPayload(array $validated): array
    {
        $payload = [
            'rejection_reason' => $validated['rejectionReason'] ?? null,
        ];

        if (isset($validated['lines'])) {
            $payload['lines'] = array_map(static fn (array $line) => [
                'id' => $line['id'],
                'approved_quantity' => $line['approvedQuantity'] ?? null,
                'issued_quantity' => $line['issuedQuantity'] ?? null,
            ], $validated['lines']);
        }

        return $payload;
    }

    private function emptyDepartmentRequisitionListPayload(Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('perPage', 20)));

        return [
            'data' => [],
            'meta' => [
                'currentPage' => $page,
                'lastPage' => 1,
                'perPage' => $perPage,
                'total' => 0,
            ],
        ];
    }

    private function emptyShortageQueuePayload(Request $request): array
    {
        $payload = $this->emptyDepartmentRequisitionListPayload($request);
        $readiness = $request->query('readiness', 'all');

        $payload['meta']['readyLineCount'] = 0;
        $payload['meta']['waitingLineCount'] = 0;
        $payload['meta']['readiness'] = in_array($readiness, ['ready', 'waiting'], true) ? $readiness : 'all';

        return $payload;
    }

    private function emptyDepartmentStockPayload(int $page, int $perPage): array
    {
        return [
            'data' => [],
            'summary' => [
                'totalRows' => 0,
                'departments' => 0,
                'items' => 0,
                'totalIssuedQuantity' => 0,
                'lastIssuedAt' => null,
            ],
            'meta' => [
                'currentPage' => $page,
                'lastPage' => 1,
                'perPage' => $perPage,
                'total' => 0,
            ],
        ];
    }

    // ─── Clinical Catalog Search ──────────────────────────────

    public function clinicalCatalogSearch(Request $request, FeatureFlagResolverInterface $featureFlagResolver, PlatformScopeQueryApplier $platformScopeQueryApplier): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'catalogType' => ['nullable', 'string', 'in:formulary_item,lab_test,radiology_procedure,theatre_procedure'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $query = ClinicalCatalogItemModel::query()
            ->select(['id', 'catalog_type', 'code', 'name', 'category', 'unit', 'description', 'metadata', 'codes', 'status'])
            ->where('status', ClinicalCatalogItemStatus::ACTIVE->value)
            ->orderBy('name');

        $catalogType = $validated['catalogType'] ?? ClinicalCatalogType::FORMULARY_ITEM->value;
        $query->where('catalog_type', $catalogType);

        $search = trim($validated['q'] ?? '');
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($this->isPlatformScopingEnabled($featureFlagResolver)) {
            $platformScopeQueryApplier->apply($query);
        }

        $perPage = min((int) ($validated['perPage'] ?? 50), 500);
        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(static fn (ClinicalCatalogItemModel $item): array => [
            'id' => (string) $item->id,
            'catalogType' => $item->catalog_type,
            'code' => $item->code,
            'name' => $item->name,
            'category' => $item->category,
            'unit' => $item->unit,
            'description' => $item->description,
            'metadata' => is_array($item->metadata) ? $item->metadata : [],
            'codes' => is_array($item->codes) ? $item->codes : [],
            'status' => $item->status,
        ])->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    // ─── Bulk Sync from Clinical Catalog ─────────────────────

    public function bulkCreateFromCatalog(
        Request $request,
        BulkCreateInventoryItemsFromCatalogUseCase $useCase,
    ): JsonResponse {
        $validated = $request->validate([
            'catalogItemIds' => ['nullable', 'array'],
            'catalogItemIds.*' => ['string', 'uuid'],
            'defaultWarehouseId' => ['required', 'uuid'],
            'defaultSupplierId' => ['nullable', 'uuid'],
        ]);

        try {
            $result = $useCase->execute(
                catalogItemIds: $validated['catalogItemIds'] ?? null,
                defaultWarehouseId: $validated['defaultWarehouseId'],
                defaultSupplierId: $validated['defaultSupplierId'] ?? null,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $statusCode = empty($result['errors']) ? 200 : 207;

        return response()->json([
            'data' => $result,
        ], $statusCode);
    }

    // ─── Department Item Catalog ─────────────────────────────

    public function getDepartmentItemCatalog(
        string $departmentId,
        DepartmentItemCatalogService $catalogService,
    ): JsonResponse {
        $items = $catalogService->catalogItemsWithDetails($departmentId);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total' => count($items),
            ],
        ]);
    }

    public function updateDepartmentItemCatalog(
        string $departmentId,
        Request $request,
        DepartmentItemCatalogService $catalogService,
    ): JsonResponse {
        $validated = $request->validate([
            'itemIds' => 'required|array|min:1',
            'itemIds.*' => 'string|exists:inventory_items,id',
        ]);

        $catalogService->assignItemsToDepartment(
            $departmentId,
            $validated['itemIds'],
            $request->user()?->id,
        );

        return response()->json([
            'data' => $catalogService->catalogItemsWithDetails($departmentId),
            'meta' => [
                'total' => count($validated['itemIds']),
            ],
        ]);
    }

    public function syncDepartmentItemCatalog(
        string $departmentId,
        Request $request,
        DepartmentItemCatalogService $catalogService,
    ): JsonResponse {
        $validated = $request->validate([
            'items' => 'required|array|min:0',
            'items.*' => 'string|exists:inventory_items,id',
        ]);

        $catalogService->assignItemsToDepartment(
            $departmentId,
            $validated['items'],
            $request->user()?->id,
        );

        return response()->json([
            'data' => $catalogService->catalogItemsWithDetails($departmentId),
            'meta' => [
                'total' => count($validated['items']),
            ],
        ]);
    }

    // ─── Department Default Warehouses ───────────────────────

    public function departmentDefaultWarehouses(
        DepartmentItemCatalogService $catalogService,
    ): JsonResponse {
        $departments = DepartmentModel::query()
            ->where('status', 'active')
            ->whereNotNull('default_warehouse_id')
            ->select(['id', 'name', 'code', 'default_warehouse_id'])
            ->get();

        $warehouses = InventoryWarehouseModel::query()
            ->whereIn('id', $departments->pluck('default_warehouse_id')->unique()->toArray())
            ->select(['id', 'warehouse_name', 'warehouse_code'])
            ->get()
            ->keyBy('id');

        $data = $departments->map(function ($dept) use ($warehouses) {
            $warehouse = $warehouses->get($dept->default_warehouse_id);

            return [
                'departmentId' => (string) $dept->id,
                'departmentName' => $dept->name,
                'departmentCode' => $dept->code,
                'defaultWarehouseId' => (string) $dept->default_warehouse_id,
                'warehouseName' => $warehouse?->warehouse_name ?? 'Unknown',
                'warehouseCode' => $warehouse?->warehouse_code ?? null,
            ];
        });

        return response()->json([
            'data' => $data->toArray(),
            'meta' => [
                'total' => count($data),
            ],
        ]);
    }

    public function updateDepartmentDefaultWarehouse(
        string $departmentId,
        Request $request,
        DepartmentItemCatalogService $catalogService,
    ): JsonResponse {
        $validated = $request->validate([
            'warehouseId' => 'nullable|string|exists:inventory_warehouses,id',
        ]);

        $catalogService->setPreferredWarehouse(
            $departmentId,
            $validated['warehouseId'] ?? null,
        );

        $department = DepartmentModel::query()->find($departmentId);

        return response()->json([
            'data' => [
                'departmentId' => $departmentId,
                'defaultWarehouseId' => $validated['warehouseId'] ?? null,
                'departmentName' => $department?->name ?? 'Unknown',
            ],
            'meta' => [
                'message' => $validated['warehouseId'] ? 'Default warehouse set.' : 'Default warehouse cleared.',
            ],
        ]);
    }
}
