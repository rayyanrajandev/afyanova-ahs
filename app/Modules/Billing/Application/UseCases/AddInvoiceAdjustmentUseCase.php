<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;

class AddInvoiceAdjustmentUseCase
{
    public function __construct(
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $invoiceId, string $type, float $amount, string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $invoice = $this->billingInvoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            return null;
        }

        $adjustments = is_array($invoice['adjustments'] ?? null) ? $invoice['adjustments'] : [];

        $adjustment = [
            'id' => Str::uuid()->toString(),
            'type' => $type,
            'amount' => round($amount, 2),
            'reason' => $reason,
            'created_by_user_id' => $actorId,
            'created_at' => now()->toISOString(),
        ];

        $adjustments[] = $adjustment;

        $updatePayload = ['adjustments' => $adjustments];

        if ($type === 'credit') {
            $currentBalance = (float) ($invoice['balance_amount'] ?? 0);
            $newBalance = round(max($currentBalance - $amount, 0), 2);
            $updatePayload['balance_amount'] = $newBalance;
        } elseif ($type === 'debit') {
            $currentTotal = (float) ($invoice['total_amount'] ?? 0);
            $currentBalance = (float) ($invoice['balance_amount'] ?? 0);
            $updatePayload['total_amount'] = round($currentTotal + $amount, 2);
            $updatePayload['balance_amount'] = round($currentBalance + $amount, 2);
        }

        $updated = $this->billingInvoiceRepository->update($invoiceId, $updatePayload);

        if ($updated === null) {
            return null;
        }

        return [
            'invoice' => $updated,
            'adjustment' => $adjustment,
        ];
    }
}
