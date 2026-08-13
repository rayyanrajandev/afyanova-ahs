<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Transformers;

class MedicalRecordSignerAttestationResponseTransformer
{
    public static function transform(array $attestation): array
    {
        return [
            'id' => $attestation['id'] ?? null,
            'medicalRecordId' => $attestation['medical_record_id'] ?? null,
            'attestedByUserId' => $attestation['attested_by_user_id'] ?? null,
            'attestedByUserName' => $attestation['attested_by_user']['name'] ?? $attestation['attestedByUser']['name'] ?? null,
            'attestationNote' => $attestation['attestation_note'] ?? null,
            'attestedAt' => $attestation['attested_at'] ?? null,
            'createdAt' => $attestation['created_at'] ?? null,
            'updatedAt' => $attestation['updated_at'] ?? null,
        ];
    }
}
