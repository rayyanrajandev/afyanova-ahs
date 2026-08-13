<?php

namespace App\Modules\Pos\Domain\Repositories;

interface PosSaleAdjustmentRepositoryInterface
{
    public function create(array $attributes): array;

    public function existsByAdjustmentNumber(string $adjustmentNumber): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findBySaleId(string $saleId): array;

    /**
     * @return array<string, float|int>
     */
    public function summarizeSession(string $sessionId): array;
}
