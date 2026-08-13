<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\CashBillingAccountRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\CashBillingAccountModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;

class CreateCashBillingAccountUseCase
{
    public function __construct(
        private readonly CashBillingAccountRepositoryInterface $cashBillingAccountRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly DefaultCurrencyResolverInterface $currencyResolver,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * Create a cash billing account for a patient
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function execute(array $payload): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();

        // Check if account already exists
        $existingAccount = $this->cashBillingAccountRepository->findByPatientId(
            (string) $payload['patient_id'],
            $tenantId,
            $facilityId
        );

        if ($existingAccount !== null && $existingAccount['status'] === 'active') {
            return $existingAccount;
        }

        // Create new account
        $account = CashBillingAccountModel::create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => (string) $payload['patient_id'],
            'currency_code' => $payload['currency_code'] ?? $this->currencyResolver->resolve(),
            'account_balance' => 0,
            'total_charged' => 0,
            'total_paid' => 0,
            'status' => 'active',
            'notes' => $payload['notes'] ?? null,
        ]);

        return $account->toArray();
    }
}
