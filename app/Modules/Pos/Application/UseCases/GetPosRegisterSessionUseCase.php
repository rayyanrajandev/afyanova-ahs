<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Pos\Domain\Repositories\PosRegisterSessionRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosSaleAdjustmentRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosSaleRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterSessionStatus;

class GetPosRegisterSessionUseCase
{
    public function __construct(
        private readonly PosRegisterSessionRepositoryInterface $posRegisterSessionRepository,
        private readonly PosSaleRepositoryInterface $posSaleRepository,
        private readonly PosSaleAdjustmentRepositoryInterface $posSaleAdjustmentRepository,
    ) {}

    public function execute(string $id): ?array
    {
        $session = $this->posRegisterSessionRepository->findById($id);
        if ($session === null) {
            return null;
        }

        if (($session['status'] ?? null) !== PosRegisterSessionStatus::OPEN->value) {
            return $session;
        }

        $summary = $this->posSaleRepository->summarizeSession($id);
        $adjustmentSummary = $this->posSaleAdjustmentRepository->summarizeSession($id);

        $session['closeout_preview'] = [
            'expected_cash_amount' => round(
                (float) ($session['opening_cash_amount'] ?? 0)
                + (float) ($summary['cashNetSalesAmount'] ?? 0)
                - (float) ($adjustmentSummary['cashAdjustmentAmount'] ?? 0),
                2,
            ),
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
        ];

        return $session;
    }
}
