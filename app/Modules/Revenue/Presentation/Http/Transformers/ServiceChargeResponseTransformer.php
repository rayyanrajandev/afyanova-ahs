<?php

namespace App\Modules\Revenue\Presentation\Http\Transformers;

use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;

class ServiceChargeResponseTransformer
{
    /**
     * Every amount goes out as a decimal string, never a JSON number.
     *
     * A float in the payload would reintroduce at the API boundary exactly the
     * precision problem the ledger stores integers to avoid, and JavaScript
     * would be the one to lose the cent.
     *
     * @return array<string, mixed>
     */
    public static function transform(ServiceChargeModel $charge): array
    {
        $currency = (string) $charge->currency_code;
        $money = static fn (mixed $minor): string => Money::of((int) $minor, $currency)->toDecimalString();

        return [
            'id' => (string) $charge->id,
            'chargeNumber' => (string) $charge->charge_number,
            'patientId' => (string) $charge->patient_id,
            'appointmentId' => $charge->appointment_id,
            'encounterId' => $charge->encounter_id,
            'sourceKind' => $charge->source_workflow_kind->value,
            'sourceId' => $charge->source_workflow_id,
            'description' => (string) $charge->description,
            'unit' => $charge->unit,
            'quantity' => (float) $charge->quantity,
            'currencyCode' => $currency,
            'unitPrice' => $money($charge->unit_price_minor),
            'grossAmount' => $money($charge->gross_amount_minor),
            'discountAmount' => $money($charge->discount_amount_minor),
            'discountReason' => $charge->discount_reason,
            'taxAmount' => $money($charge->tax_amount_minor),
            'netAmount' => $money($charge->net_amount_minor),
            'amountPaid' => $money($charge->allocated_amount_minor),
            'amountDue' => $charge->outstandingAmount()->toDecimalString(),
            'payerClass' => $charge->payer_class->value,
            'status' => $charge->status->value,
            'pricingStatus' => $charge->pricing_status,
            // What the counter actually needs to know: can I take money for
            // this right now? An unpriced charge is outstanding but not
            // payable, and the two must not look the same on screen.
            'isPayable' => $charge->status->isOutstanding() && $charge->pricing_status === 'priced',
            'authorizationBasis' => $charge->authorization_basis?->value,
            'authorizedAt' => $charge->authorized_at?->toIso8601String(),
            'createdAt' => $charge->created_at?->toIso8601String(),
        ];
    }
}
