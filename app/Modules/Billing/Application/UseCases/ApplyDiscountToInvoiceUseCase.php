<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Support\BillingFinancePostingService;
use App\Modules\Billing\Domain\Repositories\BillingDiscountPolicyRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingDiscountRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingDiscountModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class ApplyDiscountToInvoiceUseCase
{
    public function __construct(
        private readonly BillingInvoiceRepositoryInterface $invoiceRepository,
        private readonly BillingDiscountPolicyRepositoryInterface $policyRepository,
        private readonly BillingDiscountRepositoryInterface $discountRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly BillingFinancePostingService $billingFinancePostingService,
    ) {}

    /**
     * Apply a discount policy to an invoice
     *
     * @param array<string, mixed> $payload
     * @param int|null $actorId
     *
     * @return array<string, mixed>
     */
    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $invoice = null;
        $invoiceId = null;
        if (isset($payload['invoice_id']) && $payload['invoice_id'] !== null) {
            $invoiceId = (string) $payload['invoice_id'];
            $invoice = $this->invoiceRepository->findById($invoiceId);
        } elseif (isset($payload['invoice_number']) && trim((string) $payload['invoice_number']) !== '') {
            $invoice = $this->invoiceRepository->findByInvoiceNumber((string) $payload['invoice_number']);
            $invoiceId = $invoice['id'] ?? null;
        }

        $policyId = (string) $payload['discount_policy_id'];

        // Get invoice
        if ($invoice === null || $invoiceId === null) {
            $invoiceReference = $payload['invoice_number'] ?? $payload['invoice_id'] ?? 'unknown';
            throw new \RuntimeException('Invoice not found: ' . $invoiceReference);
        }

        // Get policy
        $policy = $this->policyRepository->findById($policyId);
        if ($policy === null) {
            throw new \RuntimeException('Discount policy not found: ' . $policyId);
        }

        // Verify policy is active
        if ($policy['status'] !== 'active') {
            throw new \RuntimeException('Discount policy is not active.');
        }

        // Calculate discount
        $originalAmount = (float) $invoice['total_amount'];
        $discountAmount = $this->calculateDiscount($originalAmount, $policy);
        $finalAmount = max(0, $originalAmount - $discountAmount);

        // Check if approval is needed
        if ($policy['requires_approval_above_amount'] !== null
            && $discountAmount > $policy['requires_approval_above_amount']) {
            throw new \RuntimeException(
                'Discount amount exceeds approval threshold. Manual approval required.'
            );
        }

        // Check if discount already applied
        $existingDiscount = $this->discountRepository->findByInvoiceId($invoiceId);
        if ($existingDiscount !== null) {
            throw new \RuntimeException('Discount already applied to this invoice.');
        }

        // Record discount
        $discount = BillingDiscountModel::create([
            'billing_invoice_id' => $invoiceId,
            'billing_discount_policy_id' => $policyId,
            'original_amount' => $originalAmount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'applied_by_user_id' => $actorId,
            'applied_at' => now(),
            'reason' => $payload['reason'] ?? 'Automatic discount application',
        ]);

        // Update invoice (if your system uses total_amount for final price)
        // Note: This depends on your invoice structure - adjust as needed
        $updatedInvoice = $this->invoiceRepository->update($invoiceId, [
            'total_amount' => $finalAmount,
            'discount_amount' => ($invoice['discount_amount'] ?? 0) + $discountAmount,
        ]);

        if ($updatedInvoice !== null) {
            $this->billingFinancePostingService->syncInvoiceRecognition($updatedInvoice, $actorId);
        }

        return array_merge($discount->toArray(), [
            'original_total' => $originalAmount,
            'discount_applied' => $discountAmount,
            'new_total' => $finalAmount,
        ]);
    }

    /**
     * Calculate discount amount based on policy
     *
     * @param float $originalAmount
     * @param array<string, mixed> $policy
     *
     * @return float
     */
    private function calculateDiscount(float $originalAmount, array $policy): float
    {
        return match ($policy['discount_type']) {
            'percentage' => ($originalAmount * $policy['discount_percentage']) / 100,
            'fixed' => (float) $policy['discount_value'],
            'full_waiver' => $originalAmount,
            'tiered' => $this->calculateTieredDiscount($originalAmount, $policy),
            default => 0,
        };
    }

    /**
     * Calculate tiered discount (more complex logic)
     *
     * @param float $originalAmount
     * @param array<string, mixed> $policy
     *
     * @return float
     */
    private function calculateTieredDiscount(float $originalAmount, array $policy): float
    {
        // Example tiered structure from policy
        // This is a simplified example - expand based on your needs
        $tiers = $policy['discount_value'] ?? [];

        foreach ($tiers as $tier) {
            if ($originalAmount >= $tier['min_amount'] && $originalAmount <= $tier['max_amount']) {
                return ($originalAmount * $tier['percentage']) / 100;
            }
        }

        return 0;
    }
}
