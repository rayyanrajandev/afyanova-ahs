<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryStockMovementRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementSourceKind;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementType;
use Illuminate\Support\Str;

class GetInventoryStockMovementSummaryUseCase
{
    public function __construct(private readonly InventoryStockMovementRepositoryInterface $inventoryStockMovementRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $itemId = isset($filters['itemId']) ? trim((string) $filters['itemId']) : null;
        $itemId = $itemId === '' || ! Str::isUuid($itemId) ? null : $itemId;

        $movementType = isset($filters['movementType']) ? strtolower(trim((string) $filters['movementType'])) : null;
        if (! in_array($movementType, InventoryStockMovementType::values(), true)) {
            $movementType = null;
        }

        $sourceKey = isset($filters['sourceKey']) ? strtolower(trim((string) $filters['sourceKey'])) : null;
        if (! in_array($sourceKey, InventoryStockMovementSourceKind::values(), true)) {
            $sourceKey = null;
        }

        $actorType = isset($filters['actorType']) ? strtolower(trim((string) $filters['actorType'])) : null;
        $actorType = in_array($actorType, ['system', 'user'], true) ? $actorType : null;

        $actorIdInput = isset($filters['actorId']) ? trim((string) $filters['actorId']) : null;
        $actorIdInput = $actorIdInput === '' ? null : $actorIdInput;
        $actorId = $actorIdInput !== null && ctype_digit($actorIdInput)
            ? (int) $actorIdInput
            : null;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->inventoryStockMovementRepository->summary(
            query: $query,
            itemId: $itemId,
            movementType: $movementType,
            sourceKey: $sourceKey,
            actorType: $actorType,
            actorId: $actorId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
