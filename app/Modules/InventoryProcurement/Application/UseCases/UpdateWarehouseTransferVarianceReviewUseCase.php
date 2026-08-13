<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseTransferRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseTransferStatus;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseTransferVarianceReviewStatus;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseTransferAuditLogModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseTransferLineModel;
use Illuminate\Support\Facades\DB;

class UpdateWarehouseTransferVarianceReviewUseCase
{
    public function __construct(
        private readonly InventoryWarehouseTransferRepositoryInterface $transferRepository,
    ) {}

    public function execute(string $transferId, string $reviewStatus, string $userId, ?string $reviewNotes = null): array
    {
        $transfer = $this->transferRepository->findById($transferId);

        if ($transfer === null) {
            throw new \RuntimeException('Transfer not found.');
        }

        if (($transfer['status'] ?? null) !== InventoryWarehouseTransferStatus::RECEIVED->value) {
            throw new \DomainException('Only received transfers can be reviewed for receipt variance.');
        }

        $normalizedReviewStatus = InventoryWarehouseTransferVarianceReviewStatus::from($reviewStatus);
        $normalizedReviewNotes = $this->normalizeOptionalString($reviewNotes);

        $hasVariance = InventoryWarehouseTransferLineModel::query()
            ->where('transfer_id', $transferId)
            ->where('receipt_variance_quantity', '>', 0)
            ->exists();

        if (! $hasVariance) {
            throw new InventoryStockOperationValidationException(
                'reviewStatus',
                'Only transfers with recorded receipt variance can enter the variance review queue.',
            );
        }

        return DB::transaction(function () use ($transfer, $normalizedReviewStatus, $normalizedReviewNotes, $userId) {
            $previousStatus = $this->normalizeOptionalString($transfer['receipt_variance_review_status'] ?? null);
            $previousNotes = $this->normalizeOptionalString($transfer['receipt_variance_review_notes'] ?? null);

            $updates = [
                'receipt_variance_review_status' => $normalizedReviewStatus->value,
                'receipt_variance_review_notes' => $normalizedReviewNotes,
                'receipt_variance_reviewed_by_user_id' => $normalizedReviewStatus === InventoryWarehouseTransferVarianceReviewStatus::REVIEWED ? $userId : null,
                'receipt_variance_reviewed_at' => $normalizedReviewStatus === InventoryWarehouseTransferVarianceReviewStatus::REVIEWED ? now() : null,
            ];

            $result = $this->transferRepository->update((string) $transfer['id'], $updates);

            InventoryWarehouseTransferAuditLogModel::query()->create([
                'transfer_id' => $transfer['id'],
                'action' => 'receipt_variance_review_updated',
                'actor_type' => 'user',
                'actor_id' => $userId,
                'changes' => [
                    'from' => $previousStatus,
                    'to' => $normalizedReviewStatus->value,
                    'notes_from' => $previousNotes,
                    'notes_to' => $normalizedReviewNotes,
                ],
                'metadata' => [
                    'reviewStatus' => $normalizedReviewStatus->value,
                    'reviewNotes' => $normalizedReviewNotes,
                ],
                'created_at' => now(),
            ]);

            return $result ?? $transfer;
        });
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }
}
