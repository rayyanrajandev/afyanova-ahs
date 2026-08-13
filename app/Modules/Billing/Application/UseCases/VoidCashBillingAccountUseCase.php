<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\CashBillingAccountRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VoidCashBillingAccountUseCase
{
    public function __construct(
        private readonly CashBillingAccountRepositoryInterface $cashBillingAccountRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload): array {
            $accountId = (string) $payload['cash_billing_account_id'];
            $account = $this->cashBillingAccountRepository->findById($accountId);

            if ($account === null) {
                throw new RuntimeException('Cash billing account not found.');
            }

            if ($account['status'] !== 'active') {
                throw new RuntimeException('Only active cash billing accounts can be voided.');
            }

            $voidReason = trim((string) ($payload['void_reason'] ?? ''));

            $this->cashBillingAccountRepository->update($accountId, [
                'status' => 'voided',
                'notes' => ($account['notes'] ?? '')
                    .(!empty($account['notes']) ? '; ' : '')
                    .'Account voided on '.now()->format('Y-m-d H:i:s')
                    .($voidReason !== '' ? '. Reason: '.$voidReason : '').'.',
            ]);

            return $this->cashBillingAccountRepository->findById($accountId) ?? $account;
        });
    }
}
