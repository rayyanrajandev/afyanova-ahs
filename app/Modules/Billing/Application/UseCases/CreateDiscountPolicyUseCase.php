<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingDiscountPolicyRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingDiscountPolicyModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateDiscountPolicyUseCase
{
    public function __construct(
        private readonly BillingDiscountPolicyRepositoryInterface $policyRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * Create a new discount policy
     *
     * @param array<string, mixed> $payload
     * @param int|null $actorId
     *
     * @return array<string, mixed>
     */
    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();

        // Validate discount type
        $discountType = $payload['discount_type'] ?? 'percentage';
        if (! in_array($discountType, ['percentage', 'fixed', 'full_waiver', 'tiered'])) {
            throw new \RuntimeException('Invalid discount type: ' . $discountType);
        }

        // Validate that we have either discount_value or discount_percentage
        if ($discountType === 'percentage' && ! isset($payload['discount_percentage'])) {
            throw new \RuntimeException('Percentage discount requires discount_percentage field.');
        }
        if ($discountType === 'fixed' && ! isset($payload['discount_value'])) {
            throw new \RuntimeException('Fixed discount requires discount_value field.');
        }

        // Validate code uniqueness
        $existingPolicy = $this->policyRepository->findByCode(
            $payload['code'],
            $tenantId,
            $facilityId
        );
        if ($existingPolicy !== null) {
            throw new \RuntimeException('Discount policy code already exists: ' . $payload['code']);
        }

        // Create policy
        $policy = BillingDiscountPolicyModel::create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => $payload['code'],
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'discount_type' => $discountType,
            'discount_value' => $payload['discount_value'] ?? null,
            'discount_percentage' => $payload['discount_percentage'] ?? null,
            'applicable_services' => $payload['applicable_services'] ?? null,
            'auto_apply' => $payload['auto_apply'] ?? false,
            'requires_approval_above_amount' => $payload['requires_approval_above_amount'] ?? null,
            'active_from_date' => $payload['active_from_date'] ?? now(),
            'active_to_date' => $payload['active_to_date'] ?? null,
            'status' => $payload['status'] ?? 'active',
            'created_by_user_id' => $actorId,
        ]);

        return $policy->toArray();
    }
}
