<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDispensingClaimLinkRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryDispensingClaimStatus;

class UpdateDispensingClaimStatusUseCase
{
    public function __construct(
        private readonly InventoryDispensingClaimLinkRepositoryInterface $linkRepository,
    ) {}

    public function execute(string $id, string $newStatus, array $payload): array
    {
        $existing = $this->linkRepository->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('Dispensing claim link not found.');
        }

        // Validate the status is a valid enum value
        $statusEnum = InventoryDispensingClaimStatus::tryFrom($newStatus);
        if ($statusEnum === null) {
            throw new \DomainException("Invalid claim status: {$newStatus}");
        }

        $updateData = ['claim_status' => $newStatus];

        if ($newStatus === 'submitted') {
            $updateData['submitted_at'] = $payload['submitted_at'] ?? now()->toIso8601String();
        }

        if (in_array($newStatus, ['approved', 'partially_approved', 'rejected'], true)) {
            $updateData['adjudicated_at'] = $payload['adjudicated_at'] ?? now()->toIso8601String();
            $updateData['approved_amount'] = $payload['approved_amount'] ?? null;
            $updateData['rejected_amount'] = $payload['rejected_amount'] ?? null;
            $updateData['rejection_reason'] = $payload['rejection_reason'] ?? null;
        }

        if (isset($payload['insurance_claim_id'])) {
            $updateData['insurance_claim_id'] = $payload['insurance_claim_id'];
        }

        if (isset($payload['billing_invoice_id'])) {
            $updateData['billing_invoice_id'] = $payload['billing_invoice_id'];
        }

        $result = $this->linkRepository->update($id, $updateData);
        if ($result === null) {
            throw new \RuntimeException('Failed to update dispensing claim link.');
        }

        return $result;
    }
}
