<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingDiscountPolicyRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function findForFacility(string $tenantId, string $facilityId, array $filters = []): array;

    /**
     * Find a discount policy by ID
     */
    public function findById(string $id): ?array;

    /**
     * Find a discount policy by code
     */
    public function findByCode(string $code, string $tenantId, string $facilityId): ?array;

    /**
     * Get all active discount policies
     */
    public function getActiveByFacility(string $tenantId, string $facilityId): array;

    /**
     * Create a new discount policy
     */
    public function create(array $data): array;

    /**
     * Update a discount policy
     */
    public function update(string $id, array $data): array;
}
