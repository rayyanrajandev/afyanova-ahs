<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Pos\Domain\ValueObjects\PosSaleChannel;
use App\Modules\Pos\Domain\ValueObjects\PosSaleStatus;
use App\Modules\Pos\Infrastructure\Models\PosSaleLineModel;
use App\Modules\Pos\Infrastructure\Models\PosSalePaymentModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleModel;

class VerifyFrontdeskQuickPosPaymentUseCase
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $sourceKind, string $orderId): array
    {
        $lineQuery = PosSaleLineModel::query()
            ->select([
                'pos_sale_lines.id as line_id',
                'pos_sales.id as sale_id',
                'pos_sales.sale_number',
                'pos_sales.receipt_number',
                'pos_sales.sold_at',
                'pos_sales.status',
            ])
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.pos_sale_id')
            ->where('pos_sale_lines.item_type', 'service')
            ->where('pos_sale_lines.item_reference', $orderId)
            ->where('pos_sales.sale_channel', PosSaleChannel::FRONTDESK_QUICK->value)
            ->where('pos_sales.status', PosSaleStatus::COMPLETED->value);

        $this->applyPlatformScopeIfEnabled($lineQuery, 'pos_sales.tenant_id', 'pos_sales.facility_id');

        $saleRow = $lineQuery->first();

        if ($saleRow === null) {
            return [
                'paid' => false,
                'message' => 'No payment found for this order.',
                'source_kind' => $sourceKind,
                'order_id' => $orderId,
            ];
        }

        $payments = PosSalePaymentModel::query()
            ->where('pos_sale_id', $saleRow->sale_id)
            ->get()
            ->map(static fn (PosSalePaymentModel $payment): array => [
                'payment_method' => $payment->payment_method,
                'amount' => (float) ($payment->amount_applied ?? $payment->amount_received ?? 0),
                'payment_reference' => $payment->payment_reference,
                    'paid_at' => $payment->paid_at instanceof \DateTimeInterface
                        ? $payment->paid_at->format('c')
                        : $payment->paid_at,
            ])
            ->all();

        return [
            'paid' => true,
            'message' => 'Payment verified.',
            'source_kind' => $sourceKind,
            'order_id' => $orderId,
            'sale_id' => (string) $saleRow->sale_id,
            'sale_number' => $saleRow->sale_number,
            'receipt_number' => $saleRow->receipt_number,
            'sold_at' => $saleRow->sold_at instanceof \DateTimeInterface
                ? $saleRow->sold_at->format('c')
                : $saleRow->sold_at,
            'payments' => $payments,
        ];
    }

    private function applyPlatformScopeIfEnabled(
        $query,
        ?string $tenantColumn = 'tenant_id',
        ?string $facilityColumn = 'facility_id',
    ): void {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query, $tenantColumn, $facilityColumn);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
