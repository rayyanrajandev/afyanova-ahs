<?php

namespace App\Modules\Pos\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Pos\Domain\Repositories\PosSaleAdjustmentRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosSaleAdjustmentType;
use App\Modules\Pos\Infrastructure\Models\PosSaleAdjustmentModel;
use Illuminate\Database\Eloquent\Builder;

class EloquentPosSaleAdjustmentRepository implements PosSaleAdjustmentRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $adjustment = new PosSaleAdjustmentModel();
        $adjustment->fill($attributes);
        $adjustment->save();

        $query = PosSaleAdjustmentModel::query()->with(['register', 'session']);
        $this->applyPlatformScopeIfEnabled($query);

        return $query->find((string) $adjustment->id)?->toArray() ?? $adjustment->toArray();
    }

    public function existsByAdjustmentNumber(string $adjustmentNumber): bool
    {
        return PosSaleAdjustmentModel::query()
            ->where('adjustment_number', trim($adjustmentNumber))
            ->exists();
    }

    public function findBySaleId(string $saleId): array
    {
        $query = PosSaleAdjustmentModel::query()
            ->with(['register', 'session'])
            ->where('pos_sale_id', $saleId)
            ->orderBy('processed_at');
        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->get()
            ->map(static fn (PosSaleAdjustmentModel $adjustment): array => $adjustment->toArray())
            ->all();
    }

    public function summarizeSession(string $sessionId): array
    {
        $query = PosSaleAdjustmentModel::query()
            ->with('sale')
            ->where('pos_register_session_id', $sessionId);
        $this->applyPlatformScopeIfEnabled($query);

        $adjustments = $query->get();

        $summary = [
            'voidCount' => 0,
            'refundCount' => 0,
            'adjustmentAmount' => 0.0,
            'cashAdjustmentAmount' => 0.0,
            'nonCashAdjustmentAmount' => 0.0,
        ];

        foreach ($adjustments as $adjustment) {
            $type = (string) $adjustment->adjustment_type;
            $amount = (float) $adjustment->amount;
            $cashAmount = (float) $adjustment->cash_amount;
            $nonCashAmount = (float) $adjustment->non_cash_amount;

            if ($type === PosSaleAdjustmentType::VOID->value) {
                $summary['voidCount']++;
            }

            if ($type === PosSaleAdjustmentType::REFUND->value) {
                $summary['refundCount']++;
            }

            $summary['adjustmentAmount'] += $amount;
            $summary['cashAdjustmentAmount'] += $cashAmount;
            $summary['nonCashAdjustmentAmount'] += $nonCashAmount;
        }

        return array_map(
            static fn (float|int $value): float|int => is_float($value) ? round($value, 2) : $value,
            $summary,
        );
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
