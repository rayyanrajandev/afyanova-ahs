<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Pos\Infrastructure\Models\PosSaleAdjustmentModel;
use App\Modules\Pos\Infrastructure\Models\PosSaleModel;
use Illuminate\Database\Eloquent\Builder;

class GetPosRegisterSessionReportUseCase
{
    public function __construct(
        private readonly GetPosRegisterSessionUseCase $getPosRegisterSessionUseCase,
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $id): ?array
    {
        $session = $this->getPosRegisterSessionUseCase->execute($id);
        if ($session === null) {
            return null;
        }

        $salesQuery = PosSaleModel::query()
            ->with(['register', 'session', 'lineItems', 'payments', 'adjustments'])
            ->where('pos_register_session_id', $id)
            ->orderBy('sold_at')
            ->orderBy('created_at');
        $this->applyPlatformScopeIfEnabled($salesQuery);

        $sales = $salesQuery
            ->get()
            ->map(static fn (PosSaleModel $sale): array => $sale->toArray())
            ->all();

        $adjustmentsQuery = PosSaleAdjustmentModel::query()
            ->with(['sale', 'register', 'session'])
            ->where('pos_register_session_id', $id)
            ->orderBy('processed_at')
            ->orderBy('created_at');
        $this->applyPlatformScopeIfEnabled($adjustmentsQuery);

        $adjustments = $adjustmentsQuery
            ->get()
            ->map(static fn (PosSaleAdjustmentModel $adjustment): array => $adjustment->toArray())
            ->all();

        return [
            'session' => $session,
            'sales' => $sales,
            'adjustments' => $adjustments,
            'channel_breakdown' => $this->channelBreakdown($sales),
            'payment_breakdown' => $this->paymentBreakdown($sales),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $sales
     * @return array<int, array<string, mixed>>
     */
    private function channelBreakdown(array $sales): array
    {
        $breakdown = [];

        foreach ($sales as $sale) {
            $channel = trim((string) ($sale['sale_channel'] ?? '')) ?: 'unknown';

            if (! isset($breakdown[$channel])) {
                $breakdown[$channel] = [
                    'sale_channel' => $channel,
                    'sale_count' => 0,
                    'subtotal_amount' => 0.0,
                    'discount_amount' => 0.0,
                    'tax_amount' => 0.0,
                    'total_amount' => 0.0,
                    'paid_amount' => 0.0,
                    'change_amount' => 0.0,
                ];
            }

            $breakdown[$channel]['sale_count']++;
            $breakdown[$channel]['subtotal_amount'] += (float) ($sale['subtotal_amount'] ?? 0);
            $breakdown[$channel]['discount_amount'] += (float) ($sale['discount_amount'] ?? 0);
            $breakdown[$channel]['tax_amount'] += (float) ($sale['tax_amount'] ?? 0);
            $breakdown[$channel]['total_amount'] += (float) ($sale['total_amount'] ?? 0);
            $breakdown[$channel]['paid_amount'] += (float) ($sale['paid_amount'] ?? 0);
            $breakdown[$channel]['change_amount'] += (float) ($sale['change_amount'] ?? 0);
        }

        return array_values(array_map(
            fn (array $row): array => [
                ...$row,
                'subtotal_amount' => round((float) $row['subtotal_amount'], 2),
                'discount_amount' => round((float) $row['discount_amount'], 2),
                'tax_amount' => round((float) $row['tax_amount'], 2),
                'total_amount' => round((float) $row['total_amount'], 2),
                'paid_amount' => round((float) $row['paid_amount'], 2),
                'change_amount' => round((float) $row['change_amount'], 2),
            ],
            $breakdown,
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $sales
     * @return array<int, array<string, mixed>>
     */
    private function paymentBreakdown(array $sales): array
    {
        $breakdown = [];

        foreach ($sales as $sale) {
            $payments = is_array($sale['payments'] ?? null) ? $sale['payments'] : [];

            foreach ($payments as $payment) {
                $method = trim((string) ($payment['payment_method'] ?? '')) ?: 'unknown';

                if (! isset($breakdown[$method])) {
                    $breakdown[$method] = [
                        'payment_method' => $method,
                        'payment_count' => 0,
                        'amount_received' => 0.0,
                        'amount_applied' => 0.0,
                        'change_given' => 0.0,
                    ];
                }

                $breakdown[$method]['payment_count']++;
                $breakdown[$method]['amount_received'] += (float) ($payment['amount_received'] ?? 0);
                $breakdown[$method]['amount_applied'] += (float) ($payment['amount_applied'] ?? 0);
                $breakdown[$method]['change_given'] += (float) ($payment['change_given'] ?? 0);
            }
        }

        return array_values(array_map(
            fn (array $row): array => [
                ...$row,
                'amount_received' => round((float) $row['amount_received'], 2),
                'amount_applied' => round((float) $row['amount_applied'], 2),
                'change_given' => round((float) $row['change_given'], 2),
            ],
            $breakdown,
        ));
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
