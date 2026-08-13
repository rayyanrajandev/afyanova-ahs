<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Transformers;

class MedicalRecordVersionResponseTransformer
{
    public static function transform(array $version): array
    {
        return [
            'id' => $version['id'] ?? null,
            'medicalRecordId' => $version['medical_record_id'] ?? null,
            'versionNumber' => $version['version_number'] ?? null,
            'snapshot' => is_array($version['snapshot'] ?? null) ? $version['snapshot'] : [],
            'changedFields' => is_array($version['changed_fields'] ?? null) ? $version['changed_fields'] : [],
            'createdByUserId' => $version['created_by_user_id'] ?? null,
            'createdAt' => $version['created_at'] ?? null,
        ];
    }
}
