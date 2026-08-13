<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingRoutingDecisionResponseTransformer
{
    public static function transform(array $decision): array
    {
        return [
            'routing_decision' => $decision['routing_decision'] ?? null,
            'payer_type' => $decision['payer_type'] ?? null,
            'payer_id' => $decision['payer_id'] ?? null,
            'payer_name' => $decision['payer_name'] ?? null,
            'use_insurance_pricing' => $decision['use_insurance_pricing'] ?? null,
            'insurance_type' => $decision['insurance_type'] ?? null,
            'policy_number' => $decision['policy_number'] ?? null,
            'coverage_level' => $decision['coverage_level'] ?? null,
            'reason' => $decision['reason'] ?? null,
        ];
    }
}
