<?php

namespace App\Support\ClinicalOrders;

use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;

final class ClinicalOrderPaymentEnricher
{
    /**
     * Attach payment and revenue authorization status to transformed laboratory orders.
     *
     * @param  list<array<string, mixed>>  $rawOrders
     * @param  list<array<string, mixed>>  $transformedOrders
     * @return list<array<string, mixed>>
     */
    public static function attachToTransformedOrders(
        array $rawOrders,
        array $transformedOrders,
        ChargeSourceKind $sourceKind = ChargeSourceKind::LABORATORY_ORDER,
    ): array {
        $orderIds = [];

        foreach ($rawOrders as $order) {
            $id = trim((string) ($order['id'] ?? ''));
            if ($id !== '') {
                $orderIds[] = $id;
            }
        }

        if ($orderIds === []) {
            return $transformedOrders;
        }

        $charges = ServiceChargeModel::query()
            ->where('source_workflow_kind', $sourceKind->value)
            ->whereIn('source_workflow_id', $orderIds)
            ->where('status', '!=', ServiceChargeStatus::CANCELLED->value)
            ->get()
            ->keyBy('source_workflow_id');

        $prepaidRequired = (bool) config("revenue.prepaid_required_for.{$sourceKind->value}", true);

        return array_map(function (array $transformedOrder) use ($charges, $prepaidRequired): array {
            $orderId = trim((string) ($transformedOrder['id'] ?? ''));
            /** @var ServiceChargeModel|null $charge */
            $charge = $charges->get($orderId);

            if ($charge !== null) {
                $isAuthorized = $charge->status->permitsFulfilment();
                $unitPrice = (float) $charge->unitPrice()->toDecimalString();
                $netAmount = (float) $charge->netAmount()->toDecimalString();

                return array_merge($transformedOrder, [
                    'price' => $netAmount > 0 ? $netAmount : $unitPrice,
                    'unitPrice' => $unitPrice,
                    'currencyCode' => (string) $charge->currency_code,
                    'chargeId' => (string) $charge->id,
                    'chargeNumber' => (string) $charge->charge_number,
                    'paymentStatus' => $charge->status->value,
                    'isAuthorized' => $isAuthorized,
                    'authorizationBasis' => $charge->authorization_basis?->value,
                    'amountDue' => $charge->outstandingAmount()->toDecimalString(),
                    'amountPaid' => $charge->allocatedAmount()->toDecimalString(),
                ]);
            }

            return array_merge($transformedOrder, [
                'price' => isset($transformedOrder['price']) ? (float) $transformedOrder['price'] : null,
                'unitPrice' => isset($transformedOrder['unitPrice']) ? (float) $transformedOrder['unitPrice'] : null,
                'currencyCode' => (string) ($transformedOrder['currencyCode'] ?? 'TZS'),
                'chargeId' => null,
                'chargeNumber' => null,
                'paymentStatus' => 'not_charged',
                'isAuthorized' => ! $prepaidRequired,
                'authorizationBasis' => null,
                'amountDue' => '0.00',
                'amountPaid' => '0.00',
            ]);
        }, $transformedOrders);
    }
}
