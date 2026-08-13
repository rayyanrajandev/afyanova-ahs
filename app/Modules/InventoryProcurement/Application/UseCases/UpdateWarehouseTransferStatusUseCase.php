<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Application\Services\InventoryStockReservationService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseTransferRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementType;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseTransferReceiptVarianceType;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseTransferStatus;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseTransferVarianceReviewStatus;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseTransferAuditLogModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseTransferLineModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateWarehouseTransferStatusUseCase
{
    public function __construct(
        private readonly InventoryWarehouseTransferRepositoryInterface $transferRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
        private readonly InventoryStockReservationService $inventoryStockReservationService,
    ) {}

    public function execute(string $transferId, string $newStatus, string $userId, array $extraData = []): array
    {
        $transfer = $this->transferRepository->findById($transferId);

        if (! $transfer) {
            throw new \RuntimeException('Transfer not found.');
        }

        $currentStatus = $transfer['status'];
        $allowedTransitions = InventoryWarehouseTransferStatus::allowedTransitions();

        if (! in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            throw new \DomainException("Cannot transition from '{$currentStatus}' to '{$newStatus}'.");
        }

        return DB::transaction(function () use ($transfer, $newStatus, $userId, $extraData) {
            $updates = ['status' => $newStatus];

            switch ($newStatus) {
                case InventoryWarehouseTransferStatus::APPROVED->value:
                    $updates['approved_by_user_id'] = $userId;
                    $updates['approved_at'] = now();
                    $this->reserveTransferStock($transfer, $userId);
                    break;

                case InventoryWarehouseTransferStatus::PACKED->value:
                    $this->ensureExecutionReservationIsFresh(
                        transfer: $transfer,
                        actorId: (int) $userId,
                        extraData: $extraData,
                        stage: 'packing_revalidated',
                        actionLabel: 'packing',
                    );
                    $updates['packed_by_user_id'] = $userId;
                    $updates['packed_at'] = now();
                    $updates['pack_notes'] = $extraData['pack_notes'] ?? null;
                    $updates['dispatch_note_number'] = $transfer['dispatch_note_number'] ?? $this->generateDispatchNoteNumber($transfer);
                    $this->processPackConfirmation($transfer, $extraData);
                    break;

                case InventoryWarehouseTransferStatus::REJECTED->value:
                    $updates['rejection_reason'] = $extraData['rejection_reason'] ?? null;
                    $this->inventoryStockReservationService->releaseForSource(
                        'inventory_warehouse_transfer',
                        (string) $transfer['id'],
                        (int) $userId,
                        'Transfer rejected before dispatch.',
                    );
                    break;

                case InventoryWarehouseTransferStatus::IN_TRANSIT->value:
                    $this->ensureExecutionReservationIsFresh(
                        transfer: $transfer,
                        actorId: (int) $userId,
                        extraData: $extraData,
                        stage: 'dispatch_revalidated',
                        actionLabel: 'dispatch',
                    );
                    $updates['dispatched_by_user_id'] = $userId;
                    $updates['dispatched_at'] = now();
                    $updates['dispatch_note_number'] = $transfer['dispatch_note_number'] ?? $this->generateDispatchNoteNumber($transfer);
                    $this->processDispatchStockMovements($transfer, $userId, $extraData);
                    break;

                case InventoryWarehouseTransferStatus::RECEIVED->value:
                    $updates['received_by_user_id'] = $userId;
                    $updates['received_at'] = now();
                    $updates['receiving_notes'] = $extraData['receiving_notes'] ?? null;
                    $updates = array_merge(
                        $updates,
                        $this->processReceiveStockMovements($transfer, $userId, $extraData),
                    );
                    break;

                case InventoryWarehouseTransferStatus::CANCELLED->value:
                    $this->inventoryStockReservationService->releaseForSource(
                        'inventory_warehouse_transfer',
                        (string) $transfer['id'],
                        (int) $userId,
                        'Transfer cancelled before dispatch.',
                    );
                    break;
            }

            $result = $this->transferRepository->update($transfer['id'], $updates);

            InventoryWarehouseTransferAuditLogModel::query()->create([
                'transfer_id' => $transfer['id'],
                'action' => "status_changed_to_{$newStatus}",
                'actor_type' => 'user',
                'actor_id' => $userId,
                'changes' => ['from' => $transfer['status'], 'to' => $newStatus],
                'metadata' => $extraData,
                'created_at' => now(),
            ]);

            return $result;
        });
    }

    private function processPackConfirmation(array $transfer, array $extraData): void
    {
        $lines = InventoryWarehouseTransferLineModel::query()
            ->where('transfer_id', $transfer['id'])
            ->lockForUpdate()
            ->get();

        foreach ($lines as $line) {
            $field = "packedQuantities.{$line->id}";
            $packedQty = round((float) ($extraData['packed_quantities'][$line->id] ?? $line->requested_quantity), 3);

            if ($packedQty <= 0) {
                throw new InventoryStockOperationValidationException($field, 'Packed quantity must be greater than zero.');
            }

            if ($packedQty > round((float) ($line->requested_quantity ?? 0), 3)) {
                throw new InventoryStockOperationValidationException($field, 'Packed quantity cannot exceed the requested transfer quantity.');
            }

            $line->update(['packed_quantity' => $packedQty]);
        }
    }

    private function processDispatchStockMovements(array $transfer, string $userId, array $extraData): void
    {
        $lines = InventoryWarehouseTransferLineModel::query()
            ->where('transfer_id', $transfer['id'])
            ->lockForUpdate()
            ->get();

        foreach ($lines as $line) {
            $field = "dispatchedQuantities.{$line->id}";
            $maxDispatchQuantity = $line->packed_quantity !== null
                ? round((float) $line->packed_quantity, 3)
                : round((float) ($line->requested_quantity ?? 0), 3);
            $dispatchedQty = round((float) ($extraData['dispatched_quantities'][$line->id] ?? $maxDispatchQuantity), 3);
            if ($dispatchedQty <= 0) {
                throw new InventoryStockOperationValidationException($field, 'Dispatch quantity must be greater than zero.');
            }

            if ($dispatchedQty > $maxDispatchQuantity) {
                throw new InventoryStockOperationValidationException(
                    $field,
                    $line->packed_quantity !== null
                        ? 'Dispatch quantity cannot exceed the confirmed packed quantity.'
                        : 'Dispatch quantity cannot exceed the requested transfer quantity.',
                );
            }

            $item = InventoryItemModel::query()->find($line->item_id);
            if (! $item instanceof InventoryItemModel) {
                throw new \RuntimeException('Transfer line item was not found.');
            }

            $metadata = $this->transferMovementMetadata($transfer, 'dispatch');
            $reservationExclusionIds = $this->inventoryStockReservationService->activeReservationIdsForSource(
                'inventory_warehouse_transfer',
                (string) $transfer['id'],
                (string) $line->id,
            );

            if ($this->usesBatchTracking($item)) {
                if (! is_string($line->batch_id) || trim($line->batch_id) === '') {
                    throw new InventoryStockOperationValidationException($field, 'Tracked transfer dispatch requires a source batch.');
                }

                $this->inventoryBatchStockService->issueExactBatch([
                    'item_id' => $line->item_id,
                    'batch_id' => $line->batch_id,
                    'quantity' => $dispatchedQty,
                    'quantity_field' => $field,
                    'batch_field' => $field,
                    'source_warehouse_id' => $transfer['source_warehouse_id'],
                    'destination_warehouse_id' => $transfer['destination_warehouse_id'],
                    'source_type' => 'inventory_warehouse_transfer',
                    'source_id' => $transfer['id'],
                    'movement_type' => InventoryStockMovementType::TRANSFER->value,
                    'adjustment_direction' => 'decrease',
                    'reason' => 'warehouse_transfer',
                    'notes' => "Transfer out: {$transfer['transfer_number']}",
                    'metadata' => $metadata,
                    'occurred_at' => now(),
                    'reservation_exclusion_ids' => $reservationExclusionIds,
                ], (int) $userId);
            } else {
                $this->inventoryBatchStockService->issue([
                    'item_id' => $line->item_id,
                    'quantity' => $dispatchedQty,
                    'source_warehouse_id' => $transfer['source_warehouse_id'],
                    'destination_warehouse_id' => $transfer['destination_warehouse_id'],
                    'source_type' => 'inventory_warehouse_transfer',
                    'source_id' => $transfer['id'],
                    'movement_type' => InventoryStockMovementType::TRANSFER->value,
                    'adjustment_direction' => 'decrease',
                    'reason' => 'warehouse_transfer',
                    'notes' => "Transfer out: {$transfer['transfer_number']}",
                    'metadata' => $metadata,
                    'occurred_at' => now(),
                    'reservation_exclusion_ids' => $reservationExclusionIds,
                ], (int) $userId);
            }

            $this->inventoryStockReservationService->consumeForSourceLine([
                'source_type' => 'inventory_warehouse_transfer',
                'source_id' => $transfer['id'],
                'source_line_id' => $line->id,
                'item_id' => $line->item_id,
                'quantity' => $dispatchedQty,
                'quantity_field' => $field,
                'metadata' => [
                    'transferId' => $transfer['id'],
                    'transferNumber' => $transfer['transfer_number'] ?? null,
                    'transferStage' => 'dispatch',
                ],
            ], (int) $userId);

            $line->update(['dispatched_quantity' => $dispatchedQty]);
        }
    }

    /**
     * @param  array<string, mixed>  $transfer
     * @param  array<string, mixed>  $extraData
     */
    private function ensureExecutionReservationIsFresh(
        array $transfer,
        int $actorId,
        array $extraData,
        string $stage,
        string $actionLabel,
    ): void
    {
        $sourceType = 'inventory_warehouse_transfer';
        $sourceId = (string) $transfer['id'];
        $revalidateReservation = (bool) ($extraData['revalidate_reservation'] ?? false);
        $revalidationRequired = $this->inventoryStockReservationService->dispatchRevalidationRequiredForSource($sourceType, $sourceId);

        if (! $revalidationRequired) {
            return;
        }

        if (! $revalidateReservation) {
            throw new InventoryStockOperationValidationException(
                'revalidateReservation',
                sprintf('The stock hold for this transfer has expired. Refresh the reservation before %s.', $actionLabel),
            );
        }

        $this->inventoryStockReservationService->releaseExpiredReservationsForSource(
            $sourceType,
            $sourceId,
            $actorId,
            sprintf('Expired transfer hold released before %s revalidation.', $actionLabel),
        );

        $this->reserveTransferStock($transfer, (string) $actorId, $stage);
    }

    /**
     * @return array<string, mixed>
     */
    private function processReceiveStockMovements(array $transfer, string $userId, array $extraData): array
    {
        $lines = InventoryWarehouseTransferLineModel::query()
            ->where('transfer_id', $transfer['id'])
            ->lockForUpdate()
            ->get();

        $hasVariance = false;

        foreach ($lines as $line) {
            $field = "receivedQuantities.{$line->id}";
            $dispatchedQty = round((float) ($line->dispatched_quantity ?? 0), 3);
            $receivedQty = round((float) ($extraData['received_quantities'][$line->id] ?? $dispatchedQty), 3);
            $varianceType = $this->resolveReceiptVarianceType($extraData['receipt_variance_types'][$line->id] ?? null);
            $varianceQuantity = round((float) ($extraData['receipt_variance_quantities'][$line->id] ?? 0), 3);
            $varianceReason = $this->normalizeOptionalString($extraData['receipt_variance_reasons'][$line->id] ?? null);

            $receiptVariance = $this->validateReceiptVariance(
                lineId: (string) $line->id,
                receivedField: $field,
                dispatchedQuantity: $dispatchedQty,
                acceptedQuantity: $receivedQty,
                varianceType: $varianceType,
                varianceQuantity: $varianceQuantity,
                varianceReason: $varianceReason,
            );

            $item = InventoryItemModel::query()->find($line->item_id);
            if (! $item instanceof InventoryItemModel) {
                throw new \RuntimeException('Transfer line item was not found.');
            }

            $metadata = array_merge(
                $this->transferMovementMetadata($transfer, 'receipt'),
                [
                    'reportedReceivedQuantity' => $receiptVariance['reported_received_quantity'],
                    'acceptedReceivedQuantity' => $receiptVariance['accepted_quantity'],
                    'receiptVarianceType' => $receiptVariance['variance_type'],
                    'receiptVarianceQuantity' => $receiptVariance['variance_quantity'],
                    'receiptVarianceReason' => $receiptVariance['variance_reason'],
                ],
            );

            if ($receiptVariance['accepted_quantity'] > 0 && $this->usesBatchTracking($item)) {
                if (! is_string($line->batch_id) || trim($line->batch_id) === '') {
                    throw new InventoryStockOperationValidationException($field, 'Tracked transfer receipt requires the dispatched source batch.');
                }

                $sourceBatch = InventoryBatchModel::query()
                    ->whereKey($line->batch_id)
                    ->where('item_id', $line->item_id)
                    ->lockForUpdate()
                    ->first();

                if (! $sourceBatch instanceof InventoryBatchModel) {
                    throw new InventoryStockOperationValidationException($field, 'The source batch record was not found for receipt.');
                }

                $this->inventoryBatchStockService->receiveMovement([
                    'item_id' => $line->item_id,
                    'quantity' => $receiptVariance['accepted_quantity'],
                    'quantity_field' => $field,
                    'source_warehouse_id' => $transfer['source_warehouse_id'],
                    'destination_warehouse_id' => $transfer['destination_warehouse_id'],
                    'source_type' => 'inventory_warehouse_transfer',
                    'source_id' => $transfer['id'],
                    'movement_type' => InventoryStockMovementType::TRANSFER->value,
                    'adjustment_direction' => 'increase',
                    'batch_number' => $sourceBatch->batch_number,
                    'lot_number' => $sourceBatch->lot_number,
                    'manufacture_date' => $sourceBatch->manufacture_date?->toDateString(),
                    'expiry_date' => $sourceBatch->expiry_date?->toDateString(),
                    'bin_location' => $sourceBatch->bin_location,
                    'reason' => 'warehouse_transfer',
                    'notes' => "Transfer in: {$transfer['transfer_number']}",
                    'metadata' => $metadata,
                    'occurred_at' => now(),
                ], (int) $userId);
            } elseif ($receiptVariance['accepted_quantity'] > 0) {
                $this->inventoryBatchStockService->receiveMovement([
                    'item_id' => $line->item_id,
                    'quantity' => $receiptVariance['accepted_quantity'],
                    'quantity_field' => $field,
                    'source_warehouse_id' => $transfer['source_warehouse_id'],
                    'destination_warehouse_id' => $transfer['destination_warehouse_id'],
                    'source_type' => 'inventory_warehouse_transfer',
                    'source_id' => $transfer['id'],
                    'movement_type' => InventoryStockMovementType::TRANSFER->value,
                    'adjustment_direction' => 'increase',
                    'reason' => 'warehouse_transfer',
                    'notes' => "Transfer in: {$transfer['transfer_number']}",
                    'metadata' => $metadata,
                    'occurred_at' => now(),
                ], (int) $userId);
            }

            $line->update([
                'received_quantity' => $receiptVariance['accepted_quantity'],
                'reported_received_quantity' => $receiptVariance['reported_received_quantity'],
                'receipt_variance_type' => $receiptVariance['variance_type'],
                'receipt_variance_quantity' => $receiptVariance['variance_quantity'],
                'receipt_variance_reason' => $receiptVariance['variance_reason'],
            ]);

            if ((float) $receiptVariance['variance_quantity'] > 0) {
                $hasVariance = true;
            }
        }

        return [
            'receipt_variance_review_status' => $hasVariance ? InventoryWarehouseTransferVarianceReviewStatus::PENDING->value : null,
            'receipt_variance_reviewed_by_user_id' => null,
            'receipt_variance_reviewed_at' => null,
            'receipt_variance_review_notes' => null,
        ];
    }

    /**
     * @return array{
     *     accepted_quantity: float,
     *     reported_received_quantity: float,
     *     variance_type: ?string,
     *     variance_quantity: float,
     *     variance_reason: ?string
     * }
     */
    private function validateReceiptVariance(
        string $lineId,
        string $receivedField,
        float $dispatchedQuantity,
        float $acceptedQuantity,
        InventoryWarehouseTransferReceiptVarianceType $varianceType,
        float $varianceQuantity,
        ?string $varianceReason,
    ): array {
        if ($acceptedQuantity < 0) {
            throw new InventoryStockOperationValidationException($receivedField, 'Accepted quantity cannot be negative.');
        }

        if ($acceptedQuantity > $dispatchedQuantity) {
            throw new InventoryStockOperationValidationException($receivedField, 'Accepted quantity cannot exceed the dispatched transfer quantity.');
        }

        $varianceField = "receiptVarianceQuantities.{$lineId}";
        $varianceReasonField = "receiptVarianceReasons.{$lineId}";

        if ($varianceQuantity < 0) {
            throw new InventoryStockOperationValidationException($varianceField, 'Variance quantity cannot be negative.');
        }

        return match ($varianceType) {
            InventoryWarehouseTransferReceiptVarianceType::FULL => $this->buildFullReceiptVariance(
                receivedField: $receivedField,
                acceptedQuantity: $acceptedQuantity,
                dispatchedQuantity: $dispatchedQuantity,
                varianceQuantity: $varianceQuantity,
                varianceField: $varianceField,
                varianceReason: $varianceReason,
                varianceReasonField: $varianceReasonField,
            ),
            InventoryWarehouseTransferReceiptVarianceType::SHORT,
            InventoryWarehouseTransferReceiptVarianceType::DAMAGED,
            InventoryWarehouseTransferReceiptVarianceType::WRONG_BATCH => $this->buildRejectedReceiptVariance(
                acceptedQuantity: $acceptedQuantity,
                dispatchedQuantity: $dispatchedQuantity,
                varianceType: $varianceType,
                varianceQuantity: $varianceQuantity,
                varianceField: $varianceField,
                varianceReason: $varianceReason,
                varianceReasonField: $varianceReasonField,
            ),
            InventoryWarehouseTransferReceiptVarianceType::EXCESS => $this->buildExcessReceiptVariance(
                receivedField: $receivedField,
                acceptedQuantity: $acceptedQuantity,
                dispatchedQuantity: $dispatchedQuantity,
                varianceQuantity: $varianceQuantity,
                varianceField: $varianceField,
                varianceReason: $varianceReason,
                varianceReasonField: $varianceReasonField,
            ),
        };
    }

    /**
     * @return array{
     *     accepted_quantity: float,
     *     reported_received_quantity: float,
     *     variance_type: ?string,
     *     variance_quantity: float,
     *     variance_reason: ?string
     * }
     */
    private function buildFullReceiptVariance(
        string $receivedField,
        float $acceptedQuantity,
        float $dispatchedQuantity,
        float $varianceQuantity,
        string $varianceField,
        ?string $varianceReason,
        string $varianceReasonField,
    ): array {
        if ($acceptedQuantity <= 0) {
            throw new InventoryStockOperationValidationException($receivedField, 'Accepted quantity must be greater than zero for a clean receipt.');
        }

        if ($acceptedQuantity !== $dispatchedQuantity) {
            throw new InventoryStockOperationValidationException($receivedField, 'Clean receipt requires the accepted quantity to match the dispatched quantity.');
        }

        if ($varianceQuantity !== 0.0) {
            throw new InventoryStockOperationValidationException($varianceField, 'Clean receipt cannot carry a variance quantity.');
        }

        if ($varianceReason !== null) {
            throw new InventoryStockOperationValidationException($varianceReasonField, 'Clean receipt does not require a variance reason.');
        }

        return [
            'accepted_quantity' => $acceptedQuantity,
            'reported_received_quantity' => $acceptedQuantity,
            'variance_type' => null,
            'variance_quantity' => 0.0,
            'variance_reason' => null,
        ];
    }

    /**
     * @return array{
     *     accepted_quantity: float,
     *     reported_received_quantity: float,
     *     variance_type: string,
     *     variance_quantity: float,
     *     variance_reason: string
     * }
     */
    private function buildRejectedReceiptVariance(
        float $acceptedQuantity,
        float $dispatchedQuantity,
        InventoryWarehouseTransferReceiptVarianceType $varianceType,
        float $varianceQuantity,
        string $varianceField,
        ?string $varianceReason,
        string $varianceReasonField,
    ): array {
        if ($varianceQuantity <= 0) {
            throw new InventoryStockOperationValidationException($varianceField, 'Variance quantity must be greater than zero when receipt does not match dispatch.');
        }

        if (round($acceptedQuantity + $varianceQuantity, 3) !== $dispatchedQuantity) {
            throw new InventoryStockOperationValidationException(
                $varianceField,
                'Accepted quantity plus variance quantity must fully account for the dispatched quantity.',
            );
        }

        if ($varianceReason === null) {
            throw new InventoryStockOperationValidationException($varianceReasonField, 'Provide a variance reason for non-clean receipt lines.');
        }

        $reportedReceivedQuantity = $varianceType === InventoryWarehouseTransferReceiptVarianceType::SHORT
            ? $acceptedQuantity
            : round($acceptedQuantity + $varianceQuantity, 3);

        return [
            'accepted_quantity' => $acceptedQuantity,
            'reported_received_quantity' => $reportedReceivedQuantity,
            'variance_type' => $varianceType->value,
            'variance_quantity' => $varianceQuantity,
            'variance_reason' => $varianceReason,
        ];
    }

    /**
     * @return array{
     *     accepted_quantity: float,
     *     reported_received_quantity: float,
     *     variance_type: string,
     *     variance_quantity: float,
     *     variance_reason: string
     * }
     */
    private function buildExcessReceiptVariance(
        string $receivedField,
        float $acceptedQuantity,
        float $dispatchedQuantity,
        float $varianceQuantity,
        string $varianceField,
        ?string $varianceReason,
        string $varianceReasonField,
    ): array {
        if ($acceptedQuantity <= 0) {
            throw new InventoryStockOperationValidationException($receivedField, 'Accepted quantity must be greater than zero for excess receipt lines.');
        }

        if ($acceptedQuantity !== $dispatchedQuantity) {
            throw new InventoryStockOperationValidationException(
                $receivedField,
                'Excess receipt lines may only accept up to the dispatched quantity into stock.',
            );
        }

        if ($varianceQuantity <= 0) {
            throw new InventoryStockOperationValidationException($varianceField, 'Extra quantity must be captured for excess receipt lines.');
        }

        if ($varianceReason === null) {
            throw new InventoryStockOperationValidationException($varianceReasonField, 'Provide a variance reason for excess receipt lines.');
        }

        return [
            'accepted_quantity' => $acceptedQuantity,
            'reported_received_quantity' => round($acceptedQuantity + $varianceQuantity, 3),
            'variance_type' => InventoryWarehouseTransferReceiptVarianceType::EXCESS->value,
            'variance_quantity' => $varianceQuantity,
            'variance_reason' => $varianceReason,
        ];
    }

    private function resolveReceiptVarianceType(mixed $value): InventoryWarehouseTransferReceiptVarianceType
    {
        $normalized = trim((string) ($value ?? ''));

        return InventoryWarehouseTransferReceiptVarianceType::tryFrom($normalized)
            ?? InventoryWarehouseTransferReceiptVarianceType::FULL;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function transferMovementMetadata(array $transfer, string $stage): array
    {
        return [
            'source' => 'warehouse_transfer',
            'transferId' => $transfer['id'],
            'transferNumber' => $transfer['transfer_number'] ?? null,
            'dispatchNoteNumber' => $transfer['dispatch_note_number'] ?? null,
            'transferStage' => $stage,
            'sourceWarehouseId' => $transfer['source_warehouse_id'] ?? null,
            'destinationWarehouseId' => $transfer['destination_warehouse_id'] ?? null,
        ];
    }

    private function reserveTransferStock(array $transfer, string $userId, string $stage = 'approved'): void
    {
        $lines = InventoryWarehouseTransferLineModel::query()
            ->where('transfer_id', $transfer['id'])
            ->lockForUpdate()
            ->get();

        foreach ($lines as $index => $line) {
            $item = InventoryItemModel::query()->find($line->item_id);
            if (! $item instanceof InventoryItemModel) {
                throw new \RuntimeException('Transfer line item was not found.');
            }

            $payload = [
                'item_id' => $line->item_id,
                'batch_id' => $line->batch_id,
                'warehouse_id' => $transfer['source_warehouse_id'] ?? null,
                'source_type' => 'inventory_warehouse_transfer',
                'source_id' => $transfer['id'],
                'source_line_id' => $line->id,
                'quantity' => round((float) ($line->requested_quantity ?? 0), 3),
                'quantity_field' => "lines.$index.requestedQuantity",
                'batch_field' => "lines.$index.batchId",
                'notes' => "Reserved for transfer {$transfer['transfer_number']}",
                'metadata' => [
                    'transferId' => $transfer['id'],
                    'transferNumber' => $transfer['transfer_number'] ?? null,
                    'transferStage' => $stage,
                    'sourceWarehouseId' => $transfer['source_warehouse_id'] ?? null,
                    'destinationWarehouseId' => $transfer['destination_warehouse_id'] ?? null,
                    'trackingMode' => $this->usesBatchTracking($item) ? 'tracked' : 'untracked',
                ],
                'occurred_at' => now(),
            ];

            $this->inventoryStockReservationService->reserve($payload, (int) $userId);
        }
    }

    private function usesBatchTracking(InventoryItemModel $item): bool
    {
        $category = InventoryItemCategory::tryFrom((string) ($item->category ?? ''));
        if ($category?->requiresExpiryTracking() ?? false) {
            return true;
        }

        return InventoryBatchModel::query()
            ->where('item_id', $item->id)
            ->exists();
    }

    private function generateDispatchNoteNumber(array $transfer): string
    {
        $transferNumber = trim((string) ($transfer['transfer_number'] ?? ''));

        if ($transferNumber !== '') {
            return sprintf('DN-%s', $transferNumber);
        }

        return 'DN-'.Str::upper(Str::random(8));
    }
}
