<?php

namespace App\Modules\Pos\Domain\Repositories;

interface PosSaleRepositoryInterface
{
    public function create(array $saleAttributes, array $lineItems, array $payments): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsBySaleNumber(string $saleNumber): bool;

    public function existsByReceiptNumber(string $receiptNumber): bool;

    public function search(
        ?string $query,
        ?string $registerId,
        ?string $sessionId,
        ?string $paymentMethod,
        ?string $saleChannel,
        ?string $status,
        ?string $soldFrom,
        ?string $soldTo,
        int $page,
        int $perPage
    ): array;

    /**
     * @return array<string, float|int>
     */
    public function summarizeSession(string $sessionId): array;
}
