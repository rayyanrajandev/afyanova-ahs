<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingCorporateAccountRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;
use RuntimeException;

class CreateBillingCorporateAccountUseCase
{
    public function __construct(
        private readonly BillingCorporateAccountRepositoryInterface $repository,
        private readonly BillingPayerContractRepositoryInterface $billingPayerContractRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $contractId = (string) $payload['billing_payer_contract_id'];
        $contract = $this->billingPayerContractRepository->findById($contractId);
        if ($contract === null) {
            throw new RuntimeException('Payer contract not found for corporate billing setup.');
        }

        $payerType = strtolower((string) ($contract['payer_type'] ?? ''));
        if (! in_array($payerType, ['employer', 'other', 'insurance'], true)) {
            throw new RuntimeException('Corporate billing account can only be created for employer, sponsor, or contract payer types.');
        }

        return $this->repository->createAccount([
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'billing_payer_contract_id' => $contractId,
            'account_code' => trim((string) ($payload['account_code'] ?? $this->generateAccountCode())),
            'account_name' => trim((string) ($payload['account_name'] ?? ($contract['contract_name'] ?? $contract['payer_name'] ?? 'Corporate account'))),
            'billing_contact_name' => $this->nullableString($payload['billing_contact_name'] ?? null),
            'billing_contact_email' => $this->nullableString($payload['billing_contact_email'] ?? null),
            'billing_contact_phone' => $this->nullableString($payload['billing_contact_phone'] ?? null),
            'billing_cycle_day' => max(min((int) ($payload['billing_cycle_day'] ?? 1), 31), 1),
            'settlement_terms_days' => max((int) ($payload['settlement_terms_days'] ?? ($contract['settlement_cycle_days'] ?? 30)), 1),
            'status' => (string) ($payload['status'] ?? 'active'),
            'notes' => $this->nullableString($payload['notes'] ?? null),
            'metadata' => [
                'contractCode' => $contract['contract_code'] ?? null,
                'payerType' => $contract['payer_type'] ?? null,
                'payerName' => $contract['payer_name'] ?? null,
            ],
        ]);
    }

    private function generateAccountCode(): string
    {
        return 'CORP-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
