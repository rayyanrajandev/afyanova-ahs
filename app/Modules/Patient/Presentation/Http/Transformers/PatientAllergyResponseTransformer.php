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
            'reactionCode' => $record['reaction_code'] ?? null,
            'severity' => $record['severity'] ?? null,
            'clinicalStatus' => $record['clinical_status'] ?? null,
            'verificationStatus' => $record['verification_status'] ?? null,
            'type' => $record['type'] ?? null,
            'category' => $record['category'] ?? null,
            'notedAt' => $record['noted_at'] ?? null,
            'lastReactionAt' => $record['last_reaction_at'] ?? null,
            'notes' => $record['notes'] ?? null,
            'source' => $record['source'] ?? null,
            'createdAt' => $record['created_at'] ?? null,
            'updatedAt' => $record['updated_at'] ?? null,
        ];
    }
}
