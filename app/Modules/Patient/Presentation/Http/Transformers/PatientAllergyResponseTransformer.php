<?php

namespace App\Modules\Patient\Presentation\Http\Transformers;

class PatientAllergyResponseTransformer
{
    public static function transform(array $record): array
    {
        return [
            'id' => $record['id'] ?? null,
            'patientId' => $record['patient_id'] ?? null,
            'substanceCode' => $record['substance_code'] ?? null,
            'substanceName' => $record['substance_name'] ?? null,
            'reaction' => $record['reaction'] ?? null,
            'severity' => $record['severity'] ?? null,
            'status' => $record['status'] ?? null,
            'notedAt' => $record['noted_at'] ?? null,
            'lastReactionAt' => $record['last_reaction_at'] ?? null,
            'notes' => $record['notes'] ?? null,
            'createdAt' => $record['created_at'] ?? null,
            'updatedAt' => $record['updated_at'] ?? null,
        ];
    }
}
