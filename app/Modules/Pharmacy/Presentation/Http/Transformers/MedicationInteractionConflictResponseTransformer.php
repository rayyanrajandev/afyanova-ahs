<?php

namespace App\Modules\Pharmacy\Presentation\Http\Transformers;

class MedicationInteractionConflictResponseTransformer
{
    public static function transform(array $conflict): array
    {
        return [
            'ruleCode' => $conflict['rule_code'] ?? null,
            'severity' => $conflict['severity'] ?? null,
            'category' => $conflict['category'] ?? null,
            'message' => $conflict['message'] ?? null,
            'clinicalEffect' => $conflict['clinical_effect'] ?? null,
            'recommendedAction' => $conflict['recommended_action'] ?? null,
            'targetMedicationCode' => $conflict['target_medication_code'] ?? null,
            'targetMedicationName' => $conflict['target_medication_name'] ?? null,
            'interactingMedicationCode' => $conflict['interacting_medication_code'] ?? null,
            'interactingMedicationName' => $conflict['interacting_medication_name'] ?? null,
            'sourceType' => $conflict['source_type'] ?? null,
            'sourceLabel' => $conflict['source_label'] ?? null,
        ];
    }
}
