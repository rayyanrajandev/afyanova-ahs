<?php

namespace App\Modules\InventoryProcurement\Application\Services;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockReservationModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryStockReservationService
{
    private const DEFAULT_HOLD_HOURS = 24;

    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reserve(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload, $actorId): array {
            $itemId = $this->stringOrNull($payload['item_id'] ?? null);
            $batchId = $this->stringOrNull($payload['batch_id'] ?? null);
            $sourceType = $this->stringOrNull($payload['source_type'] ?? null);
            $sourceId = $this->stringOrNull($payload['source_id'] ?? null);
            $sourceLineId = $this->stringOrNull($payload['source_line_id'] ?? null);
            $quantity = round((float) ($payload['quantity'] ?? 0), 3);
            $quantityField = $this->stringOrNull($payload['quantity_field'] ?? null) ?? 'quantity';
            $batchField = $this->stringOrNull($payload['batch_field'] ?? null) ?? 'batchId';
            $occurredAt = $this->normalizeOccurredAt($payload['occurred_at'] ?? null);
            $expiresAt = $this->normalizeExpiry($payload['expires_at'] ?? null, $occurredAt);

            if ($itemId === null) {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            if ($sourceType === null || $sourceId === null) {
                throw new \InvalidArgumentException('Inventory stock reservation requires a source type and source id.');
            }

            if ($quantity <= 0) {
                throw new InventoryStockOperationValidationException(
                    $quantityField,
                    'Reserved quantity must be greater than zero.',
                );
            }

            $item = InventoryItemModel::query()
                ->whereKey($itemId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof InventoryItemModel) {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            $existingReservation = $this->activeSourceReservationQuery($sourceType, $sourceId, $sourceLineId, $item->id)
                ->lockForUpdate()
                ->first();

            $excludeReservationIds = $existingReservation instanceof InventoryStockReservationModel
                ? [(string) $existingReservation->id]
                : [];

            $warehouseId = $this->stringOrNull($payload['warehouse_id'] ?? null)
                ?? $this->stringOrNull($payload['source_warehouse_id'] ?? null)
                ?? $this->stringOrNull($item->default_warehouse_id ?? null);

            if ($batchId !== null) {
                $batch = InventoryBatchModel::query()
                    ->whereKey($batchId)
                    ->where('item_id', $item->id)
                    ->lockForUpdate()
                    ->first();

                if (! $batch instanceof InventoryBatchModel) {
                    throw new InventoryStockOperationValidationException(
                        $batchField,
                        'Selected batch was not found for this inventory item.',
                    );
                }

                if ($warehouseId !== null && $batch->warehouse_id !== $warehouseId) {
                    throw new InventoryStockOperationValidationException(
                        $batchField,
                        'Selected batch is not stocked in the chosen source warehouse.',
                    );
                }

                if (! $this->isBatchReservable($batch, $occurredAt)) {
                    throw new InventoryStockOperationValidationException(
                        $batchField,
                        'Selected batch is not available for reservation. Use an available, non-expired batch.',
                    );
                }

                $availableQuantity = round(max(
                    (float) ($batch->quantity ?? 0) - $this->activeBatchReservationQuantity($batch->id, $excludeReservationIds),
                    0
                ), 3);
                $warehouseId ??= $this->stringOrNull($batch->warehouse_id);
            } else {
                $availableQuantity = round(max(
                    (float) ($item->current_stock ?? 0) - $this->activeItemReservationQuantity($item->id, $excludeReservationIds),
                    0
                ), 3);
            }

            if ($availableQuantity < $quantity) {
                throw new InventoryStockOperationValidationException(
                    $quantityField,
                    'There is not enough unreserved stock available to hold this quantity.',
                );
            }

            $attributes = [
                'tenant_id' => $this->stringOrNull($payload['tenant_id'] ?? null) ?? $this->platformScopeContext->tenantId(),
                'facility_id' => $this->stringOrNull($payload['facility_id'] ?? null) ?? $this->platformScopeContext->facilityId(),
                'item_id' => $item->id,
                'batch_id' => $batchId,
                'warehouse_id' => $warehouseId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_line_id' => $sourceLineId,
                'quantity' => $quantity,
                'status' => InventoryStockReservationModel::STATUS_ACTIVE,
                'reserved_by_user_id' => $actorId,
                'reserved_at' => now(),
                'expires_at' => $expiresAt,
                'notes' => $payload['notes'] ?? null,
                'metadata' => $this->mergedMetadata(
                    $existingReservation?->metadata,
                    $payload['metadata'] ?? null,
                    [
                        'availableQuantityAtReservation' => $availableQuantity,
                        'holdHours' => self::DEFAULT_HOLD_HOURS,
                    ],
                ),
            ];

            $reservation = $existingReservation instanceof InventoryStockReservationModel
                ? tap($existingReservation)->forceFill($attributes)->save()
                : InventoryStockReservationModel::query()->create($attributes);

            if ($reservation === true) {
                $reservation = $existingReservation;
            }

            if (! $reservation instanceof InventoryStockReservationModel) {
                throw new \RuntimeException('Inventory reservation could not be persisted.');
            }

            return $reservation->fresh()?->toArray() ?? $reservation->toArray();
        });
    }

    public function releaseForSource(string $sourceType, string $sourceId, ?int $actorId = null, ?string $reason = null): int
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($sourceType, $sourceId, $actorId, $reason): int {
            $reservations = InventoryStockReservationModel::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', InventoryStockReservationModel::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->forceFill([
                    'status' => InventoryStockReservationModel::STATUS_RELEASED,
                    'released_by_user_id' => $actorId,
                    'released_at' => now(),
                    'metadata' => $this->mergedMetadata(
                        $reservation->metadata,
                        [
                            'releaseReason' => $reason,
                        ],
                    ),
                ])->save();
            }

            return $reservations->count();
        });
    }

    public function releaseExpiredReservationsForSource(string $sourceType, string $sourceId, ?int $actorId = null, ?string $reason = null): int
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($sourceType, $sourceId, $actorId, $reason): int {
            $reservations = $this->staleSourceReservationQuery($sourceType, $sourceId)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->forceFill([
                    'status' => InventoryStockReservationModel::STATUS_RELEASED,
                    'released_by_user_id' => $actorId,
                    'released_at' => now(),
                    'metadata' => $this->mergedMetadata(
                        $reservation->metadata,
                        [
                            'releaseReason' => $reason,
                            'releaseSource' => 'expired_reservation',
                        ],
                    ),
                ])->save();
            }

            return $reservations->count();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function consumeForSourceLine(array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload, $actorId): ?array {
            $sourceType = $this->stringOrNull($payload['source_type'] ?? null);
            $sourceId = $this->stringOrNull($payload['source_id'] ?? null);
            $sourceLineId = $this->stringOrNull($payload['source_line_id'] ?? null);
            $itemId = $this->stringOrNull($payload['item_id'] ?? null);
            $quantity = round((float) ($payload['quantity'] ?? 0), 3);
            $quantityField = $this->stringOrNull($payload['quantity_field'] ?? null) ?? 'quantity';

            if ($sourceType === null || $sourceId === null) {
                throw new \InvalidArgumentException('Inventory reservation consumption requires a source type and source id.');
            }

            if ($quantity <= 0) {
                throw new InventoryStockOperationValidationException(
                    $quantityField,
                    'Consumed reservation quantity must be greater than zero.',
                );
            }

            $reservation = $this->activeSourceReservationQuery($sourceType, $sourceId, $sourceLineId, $itemId)
                ->lockForUpdate()
                ->first();

            if (! $reservation instanceof InventoryStockReservationModel) {
                return null;
            }

            $reservedQuantity = round((float) ($reservation->quantity ?? 0), 3);
            if ($quantity > $reservedQuantity) {
                throw new InventoryStockOperationValidationException(
                    $quantityField,
                    'Dispatch quantity cannot exceed the held reservation quantity.',
                );
            }

            $releasedQuantity = round(max($reservedQuantity - $quantity, 0), 3);

            $reservation->forceFill([
                'status' => InventoryStockReservationModel::STATUS_CONSUMED,
                'consumed_by_user_id' => $actorId,
                'consumed_at' => now(),
                'metadata' => $this->mergedMetadata(
                    $reservation->metadata,
                    $payload['metadata'] ?? null,
                    [
                        'consumedQuantity' => $quantity,
                        'releasedQuantity' => $releasedQuantity,
                    ],
                ),
            ])->save();

            return $reservation->fresh()?->toArray() ?? $reservation->toArray();
        });
    }

    /**
     * @return array<int, string>
     */
    public function activeReservationIdsForSource(string $sourceType, string $sourceId, ?string $sourceLineId = null): array
    {
        return $this->activeSourceReservationQuery($sourceType, $sourceId, $sourceLineId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function hasStaleReservationsForSource(string $sourceType, string $sourceId): bool
    {
        return $this->staleSourceReservationQuery($sourceType, $sourceId)->exists();
    }

    public function freshActiveReservationCountForSource(string $sourceType, string $sourceId): int
    {
        return $this->activeReservationQuery()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->count();
    }

    public function hasExpiredReleasedReservationsForSource(string $sourceType, string $sourceId): bool
    {
        return InventoryStockReservationModel::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', InventoryStockReservationModel::STATUS_RELEASED)
            ->get(['metadata'])
            ->contains(static function (InventoryStockReservationModel $reservation): bool {
                return (($reservation->metadata ?? [])['releaseSource'] ?? null) === 'expired_reservation';
            });
    }

    public function dispatchRevalidationRequiredForSource(string $sourceType, string $sourceId): bool
    {
        if ($this->hasStaleReservationsForSource($sourceType, $sourceId)) {
            return true;
        }

        return $this->freshActiveReservationCountForSource($sourceType, $sourceId) === 0
            && $this->hasExpiredReleasedReservationsForSource($sourceType, $sourceId);
    }

    /**
     * @param  array<int, string>  $excludeReservationIds
     */
    public function activeItemReservationQuantity(string $itemId, array $excludeReservationIds = []): float
    {
        return round((float) $this->activeReservationQuery($excludeReservationIds)
            ->where('item_id', $itemId)
            ->sum('quantity'), 3);
    }

    /**
     * @param  array<int, string>  $excludeReservationIds
     */
    public function activeBatchReservationQuantity(string $batchId, array $excludeReservationIds = []): float
    {
        return round((float) $this->activeReservationQuery($excludeReservationIds)
            ->where('batch_id', $batchId)
            ->sum('quantity'), 3);
    }

    /**
     * @param  array<int, string>  $batchIds
     * @param  array<int, string>  $excludeReservationIds
     * @return array<string, float>
     */
    public function activeBatchReservationQuantities(array $batchIds, array $excludeReservationIds = []): array
    {
        $ids = collect($batchIds)
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return $this->activeReservationQuery($excludeReservationIds)
            ->whereIn('batch_id', $ids)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, SUM(quantity) as reserved_quantity')
            ->get()
            ->mapWithKeys(static fn (InventoryStockReservationModel $reservation): array => [
                (string) $reservation->batch_id => round((float) ($reservation->reserved_quantity ?? 0), 3),
            ])
            ->all();
    }

    private function activeReservationQuery(array $excludeReservationIds = []): Builder
    {
        $query = InventoryStockReservationModel::query()
            ->where('status', InventoryStockReservationModel::STATUS_ACTIVE)
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        if ($excludeReservationIds !== []) {
            $query->whereNotIn('id', $excludeReservationIds);
        }

        return $query;
    }

    private function activeSourceReservationQuery(
        string $sourceType,
        string $sourceId,
        ?string $sourceLineId = null,
        ?string $itemId = null,
    ): Builder {
        $query = InventoryStockReservationModel::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', InventoryStockReservationModel::STATUS_ACTIVE);

        if ($sourceLineId !== null) {
            $query->where('source_line_id', $sourceLineId);
        }

        if ($itemId !== null) {
            $query->where('item_id', $itemId);
        }

        return $query;
    }

    private function staleSourceReservationQuery(
        string $sourceType,
        string $sourceId,
        ?string $sourceLineId = null,
        ?string $itemId = null,
    ): Builder {
        $query = InventoryStockReservationModel::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', InventoryStockReservationModel::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        if ($sourceLineId !== null) {
            $query->where('source_line_id', $sourceLineId);
        }

        if ($itemId !== null) {
            $query->where('item_id', $itemId);
        }

        return $query;
    }

    private function isBatchReservable(InventoryBatchModel $batch, Carbon $asOf): bool
    {
        if ((string) ($batch->status ?? '') !== 'available') {
            return false;
        }

        $quantity = round((float) ($batch->quantity ?? 0), 3);
        if ($quantity <= 0) {
            return false;
        }

        $expiryDate = $batch->expiry_date;
        if ($expiryDate === null) {
            return true;
        }

        $expiry = $expiryDate instanceof Carbon
            ? $expiryDate->copy()
            : Carbon::parse((string) $expiryDate);

        return ! $expiry->endOfDay()->lt($asOf);
    }

    private function normalizeOccurredAt(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value === null || trim((string) $value) === '') {
            return now();
        }

        return Carbon::parse((string) $value);
    }

    private function normalizeExpiry(mixed $value, Carbon $occurredAt): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value !== null && trim((string) $value) !== '') {
            return Carbon::parse((string) $value);
        }

        return $occurredAt->copy()->addHours(self::DEFAULT_HOLD_HOURS);
    }

    /**
     * @param  array<string, mixed>|null  ...$sources
     * @return array<string, mixed>
     */
    private function mergedMetadata(...$sources): array
    {
        $merged = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $merged = array_merge($merged, $source);
        }

        return $merged;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
