<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Domain\Repositories\PosRegisterSessionRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosSaleAdjustmentRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosSaleRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterSessionStatus;
use Illuminate\Support\Facades\DB;

class ClosePosRegisterSessionUseCase
{
    public function __construct(
        private readonly PosRegisterSessionRepositoryInterface $posRegisterSessionRepository,
        private readonly PosSaleRepositoryInterface $posSaleRepository,
        private readonly PosSaleAdjustmentRepositoryInterface $posSaleAdjustmentRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($id, $payload, $actorId): ?array {
            $session = $this->posRegisterSessionRepository->findById($id, true);
            if ($session === null) {
                return null;
            }

            if (($session['status'] ?? null) !== PosRegisterSessionStatus::OPEN->value) {
                throw new PosOperationException('Register session is already closed.', 'sessionId');
            }

            $summary = $this->posSaleRepository->summarizeSession($id);
            $adjustmentSummary = $this->posSaleAdjustmentRepository->summarizeSession($id);
            $expectedCashAmount = round(
                (float) ($session['opening_cash_amount'] ?? 0)
                + (float) ($summary['cashNetSalesAmount'] ?? 0)
                - (float) ($adjustmentSummary['cashAdjustmentAmount'] ?? 0),
                2,
            );
            $closingCashAmount = round(max((float) ($payload['closing_cash_amount'] ?? 0), 0), 2);

            return $this->posRegisterSessionRepository->update($id, [
                'status' => PosRegisterSessionStatus::CLOSED->value,
                'closed_at' => now(),
                'closing_cash_amount' => $closingCashAmount,
                'expected_cash_amount' => $expectedCashAmount,
                'discrepancy_amount' => round($closingCashAmount - $expectedCashAmount, 2),
                'gross_sales_amount' => round((float) ($summary['grossSalesAmount'] ?? 0), 2),
                'total_discount_amount' => round((float) ($summary['totalDiscountAmount'] ?? 0), 2),
                'total_tax_amount' => round((float) ($summary['totalTaxAmount'] ?? 0), 2),
                'cash_net_sales_amount' => round((float) ($summary['cashNetSalesAmount'] ?? 0), 2),
                'non_cash_sales_amount' => round((float) ($summary['nonCashSalesAmount'] ?? 0), 2),
                'sale_count' => (int) ($summary['saleCount'] ?? 0),
                'void_count' => (int) ($adjustmentSummary['voidCount'] ?? 0),
                'refund_count' => (int) ($adjustmentSummary['refundCount'] ?? 0),
                'adjustment_amount' => round((float) ($adjustmentSummary['adjustmentAmount'] ?? 0), 2),
                'cash_adjustment_amount' => round((float) ($adjustmentSummary['cashAdjustmentAmount'] ?? 0), 2),
                'non_cash_adjustment_amount' => round((float) ($adjustmentSummary['nonCashAdjustmentAmount'] ?? 0), 2),
                'closing_note' => $this->nullableTrimmedValue($payload['closing_note'] ?? null),
                'closed_by_user_id' => $actorId,
            ]);
        });
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
