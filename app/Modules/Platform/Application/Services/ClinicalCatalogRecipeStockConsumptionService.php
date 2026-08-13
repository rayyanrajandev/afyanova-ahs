<?php

namespace App\Modules\Platform\Application\Services;

use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\InventoryProcurement\Application\Services\DepartmentStockService;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogConsumptionRecipeItemModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicalCatalogRecipeStockConsumptionService
{
    private const MOVEMENT_SOURCE = 'clinical_catalog_consumption_recipe';

    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
        private readonly DepartmentStockService $departmentStockService,
        private readonly DepartmentRepositoryInterface $departmentRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $sourceSnapshot
     * @return array{status: string, movementCount: int, movements: array<int, array<string, mixed>>}
     */
    public function consumeForCompletedClinicalWork(
        ?string $clinicalCatalogItemId,
        string $catalogType,
        string $sourceType,
        string $sourceId,
        ?int $actorId = null,
        array $sourceSnapshot = [],
    ): array {
        $clinicalCatalogItemId = $this->nullableUuid($clinicalCatalogItemId);
        $sourceId = $this->nullableUuid($sourceId);

        if ($clinicalCatalogItemId === null || $sourceId === null) {
            return [
                'status' => 'no_catalog_item',
                'movementCount' => 0,
                'movements' => [],
            ];
        }

        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($clinicalCatalogItemId, $catalogType, $sourceType, $sourceId, $actorId, $sourceSnapshot): array {
            $existingMovements = InventoryStockMovementModel::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('metadata->source', self::MOVEMENT_SOURCE)
                ->get();

            if ($existingMovements->isNotEmpty()) {
                return [
                    'status' => 'already_consumed',
                    'movementCount' => $existingMovements->count(),
                    'movements' => $existingMovements->map(static fn (InventoryStockMovementModel $movement): array => $movement->toArray())->all(),
                ];
            }

            $recipeItems = ClinicalCatalogConsumptionRecipeItemModel::query()
                ->with(['clinicalCatalogItem', 'inventoryItem'])
                ->where('clinical_catalog_item_id', $clinicalCatalogItemId)
                ->where('is_active', true)
                ->orderBy('created_at')
                ->get();

            if ($recipeItems->isEmpty()) {
                return [
                    'status' => 'no_recipe',
                    'movementCount' => 0,
                    'movements' => [],
                ];
            }

            $tenantId = $sourceSnapshot['tenant_id'] ?? $this->platformScopeContext->tenantId();
            $facilityId = $sourceSnapshot['facility_id'] ?? $this->platformScopeContext->facilityId();
            $occurredAt = $sourceSnapshot['completed_at']
                ?? $sourceSnapshot['resulted_at']
                ?? now();

            $movements = [];
            foreach ($recipeItems as $recipeItem) {
                $movement = $this->consumeRecipeItem(
                    recipeItem: $recipeItem,
                    catalogType: $catalogType,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    actorId: $actorId,
                    tenantId: is_string($tenantId) ? $tenantId : null,
                    facilityId: is_string($facilityId) ? $facilityId : null,
                    occurredAt: $occurredAt,
                    sourceSnapshot: $sourceSnapshot,
                );
                $movements[] = $movement->toArray();
            }

            return [
                'status' => 'consumed',
                'movementCount' => count($movements),
                'movements' => $movements,
            ];
        });
    }

    /**
     * @return array{
     *     supported: bool,
     *     status: string,
     *     blocking: bool,
     *     summary: string,
     *     lineCount: int,
     *     insufficientLineCount: int,
     *     lines: array<int, array<string, mixed>>
     * }
     */
    public function precheckForClinicalWork(
        ?string $clinicalCatalogItemId,
        string $catalogType,
    ): array {
        if (! $this->supportsCatalogType($catalogType)) {
            return [
                'supported' => false,
                'status' => 'not_supported',
                'blocking' => false,
                'summary' => 'Automatic stock readiness is not enabled for this clinical definition.',
                'lineCount' => 0,
                'insufficientLineCount' => 0,
                'lines' => [],
            ];
        }

        $clinicalCatalogItemId = $this->nullableUuid($clinicalCatalogItemId);
        if ($clinicalCatalogItemId === null) {
            return [
                'supported' => true,
                'status' => 'no_catalog_item',
                'blocking' => false,
                'summary' => 'No clinical catalog link is attached, so stock readiness cannot be checked yet.',
                'lineCount' => 0,
                'insufficientLineCount' => 0,
                'lines' => [],
            ];
        }

        $recipeItems = ClinicalCatalogConsumptionRecipeItemModel::query()
            ->with('inventoryItem')
            ->where('clinical_catalog_item_id', $clinicalCatalogItemId)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->get();

        if ($recipeItems->isEmpty()) {
            return [
                'supported' => true,
                'status' => 'no_recipe',
                'blocking' => false,
                'summary' => 'No consumables are mapped yet. Completion can continue, but store stock will not be deducted automatically.',
                'lineCount' => 0,
                'insufficientLineCount' => 0,
                'lines' => [],
            ];
        }

        $lines = [];
        $insufficientLineCount = 0;

        foreach ($recipeItems as $recipeItem) {
            $line = $this->precheckLine($recipeItem);
            if (! $line['enoughStock']) {
                $insufficientLineCount++;
            }

            $lines[] = $line;
        }

        $lineCount = count($lines);
        $status = $insufficientLineCount > 0 ? 'insufficient' : 'ready';
        $blocking = $insufficientLineCount > 0;

        return [
            'supported' => true,
            'status' => $status,
            'blocking' => $blocking,
            'summary' => $this->precheckSummary($status, $lineCount, $insufficientLineCount),
            'lineCount' => $lineCount,
            'insufficientLineCount' => $insufficientLineCount,
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceSnapshot
     */
    private function consumeRecipeItem(
        ClinicalCatalogConsumptionRecipeItemModel $recipeItem,
        string $catalogType,
        string $sourceType,
        string $sourceId,
        ?int $actorId,
        ?string $tenantId,
        ?string $facilityId,
        mixed $occurredAt,
        array $sourceSnapshot,
    ): InventoryStockMovementModel {
        $inventoryItem = InventoryItemModel::query()
            ->whereKey($recipeItem->inventory_item_id)
            ->lockForUpdate()
            ->first();

        if (! $inventoryItem instanceof InventoryItemModel) {
            throw ValidationException::withMessages([
                'consumptionRecipe' => ['A mapped store item is no longer available in inventory.'],
            ]);
        }

        if ((string) ($inventoryItem->status ?? '') !== 'active') {
            throw ValidationException::withMessages([
                'consumptionRecipe' => [sprintf('%s is not active and cannot be consumed automatically.', $inventoryItem->item_name ?? 'Mapped store item')],
            ]);
        }

        $quantity = $this->effectiveQuantity($recipeItem);
        try {
            $movement = $this->inventoryBatchStockService->issue([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'item_id' => (string) $inventoryItem->id,
                'source_warehouse_id' => $inventoryItem->default_warehouse_id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'clinical_catalog_item_id' => $recipeItem->clinical_catalog_item_id,
                'consumption_recipe_item_id' => $recipeItem->id,
                'quantity' => $quantity,
                'reason' => 'Automated clinical consumption',
                'notes' => $recipeItem->notes,
                'metadata' => [
                    'source' => self::MOVEMENT_SOURCE,
                    'catalogType' => $catalogType,
                    'consumptionStage' => $recipeItem->consumption_stage,
                    'quantityPerOrder' => (string) $recipeItem->quantity_per_order,
                    'wasteFactorPercent' => (string) $recipeItem->waste_factor_percent,
                    'sourceSnapshot' => $this->sourceSnapshotForMetadata($sourceSnapshot),
                ],
                'occurred_at' => $occurredAt,
            ], $actorId);
        } catch (\App\Modules\InventoryProcurement\Application\Exceptions\InsufficientInventoryStockException) {
            throw ValidationException::withMessages([
                'consumptionRecipe' => [sprintf(
                    'Insufficient stock for %s. Required %.3f %s, available %.3f.',
                    $inventoryItem->item_name ?? 'mapped store item',
                    $quantity,
                    $recipeItem->unit ?: ($inventoryItem->unit ?? 'unit'),
                    $this->inventoryBatchStockService->availability(
                        (string) $inventoryItem->id,
                        $occurredAt,
                        $inventoryItem->default_warehouse_id,
                    )['availableQuantity'] ?? 0,
                )],
            ]);
        }

        /** @var InventoryStockMovementModel|null $storedMovement */
        $storedMovement = InventoryStockMovementModel::query()->find($movement['id'] ?? null);

        if (! $storedMovement instanceof InventoryStockMovementModel) {
            throw ValidationException::withMessages([
                'consumptionRecipe' => ['The clinical stock movement ledger could not be finalized.'],
            ]);
        }

        $this->recordDepartmentStockConsumption(
            sourceSnapshot: $sourceSnapshot,
            itemId: (string) $inventoryItem->id,
            quantity: $quantity,
            unit: $recipeItem->unit ?: ($inventoryItem->unit ?? null),
            sourceType: $sourceType,
            sourceId: $sourceId,
            actorId: $actorId,
        );

        return $storedMovement;
    }

    private function effectiveQuantity(ClinicalCatalogConsumptionRecipeItemModel $recipeItem): float
    {
        $quantityPerOrder = max(0, (float) ($recipeItem->quantity_per_order ?? 0));
        $wasteFactor = max(0, (float) ($recipeItem->waste_factor_percent ?? 0));

        return round($quantityPerOrder * (1 + ($wasteFactor / 100)), 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function precheckLine(ClinicalCatalogConsumptionRecipeItemModel $recipeItem): array
    {
        $inventoryItem = $recipeItem->inventoryItem;
        $requiredQuantity = $this->effectiveQuantity($recipeItem);
        $inventoryMissing = ! $inventoryItem instanceof InventoryItemModel;
        $inventoryActive = ! $inventoryMissing && (string) ($inventoryItem->status ?? '') === 'active';
        $availability = $inventoryMissing
            ? null
            : $this->inventoryBatchStockService->availability(
                (string) $inventoryItem->id,
                now(),
                $inventoryItem->default_warehouse_id,
            );
        $currentStock = $inventoryMissing
            ? 0.0
            : round((float) ($availability['availableQuantity'] ?? 0), 3);
        $remainingStock = round($currentStock - $requiredQuantity, 3);
        $enoughStock = ! $inventoryMissing && $inventoryActive && $remainingStock >= 0;

        return [
            'recipeItemId' => (string) $recipeItem->id,
            'inventoryItemId' => $inventoryMissing ? null : (string) $inventoryItem->id,
            'itemCode' => $inventoryItem?->item_code,
            'itemName' => $inventoryItem?->item_name ?? 'Missing inventory item',
            'category' => $inventoryItem?->category,
            'inventoryStatus' => $inventoryMissing ? 'missing' : (string) ($inventoryItem->status ?? 'inactive'),
            'unit' => $recipeItem->unit ?: ($inventoryItem?->unit),
            'quantityPerOrder' => round((float) ($recipeItem->quantity_per_order ?? 0), 3),
            'wasteFactorPercent' => round((float) ($recipeItem->waste_factor_percent ?? 0), 2),
            'requiredQuantity' => $requiredQuantity,
            'currentStock' => $currentStock,
            'onHandStock' => $inventoryMissing
                ? 0.0
                : round((float) ($availability['onHandQuantity'] ?? $inventoryItem->current_stock ?? 0), 3),
            'remainingStockAfterPlannedUse' => $remainingStock,
            'reorderLevel' => $inventoryMissing ? null : round((float) ($inventoryItem->reorder_level ?? 0), 3),
            'stockState' => $this->stockState($inventoryItem, $availability),
            'enoughStock' => $enoughStock,
            'blockingReason' => $this->blockingReason(
                $inventoryItem,
                $requiredQuantity,
                $currentStock,
                $remainingStock,
                $availability,
            ),
            'batchTrackingMode' => $availability['trackingMode'] ?? 'untracked',
            'blockedBatchQuantity' => $inventoryMissing ? 0 : round((float) ($availability['blockedQuantity'] ?? 0), 3),
            'validBatchCount' => $inventoryMissing ? 0 : (int) ($availability['validBatchCount'] ?? 0),
            'consumptionStage' => (string) ($recipeItem->consumption_stage ?? 'per_order'),
            'notes' => $recipeItem->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceSnapshot
     * @return array<string, mixed>
     */
    private function sourceSnapshotForMetadata(array $sourceSnapshot): array
    {
        return array_intersect_key($sourceSnapshot, array_flip([
            'id',
            'order_number',
            'procedure_number',
            'patient_id',
            'tenant_id',
            'facility_id',
            'status',
            'completed_at',
            'resulted_at',
        ]));
    }

    private function supportsCatalogType(string $catalogType): bool
    {
        return ClinicalCatalogType::tryFrom($catalogType)?->supportsConsumptionRecipes() ?? false;
    }

    private function precheckSummary(
        string $status,
        int $lineCount,
        int $insufficientLineCount,
    ): string {
        if ($status === 'insufficient') {
            $label = $insufficientLineCount === 1 ? '1 consumable line is short' : sprintf('%d consumable lines are short', $insufficientLineCount);

            return $label.' and completion will fail until store stock is replenished.';
        }

        $label = $lineCount === 1 ? '1 consumable line is ready' : sprintf('%d consumable lines are ready', $lineCount);

        return $label.' for automatic store deduction at completion.';
    }

    /**
     * @param  array<string, mixed>|null  $availability
     */
    private function stockState(?InventoryItemModel $inventoryItem, ?array $availability): string
    {
        if (! $inventoryItem instanceof InventoryItemModel) {
            return 'missing';
        }

        if ((string) ($inventoryItem->status ?? '') !== 'active') {
            return 'inactive';
        }

        return (string) ($availability['stockState'] ?? 'healthy');
    }

    /**
     * @param  array<string, mixed>|null  $availability
     */
    private function blockingReason(
        ?InventoryItemModel $inventoryItem,
        float $requiredQuantity,
        float $currentStock,
        float $remainingStock,
        ?array $availability,
    ): ?string {
        if (! $inventoryItem instanceof InventoryItemModel) {
            return 'Inventory item is missing from stock master data.';
        }

        if ((string) ($inventoryItem->status ?? '') !== 'active') {
            return 'Inventory item is inactive and cannot be consumed automatically.';
        }

        if ($remainingStock < 0) {
            if (($availability['trackingMode'] ?? 'untracked') === 'tracked' && $currentStock <= 0) {
                return 'No valid FEFO batch stock is available. Check expiry, quarantine, or warehouse batch onboarding.';
            }

            return sprintf(
                'Need %.3f %s, available %.3f.',
                $requiredQuantity,
                $inventoryItem->unit ?? 'unit',
                $currentStock,
            );
        }

        return null;
    }

    private function nullableUuid(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $sourceSnapshot
     */
    private function recordDepartmentStockConsumption(
        array $sourceSnapshot,
        string $itemId,
        float $quantity,
        ?string $unit,
        string $sourceType,
        string $sourceId,
        ?int $actorId,
    ): void {
        $departmentId = $this->resolveDepartmentId($sourceSnapshot);
        if ($departmentId === null) {
            return;
        }

        $this->departmentStockService->recordConsumption(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            departmentId: $departmentId,
            itemId: $itemId,
            quantity: $quantity,
            batchId: null,
            source: $sourceType,
            sourceId: $sourceId,
            actorId: $actorId,
            notes: 'Clinical recipe consumption (' . $sourceType . ')',
        );
    }

    /**
     * @param  array<string, mixed>  $sourceSnapshot
     */
    private function resolveDepartmentId(array $sourceSnapshot): ?string
    {
        $appointmentId = $sourceSnapshot['appointment_id'] ?? null;
        if ($appointmentId !== null) {
            $appointment = \App\Modules\Appointment\Infrastructure\Models\AppointmentModel::query()->find($appointmentId);
            $departmentName = $appointment['department'] ?? null;
            if ($departmentName !== null && trim((string) $departmentName) !== '') {
                $department = $this->departmentRepository->findActiveByName((string) $departmentName);
                if ($department !== null) {
                    return (string) $department['id'];
                }
            }
        }

        return null;
    }
}
