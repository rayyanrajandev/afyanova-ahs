<?php

namespace App\Modules\Billing\Domain\Repositories;

interface CashBillingChargeRepositoryInterface
{
    /**
     * Find a charge by ID
     *
     * @param string $id
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array;

    /**
     * Find all charges for an account
     *
     * @param string $accountId
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByAccountId(string $accountId): array;

    /**
     * Create a new charge
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data): array;
}
