<?php

namespace App\Modules\Encounter\Presentation\Http\Transformers;

class EncounterDiagnosisResponseTransformer
{
    /**
     * @param  array<string, mixed>  $diagnosis
     * @return array<string, mixed>
     */
    public static function transform(array $diagnosis): array
    {
        return [
            'id' => $diagnosis['id'] ?? null,
            'encounterId' => $diagnosis['encounter_id'] ?? null,
            'diagnosisCode' => $diagnosis['diagnosis_code'] ?? null,
            'diagnosisDescription' => $diagnosis['diagnosis_description'] ?? null,
            'diagnosisType' => $diagnosis['diagnosis_type'] ?? null,
            'recordedByUserId' => $diagnosis['recorded_by_user_id'] ?? null,
            'recordedByUserName' => $diagnosis['recorded_by']['name'] ?? null,
            'recordedAt' => $diagnosis['recorded_at'] ?? null,
            'createdAt' => $diagnosis['created_at'] ?? null,
        ];
    }
}
