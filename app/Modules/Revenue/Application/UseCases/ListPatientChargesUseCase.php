<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;

/**
 * The basket: everything this patient currently owes, and its total.
 */
class ListPatientChargesUseCase
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function execute(string $patientId, bool $includeSettled = false): array
    {
        $charges = ServiceChargeModel::query()
            ->where('patient_id', $patientId)
            ->when(
                ! $includeSettled,
                fn ($q) => $q->whereIn('status', [
                    ServiceChargeStatus::DRAFT->value,
                    ServiceChargeStatus::PENDING_PAYMENT->value,
                ]),
            )
            ->orderBy('created_at')
            ->get();

        $currency = (string) ($charges->first()?->currency_code ?? 'TZS');

        $outstanding = $charges->reduce(
            fn (Money $carry, ServiceChargeModel $c): Money => $carry->plus($c->outstandingAmount()),
            Money::zero($currency),
        );

        return [
            'data' => $charges->all(),
            'meta' => [
                'total' => $charges->count(),
                'amountDue' => $outstanding->toDecimalString(),
                'currencyCode' => $currency,
                // A charge with no price cannot be paid for, and the counter
                // has to be told rather than left with a total that quietly
                // omits it.
                'unpricedCount' => $charges
                    ->filter(fn (ServiceChargeModel $c): bool => $c->pricing_status !== 'priced')
                    ->count(),
            ],
        ];
    }
}
