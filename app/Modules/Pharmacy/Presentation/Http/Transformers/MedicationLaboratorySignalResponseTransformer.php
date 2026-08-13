<?php

namespace App\Modules\Pharmacy\Presentation\Http\Transformers;

class MedicationLaboratorySignalResponseTransformer
{
    public static function transform(array $signal): array
    {
        return [
            'ruleCode' => $signal['rule_code'] ?? null,
            'severity' => $signal['severity'] ?? null,
            'category' => $signal['category'] ?? null,
            'message' => $signal['message'] ?? null,
            'clinicalEffect' => $signal['clinical_effect'] ?? null,
            'recommendedAction' => $signal['recommended_action'] ?? null,
            'sourceOrderId' => $signal['source_order_id'] ?? null,
            'sourceTestCode' => $signal['source_test_code'] ?? null,
            'sourceTestName' => $signal['source_test_name'] ?? null,
            'sourceResultSummary' => $signal['source_result_summary'] ?? null,
            'sourceVerifiedAt' => $signal['source_verified_at'] ?? null,
            'sourceFlag' => $signal['source_flag'] ?? null,
        ];
    }
}
